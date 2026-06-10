<?php
/**
 * Newspack Insights — Advertising Metric orchestrator (Tab 8, NPPD-1663).
 *
 * Publisher-side equivalent of the Audience/Engagement orchestrators
 * (NPPD-1648), but the data source is Google Ad Manager via the SOAP
 * ReportService client landed in NPPD-1662 ({@see \Newspack\Insights\GAM\Client}).
 *
 * Unlike GA4 (fast, synchronous), GAM reports are asynchronous jobs that can
 * take seconds to minutes (submit -> poll -> download -> parse). They therefore
 * never run inside a web request. Instead:
 *   - {@see self::get_all()} reads a transient cache and, on a missing/stale
 *     entry, schedules the {@see self::REFRESH_ACTION} Action Scheduler job and
 *     returns the (stale or loading) payload immediately (stale-while-revalidate).
 *   - {@see self::run_scheduled_refresh()} (the Action Scheduler handler) runs
 *     every metric's report end-to-end and writes the window payload to cache.
 *
 * Caching uses WP transients to match the NPPD-1648 orchestrators. (The original
 * architecture's custom `wp_newspack_insights_cache` SWR table — NPPD-1605 — was
 * canceled; see architecture.md.)
 *
 * GAM revenue columns are micro-currency; normalization to standard currency
 * happens at this layer's boundary so the UI never receives micros.
 *
 * Enum names (columns/dimensions/line-item types) are the documented v202602
 * ReportService names and remain pending verification against a real publisher
 * network (NPPD-1666).
 *
 * Payload shapes mirror the Audience orchestrator:
 *   scalar  : { value, computable, type: count|currency|decimal }
 *   rate    : { value (0-1), computable, type: rate, numerator, denominator }
 *   rows    : { rows: [...], computable, type: breakdown|table|timeseries }
 *   overlay : { value: null, computable: false, overlay: { type } }
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Insights\GAM\Client;
use Newspack\Insights\GAM\Report_Query;
use Newspack\Insights\GAM\Report_Job_Status;
use Newspack\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Advertising (Tab 8) metric orchestrator.
 *
 * Not `final` (unlike the Audience/Engagement orchestrators): the GAM-touching
 * {@see self::run_gam_report()} seam is `protected` and called via `static::`
 * so unit tests can subclass and inject canned report rows per metric (the GAM
 * Client is `final` + static and cannot otherwise be mocked).
 */
class Advertising_Metric {

	const CACHE_KEY_PREFIX = 'newspack_insights_advertising_v1:';
	const CACHE_FRESH_TTL  = 15 * MINUTE_IN_SECONDS;
	const CACHE_HARD_TTL   = DAY_IN_SECONDS;
	const CACHE_RETRY_TTL  = 5 * MINUTE_IN_SECONDS;

	const REFRESH_ACTION = 'newspack_insights_advertising_refresh';
	const REFRESH_GROUP  = 'newspack-insights';

	const AUDIT_LOG_OPTION = 'newspack_insights_advertising_audit_log';
	const AUDIT_LOG_MAX    = 500;
	const LOGGER_HEADER    = 'NEWSPACK-INSIGHTS-ADVERTISING';

	/**
	 * Per-report poll backoff (seconds) and overall ceiling per report job.
	 */
	const POLL_BACKOFF_SECONDS = [ 1, 2, 4, 8, 16, 30 ];
	const POLL_MAX_SECONDS     = 300;

	/**
	 * GAM data lag: figures for the most recent N days are estimated until AdX
	 * clears. Drives the "data as of" / estimated-window indicators.
	 */
	const ESTIMATED_LAG_DAYS = 7;

	/*
	 * GAM v202602 ReportService column/dimension enum names. Pending NPPD-1666
	 * verification against a real publisher network.
	 */
	const COL_IMPRESSIONS   = 'TOTAL_IMPRESSIONS';
	const COL_REVENUE       = 'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE';
	const COL_CODED         = 'TOTAL_CODE_SERVED_COUNT';
	const COL_CLICKS        = 'TOTAL_LINE_ITEM_LEVEL_CLICKS';
	const COL_AV_VIEWABLE   = 'TOTAL_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS';
	const COL_AV_MEASURABLE = 'TOTAL_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS';

	const DIM_LINE_ITEM_TYPE  = 'LINE_ITEM_TYPE';
	const DIM_AD_UNIT_NAME    = 'AD_UNIT_NAME';
	const DIM_ADVERTISER_NAME = 'ADVERTISER_NAME';

	const DIRECT_LINE_ITEM_TYPES       = [ 'SPONSORSHIP', 'STANDARD', 'BULK', 'PRICE_PRIORITY' ];
	const PROGRAMMATIC_LINE_ITEM_TYPES = [ 'NETWORK', 'AD_EXCHANGE' ];

	/**
	 * Per-request memo of Client::can_run_reports() (which makes a remote OAuth
	 * scope call), so a single request — including the current + comparison
	 * windows and readiness_issues() — performs it at most once.
	 *
	 * @var bool|null
	 */
	private static $can_run_memo = null;

	/**
	 * Per-request memo of the GAM-scope check (remote tokeninfo call).
	 *
	 * @var bool|null
	 */
	private static $has_scope_memo = null;

	/*
	 * Visibility / readiness
	 */

	/**
	 * Whether Tab 8 should be visible: Google Ad Manager active on the site.
	 *
	 * @return bool
	 */
	public static function is_tab_visible(): bool {
		return Client::is_gam_active();
	}

	/**
	 * Whether a report can actually be run (GAM active + OAuth scope + network
	 * code). Memoized per request: the underlying Client::can_run_reports()
	 * makes a remote OAuth scope call, so this is computed once even though
	 * get_all() (current + previous windows) and readiness_issues() all consult it.
	 *
	 * @return bool
	 */
	public static function is_report_ready(): bool {
		if ( null === self::$can_run_memo ) {
			self::$can_run_memo = Client::can_run_reports();
		}
		return self::$can_run_memo;
	}

	/**
	 * Reset the per-request readiness memos. Mainly for tests; harmless in
	 * production (each request starts fresh).
	 *
	 * @return void
	 */
	public static function reset_readiness_cache(): void {
		self::$can_run_memo   = null;
		self::$has_scope_memo = null;
	}

	/**
	 * Specific reasons reporting isn't ready, for the UI to render guidance.
	 * Empty array when ready. Both issues can be present simultaneously.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function readiness_issues(): array {
		// When GAM isn't even active the tab is hidden; skip the remote scope
		// check entirely (is_tab_visible is a cheap, local-only signal).
		if ( ! self::is_tab_visible() || self::is_report_ready() ) {
			return [];
		}
		$issues = [];
		if ( ! self::has_admanager_scope() ) {
			$issues[] = [
				'code'            => 'oauth_scope_missing',
				'message'         => __( 'Your Google connection is missing the Ad Manager scope. Reconnect Google to grant it.', 'newspack-plugin' ),
				'remediation_url' => admin_url( 'admin.php?page=newspack-settings' ),
			];
		}
		if ( '' === self::resolve_network_code() ) {
			$issues[] = [
				'code'            => 'network_code_missing',
				'message'         => __( 'No Google Ad Manager network is configured.', 'newspack-plugin' ),
				'remediation_url' => admin_url( 'admin.php?page=newspack-advertising' ),
			];
		}
		return $issues;
	}

	/**
	 * Whether the saved Google OAuth token carries the GAM scope.
	 *
	 * Resolved here (rather than on the Client, whose accessor is protected) so
	 * this PR stays scoped to new files; mirrors Client::has_gam_scope().
	 *
	 * @return bool
	 */
	private static function has_admanager_scope(): bool {
		if ( null === self::$has_scope_memo ) {
			self::$has_scope_memo = class_exists( '\Newspack\Google_OAuth' )
				&& \Newspack\Google_OAuth::token_has_scope( Client::GAM_SCOPE );
		}
		return self::$has_scope_memo;
	}

	/**
	 * Resolve the publisher's GAM network code, server-side. Mirrors the
	 * Client's resolution (its accessor is protected).
	 *
	 * @return string Network code, or '' if none.
	 */
	private static function resolve_network_code(): string {
		if ( class_exists( '\Newspack_Ads\Providers\GAM_Model' ) ) {
			$code = \Newspack_Ads\Providers\GAM_Model::get_active_network_code();
		} else {
			$code = get_option( '_newspack_ads_gam_network_code', '' );
		}
		if ( is_string( $code ) && false !== strpos( $code, ',' ) ) {
			$parts = explode( ',', $code );
			$code  = trim( $parts[0] );
		}
		return (string) $code;
	}

	/*
	 * Public tab payload
	 */

	/**
	 * Full Tab 8 payload for a window. Reads cache; schedules a background
	 * refresh on a missing/stale entry (stale-while-revalidate). Never runs GAM
	 * reports synchronously.
	 *
	 * @param string $start_date YYYY-MM-DD (site timezone).
	 * @param string $end_date   YYYY-MM-DD (site timezone).
	 * @param bool   $compare    Whether to attach the prior-period payload.
	 * @return array
	 */
	public static function get_all( string $start_date, string $end_date, bool $compare = false ): array {
		$envelope = [
			'window'           => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			'is_tab_visible'   => self::is_tab_visible(),
			'is_report_ready'  => self::is_report_ready(),
			'readiness_issues' => self::readiness_issues(),
		];

		// Not connected enough to report: return the envelope so the UI can show
		// the tab (if visible) with a "finish connecting" diagnostic. Don't
		// schedule a refresh that would just be skipped.
		if ( ! $envelope['is_tab_visible'] || ! $envelope['is_report_ready'] ) {
			return array_merge( $envelope, self::empty_window( $start_date, $end_date ), [ 'is_report_ready' => $envelope['is_report_ready'] ] );
		}

		$window = self::read_window( $start_date, $end_date );
		$envelope = array_merge( $envelope, $window );

		if ( $compare ) {
			[ $prior_start, $prior_end ] = self::prior_period( $start_date, $end_date );
			$compare_window              = self::read_window( $prior_start, $prior_end );
			$envelope['compare']         = $compare_window;
		}

		return $envelope;
	}

	/**
	 * Realistic fixture payload for UI smoke testing without a GAM connection.
	 * Served by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @param bool   $compare    Whether to attach the comparison payload.
	 * @param string $variant    Render-path variant: populated|not_ready|zero|no_viewability.
	 * @return array
	 */
	public static function get_fixture( string $start_date, string $end_date, bool $compare = false, string $variant = 'populated' ): array {
		$fixture = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/advertising-fixture.php';
		return $fixture( $start_date, $end_date, $compare, $variant );
	}

	/*
	 * Cache (transient SWR) + Action Scheduler refresh
	 */

	/**
	 * Read a window's cached payload, scheduling a background refresh when the
	 * entry is missing (loading) or stale (stale-while-revalidate).
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array Window payload with `is_loading` / `is_stale` flags as relevant.
	 */
	private static function read_window( string $start_date, string $end_date ): array {
		$cached = self::read_cache_entry( $start_date, $end_date );

		if ( null === $cached ) {
			self::schedule_refresh( $start_date, $end_date );
			$window               = self::empty_window( $start_date, $end_date );
			$window['is_loading'] = true;
			return $window;
		}

		$window = $cached['payload'];
		if ( ( time() - (int) $cached['computed_at'] ) > self::CACHE_FRESH_TTL ) {
			self::schedule_refresh( $start_date, $end_date );
			$window['is_stale'] = true;
		}
		return $window;
	}

	/**
	 * Read and validate a window's cache entry. Returns the
	 * `[ computed_at, payload ]` wrapper only when well-formed, else null. The
	 * single source of truth for "a usable cache entry exists", shared by
	 * read_window() and the refresh guard.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array|null
	 */
	private static function read_cache_entry( string $start_date, string $end_date ): ?array {
		$cached = get_transient( self::cache_key( $start_date, $end_date ) );
		if ( is_array( $cached ) && isset( $cached['payload'], $cached['computed_at'] ) && is_array( $cached['payload'] ) ) {
			return $cached;
		}
		return null;
	}

	/**
	 * Window cache key, scoped to the network code so a reconnect to a different
	 * network never serves the previous network's cached payload.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return string
	 */
	private static function cache_key( string $start_date, string $end_date ): string {
		return self::CACHE_KEY_PREFIX . md5( self::resolve_network_code() . '|' . $start_date . '|' . $end_date );
	}

	/**
	 * Schedule a one-off background refresh for a window if one isn't already
	 * pending. No-op if Action Scheduler isn't available.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return void
	 */
	private static function schedule_refresh( string $start_date, string $end_date ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$args = [
			'start' => $start_date,
			'end'   => $end_date,
		];
		if ( as_has_scheduled_action( self::REFRESH_ACTION, [ $args ], self::REFRESH_GROUP ) ) {
			return;
		}
		as_schedule_single_action( time(), self::REFRESH_ACTION, [ $args ], self::REFRESH_GROUP );
	}

	/**
	 * Action Scheduler handler: run every metric's report for the window and
	 * write the result to cache. Skips cleanly (leaving any existing cache in
	 * place) when reporting isn't ready.
	 *
	 * @param array $args [ 'start' => YYYY-MM-DD, 'end' => YYYY-MM-DD ].
	 * @return void
	 */
	public static function run_scheduled_refresh( $args ): void {
		$start_date = is_array( $args ) ? (string) ( $args['start'] ?? '' ) : '';
		$end_date   = is_array( $args ) ? (string) ( $args['end'] ?? '' ) : '';
		if ( '' === $start_date || '' === $end_date ) {
			return;
		}

		// Never run GAM reports when not ready; leave previous cache untouched.
		if ( ! self::is_report_ready() ) {
			return;
		}

		$metrics      = self::compute_window( $start_date, $end_date );
		$had_failures = self::any_failed( $metrics );

		// If this refresh hit ANY failure and a valid prior payload exists, keep
		// the prior payload rather than overwriting good data with errors.
		if ( $had_failures && null !== self::read_cache_entry( $start_date, $end_date ) ) {
			return;
		}

		$lag    = self::data_lag_info( $end_date );
		$window = array_merge(
			[
				'window'      => [
					'start' => $start_date,
					'end'   => $end_date,
				],
				'metrics'     => $metrics,
				'computed_at' => gmdate( 'c' ),
			],
			$lag
		);

		// A failure-containing payload (only written when there's no prior good
		// entry) gets a short TTL so it self-expires and retries soon, instead of
		// being served as "fresh" for the full hard TTL.
		set_transient(
			self::cache_key( $start_date, $end_date ),
			[
				'computed_at' => time(),
				'payload'     => $window,
			],
			$had_failures ? self::CACHE_RETRY_TTL : self::CACHE_HARD_TTL
		);
	}

	/**
	 * Compute every Tab 8 metric for a window (the expensive, GAM-touching path
	 * run from the background job).
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array<string,array> Keyed metric payloads.
	 */
	private static function compute_window( string $start_date, string $end_date ): array {
		return [
			'total_impressions'      => self::total_impressions( $start_date, $end_date ),
			'total_revenue'          => self::total_revenue( $start_date, $end_date ),
			'avg_ecpm'               => self::avg_ecpm( $start_date, $end_date ),
			'fill_rate'              => self::fill_rate( $start_date, $end_date ),
			'viewability_rate'       => self::viewability_rate( $start_date, $end_date ),
			'direct_vs_programmatic' => self::direct_vs_programmatic( $start_date, $end_date ),
			'top_ad_units'           => self::top_ad_units( $start_date, $end_date ),
			'top_advertisers'        => self::top_advertisers( $start_date, $end_date ),
		];
	}

	/*
	 * Metric methods (one per formula-doc metric). Each runs a GAM report
	 * end-to-end and returns a MetricCard-shaped payload. Invoked from the
	 * background refresh, never synchronously in a request.
	 */

	/**
	 * Total Impressions (window).
	 *
	 * @param string $s Start date.
	 * @param string $e End date.
	 * @return array
	 */
	public static function total_impressions( string $s, string $e ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'columns'    => [ self::COL_IMPRESSIONS ],
					'start_date' => $s,
					'end_date'   => $e,
				]
			)
		);
		if ( isset( $rows['error'] ) || isset( $rows['overlay'] ) ) {
			return $rows;
		}
		return self::scalar_count( self::sum_column( $rows['rows'], self::COL_IMPRESSIONS ) );
	}

	/**
	 * Total Revenue (window), normalized from micros.
	 *
	 * @param string $s Start date.
	 * @param string $e End date.
	 * @return array
	 */
	public static function total_revenue( string $s, string $e ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'columns'    => [ self::COL_REVENUE ],
					'start_date' => $s,
					'end_date'   => $e,
				]
			)
		);
		if ( isset( $rows['error'] ) || isset( $rows['overlay'] ) ) {
			return $rows;
		}
		$revenue = Client::normalize_currency_micros( self::sum_column( $rows['rows'], self::COL_REVENUE ) );
		return [
			'value'      => $revenue,
			'computable' => true,
			'type'       => 'currency',
		];
	}

	/**
	 * Average eCPM — revenue (normalized) / coded impressions * 1000.
	 *
	 * @param string $s Start date.
	 * @param string $e End date.
	 * @return array
	 */
	public static function avg_ecpm( string $s, string $e ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'columns'    => [ self::COL_REVENUE, self::COL_CODED ],
					'start_date' => $s,
					'end_date'   => $e,
				]
			)
		);
		if ( isset( $rows['error'] ) || isset( $rows['overlay'] ) ) {
			return $rows;
		}
		$revenue = Client::normalize_currency_micros( self::sum_column( $rows['rows'], self::COL_REVENUE ) );
		$coded   = self::sum_column( $rows['rows'], self::COL_CODED );
		return [
			'value'       => $coded > 0 ? ( $revenue / $coded ) * 1000 : 0.0,
			'computable'  => $coded > 0,
			'type'        => 'currency',
			'numerator'   => $revenue,
			'denominator' => $coded,
		];
	}

	/**
	 * Fill Rate — coded impressions / total impressions.
	 *
	 * @param string $s Start date.
	 * @param string $e End date.
	 * @return array
	 */
	public static function fill_rate( string $s, string $e ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'columns'    => [ self::COL_CODED, self::COL_IMPRESSIONS ],
					'start_date' => $s,
					'end_date'   => $e,
				]
			)
		);
		if ( isset( $rows['error'] ) || isset( $rows['overlay'] ) ) {
			return $rows;
		}
		$coded = self::sum_column( $rows['rows'], self::COL_CODED );
		$total = self::sum_column( $rows['rows'], self::COL_IMPRESSIONS );
		return [
			'value'       => $total > 0 ? min( 1.0, $coded / $total ) : 0.0,
			'computable'  => $total > 0,
			'type'        => 'rate',
			'numerator'   => $coded,
			'denominator' => $total,
		];
	}

	/**
	 * Viewability Rate — Active View viewable / measurable. Degrades to a
	 * data_unavailable overlay when the network has no Active View data.
	 *
	 * @param string $s Start date.
	 * @param string $e End date.
	 * @return array
	 */
	public static function viewability_rate( string $s, string $e ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'columns'    => [ self::COL_AV_VIEWABLE, self::COL_AV_MEASURABLE ],
					'start_date' => $s,
					'end_date'   => $e,
				]
			)
		);
		if ( isset( $rows['error'] ) || isset( $rows['overlay'] ) ) {
			return $rows;
		}
		$measurable = self::sum_column( $rows['rows'], self::COL_AV_MEASURABLE );
		if ( $measurable <= 0 ) {
			return [
				'value'      => null,
				'computable' => false,
				'overlay'    => [ 'type' => 'data_unavailable' ],
			];
		}
		$viewable = self::sum_column( $rows['rows'], self::COL_AV_VIEWABLE );
		return [
			'value'       => min( 1.0, $viewable / $measurable ),
			'computable'  => true,
			'type'        => 'rate',
			'numerator'   => $viewable,
			'denominator' => $measurable,
		];
	}

	/**
	 * Direct vs Programmatic split — bucket LINE_ITEM_TYPE into direct / house /
	 * programmatic / other, by revenue and impressions.
	 *
	 * @param string $s Start date.
	 * @param string $e End date.
	 * @return array
	 */
	public static function direct_vs_programmatic( string $s, string $e ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'dimensions' => [ self::DIM_LINE_ITEM_TYPE ],
					'columns'    => [ self::COL_REVENUE, self::COL_IMPRESSIONS ],
					'start_date' => $s,
					'end_date'   => $e,
				]
			)
		);
		if ( isset( $rows['error'] ) || isset( $rows['overlay'] ) ) {
			return $rows;
		}
		$buckets = [
			'direct'       => [
				'revenue'     => 0.0,
				'impressions' => 0,
			],
			'house'        => [
				'revenue'     => 0.0,
				'impressions' => 0,
			],
			'programmatic' => [
				'revenue'     => 0.0,
				'impressions' => 0,
			],
			'other'        => [
				'revenue'     => 0.0,
				'impressions' => 0,
			],
		];
		foreach ( $rows['rows'] as $row ) {
			$type    = strtoupper( (string) self::cell( $row, self::DIM_LINE_ITEM_TYPE ) );
			$bucket  = self::line_item_bucket( $type );
			$buckets[ $bucket ]['revenue']     += Client::normalize_currency_micros( self::cell_number( $row, self::COL_REVENUE ) );
			$buckets[ $bucket ]['impressions'] += (int) self::cell_number( $row, self::COL_IMPRESSIONS );
		}
		$out = [];
		foreach ( $buckets as $label => $vals ) {
			$out[] = [
				'label'       => $label,
				'revenue'     => $vals['revenue'],
				'impressions' => $vals['impressions'],
			];
		}
		// Computable when there's anything to show — revenue OR impressions.
		// House/unsold inventory has real impressions at $0 revenue and should
		// still render rather than being treated as "no data".
		$total_revenue     = array_sum( array_column( $out, 'revenue' ) );
		$total_impressions = array_sum( array_column( $out, 'impressions' ) );
		return [
			'rows'       => $out,
			'computable' => $total_revenue > 0 || $total_impressions > 0,
			'type'       => 'breakdown',
		];
	}

	/**
	 * Top Ad Units by revenue.
	 *
	 * @param string $s     Start date.
	 * @param string $e     End date.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function top_ad_units( string $s, string $e, int $limit = 25 ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'dimensions' => [ self::DIM_AD_UNIT_NAME ],
					'columns'    => [ self::COL_IMPRESSIONS, self::COL_REVENUE, self::COL_CODED, self::COL_CLICKS ],
					'start_date' => $s,
					'end_date'   => $e,
				]
			)
		);
		if ( isset( $rows['error'] ) || isset( $rows['overlay'] ) ) {
			return $rows;
		}
		$out = [];
		foreach ( $rows['rows'] as $row ) {
			$impressions = (int) self::cell_number( $row, self::COL_IMPRESSIONS );
			$revenue     = Client::normalize_currency_micros( self::cell_number( $row, self::COL_REVENUE ) );
			$coded       = (int) self::cell_number( $row, self::COL_CODED );
			$clicks      = (int) self::cell_number( $row, self::COL_CLICKS );
			$out[]       = [
				'ad_unit'     => (string) self::cell( $row, self::DIM_AD_UNIT_NAME ),
				'impressions' => $impressions,
				'revenue'     => $revenue,
				'ecpm'        => $coded > 0 ? ( $revenue / $coded ) * 1000 : 0.0,
				'ctr'         => $coded > 0 ? $clicks / $coded : 0.0,
			];
		}
		return self::rank_table( $out, 'revenue', $limit );
	}

	/**
	 * Top Advertisers by revenue — direct-sold line item types only.
	 *
	 * @param string $s     Start date.
	 * @param string $e     End date.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function top_advertisers( string $s, string $e, int $limit = 25 ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'dimensions' => [ self::DIM_ADVERTISER_NAME ],
					'columns'    => [ self::COL_IMPRESSIONS, self::COL_REVENUE ],
					'pql_filter' => self::direct_sold_pql_filter(),
					'start_date' => $s,
					'end_date'   => $e,
				]
			)
		);
		if ( isset( $rows['error'] ) || isset( $rows['overlay'] ) ) {
			return $rows;
		}
		$out = [];
		foreach ( $rows['rows'] as $row ) {
			$out[] = [
				'advertiser'  => (string) self::cell( $row, self::DIM_ADVERTISER_NAME ),
				'impressions' => (int) self::cell_number( $row, self::COL_IMPRESSIONS ),
				'revenue'     => Client::normalize_currency_micros( self::cell_number( $row, self::COL_REVENUE ) ),
			];
		}
		return self::rank_table( $out, 'revenue', $limit );
	}

	/*
	 * GAM report execution + parsing helpers
	 */

	/**
	 * Run a GAM report end-to-end: submit -> poll (backoff) -> download -> parse.
	 * Returns `{ rows: array }` on success, or a `{ error }` / `{ overlay }`
	 * payload-shaped failure. Audit-logs the submission with metric context.
	 *
	 * @param Report_Query $query The report query.
	 * @return array
	 */
	protected static function run_gam_report( Report_Query $query ): array {
		$network_code = self::resolve_network_code();
		if ( '' === $network_code ) {
			return self::error_payload( __( 'No Google Ad Manager network is configured.', 'newspack-plugin' ) );
		}

		try {
			$job_id = Client::run_report_job( $network_code, $query );
			self::audit( $network_code, $query, $job_id, true );

			$status = self::poll_until_terminal( $network_code, $job_id );
			if ( Report_Job_Status::COMPLETED !== $status ) {
				return self::error_payload(
					sprintf(
						/* translators: %s: report job status. */
						__( 'GAM report did not complete (status: %s).', 'newspack-plugin' ),
						$status
					)
				);
			}

			$url  = Client::get_report_download_url( $network_code, $job_id );
			$rows = Client::fetch_and_parse_csv( $url );
			return [ 'rows' => $rows ];
		} catch ( \Exception $e ) {
			self::audit( $network_code, $query, '', false );
			Logger::error( $e->getMessage(), self::LOGGER_HEADER );
			return self::error_payload( $e->getMessage() );
		}
	}

	/**
	 * Maximum consecutive status-check errors tolerated before giving up on a
	 * job (a single transient SOAP/OAuth hiccup must not kill a healthy job).
	 */
	const POLL_MAX_CONSECUTIVE_ERRORS = 3;

	/**
	 * Poll a report job until it reaches a terminal status or the time ceiling,
	 * with capped exponential backoff. Tolerates a few consecutive transient
	 * status-check errors before re-throwing.
	 *
	 * @param string $network_code Network code.
	 * @param string $job_id       Job ID.
	 * @return string A {@see Report_Job_Status} value (UNKNOWN on timeout).
	 *
	 * @throws \Exception If status checks fail repeatedly in a row.
	 */
	private static function poll_until_terminal( string $network_code, string $job_id ): string {
		$elapsed             = 0;
		$attempt             = 0;
		$consecutive_errors  = 0;
		while ( $elapsed < self::POLL_MAX_SECONDS ) {
			try {
				$status             = Client::get_report_job_status( $network_code, $job_id );
				$consecutive_errors = 0;
				if ( Report_Job_Status::is_terminal( $status ) ) {
					return $status;
				}
			} catch ( \Exception $e ) {
				// A transient blip shouldn't abort a healthy job; retry a few
				// times before surfacing the error to the caller.
				if ( ++$consecutive_errors >= self::POLL_MAX_CONSECUTIVE_ERRORS ) {
					throw $e;
				}
			}
			$wait     = self::POLL_BACKOFF_SECONDS[ min( $attempt, count( self::POLL_BACKOFF_SECONDS ) - 1 ) ];
			$wait     = (int) min( $wait, self::POLL_MAX_SECONDS - $elapsed );
			self::sleep( $wait );
			$elapsed += $wait;
			++$attempt;
		}
		return Report_Job_Status::UNKNOWN;
	}

	/**
	 * Sleep wrapper (overridable in tests to avoid real waits).
	 *
	 * @param int $seconds Seconds to sleep.
	 * @return void
	 */
	protected static function sleep( int $seconds ): void {
		if ( $seconds > 0 ) {
			sleep( $seconds );
		}
	}

	/**
	 * Bucket a LINE_ITEM_TYPE value into direct / house / programmatic / other.
	 *
	 * @param string $type Upper-cased line item type.
	 * @return string
	 */
	private static function line_item_bucket( string $type ): string {
		if ( in_array( $type, self::DIRECT_LINE_ITEM_TYPES, true ) ) {
			return 'direct';
		}
		if ( 'HOUSE' === $type ) {
			return 'house';
		}
		if ( in_array( $type, self::PROGRAMMATIC_LINE_ITEM_TYPES, true ) ) {
			return 'programmatic';
		}
		return 'other';
	}

	/**
	 * PQL filter clause restricting to direct-sold line item types.
	 *
	 * @return string
	 */
	private static function direct_sold_pql_filter(): string {
		$types = array_map(
			function ( $type ) {
				return "'" . $type . "'";
			},
			self::DIRECT_LINE_ITEM_TYPES
		);
		return self::DIM_LINE_ITEM_TYPE . ' IN (' . implode( ',', $types ) . ')';
	}

	/**
	 * Sum a numeric column across parsed CSV rows.
	 *
	 * @param array  $rows   Parsed rows.
	 * @param string $column Column key.
	 * @return float
	 */
	private static function sum_column( array $rows, string $column ): float {
		$sum = 0.0;
		foreach ( $rows as $row ) {
			$sum += self::cell_number( $row, $column );
		}
		return $sum;
	}

	/**
	 * Read a raw cell from a parsed CSV row. GAM CSV headers may be qualified
	 * (e.g. `Column.TOTAL_IMPRESSIONS`); match the bare enum name as a suffix.
	 *
	 * @param array  $row Parsed row (assoc).
	 * @param string $key Column/dimension enum name.
	 * @return string
	 */
	private static function cell( array $row, string $key ): string {
		if ( array_key_exists( $key, $row ) ) {
			return (string) $row[ $key ];
		}
		foreach ( $row as $header => $value ) {
			// Match a dotted-qualified header ending in the enum name.
			if ( $header === $key || ( is_string( $header ) && str_ends_with( $header, '.' . $key ) ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * Read a numeric cell from a parsed CSV row.
	 *
	 * @param array  $row Parsed row.
	 * @param string $key Column enum name.
	 * @return float
	 */
	private static function cell_number( array $row, string $key ): float {
		$raw = self::cell( $row, $key );
		return is_numeric( $raw ) ? (float) $raw : 0.0;
	}

	/*
	 * Payload helpers
	 */

	/**
	 * Scalar count payload.
	 *
	 * @param float $value Value.
	 * @return array
	 */
	private static function scalar_count( float $value ): array {
		return [
			'value'      => (int) $value,
			'computable' => true,
			'type'       => 'count',
		];
	}

	/**
	 * Rank a table by a numeric column (desc) and cap to a limit.
	 *
	 * @param array  $rows  Rows.
	 * @param string $by    Column to sort by.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	private static function rank_table( array $rows, string $by, int $limit ): array {
		usort(
			$rows,
			function ( $a, $b ) use ( $by ) {
				return ( $b[ $by ] ?? 0 ) <=> ( $a[ $by ] ?? 0 );
			}
		);
		if ( $limit > 0 ) {
			$rows = array_slice( $rows, 0, $limit );
		}
		return [
			'rows'       => $rows,
			'computable' => ! empty( $rows ),
			'type'       => 'table',
		];
	}

	/**
	 * Standard error payload.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	private static function error_payload( string $message ): array {
		return [
			'value'      => null,
			'computable' => false,
			'error'      => $message,
		];
	}

	/**
	 * Whether any metric in a computed window is a failure (error) payload.
	 *
	 * @param array $metrics Keyed metric payloads.
	 * @return bool
	 */
	private static function any_failed( array $metrics ): bool {
		foreach ( $metrics as $payload ) {
			if ( isset( $payload['error'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Empty/loading window scaffold (no metric data yet).
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function empty_window( string $start_date, string $end_date ): array {
		return array_merge(
			[
				'window'  => [
					'start' => $start_date,
					'end'   => $end_date,
				],
				'metrics' => [],
			],
			self::data_lag_info( $end_date )
		);
	}

	/**
	 * GAM data-lag indicators for a window end date. GAM figures for the most
	 * recent {@see self::ESTIMATED_LAG_DAYS} days are estimated until AdX clears.
	 *
	 * @param string $end_date YYYY-MM-DD.
	 * @return array{data_as_of:string,has_estimated_data:bool,estimated_window_start_date:?string}
	 */
	private static function data_lag_info( string $end_date ): array {
		$today      = new \DateTimeImmutable( 'today', wp_timezone() );
		$data_as_of = $today->modify( '-1 day' );
		$estimated_boundary = $today->modify( '-' . self::ESTIMATED_LAG_DAYS . ' days' );

		try {
			$end = new \DateTimeImmutable( $end_date, wp_timezone() );
		} catch ( \Exception $e ) {
			$end = $today;
		}

		$has_estimated = $end >= $estimated_boundary;
		return [
			'data_as_of'                  => $data_as_of->format( 'Y-m-d' ),
			'has_estimated_data'          => $has_estimated,
			'estimated_window_start_date' => $has_estimated ? $estimated_boundary->format( 'Y-m-d' ) : null,
		];
	}

	/**
	 * Immediately-preceding window of equal length.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return string[] [ prior_start, prior_end ].
	 */
	private static function prior_period( string $start_date, string $end_date ): array {
		$start       = new \DateTimeImmutable( $start_date );
		$end         = new \DateTimeImmutable( $end_date );
		$days        = (int) $start->diff( $end )->format( '%a' ) + 1;
		$prior_end   = $start->modify( '-1 day' );
		$prior_start = $prior_end->modify( '-' . ( $days - 1 ) . ' days' );
		return [ $prior_start->format( 'Y-m-d' ), $prior_end->format( 'Y-m-d' ) ];
	}

	/*
	 * Audit log (a parallel metric-context log, separate from the GAM client's own per-submission log)
	 */

	/**
	 * Append a metric-context audit entry for a report job submission.
	 *
	 * @param string       $network_code Network code.
	 * @param Report_Query $query        The query.
	 * @param string       $job_id       Returned job ID (empty on failure).
	 * @param bool         $success      Whether submission succeeded.
	 * @return void
	 */
	private static function audit( string $network_code, Report_Query $query, string $job_id, bool $success ): void {
		$entry = [
			'time'         => gmdate( 'c' ),
			'tab'          => 'advertising',
			'network_code' => $network_code,
			'dimensions'   => $query->dimensions,
			'columns'      => $query->columns,
			'date_range'   => $query->start_date . '..' . $query->end_date,
			'query_hash'   => $query->hash(),
			'user_id'      => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'success'      => $success,
			'job_id'       => $job_id,
		];
		if ( class_exists( '\Newspack\Logger' ) ) {
			Logger::log( $entry, self::LOGGER_HEADER, $success ? 'info' : 'error' );
		}
		$log = get_option( self::AUDIT_LOG_OPTION, [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		$log[] = $entry;
		if ( count( $log ) > self::AUDIT_LOG_MAX ) {
			$log = array_slice( $log, - self::AUDIT_LOG_MAX );
		}
		update_option( self::AUDIT_LOG_OPTION, $log, false );
	}
}
