<?php
/**
 * Newspack Insights — Newsletter Ads Metric orchestrator (NPPD-1861).
 *
 * Newsletter-ad performance from the newspack-newsletters plugin's data:
 *   - Ads CPT (`newspack_nl_ads_cpt`) with per-ad meta: `start_date`,
 *     `expiry_date` (flight dates), `price` (flat flight price, no currency),
 *     and `tracking_impressions` / `tracking_clicks` (lifetime cumulative
 *     counters).
 *   - The dated stats table `{$wpdb->prefix}newspack_newsletters_ad_stats`
 *     (one row per ad/newsletter/UTC day; `newsletter_id` 0 is the
 *     unknown-source click sentinel), shipped by a newer newspack-newsletters
 *     via {@see \Newspack_Newsletters\Tracking\Ad_Stats}.
 *
 * Every newsletters-plugin touch is defensively guarded (class_exists /
 * post_type_exists / SHOW TABLES): missing pieces degrade to
 * computable=false payloads, never fatals. Lifetime metrics come from the
 * meta counters and work without the stats table; timeframe metrics need
 * the table ({@see self::is_report_ready()}).
 *
 * Unlike GAM (async report jobs), everything here is cheap local SQL, so the
 * tab computes SYNCHRONOUSLY in the request — no Action Scheduler, and
 * `is_loading` is always false. The full window envelope is cached in a
 * transient ({@see self::CACHE_KEY_PREFIX}).
 *
 * Revenue uses a FLAT-OVER-FLIGHT model: an ad's `price` covers its whole
 * flight ([start_date, expiry_date], inclusive), so a window earns
 * price / flight_days × overlapping_days. Ads missing price or either flight
 * date are excluded from revenue (and surfaced via `revenue_excluded_ads`).
 *
 * Payload shapes mirror the Advertising orchestrator:
 *   scalar : { value, computable, type: count|currency }
 *   rate   : { value (0-1), computable, type: rate, numerator, denominator }
 *   rows   : { rows: [...], computable, type: table|timeseries }
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter Ads metric orchestrator.
 *
 * Not `final` (like {@see Advertising_Metric}): the newsletters-plugin
 * seams ({@see self::ad_stats_class()}, {@see self::stats_table_exists()})
 * are `protected` and called via `static::` so unit tests can subclass and
 * simulate the plugin/table being absent.
 */
class Newsletter_Ads_Metric {

	/**
	 * Ads CPT registered by newspack-newsletters
	 * ({@see \Newspack_Newsletters_Ads::CPT}). Mirrored as a local constant so
	 * this class never hard-depends on the newsletters plugin being loaded.
	 */
	const ADS_CPT = 'newspack_nl_ads_cpt';

	/**
	 * Advertiser taxonomy registered by newspack-newsletters
	 * ({@see \Newspack_Newsletters_Ads::ADVERTISER_TAX}).
	 */
	const ADVERTISER_TAX = 'newspack_nl_advertiser';

	/**
	 * The newsletters plugin's dated ad-stats reader. Only present on newer
	 * newspack-newsletters builds; its absence means timeframe metrics can't
	 * be computed ({@see self::is_report_ready()}).
	 */
	const AD_STATS_CLASS = 'Newspack_Newsletters\Tracking\Ad_Stats';

	/**
	 * The newsletters plugin's tracking settings surface, used to detect
	 * whether the open-tracking pixel (the impressions source) is disabled.
	 */
	const TRACKING_ADMIN_CLASS = 'Newspack_Newsletters\Tracking\Admin';

	/**
	 * Unprefixed stats table name, used as a fallback when the Ad_Stats class
	 * doesn't expose {@see \Newspack_Newsletters\Tracking\Ad_Stats::get_table_name()}.
	 */
	const STATS_TABLE_SUFFIX = 'newspack_newsletters_ad_stats';

	/**
	 * `newsletter_id` value the stats table uses for clicks whose source
	 * newsletter is unknown. Excluded from the by-newsletter breakdown.
	 */
	const UNKNOWN_NEWSLETTER_SENTINEL = 0;

	const CACHE_KEY_PREFIX = 'newspack_insights_newsletter_ads_v1:';

	/**
	 * Envelope cache TTL. Mirrors Advertising's CACHE_FRESH_TTL; because this
	 * tab computes synchronously and cheaply there is no stale-while-revalidate
	 * layer — the fresh TTL is simply the transient expiry.
	 */
	const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Cache key for the tab-visibility detection result (mirrors the Donors
	 * `has_donation_activity()` transient pattern).
	 */
	const ADS_PRESENCE_TRANSIENT = 'newspack_insights_has_newsletter_ads';

	/**
	 * Row cap for the breakdown tables (top ads / advertisers / newsletters).
	 */
	const TABLE_ROW_LIMIT = 10;

	/**
	 * Per-request memo of the stats-table existence check (a SHOW TABLES
	 * query), so a single request performs it at most once.
	 *
	 * @var bool|null
	 */
	private static $table_exists_memo = null;

	/*
	 * Visibility / readiness
	 */

	/**
	 * Whether the Newsletter Ads tab should be visible: the ads CPT exists
	 * (newsletters plugin active with ads enabled) AND the site has at least
	 * one published ad. Cached for 24h; state transitions ("publisher created
	 * their first newsletter ad") are rare, and tests / manual invalidation
	 * can call {@see self::force_refresh_tab_visibility()}.
	 *
	 * @return bool
	 */
	public static function is_tab_visible(): bool {
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return true;
		}
		$cached = get_transient( self::ADS_PRESENCE_TRANSIENT );
		if ( 'yes' === $cached ) {
			return true;
		}
		if ( 'no' === $cached ) {
			return false;
		}
		$has_ads = self::compute_has_ads();
		set_transient( self::ADS_PRESENCE_TRANSIENT, $has_ads ? 'yes' : 'no', DAY_IN_SECONDS );
		return $has_ads;
	}

	/**
	 * Force-recompute the ads-presence flag, bypassing and refreshing the
	 * cache. Useful for tests and for the case where a publisher just
	 * published their first ad.
	 *
	 * @return bool The freshly computed flag.
	 */
	public static function force_refresh_tab_visibility(): bool {
		delete_transient( self::ADS_PRESENCE_TRANSIENT );
		$has_ads = self::compute_has_ads();
		set_transient( self::ADS_PRESENCE_TRANSIENT, $has_ads ? 'yes' : 'no', DAY_IN_SECONDS );
		return $has_ads;
	}

	/**
	 * Run the ads-presence check without consulting the cache.
	 *
	 * @return bool
	 */
	private static function compute_has_ads(): bool {
		if ( ! post_type_exists( self::ADS_CPT ) ) {
			return false;
		}
		$ids = get_posts(
			[
				'post_type'   => self::ADS_CPT,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
			]
		);
		return ! empty( $ids );
	}

	/**
	 * Whether timeframe (windowed) reporting is available: the newsletters
	 * plugin ships the Ad_Stats reader AND its dated stats table actually
	 * exists in the database. Lifetime metrics (meta counters) don't need
	 * this and are computed regardless.
	 *
	 * @return bool
	 */
	public static function is_report_ready(): bool {
		return null !== static::ad_stats_class() && static::stats_table_exists();
	}

	/**
	 * Reset the per-request readiness memo. Mainly for tests; harmless in
	 * production (each request starts fresh).
	 *
	 * @return void
	 */
	public static function reset_readiness_cache(): void {
		self::$table_exists_memo = null;
	}

	/**
	 * The newsletters plugin's Ad_Stats class name when it's loaded, else
	 * null. A `protected` seam (called via `static::`) so tests can simulate
	 * the plugin being absent without unloading classes.
	 *
	 * @return string|null
	 */
	protected static function ad_stats_class(): ?string {
		return class_exists( self::AD_STATS_CLASS ) ? self::AD_STATS_CLASS : null;
	}

	/**
	 * Whether the dated stats table exists (SHOW TABLES), memoized per
	 * request. The class shipping without its table (e.g. migration not yet
	 * run) must degrade cleanly rather than erroring on every query.
	 *
	 * @return bool
	 */
	protected static function stats_table_exists(): bool {
		if ( null !== self::$table_exists_memo ) {
			return self::$table_exists_memo;
		}
		global $wpdb;
		$table = self::stats_table_name();
		// Probe with a suppressed no-op SELECT rather than SHOW TABLES:
		// $wpdb->query() returns false only on error (0 rows is fine), and —
		// unlike SHOW TABLES — this also sees TEMPORARY tables, which is what
		// CREATE TABLE becomes under the WP test framework's query rewrite.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
		$wpdb->suppress_errors( $suppress );
		self::$table_exists_memo = ( false !== $result );
		return self::$table_exists_memo;
	}

	/**
	 * Resolve the stats table name: prefer the newsletters plugin's own
	 * accessor so a rename on their side can't strand us, falling back to the
	 * documented `{$wpdb->prefix}newspack_newsletters_ad_stats`.
	 *
	 * @return string
	 */
	public static function stats_table_name(): string {
		global $wpdb;
		$class = static::ad_stats_class();
		if ( null !== $class && is_callable( [ $class, 'get_table_name' ] ) ) {
			$name = call_user_func( [ $class, 'get_table_name' ] );
			if ( is_string( $name ) && '' !== $name ) {
				return $name;
			}
		}
		return $wpdb->prefix . self::STATS_TABLE_SUFFIX;
	}

	/**
	 * Specific reasons reporting is limited, for the UI to render guidance.
	 * Two independent issues:
	 *  - `newsletter_ads_stats_missing` — the dated stats table isn't
	 *    available (old newsletters plugin), so timeframe metrics can't run.
	 *  - `newsletter_ads_tracking_disabled` — informational: the open-tracking
	 *    pixel is off, so impressions aren't being recorded (clicks may still
	 *    accrue). Present regardless of readiness.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function readiness_issues(): array {
		$issues = [];
		if ( ! static::is_report_ready() ) {
			$issues[] = [
				'code'            => 'newsletter_ads_stats_missing',
				'message'         => __( 'Newsletter ad statistics require the latest Newspack Newsletters plugin.', 'newspack-plugin' ),
				'remediation_url' => '',
			];
		}
		if (
			class_exists( self::TRACKING_ADMIN_CLASS )
			&& method_exists( self::TRACKING_ADMIN_CLASS, 'is_tracking_pixel_enabled' )
			&& ! call_user_func( [ self::TRACKING_ADMIN_CLASS, 'is_tracking_pixel_enabled' ] )
		) {
			$issues[] = [
				'code'            => 'newsletter_ads_tracking_disabled',
				'message'         => __( 'Newsletter open tracking (the tracking pixel) is disabled, so ad impressions are not recorded. Enable it in Newsletters settings to measure impressions.', 'newspack-plugin' ),
				'remediation_url' => '',
			];
		}
		return $issues;
	}

	/*
	 * Public tab payload
	 */

	/**
	 * Full Newsletter Ads payload for a window. Computes synchronously (cheap
	 * local SQL — no Action Scheduler; `is_loading` always false) and caches
	 * the full envelope in a transient.
	 *
	 * @param string $start_date YYYY-MM-DD (site timezone).
	 * @param string $end_date   YYYY-MM-DD (site timezone).
	 * @param bool   $compare    Whether to attach the prior-period payload.
	 * @return array
	 */
	public static function get_all( string $start_date, string $end_date, bool $compare = false ): array {
		$envelope = self::read_envelope( $start_date, $end_date );
		if ( $compare ) {
			[ $prior_start, $prior_end ] = self::prior_period( $start_date, $end_date );
			$envelope['compare']         = self::read_envelope( $prior_start, $prior_end );
		}
		return $envelope;
	}

	/**
	 * Realistic fixture payload for UI smoke testing without the newsletters
	 * plugin. Served by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE
	 * is on.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @param bool   $compare    Whether to attach the comparison payload.
	 * @param string $variant    Render-path variant: populated|zero|not_ready|no_impressions.
	 * @return array
	 */
	public static function get_fixture( string $start_date, string $end_date, bool $compare = false, string $variant = 'populated' ): array {
		$fixture = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/newsletter-ads-fixture.php';
		return $fixture( $start_date, $end_date, $compare, $variant );
	}

	/**
	 * Derived empty-state signal: whether a resolved window saw any newsletter
	 * ad activity. A pure function of the two window volume metrics, mirroring
	 * the Advertising / Donors derivations. Set on the envelope only when the
	 * window metrics are computable (report ready), so the React layer's
	 * strict `=== false` check can't fire on a not-ready tab.
	 *
	 * @param int $impressions Total window impressions.
	 * @param int $clicks      Total window clicks.
	 * @return bool
	 */
	public static function window_activity_signal( int $impressions, int $clicks ): bool {
		return $impressions > 0 || $clicks > 0;
	}

	/*
	 * Envelope computation + cache
	 */

	/**
	 * Read a window's envelope through the transient cache.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function read_envelope( string $start_date, string $end_date ): array {
		$cache_disabled = defined( 'NEWSPACK_INSIGHTS_CACHE_DISABLED' ) && NEWSPACK_INSIGHTS_CACHE_DISABLED;
		$cache_key      = self::CACHE_KEY_PREFIX . $start_date . ':' . $end_date;
		if ( ! $cache_disabled ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$envelope = self::compute_envelope( $start_date, $end_date );
		if ( ! $cache_disabled ) {
			set_transient( $cache_key, $envelope, self::CACHE_TTL );
		}
		return $envelope;
	}

	/**
	 * Compute a full window envelope: lifetime metrics (meta counters, always
	 * computed), timeframe metrics (stats table + flight-prorated revenue,
	 * gated on {@see self::is_report_ready()}), and the activity signal.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_envelope( string $start_date, string $end_date ): array {
		$is_ready = static::is_report_ready();
		$ads      = self::get_published_ads();

		$metrics = self::lifetime_metrics( $ads );

		if ( $is_ready ) {
			$metrics = array_merge( $metrics, self::window_metrics( $ads, $start_date, $end_date ) );
		} else {
			$metrics = array_merge( $metrics, self::not_ready_window_metrics() );
		}

		$envelope = [
			'window'           => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			'is_tab_visible'   => static::is_tab_visible(),
			'is_report_ready'  => $is_ready,
			'readiness_issues' => static::readiness_issues(),
			'data_as_of'       => ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ),
			// This tab computes synchronously — never a background-loading state.
			'is_loading'       => false,
			'metrics'          => $metrics,
		];

		// Derived empty-state signal: only when the window volume metrics are
		// computable (i.e. the stats table resolved), mirroring Advertising's
		// read_window() derivation — absent otherwise so the UI's strict
		// `=== false` empty-state check can't fire on a not-ready tab.
		if ( ! empty( $metrics['total_impressions']['computable'] ) && ! empty( $metrics['total_clicks']['computable'] ) ) {
			$envelope['has_window_activity'] = self::window_activity_signal(
				(int) ( $metrics['total_impressions']['value'] ?? 0 ),
				(int) ( $metrics['total_clicks']['value'] ?? 0 )
			);
		}

		return $envelope;
	}

	/*
	 * Lifetime metrics (meta counters — independent of the stats table)
	 */

	/**
	 * Lifetime impressions / clicks / CTR from the per-ad cumulative meta
	 * counters. These work on every newsletters build (no stats table
	 * required). CTR is non-computable at zero impressions — the real case
	 * of click tracking running with the pixel disabled must render n/a,
	 * never 0%.
	 *
	 * @param array $ads Published-ad records ({@see self::get_published_ads()}).
	 * @return array<string,array>
	 */
	private static function lifetime_metrics( array $ads ): array {
		$impressions = 0;
		$clicks      = 0;
		foreach ( $ads as $ad ) {
			$impressions += $ad['lifetime_impressions'];
			$clicks      += $ad['lifetime_clicks'];
		}
		return [
			'lifetime_impressions' => self::count_payload( $impressions ),
			'lifetime_clicks'      => self::count_payload( $clicks ),
			'lifetime_ctr'         => self::rate_payload( $clicks, $impressions ),
		];
	}

	/*
	 * Timeframe metrics (stats table + flat-over-flight revenue)
	 */

	/**
	 * Compute every windowed metric. Only called when reporting is ready.
	 *
	 * @param array  $ads        Published-ad records.
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array<string,array>
	 */
	private static function window_metrics( array $ads, string $start_date, string $end_date ): array {
		$totals  = self::query_window_totals( $start_date, $end_date );
		$flights = self::flight_revenue( $ads, $start_date, $end_date );

		$impressions = $totals['impressions'];
		$clicks      = $totals['clicks'];
		$revenue     = round( $flights['revenue'], 2 );

		$per_ad_rows = self::query_per_ad_totals( $start_date, $end_date );

		return [
			'total_impressions'    => self::count_payload( $impressions ),
			'total_clicks'         => self::count_payload( $clicks ),
			'ctr'                  => self::rate_payload( $clicks, $impressions ),
			'total_revenue'        => [
				'value'      => $revenue,
				'computable' => true,
				'type'       => 'currency',
			],
			'revenue_excluded_ads' => self::count_payload( $flights['excluded'] ),
			'ecpm'                 => [
				'value'       => ( $revenue > 0 && $impressions > 0 ) ? round( ( $revenue / $impressions ) * 1000, 2 ) : 0.0,
				'computable'  => $revenue > 0 && $impressions > 0,
				'type'        => 'currency',
				'numerator'   => $revenue,
				'denominator' => $impressions,
			],
			'active_ads'           => self::count_payload( $flights['active'] ),
			'performance_by_day'   => self::performance_by_day( $start_date, $end_date ),
			'top_ads'              => self::top_ads( $per_ad_rows, $ads, $flights['per_ad'] ),
			'top_advertisers'      => self::top_advertisers( $per_ad_rows, $ads, $flights['per_ad'] ),
			'by_newsletter'        => self::by_newsletter( $start_date, $end_date ),
		];
	}

	/**
	 * The not-ready degradation: every windowed metric present but
	 * computable=false, so the UI renders n/a cards instead of erroring
	 * (lifetime metrics are still real).
	 *
	 * @return array<string,array>
	 */
	private static function not_ready_window_metrics(): array {
		$count = [
			'value'      => 0,
			'computable' => false,
			'type'       => 'count',
		];
		$currency = [
			'value'      => 0.0,
			'computable' => false,
			'type'       => 'currency',
		];
		return [
			'total_impressions'    => $count,
			'total_clicks'         => $count,
			'ctr'                  => [
				'value'       => 0.0,
				'computable'  => false,
				'type'        => 'rate',
				'numerator'   => 0,
				'denominator' => 0,
			],
			'total_revenue'        => $currency,
			'revenue_excluded_ads' => $count,
			'ecpm'                 => [
				'value'       => 0.0,
				'computable'  => false,
				'type'        => 'currency',
				'numerator'   => 0.0,
				'denominator' => 0,
			],
			'active_ads'           => $count,
			'performance_by_day'   => [
				'rows'       => [],
				'computable' => false,
				'type'       => 'timeseries',
			],
			'top_ads'              => [
				'rows'       => [],
				'computable' => false,
				'type'       => 'table',
			],
			'top_advertisers'      => [
				'rows'       => [],
				'computable' => false,
				'type'       => 'table',
			],
			'by_newsletter'        => [
				'rows'       => [],
				'computable' => false,
				'type'       => 'table',
			],
		];
	}

	/**
	 * Window impression/click totals from the stats table. Includes
	 * unknown-source (sentinel) rows — totals reflect all recorded activity.
	 *
	 * @param string $start_date YYYY-MM-DD (UTC day, matching stat_date).
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array{impressions:int,clicks:int}
	 */
	private static function query_window_totals( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = self::stats_table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( impressions ), 0 ) AS impressions, COALESCE( SUM( clicks ), 0 ) AS clicks FROM {$table} WHERE stat_date BETWEEN %s AND %s",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		// phpcs:enable
		return [
			'impressions' => (int) ( $row['impressions'] ?? 0 ),
			'clicks'      => (int) ( $row['clicks'] ?? 0 ),
		];
	}

	/**
	 * Daily impressions/clicks series from the stats table, chronological.
	 * Zero-activity days are omitted (no zero-filling).
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array Timeseries payload.
	 */
	private static function performance_by_day( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = self::stats_table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, SUM( impressions ) AS impressions, SUM( clicks ) AS clicks FROM {$table} WHERE stat_date BETWEEN %s AND %s GROUP BY stat_date ORDER BY stat_date ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[] = [
				'date'        => (string) $row['stat_date'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
			];
		}
		return [
			'rows'       => $out,
			'computable' => ! empty( $out ),
			'type'       => 'timeseries',
		];
	}

	/**
	 * Per-ad window totals from the stats table (all newsletters, sentinel
	 * included — a click is the ad's regardless of source attribution).
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array<int,array{ad_id:int,impressions:int,clicks:int}>
	 */
	private static function query_per_ad_totals( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = self::stats_table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ad_id, SUM( impressions ) AS impressions, SUM( clicks ) AS clicks FROM {$table} WHERE stat_date BETWEEN %s AND %s GROUP BY ad_id",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[] = [
				'ad_id'       => (int) $row['ad_id'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
			];
		}
		return $out;
	}

	/**
	 * Top ads table: per-ad window stats enriched with title, advertiser, CTR
	 * and the window's flat-over-flight revenue share. Top
	 * {@see self::TABLE_ROW_LIMIT} by impressions, falling back to clicks when
	 * no ad recorded an impression (pixel-disabled sites).
	 *
	 * @param array $per_ad_rows Per-ad stat rows ({@see self::query_per_ad_totals()}).
	 * @param array $ads         Published-ad records keyed by ID.
	 * @param array $per_ad_rev  Window revenue share keyed by ad ID.
	 * @return array Table payload.
	 */
	private static function top_ads( array $per_ad_rows, array $ads, array $per_ad_rev ): array {
		$out = [];
		foreach ( $per_ad_rows as $row ) {
			$ad_id = $row['ad_id'];
			if ( isset( $ads[ $ad_id ] ) ) {
				$title      = $ads[ $ad_id ]['title'];
				$advertiser = $ads[ $ad_id ]['advertiser'];
			} else {
				// Stats can outlive the ad (unpublished/deleted).
				$post       = get_post( $ad_id );
				$title      = $post ? get_the_title( $post ) : __( '(deleted)', 'newspack-plugin' );
				$advertiser = $post ? self::first_advertiser_name( $ad_id ) : '';
			}
			$out[] = [
				'ad_id'       => $ad_id,
				'title'       => $title,
				'advertiser'  => $advertiser,
				'impressions' => $row['impressions'],
				'clicks'      => $row['clicks'],
				'ctr'         => $row['impressions'] > 0 ? round( $row['clicks'] / $row['impressions'], 4 ) : null,
				'revenue'     => isset( $per_ad_rev[ $ad_id ] ) ? round( $per_ad_rev[ $ad_id ], 2 ) : null,
			];
		}
		return self::rank_table( $out );
	}

	/**
	 * Top advertisers table: the per-ad window stats grouped by advertiser
	 * term (first term per ad; ads without one grouped under a translated
	 * "(no advertiser)" label).
	 *
	 * @param array $per_ad_rows Per-ad stat rows.
	 * @param array $ads         Published-ad records keyed by ID.
	 * @param array $per_ad_rev  Window revenue share keyed by ad ID.
	 * @return array Table payload.
	 */
	private static function top_advertisers( array $per_ad_rows, array $ads, array $per_ad_rev ): array {
		$no_advertiser = __( '(no advertiser)', 'newspack-plugin' );
		$groups        = [];
		foreach ( $per_ad_rows as $row ) {
			$ad_id      = $row['ad_id'];
			$advertiser = $ads[ $ad_id ]['advertiser'] ?? self::first_advertiser_name( $ad_id );
			if ( '' === $advertiser ) {
				$advertiser = $no_advertiser;
			}
			if ( ! isset( $groups[ $advertiser ] ) ) {
				$groups[ $advertiser ] = [
					'advertiser'  => $advertiser,
					'ads'         => 0,
					'impressions' => 0,
					'clicks'      => 0,
					'revenue'     => null,
				];
			}
			++$groups[ $advertiser ]['ads'];
			$groups[ $advertiser ]['impressions'] += $row['impressions'];
			$groups[ $advertiser ]['clicks']      += $row['clicks'];
			if ( isset( $per_ad_rev[ $ad_id ] ) ) {
				$groups[ $advertiser ]['revenue'] = round( ( $groups[ $advertiser ]['revenue'] ?? 0.0 ) + $per_ad_rev[ $ad_id ], 2 );
			}
		}
		$out = [];
		foreach ( $groups as $group ) {
			$group['ctr'] = $group['impressions'] > 0 ? round( $group['clicks'] / $group['impressions'], 4 ) : null;
			$out[]        = $group;
		}
		return self::rank_table( $out );
	}

	/**
	 * By-newsletter table: stats grouped by source newsletter, excluding the
	 * unknown-source sentinel (`newsletter_id` 0 — clicks that couldn't be
	 * attributed to a send). Top {@see self::TABLE_ROW_LIMIT} by impressions.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array Table payload.
	 */
	private static function by_newsletter( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = self::stats_table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT newsletter_id, COUNT( DISTINCT ad_id ) AS ads, SUM( impressions ) AS impressions, SUM( clicks ) AS clicks FROM {$table} WHERE stat_date BETWEEN %s AND %s AND newsletter_id != %d GROUP BY newsletter_id",
				$start_date,
				$end_date,
				self::UNKNOWN_NEWSLETTER_SENTINEL
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = [];
		foreach ( (array) $rows as $row ) {
			$newsletter_id = (int) $row['newsletter_id'];
			$post          = get_post( $newsletter_id );
			$impressions   = (int) $row['impressions'];
			$clicks        = (int) $row['clicks'];
			$out[]         = [
				'newsletter_id' => $newsletter_id,
				'title'         => $post ? get_the_title( $post ) : __( '(deleted)', 'newspack-plugin' ),
				'sent_date'     => $post ? mysql2date( 'Y-m-d', $post->post_date, false ) : '',
				'ads'           => (int) $row['ads'],
				'impressions'   => $impressions,
				'clicks'        => $clicks,
				'ctr'           => $impressions > 0 ? round( $clicks / $impressions, 4 ) : null,
			];
		}
		return self::rank_table( $out );
	}

	/*
	 * Flat-over-flight revenue model
	 */

	/**
	 * Prorate each ad's flat flight price over the report window.
	 *
	 * For each published ad with `price` > 0 and a valid flight
	 * (start_date <= expiry_date, both present): daily_rate = price /
	 * flight_days (inclusive), and the window earns daily_rate × the number
	 * of flight days overlapping [start, end].
	 *
	 * Ads whose flight overlaps the window but that are missing price or
	 * either date are EXCLUDED from revenue and counted in `excluded` (the
	 * `revenue_excluded_ads` honesty signal). `active` counts every published
	 * ad whose flight overlaps the window under the lenient rule: a missing
	 * expiry is open-ended, a missing start means already started.
	 *
	 * @param array  $ads        Published-ad records.
	 * @param string $start_date Window start (YYYY-MM-DD).
	 * @param string $end_date   Window end (YYYY-MM-DD).
	 * @return array{revenue:float,excluded:int,active:int,per_ad:array<int,float>}
	 */
	private static function flight_revenue( array $ads, string $start_date, string $end_date ): array {
		$revenue  = 0.0;
		$excluded = 0;
		$active   = 0;
		$per_ad   = [];

		foreach ( $ads as $ad ) {
			$flight_start = $ad['flight_start'];
			$flight_end   = $ad['flight_end'];

			// Lenient overlap (for active_ads / exclusion counting): missing
			// start = already started; missing expiry = open-ended.
			$overlaps = ( null === $flight_start || $flight_start <= $end_date )
				&& ( null === $flight_end || $flight_end >= $start_date );
			if ( $overlaps ) {
				++$active;
			}

			$revenue_eligible = null !== $ad['price'] && $ad['price'] > 0
				&& null !== $flight_start && null !== $flight_end
				&& $flight_end >= $flight_start;
			if ( ! $revenue_eligible ) {
				if ( $overlaps ) {
					++$excluded;
				}
				continue;
			}

			// Strict flight ∩ window overlap, in inclusive days. Y-m-d strings
			// compare correctly lexicographically.
			$overlap_start = max( $flight_start, $start_date );
			$overlap_end   = min( $flight_end, $end_date );
			if ( $overlap_start > $overlap_end ) {
				continue;
			}
			$flight_days  = self::days_inclusive( $flight_start, $flight_end );
			$overlap_days = self::days_inclusive( $overlap_start, $overlap_end );
			if ( $flight_days <= 0 ) {
				continue;
			}
			$share = ( $ad['price'] / $flight_days ) * $overlap_days;

			$per_ad[ $ad['id'] ] = $share;
			$revenue            += $share;
		}

		return [
			'revenue'  => $revenue,
			'excluded' => $excluded,
			'active'   => $active,
			'per_ad'   => $per_ad,
		];
	}

	/**
	 * Inclusive day count between two Y-m-d dates (same day = 1).
	 *
	 * @param string $from YYYY-MM-DD.
	 * @param string $to   YYYY-MM-DD (>= $from).
	 * @return int
	 */
	private static function days_inclusive( string $from, string $to ): int {
		try {
			$a = new \DateTimeImmutable( $from );
			$b = new \DateTimeImmutable( $to );
		} catch ( \Exception $e ) {
			return 0;
		}
		return (int) $a->diff( $b )->format( '%a' ) + 1;
	}

	/*
	 * Ad records
	 */

	/**
	 * Load every published ad's fields once: flight meta (normalized), price,
	 * lifetime counters, title, and first advertiser term. Real catalogs run
	 * into the hundreds of ads, so the post/meta/term caches are primed for
	 * the full ID set up front — the per-ad reads in the loop then hit cache
	 * instead of issuing ~7 queries per ad.
	 *
	 * @return array<int,array> Records keyed by ad ID.
	 */
	private static function get_published_ads(): array {
		if ( ! post_type_exists( self::ADS_CPT ) ) {
			return [];
		}
		$ids = get_posts(
			[
				'post_type'   => self::ADS_CPT,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		if ( ! empty( $ids ) ) {
			// One query each for posts (titles), meta, and terms.
			_prime_post_caches( $ids, false, false );
			update_meta_cache( 'post', $ids );
			if ( taxonomy_exists( self::ADVERTISER_TAX ) ) {
				update_object_term_cache( $ids, self::ADS_CPT );
			}
		}
		$ads = [];
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$price = get_post_meta( $id, 'price', true );
			$ads[ $id ] = [
				'id'                   => $id,
				'title'                => get_the_title( $id ),
				'advertiser'           => self::first_advertiser_name( $id ),
				'price'                => is_numeric( $price ) ? (float) $price : null,
				'flight_start'         => self::normalize_date_meta( get_post_meta( $id, 'start_date', true ) ),
				'flight_end'           => self::normalize_date_meta( get_post_meta( $id, 'expiry_date', true ) ),
				'lifetime_impressions' => (int) get_post_meta( $id, 'tracking_impressions', true ),
				'lifetime_clicks'      => (int) get_post_meta( $id, 'tracking_clicks', true ),
			];
		}
		return $ads;
	}

	/**
	 * First advertiser term name for an ad, or '' (taxonomy guarded — the
	 * newsletters plugin registers it).
	 *
	 * @param int $ad_id Ad post ID.
	 * @return string
	 */
	private static function first_advertiser_name( int $ad_id ): string {
		if ( ! taxonomy_exists( self::ADVERTISER_TAX ) ) {
			return '';
		}
		$terms = get_the_terms( $ad_id, self::ADVERTISER_TAX );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}
		return (string) $terms[0]->name;
	}

	/**
	 * Normalize a date meta value to Y-m-d, or null when empty/unparseable.
	 *
	 * @param mixed $raw Raw meta value.
	 * @return string|null
	 */
	private static function normalize_date_meta( $raw ): ?string {
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return null;
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $raw ) )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/*
	 * Payload helpers
	 */

	/**
	 * Scalar count payload.
	 *
	 * @param int $value Value.
	 * @return array
	 */
	private static function count_payload( int $value ): array {
		return [
			'value'      => $value,
			'computable' => true,
			'type'       => 'count',
		];
	}

	/**
	 * Rate payload — non-computable at a zero denominator (renders n/a,
	 * never a fake 0%).
	 *
	 * @param int $numerator   Numerator.
	 * @param int $denominator Denominator.
	 * @return array
	 */
	private static function rate_payload( int $numerator, int $denominator ): array {
		return [
			'value'       => $denominator > 0 ? round( $numerator / $denominator, 4 ) : 0.0,
			'computable'  => $denominator > 0,
			'type'        => 'rate',
			'numerator'   => $numerator,
			'denominator' => $denominator,
		];
	}

	/**
	 * Rank a table by impressions (desc), falling back to clicks when no row
	 * recorded an impression (pixel-disabled sites still get a meaningful
	 * ranking), capped to {@see self::TABLE_ROW_LIMIT}.
	 *
	 * @param array $rows Rows (each with impressions + clicks keys).
	 * @return array Table payload.
	 */
	private static function rank_table( array $rows ): array {
		$by = array_sum( array_column( $rows, 'impressions' ) ) > 0 ? 'impressions' : 'clicks';
		usort(
			$rows,
			function ( $a, $b ) use ( $by ) {
				return ( $b[ $by ] ?? 0 ) <=> ( $a[ $by ] ?? 0 );
			}
		);
		$rows = array_slice( $rows, 0, self::TABLE_ROW_LIMIT );
		return [
			'rows'       => $rows,
			'computable' => ! empty( $rows ),
			'type'       => 'table',
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
}
