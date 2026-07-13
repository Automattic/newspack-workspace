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
use Newspack\Insights\Broadstreet\Client as Broadstreet_Client;
use Newspack\Insights\Derived\Cross_System_Metrics;
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

	const CACHE_KEY_PREFIX = 'newspack_insights_advertising_v2:';
	const CACHE_FRESH_TTL  = 15 * MINUTE_IN_SECONDS;
	const CACHE_HARD_TTL   = DAY_IN_SECONDS;
	const CACHE_RETRY_TTL  = 5 * MINUTE_IN_SECONDS;

	/**
	 * Broadstreet window cache. The Broadstreet rollup is synchronous and cheap, so
	 * unlike GAM there's no stale-while-revalidate / Action Scheduler machinery — the
	 * envelope is simply computed on a miss and cached for a short window.
	 */
	const BROADSTREET_CACHE_KEY_PREFIX = 'newspack_insights_advertising_broadstreet_v1:';
	const BROADSTREET_CACHE_TTL        = 15 * MINUTE_IN_SECONDS;

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

	const DIM_LINE_ITEM_TYPE   = 'LINE_ITEM_TYPE';
	const DIM_AD_UNIT_NAME     = 'AD_UNIT_NAME';
	const DIM_ADVERTISER_NAME  = 'ADVERTISER_NAME';
	const DIM_CUSTOM_DIMENSION = 'CUSTOM_DIMENSION';
	const DIM_DATE             = 'DATE';
	const DIM_DEVICE_CATEGORY  = 'DEVICE_CATEGORY_NAME';
	const DIM_ORDER_NAME       = 'ORDER_NAME';

	/**
	 * The reportable custom-dimension key newspack-network creates for each site
	 * in a Newspack Network (NPPD-1671). Its values are sanitized site URLs.
	 */
	const SITE_DIMENSION_KEY = 'site';

	/**
	 * Cache of the resolved `site` custom-dimension key ID (per network). Stable —
	 * the dimension rarely changes — so a 1-day TTL keeps the CustomTargetingService
	 * lookup off the per-window path. An empty-string entry caches "not found".
	 */
	const SITE_KEY_CACHE_PREFIX = 'newspack_insights_advertising_site_key:';
	const SITE_KEY_CACHE_TTL    = DAY_IN_SECONDS;

	const DIRECT_LINE_ITEM_TYPES       = [ 'SPONSORSHIP', 'STANDARD', 'BULK', 'PRICE_PRIORITY' ];
	const PROGRAMMATIC_LINE_ITEM_TYPES = [ 'NETWORK', 'AD_EXCHANGE' ];

	/**
	 * By-channel bucket map: raw GAM LINE_ITEM_TYPE → channel bucket key
	 * (the single source of truth for the `by_channel` grouping behind the
	 * impressions-weighted "Impressions by type" pie; distinct from the legacy
	 * `direct_vs_programmatic` payload above). Types not in the map fall back per
	 * {@see self::channel_bucket()}: anything containing "EXCHANGE" is
	 * programmatic; everything else is "other".
	 *
	 * NETWORK / BULK / PRICE_PRIORITY back non-guaranteed demand that most
	 * publishers monetize programmatically, so they're bucketed as programmatic —
	 * a product-tunable default, not a GAM-defined grouping.
	 */
	const CHANNEL_BUCKETS = [
		'SPONSORSHIP'    => 'direct',
		'STANDARD'       => 'direct',
		'AD_EXCHANGE'    => 'programmatic',
		'ADSENSE'        => 'programmatic',
		'PREFERRED_DEAL' => 'programmatic',
		'NETWORK'        => 'programmatic',
		'BULK'           => 'programmatic',
		'PRICE_PRIORITY' => 'programmatic',
		'HOUSE'          => 'house',
	];

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
	 * The active ad-server provider backing Tab 8, or null when neither is
	 * configured. GAM wins when both are active (a placement-count tiebreaker is a
	 * later concern). Detection is local/cheap — no remote calls — so it's safe to
	 * call repeatedly per request.
	 *
	 * @return string|null 'gam' | 'broadstreet' | null.
	 */
	public static function active_provider(): ?string {
		if ( Client::is_gam_active() ) {
			return 'gam';
		}
		if ( static::is_broadstreet_active() ) {
			return 'broadstreet';
		}
		return null;
	}

	/**
	 * Whether Broadstreet is the site's active ad server. A `protected` seam over
	 * {@see \Newspack\Insights\Broadstreet\Client::is_active()} (plugin present +
	 * API key + network id) so tests can force it without stubbing the optional
	 * Broadstreet plugin. Called via `static::` from {@see self::active_provider()}
	 * so a test subclass's override takes effect.
	 *
	 * @return bool
	 */
	protected static function is_broadstreet_active(): bool {
		return Broadstreet_Client::is_active();
	}

	/**
	 * Whether Tab 8 should be visible: a supported ad server (Google Ad Manager or
	 * Broadstreet) is active on the site.
	 *
	 * @return bool
	 */
	public static function is_tab_visible(): bool {
		return null !== static::active_provider();
	}

	/**
	 * Whether this site belongs to a Newspack Network (NPPD-1671). Gates the
	 * per-site GAM breakdown: newspack-network creates a reportable `site` custom
	 * dimension in the network's shared GAM, so any network site with GAM
	 * connected (in practice the nodes, whose credentials carry network-level
	 * reporting) can query impressions/revenue by site. Guarded so Insights never
	 * hard-depends on newspack-network being active.
	 *
	 * @return bool
	 */
	public static function is_network_member(): bool {
		return class_exists( '\Newspack_Network\Site_Role' ) && \Newspack_Network\Site_Role::has_role();
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
		$provider = static::active_provider();

		// Broadstreet reporting is a synchronous REST rollup (no OAuth scope, no
		// network-code readiness dance, no async job): if Broadstreet is the active
		// provider it's immediately ready to report.
		if ( 'broadstreet' === $provider ) {
			return true;
		}

		// Any non-GAM provider (including none) can't run a GAM report.
		if ( 'gam' !== $provider ) {
			return false;
		}

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
		$provider = self::active_provider();
		$envelope = [
			'window'            => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			'active_provider'   => $provider,
			'is_tab_visible'    => self::is_tab_visible(),
			'is_report_ready'   => self::is_report_ready(),
			'is_network_member' => self::is_network_member(),
			'readiness_issues'  => self::readiness_issues(),
		];

		// Not connected enough to report: return the envelope so the UI can show
		// the tab (if visible) with a "finish connecting" diagnostic. Don't
		// schedule a refresh that would just be skipped.
		if ( ! $envelope['is_tab_visible'] || ! $envelope['is_report_ready'] ) {
			return array_merge( $envelope, self::empty_window( $start_date, $end_date ), [ 'is_report_ready' => $envelope['is_report_ready'] ] );
		}

		// Broadstreet: a synchronous, impressions-only rollup (no revenue in the v1
		// API, no async job). Computed inline and transient-cached — never through the
		// GAM stale-while-revalidate / Action Scheduler path.
		if ( 'broadstreet' === $provider ) {
			$envelope = array_merge( $envelope, self::read_broadstreet_window( $start_date, $end_date ) );
			if ( $compare ) {
				[ $prior_start, $prior_end ] = self::prior_period( $start_date, $end_date );
				$envelope['compare']         = self::read_broadstreet_window( $prior_start, $prior_end );
			}
			return $envelope;
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
	 * @param string $provider   Ad-server variant: gam|broadstreet (NPPD-2045). Broadstreet
	 *                           renders the impressions-only envelope (no revenue/RPM).
	 * @return array
	 */
	public static function get_fixture( string $start_date, string $end_date, bool $compare = false, string $variant = 'populated', string $provider = 'gam' ): array {
		$fixture = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/advertising-fixture.php';
		return $fixture( $start_date, $end_date, $compare, $variant, $provider );
	}

	/**
	 * Derived empty-state signal (NPPD-1697): whether a resolved window saw any
	 * ad activity. A pure function of the two volume metrics the report already
	 * produces — no SOAP query — mirroring the Donors / Subscribers empty-state
	 * derivation (their respective `window_activity_signal()` helpers). GAM has no
	 * refunds (no negative revenue) and no transaction count, so impressions and
	 * revenue are the only signals.
	 *
	 * Set on the window only once the report has resolved AND both metrics are
	 * computable (see {@see self::read_window()}); during loading, or when either
	 * metric errored, it is left absent so the React layer's strict `=== false`
	 * check can't fire mid-load or mask a per-card error.
	 *
	 * @param int   $impressions Total impressions in the window.
	 * @param float $revenue     Total revenue in the window.
	 * @return bool
	 */
	public static function window_activity_signal( int $impressions, float $revenue ): bool {
		return $impressions > 0 || $revenue > 0.0;
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

		// Derived empty-state signal (NPPD-1697). Set only on a resolved window
		// (this branch — the loading branch above returns first) and only when both
		// volume metrics are computable. Left ABSENT when either errored, so the
		// errored card surfaces its own error treatment rather than the section
		// collapsing to a no_opportunity empty state (mirrors Gates' dataKnown gate).
		$metrics = $window['metrics'] ?? [];
		$imp     = $metrics['total_impressions'] ?? [];
		$rev     = $metrics['total_revenue'] ?? [];
		if ( ! empty( $imp['computable'] ) && ! empty( $rev['computable'] ) ) {
			$window['has_window_activity'] = self::window_activity_signal( (int) ( $imp['value'] ?? 0 ), (float) ( $rev['value'] ?? 0 ) );
		}

		// Cross-system derived scorecards (NPPD-1675): RPM and avg impressions per
		// session join this window's GAM revenue/impressions (already resolved, from
		// the cache above) with GA4 sessions fetched fresh from the Audience
		// orchestrator. Computed HERE at the read layer — not baked into the day-long
		// GAM cache in compute_window() — so the sessions denominator tracks Audience's
		// 15-minute cache rather than lagging up to a day. Skipped on a loading/empty
		// window (that branch returned above); each derived metric degrades to a
		// data-unavailable overlay on its own when sessions or its source is missing.
		if ( ! empty( $metrics ) ) {
			$sessions = Cross_System_Metrics::sessions_for_window( $start_date, $end_date );

			$window['metrics']['rpm'] = Cross_System_Metrics::rpm(
				$metrics['total_revenue'] ?? [],
				$sessions
			);
			$window['metrics']['avg_impressions_per_session'] = Cross_System_Metrics::avg_impressions_per_session(
				$metrics['total_impressions'] ?? [],
				$sessions
			);
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
		$metrics = [
			'total_impressions'      => self::total_impressions( $start_date, $end_date ),
			'total_revenue'          => self::total_revenue( $start_date, $end_date ),
			'revenue_by_day'         => self::revenue_by_day( $start_date, $end_date ),
			'avg_ecpm'               => self::avg_ecpm( $start_date, $end_date ),
			'fill_rate'              => self::fill_rate( $start_date, $end_date ),
			'viewability_rate'       => self::viewability_rate( $start_date, $end_date ),
			'direct_vs_programmatic' => self::direct_vs_programmatic( $start_date, $end_date ),
			'by_channel'             => self::by_channel( $start_date, $end_date ),
			'by_device'              => self::by_device( $start_date, $end_date ),
			'top_ad_units'           => self::top_ad_units( $start_date, $end_date ),
			'top_advertisers'        => self::top_advertisers( $start_date, $end_date ),
			'top_campaigns'          => self::top_campaigns( $start_date, $end_date ),
		];

		// Per-site breakdown (NPPD-1671): only for network members. The runner skips
		// this GAM report entirely for non-members — even when reporting is otherwise
		// ready — so a standalone publisher never pays for a `site` report it can't run.
		if ( self::is_network_member() ) {
			$metrics['top_sites'] = self::top_sites( $start_date, $end_date );
		}

		return $metrics;
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
	 * Revenue by day (NPPD-1674) — the window's revenue broken down by the GAM
	 * `DATE` dimension for the trend line chart. Returns a timeseries payload of
	 * `{ date: 'YYYY-MM-DD', value: <dollars> }` rows sorted chronologically
	 * (GAM's row order isn't guaranteed), micros normalized at the boundary.
	 *
	 * @param string $s Start date.
	 * @param string $e End date.
	 * @return array
	 */
	public static function revenue_by_day( string $s, string $e ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'dimensions' => [ self::DIM_DATE ],
					'columns'    => [ self::COL_REVENUE ],
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
			// TODO(NPPD-1666): confirm the DATE dimension's CSV header + value format
			// (assumed 'YYYY-MM-DD') against a live network.
			$date = (string) self::cell( $row, self::DIM_DATE );
			if ( '' === $date ) {
				continue;
			}
			$out[] = [
				'date'  => $date,
				'value' => Client::normalize_currency_micros( self::cell_number( $row, self::COL_REVENUE ) ),
			];
		}
		// GAM doesn't guarantee chronological order; sort so the line reads left→right.
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		return [
			'rows'       => $out,
			'computable' => ! empty( $out ),
			'type'       => 'timeseries',
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
	 * Top Ad Units by revenue. Rows carry clicks and CTR (clicks / impressions,
	 * null — never 0% — when the unit served no impressions).
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
				'clicks'      => $clicks,
				'revenue'     => $revenue,
				'ecpm'        => $coded > 0 ? ( $revenue / $coded ) * 1000 : 0.0,
				'ctr'         => self::ctr( $clicks, $impressions ),
			];
		}
		return self::rank_table( $out, 'revenue', $limit );
	}

	/**
	 * Top Advertisers by revenue — direct-sold line item types only. Rows carry
	 * clicks and CTR (clicks / impressions, null when impressions are zero).
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
					'columns'    => [ self::COL_IMPRESSIONS, self::COL_REVENUE, self::COL_CLICKS ],
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
			$impressions = (int) self::cell_number( $row, self::COL_IMPRESSIONS );
			$clicks      = (int) self::cell_number( $row, self::COL_CLICKS );
			$out[]       = [
				'advertiser'  => (string) self::cell( $row, self::DIM_ADVERTISER_NAME ),
				'impressions' => $impressions,
				'clicks'      => $clicks,
				'ctr'         => self::ctr( $clicks, $impressions ),
				'revenue'     => Client::normalize_currency_micros( self::cell_number( $row, self::COL_REVENUE ) ),
			];
		}
		return self::rank_table( $out, 'revenue', $limit );
	}

	/**
	 * By channel — the window's impressions and revenue grouped from raw
	 * LINE_ITEM_TYPE values into the {@see self::CHANNEL_BUCKETS} buckets
	 * (Direct-sold / Programmatic / House / Other), for the channel pie. The pie
	 * is impressions-weighted — house line items are unpaid, so a revenue
	 * weighting would render House invisible; impressions show how inventory is
	 * allocated, including the house/unsold share. Rows keep both revenue and
	 * impressions; each row's `share` is its fraction of total impressions
	 * (0–1). Fully-empty buckets are dropped so the legend only lists channels
	 * with activity. Micros normalized at the boundary; rows sorted by
	 * impressions desc.
	 *
	 * @param string $s Start date.
	 * @param string $e End date.
	 * @return array
	 */
	public static function by_channel( string $s, string $e ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'dimensions' => [ self::DIM_LINE_ITEM_TYPE ],
					'columns'    => [ self::COL_IMPRESSIONS, self::COL_REVENUE ],
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
			'programmatic' => [
				'revenue'     => 0.0,
				'impressions' => 0,
			],
			'house'        => [
				'revenue'     => 0.0,
				'impressions' => 0,
			],
			'other'        => [
				'revenue'     => 0.0,
				'impressions' => 0,
			],
		];
		foreach ( $rows['rows'] as $row ) {
			$type   = strtoupper( (string) self::cell( $row, self::DIM_LINE_ITEM_TYPE ) );
			$bucket = self::channel_bucket( $type );
			$buckets[ $bucket ]['revenue']     += Client::normalize_currency_micros( self::cell_number( $row, self::COL_REVENUE ) );
			$buckets[ $bucket ]['impressions'] += (int) self::cell_number( $row, self::COL_IMPRESSIONS );
		}
		$total_revenue     = array_sum( array_column( $buckets, 'revenue' ) );
		$total_impressions = array_sum( array_column( $buckets, 'impressions' ) );
		$out               = [];
		foreach ( $buckets as $bucket => $vals ) {
			// Drop buckets with no activity at all — an empty legend row is noise.
			if ( $vals['revenue'] <= 0 && $vals['impressions'] <= 0 ) {
				continue;
			}
			$out[] = [
				'channel'     => self::channel_label( $bucket ),
				'revenue'     => $vals['revenue'],
				'impressions' => $vals['impressions'],
				// (float) — an evenly-divisible int/int division returns int in PHP.
				'share'       => $total_impressions > 0 ? (float) ( $vals['impressions'] / $total_impressions ) : 0.0,
			];
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);
		// Computable when there's anything to show — revenue OR impressions —
		// matching direct_vs_programmatic (house/unsold inventory is real at $0).
		return [
			'rows'       => $out,
			'computable' => $total_revenue > 0 || $total_impressions > 0,
			'type'       => 'breakdown',
		];
	}

	/**
	 * Performance by device — impressions + revenue broken down by the GAM
	 * DEVICE_CATEGORY_NAME dimension (Desktop / Smartphone / Tablet / Connected
	 * TV), with per-row eCPM (null when the device served no impressions).
	 * Rows sorted by impressions desc.
	 *
	 * @param string $s     Start date.
	 * @param string $e     End date.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function by_device( string $s, string $e, int $limit = 10 ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'dimensions' => [ self::DIM_DEVICE_CATEGORY ],
					'columns'    => [ self::COL_IMPRESSIONS, self::COL_REVENUE ],
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
			$device = (string) self::cell( $row, self::DIM_DEVICE_CATEGORY );
			if ( '' === $device ) {
				continue;
			}
			$impressions = (int) self::cell_number( $row, self::COL_IMPRESSIONS );
			$revenue     = Client::normalize_currency_micros( self::cell_number( $row, self::COL_REVENUE ) );
			$out[]       = [
				'device'      => $device,
				'impressions' => $impressions,
				'revenue'     => $revenue,
				'ecpm'        => $impressions > 0 ? ( $revenue / $impressions ) * 1000 : null,
			];
		}
		return self::rank_table( $out, 'impressions', $limit );
	}

	/**
	 * Top campaigns (orders) by revenue — impressions, clicks, CTR, and revenue
	 * broken down by ORDER_NAME with ADVERTISER_NAME as a secondary dimension.
	 * This reports DIRECT-SOLD orders: programmatic delivery has no order, so
	 * GAM emits it (if at all) with an empty or "-" order name — those rows are
	 * filtered out. CTR is clicks / impressions (null when impressions are zero).
	 *
	 * @param string $s     Start date.
	 * @param string $e     End date.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function top_campaigns( string $s, string $e, int $limit = 10 ): array {
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'dimensions' => [ self::DIM_ORDER_NAME, self::DIM_ADVERTISER_NAME ],
					'columns'    => [ self::COL_IMPRESSIONS, self::COL_CLICKS, self::COL_REVENUE ],
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
			$campaign = trim( (string) self::cell( $row, self::DIM_ORDER_NAME ) );
			// Order-less (programmatic) delivery: skip empty / "-" order names.
			if ( '' === $campaign || '-' === $campaign ) {
				continue;
			}
			$impressions = (int) self::cell_number( $row, self::COL_IMPRESSIONS );
			$clicks      = (int) self::cell_number( $row, self::COL_CLICKS );
			$out[]       = [
				'campaign'    => $campaign,
				'advertiser'  => (string) self::cell( $row, self::DIM_ADVERTISER_NAME ),
				'impressions' => $impressions,
				'clicks'      => $clicks,
				'ctr'         => self::ctr( $clicks, $impressions ),
				'revenue'     => Client::normalize_currency_micros( self::cell_number( $row, self::COL_REVENUE ) ),
			];
		}
		return self::rank_table( $out, 'revenue', $limit );
	}

	/**
	 * Performance by site (NPPD-1671) — impressions + revenue broken down by the
	 * network `site` custom dimension. Resolves the reportable key ID first (cached),
	 * then runs a report dimensioned by it. Returns an empty (non-computable) table
	 * when the site has no `site` dimension (e.g. a network where it wasn't created);
	 * the UI renders the section with its empty-state message rather than erroring.
	 *
	 * @param string $s     Start date.
	 * @param string $e     End date.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function top_sites( string $s, string $e, int $limit = 25 ): array {
		$key_id = static::resolve_site_key_id();
		if ( null === $key_id ) {
			return [
				'rows'       => [],
				'computable' => false,
				'type'       => 'table',
			];
		}
		$rows = static::run_gam_report(
			new Report_Query(
				[
					'dimensions'               => [ self::DIM_CUSTOM_DIMENSION ],
					'custom_dimension_key_ids' => [ $key_id ],
					'columns'                  => [ self::COL_IMPRESSIONS, self::COL_REVENUE ],
					'start_date'               => $s,
					'end_date'                 => $e,
				]
			)
		);
		if ( isset( $rows['error'] ) || isset( $rows['overlay'] ) ) {
			return $rows;
		}
		$out = [];
		foreach ( $rows['rows'] as $row ) {
			// The custom-dimension value column carries the sanitized site URL.
			// TODO(NPPD-1666): confirm the exact CSV header for a custom-dimension
			// report against a live network before GA.
			$site = (string) self::cell( $row, self::DIM_CUSTOM_DIMENSION );
			if ( '' === $site ) {
				continue;
			}
			$impressions = (int) self::cell_number( $row, self::COL_IMPRESSIONS );
			$revenue     = Client::normalize_currency_micros( self::cell_number( $row, self::COL_REVENUE ) );
			$out[]       = [
				'site'        => self::humanize_site( $site ),
				'impressions' => $impressions,
				'revenue'     => $revenue,
				'ecpm'        => $impressions > 0 ? round( ( $revenue / $impressions ) * 1000, 2 ) : 0.0,
			];
		}
		return self::rank_table( $out, 'revenue', $limit );
	}

	/**
	 * Resolve the `site` custom-dimension key ID for the current network, cached
	 * for a day (the dimension rarely changes) so the CustomTargetingService lookup
	 * stays off the per-window path. An empty-string transient caches "not found".
	 * A `protected` seam so tests inject a known ID without touching SOAP.
	 *
	 * @return int|null The key ID, or null when unavailable / not a network.
	 */
	protected static function resolve_site_key_id(): ?int {
		$network_code = self::resolve_network_code();
		if ( '' === $network_code ) {
			return null;
		}
		$cache_key = self::SITE_KEY_CACHE_PREFIX . $network_code;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return '' === $cached ? null : (int) $cached;
		}
		try {
			$key_id = Client::resolve_custom_dimension_key_id( self::SITE_DIMENSION_KEY, (int) $network_code );
		} catch ( \Exception $e ) {
			Logger::error( $e->getMessage(), self::LOGGER_HEADER );
			return null; // Transient outage — don't cache; retry next window.
		}
		set_transient( $cache_key, null === $key_id ? '' : (int) $key_id, self::SITE_KEY_CACHE_TTL );
		return $key_id;
	}

	/**
	 * Humanize a `site` dimension value (a sanitized site URL) to a bare domain
	 * for the table label: "https://www.example.com" → "example.com".
	 *
	 * @param string $value The raw dimension value.
	 * @return string
	 */
	private static function humanize_site( string $value ): string {
		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( ! $host ) {
			$host = preg_replace( '#^https?://#', '', $value );
		}
		return (string) preg_replace( '#^www\.#', '', (string) $host );
	}

	/*
	 * Broadstreet (NPPD-2045) — synchronous, impressions-only window.
	 *
	 * Broadstreet's v1 API has no revenue/RPM/eCPM/cost, so this path emits only
	 * impressions-side metrics: total impressions, overall CTR, mobile share, top
	 * advertisers, top zones, top campaigns, plus the provider-agnostic avg
	 * impressions/session cross-system join (added at the read layer). Every
	 * revenue/inventory metric GAM produces is deliberately absent.
	 */

	/**
	 * Read a Broadstreet window: transient-cached synchronous compute (no Action
	 * Scheduler). The avg-impressions-per-session cross-system scorecard and the
	 * derived empty-state signal are layered on AFTER the cache — matching the GAM
	 * read layer — so sessions track the Audience cache rather than the ad cache.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array Window payload.
	 */
	private static function read_broadstreet_window( string $start_date, string $end_date ): array {
		$cache_key = self::broadstreet_cache_key( $start_date, $end_date );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['metrics'] ) && is_array( $cached['metrics'] ) ) {
			$window = $cached;
		} else {
			$window = self::compute_broadstreet_window( $start_date, $end_date );
			set_transient( $cache_key, $window, self::BROADSTREET_CACHE_TTL );
		}

		// Cross-system derived scorecard (provider-agnostic): impressions per session,
		// joining this window's Broadstreet impressions with fresh GA4 sessions.
		$metrics  = $window['metrics'] ?? [];
		$sessions = Cross_System_Metrics::sessions_for_window( $start_date, $end_date );
		$window['metrics']['avg_impressions_per_session'] = Cross_System_Metrics::avg_impressions_per_session(
			$metrics['total_impressions'] ?? [],
			$sessions
		);

		// Derived empty-state signal (NPPD-1697): Broadstreet has no revenue, so
		// impressions are the only activity signal. Set only when the volume metric is
		// computable, so an errored/absent metric doesn't collapse the section.
		$imp = $metrics['total_impressions'] ?? [];
		if ( ! empty( $imp['computable'] ) ) {
			$window['has_window_activity'] = self::window_activity_signal( (int) ( $imp['value'] ?? 0 ), 0.0 );
		}

		return $window;
	}

	/**
	 * Compute a Broadstreet window's metrics (the report-touching path). Cheap and
	 * synchronous — a single rollup call per metric, no fan-out.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array Window payload (window + metrics + minimal lag info).
	 */
	private static function compute_broadstreet_window( string $start_date, string $end_date ): array {
		// A SINGLE group=network call yields three scorecards (impressions, CTR,
		// mobile share); the advertiser/zone/campaign groups are one call each.
		$network = self::broadstreet_network_metrics( $start_date, $end_date );
		return [
			'window'                      => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			'metrics'                     => [
				'total_impressions' => $network['total_impressions'],
				'overall_ctr'       => $network['overall_ctr'],
				'mobile_share'      => $network['mobile_share'],
				'top_advertisers'   => self::broadstreet_top_advertisers( $start_date, $end_date ),
				'top_zones'         => self::broadstreet_top_zones( $start_date, $end_date ),
				'top_campaigns'     => self::broadstreet_top_campaigns( $start_date, $end_date ),
			],
			// Broadstreet reports settle without GAM's multi-day AdX estimate lag, so
			// there's no estimated-data window; the UI hides the lag indicator anyway.
			'data_as_of'                  => ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d' ),
			'has_estimated_data'          => false,
			'estimated_window_start_date' => null,
		];
	}

	/**
	 * Broadstreet network-rollup scorecards — a SINGLE `group=network` call
	 * selecting `count(view)`, `count(click)`, and `count(mobile_view)`, from which
	 * three scorecards are derived without any extra requests:
	 *   - total_impressions : sum of `count(view)`.
	 *   - overall_ctr       : clicks ÷ impressions (rate).
	 *   - mobile_share      : mobile views ÷ impressions (rate).
	 *
	 * `count(mobile_view)` is Broadstreet's ONLY device signal — a mobile-vs-total
	 * split, not a full device breakdown (desktop/tablet counts don't exist in the
	 * v1 API), so there's deliberately no device table.
	 *
	 * @param string $s Start date.
	 * @param string $e End date.
	 * @return array{total_impressions:array,overall_ctr:array,mobile_share:array}
	 */
	public static function broadstreet_network_metrics( string $s, string $e ): array {
		$rows        = static::run_broadstreet_report( 'network', [ 'count(view)', 'count(click)', 'count(mobile_view)' ], $s, $e );
		$impressions = 0.0;
		$clicks      = 0.0;
		$mobile      = 0.0;
		foreach ( $rows as $row ) {
			$impressions += self::cell_number( $row, 'count(view)' );
			$clicks      += self::cell_number( $row, 'count(click)' );
			$mobile      += self::cell_number( $row, 'count(mobile_view)' );
		}
		return [
			'total_impressions' => self::scalar_count( $impressions ),
			'overall_ctr'       => self::broadstreet_rate( $clicks, $impressions ),
			'mobile_share'      => self::broadstreet_rate( $mobile, $impressions ),
		];
	}

	/**
	 * Shape a Broadstreet rate scorecard: numerator ÷ denominator, carrying the raw
	 * counts. Not computable (→ em-dash, never a misleading 0%) when the denominator
	 * is zero, so an empty/degraded rollup reads as "no data", not a real zero rate.
	 *
	 * @param float $numerator   Rate numerator (clicks, or mobile views).
	 * @param float $denominator Rate denominator (impressions).
	 * @return array
	 */
	private static function broadstreet_rate( float $numerator, float $denominator ): array {
		return [
			'value'       => $denominator > 0 ? $numerator / $denominator : 0.0,
			'computable'  => $denominator > 0,
			'type'        => 'rate',
			'numerator'   => (int) $numerator,
			'denominator' => (int) $denominator,
		];
	}

	/**
	 * Broadstreet top advertisers by impressions — `group=advertiser`. Rows carry
	 * impressions, clicks, and CTR (clicks / impressions, null when impressions are
	 * zero). Top 10 by impressions. No revenue — the Broadstreet API has none.
	 *
	 * @param string $s     Start date.
	 * @param string $e     End date.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function broadstreet_top_advertisers( string $s, string $e, int $limit = 10 ): array {
		$rows = static::run_broadstreet_report( 'advertiser', [ 'advertiser.name', 'count(view)', 'count(click)' ], $s, $e );
		$out  = [];
		foreach ( $rows as $row ) {
			$impressions = (int) self::cell_number( $row, 'count(view)' );
			$clicks      = (int) self::cell_number( $row, 'count(click)' );
			$out[]       = [
				'advertiser'  => (string) self::cell( $row, 'advertiser.name' ),
				'impressions' => $impressions,
				'clicks'      => $clicks,
				'ctr'         => self::ctr( $clicks, $impressions ),
			];
		}
		return self::rank_table( $out, 'impressions', $limit );
	}

	/**
	 * Broadstreet top zones by impressions — `group=zone`. Rows carry impressions,
	 * clicks, and CTR (null when impressions are zero). Top 10 by impressions.
	 *
	 * @param string $s     Start date.
	 * @param string $e     End date.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function broadstreet_top_zones( string $s, string $e, int $limit = 10 ): array {
		$rows = static::run_broadstreet_report( 'zone', [ 'zone.name', 'count(view)', 'count(click)' ], $s, $e );
		$out  = [];
		foreach ( $rows as $row ) {
			$impressions = (int) self::cell_number( $row, 'count(view)' );
			$clicks      = (int) self::cell_number( $row, 'count(click)' );
			$out[]       = [
				'zone'        => (string) self::cell( $row, 'zone.name' ),
				'impressions' => $impressions,
				'clicks'      => $clicks,
				'ctr'         => self::ctr( $clicks, $impressions ),
			];
		}
		return self::rank_table( $out, 'impressions', $limit );
	}

	/**
	 * Broadstreet top campaigns by impressions — `group=campaign`. Rows carry
	 * impressions, clicks, and CTR (clicks / impressions, null when impressions are
	 * zero). Top 10 by impressions. No revenue — the Broadstreet API has none. Unlike
	 * the GAM top-campaigns metric (direct-sold ORDER_NAME rows), Broadstreet's
	 * `campaign` group is a first-class reporting dimension with real named campaigns.
	 *
	 * @param string $s     Start date.
	 * @param string $e     End date.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function broadstreet_top_campaigns( string $s, string $e, int $limit = 10 ): array {
		$rows = static::run_broadstreet_report( 'campaign', [ 'campaign.name', 'count(view)', 'count(click)' ], $s, $e );
		$out  = [];
		foreach ( $rows as $row ) {
			$impressions = (int) self::cell_number( $row, 'count(view)' );
			$clicks      = (int) self::cell_number( $row, 'count(click)' );
			$out[]       = [
				'campaign'    => (string) self::cell( $row, 'campaign.name' ),
				'impressions' => $impressions,
				'clicks'      => $clicks,
				'ctr'         => self::ctr( $clicks, $impressions ),
			];
		}
		return self::rank_table( $out, 'impressions', $limit );
	}

	/**
	 * Run a Broadstreet rollup report. The mockable seam (mirrors GAM's
	 * {@see self::run_gam_report()}): tests subclass and override this to inject
	 * canned `records` rows without touching the network. Returns the raw rows, or
	 * [] on any degrade.
	 *
	 * @param string   $group  Grouping dimension (network|advertiser|zone|campaign).
	 * @param string[] $select Fields to select.
	 * @param string   $s      Start date, YYYY-MM-DD.
	 * @param string   $e      End date, YYYY-MM-DD.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function run_broadstreet_report( string $group, array $select, string $s, string $e ): array {
		return Broadstreet_Client::report( $group, $select, $s, $e );
	}

	/**
	 * Broadstreet window cache key, scoped to the network id so a reconnect to a
	 * different Broadstreet network never serves a stale payload.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return string
	 */
	private static function broadstreet_cache_key( string $start_date, string $end_date ): string {
		return self::BROADSTREET_CACHE_KEY_PREFIX . md5( Broadstreet_Client::get_network_id() . '|' . $start_date . '|' . $end_date );
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
	 * Bucket a LINE_ITEM_TYPE value for the by_channel (Impressions by type) grouping. The
	 * explicit map is {@see self::CHANNEL_BUCKETS}; unmapped EXCHANGE-suffixed
	 * types (e.g. legacy Ad Exchange variants) fall back to programmatic, and
	 * anything else to "other".
	 *
	 * @param string $type Upper-cased line item type.
	 * @return string One of direct|programmatic|house|other.
	 */
	private static function channel_bucket( string $type ): string {
		if ( isset( self::CHANNEL_BUCKETS[ $type ] ) ) {
			return self::CHANNEL_BUCKETS[ $type ];
		}
		if ( false !== strpos( $type, 'EXCHANGE' ) ) {
			return 'programmatic';
		}
		return 'other';
	}

	/**
	 * User-facing label for a by_channel (Impressions by type) bucket key. Resolved at compute
	 * time (the payload is server-rendered) so the pie legend is translatable.
	 *
	 * @param string $bucket Bucket key from {@see self::channel_bucket()}.
	 * @return string
	 */
	private static function channel_label( string $bucket ): string {
		switch ( $bucket ) {
			case 'direct':
				return __( 'Direct-sold', 'newspack-plugin' );
			case 'programmatic':
				return __( 'Programmatic', 'newspack-plugin' );
			case 'house':
				return __( 'House', 'newspack-plugin' );
			default:
				return __( 'Other', 'newspack-plugin' );
		}
	}

	/**
	 * CTR = clicks / impressions. Null — never 0% — when there were no
	 * impressions, so the UI renders an em-dash instead of a misleading zero.
	 *
	 * @param int $clicks      Clicks.
	 * @param int $impressions Impressions.
	 * @return float|null
	 */
	private static function ctr( int $clicks, int $impressions ): ?float {
		return $impressions > 0 ? $clicks / $impressions : null;
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
