<?php
/**
 * Newspack Insights — Audience Metric orchestrator (Tab 1, NPPD-1648).
 *
 * Returns MetricCard-ready payloads for all 19 Audience tab metrics via the
 * BigQuery proxy client (NPPD-1729). All 17 visible metrics and the 2
 * previously-hidden metrics (top_categories, returning_reader_rate_strict)
 * are now dispatched through BQ. The GA4 path has been removed (NPPD-1729
 * Task B3).
 *
 * Payload shapes:
 *   scalar  : { value, computable, type: count|decimal }
 *   rate    : { value (0-1), computable, type: rate[, numerator, denominator] }
 *             (numerator/denominator included where meaningful)
 *   rows    : { rows: [...], computable, type: breakdown|table|timeseries }
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Insights\BigQuery_Proxy_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Audience (Tab 1) metric orchestrator.
 */
final class Audience_Metric {

	const CACHE_TTL        = 15 * MINUTE_IN_SECONDS;
	const CACHE_KEY_PREFIX = 'newspack_insights_audience_v1:';

	/**
	 * Resolve the GA4 property ID.
	 *
	 * Reads from `newspack_ga4_info` (set by the Newspack GA4 integration) rather
	 * than the old Site Kit option. Falls back to '' when no property is connected.
	 *
	 * @return string Numeric property ID, or '' when none is connected.
	 */
	private static function resolve_property_id(): string {
		$info = get_option( 'newspack_ga4_info', [] );
		return is_array( $info ) && ! empty( $info['property_id'] ) ? (string) $info['property_id'] : '';
	}

	/**
	 * Build the per-window transient cache key. Uses a 'bq' backend suffix
	 * so existing GA4-keyed transients never collide after the path switch.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return string
	 */
	private static function window_cache_key( string $start_date, string $end_date ): string {
		return self::CACHE_KEY_PREFIX . md5(
			self::resolve_property_id() . '|' . $start_date . '|' . $end_date . '|bq'
		);
	}

	/**
	 * Tab-level connection check. BQ path requires no OAuth gate; always returns
	 * null (no error). Retained for API compatibility with the REST controller.
	 *
	 * @return array|null
	 */
	public static function connection_error(): ?array {
		return null;
	}

	/**
	 * Full tab payload for a window. When $compare is true, also computes the
	 * immediately-preceding same-length window under the `compare` key.
	 *
	 * @param string $start_date YYYY-MM-DD (site timezone).
	 * @param string $end_date   YYYY-MM-DD (site timezone).
	 * @param bool   $compare    Whether to attach the prior-period payload.
	 * @return array
	 */
	public static function get_all( string $start_date, string $end_date, bool $compare = false ): array {
		$error = self::connection_error();
		if ( null !== $error ) {
			return $error;
		}

		$payload = self::compute_window_cached( $start_date, $end_date );

		if ( $compare ) {
			[ $prior_start, $prior_end ] = self::prior_period( $start_date, $end_date );
			$payload['compare']          = self::compute_window_cached( $prior_start, $prior_end );
		}

		return $payload;
	}

	/**
	 * Realistic fixture payload for UI smoke testing without a GA4 connection.
	 * Returned by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
	 * Same { current, previous } shape the live controller assembles.
	 *
	 * @return array
	 */
	public static function get_fixture(): array {
		return require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/audience-fixture.php';
	}

	/*
	===================================================================
	 * Registered readers (local wp_users — GA4-independent, NPPD-1733)
	 * ===================================================================
	 */

	/**
	 * Total registered readers — an all-time snapshot count of reader accounts in
	 * the local wp_users table. Alone among Audience metrics this reads from
	 * wp_users, not GA4/BigQuery, so it is computed by the REST controller outside
	 * the GA4 connection gate and renders even when the rest of the tab cannot.
	 *
	 * "Reader" approximates {@see \Newspack\Reader_Activation::is_user_reader()} via
	 * its reader-role branch rather than a raw `subscriber`-role filter: the
	 * population is the configured reader roles (subscriber + customer by default)
	 * minus the restricted staff roles (administrator + editor). It does not also
	 * union readers carrying only the `np_reader` meta without a reader role — that
	 * cohort is empty in practice, and the role branch is what stays faithful on
	 * legacy sites where the meta was never backfilled. Close to the GA4 "known
	 * reader" segment; avoids folding in legacy staff and missing customer-role
	 * (WooCommerce) readers.
	 *
	 * @return array Scalar payload { value, computable, type } | not-computable.
	 */
	public static function registered_readers_total(): array {
		return self::count_registered_readers( null, null );
	}

	/**
	 * New registered readers whose account was created within the window, by
	 * `user_registered`. Same scalar shape as the snapshot; the REST controller
	 * pairs the current and prior windows so the card can render a period delta.
	 *
	 * @param string $start_date Inclusive window start, YYYY-MM-DD (site timezone).
	 * @param string $end_date   Inclusive window end, YYYY-MM-DD (site timezone).
	 * @return array Scalar payload { value, computable, type } | not-computable.
	 */
	public static function registered_readers_new( string $start_date, string $end_date ): array {
		return self::count_registered_readers( $start_date, $end_date );
	}

	/**
	 * Count reader accounts via WP_User_Query, optionally bounded to a
	 * registration window. Parameterized end to end — WP_User_Query builds the SQL,
	 * nothing is interpolated. Returns a not-computable payload (the NPPD-1698
	 * em-dash treatment) when Reader Activation is unavailable or no reader roles
	 * are configured — never a misleading 0.
	 *
	 * @param string|null $start_date Window start YYYY-MM-DD, or null for all-time.
	 * @param string|null $end_date   Window end YYYY-MM-DD, or null for all-time.
	 * @return array
	 */
	private static function count_registered_readers( ?string $start_date, ?string $end_date ): array {
		if ( ! class_exists( '\Newspack\Reader_Activation' ) ) {
			return self::registered_readers_not_computable();
		}

		$reader_roles = \Newspack\Reader_Activation::get_reader_roles();
		if ( ! is_array( $reader_roles ) || empty( $reader_roles ) ) {
			return self::registered_readers_not_computable();
		}

		$args = [
			'role__in'    => $reader_roles,
			'number'      => 1,     // Count only; never hydrate the matched users.
			'fields'      => 'ID',
			'count_total' => true,
		];

		// Mirror is_user_reader(): staff roles never count as readers. Same filter,
		// so a publisher that has customized the restriction stays consistent.
		$restricted_roles = \apply_filters( 'newspack_reader_restricted_roles', [ 'administrator', 'editor' ] );
		if ( is_array( $restricted_roles ) && ! empty( $restricted_roles ) ) {
			$args['role__not_in'] = $restricted_roles;
		}

		if ( null !== $start_date && null !== $end_date ) {
			$args['date_query'] = [
				[
					'column'    => 'user_registered',
					'after'     => self::window_bound_to_utc( $start_date, false ),
					'before'    => self::window_bound_to_utc( $end_date, true ),
					'inclusive' => true,
				],
			];
		}

		$query = new \WP_User_Query( $args );

		return [
			'value'      => (int) $query->get_total(),
			'computable' => true,
			'type'       => 'count',
		];
	}

	/**
	 * Convert a site-timezone calendar bound (YYYY-MM-DD) into the UTC datetime
	 * string WP_User_Query needs to match against `user_registered`, which WP
	 * stores in UTC. Without this a registration near local midnight would land in
	 * the wrong window by the site's UTC offset.
	 *
	 * @param string $date       YYYY-MM-DD in the site timezone.
	 * @param bool   $end_of_day Anchor at 23:59:59 (true) or 00:00:00 (false).
	 * @return string `Y-m-d H:i:s` in UTC.
	 */
	private static function window_bound_to_utc( string $date, bool $end_of_day ): string {
		$time  = $end_of_day ? ' 23:59:59' : ' 00:00:00';
		$local = new \DateTimeImmutable( $date . $time, wp_timezone() );
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Not-computable payload for the registered-reader counts — a genuine wp_users
	 * failure (or missing Reader Activation), surfaced via the NPPD-1698 em-dash +
	 * line treatment in the UI rather than a misleading 0.
	 *
	 * @return array
	 */
	private static function registered_readers_not_computable(): array {
		return [
			'value'      => null,
			'computable' => false,
			'type'       => 'count',
		];
	}

	/**
	 * Immediately-preceding window of equal length.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return string[] [ prior_start, prior_end ] as YYYY-MM-DD.
	 */
	private static function prior_period( string $start_date, string $end_date ): array {
		$start = new \DateTimeImmutable( $start_date );
		$end   = new \DateTimeImmutable( $end_date );
		$days  = (int) $start->diff( $end )->format( '%a' ) + 1;
		$prior_end   = $start->modify( '-1 day' );
		$prior_start = $prior_end->modify( '-' . ( $days - 1 ) . ' days' );
		return [ $prior_start->format( 'Y-m-d' ), $prior_end->format( 'Y-m-d' ) ];
	}

	/**
	 * Cached single-window computation via the BigQuery proxy.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_window_cached( string $start_date, string $end_date ): array {
		$cache_key = self::window_cache_key( $start_date, $end_date );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$payload = self::compute_via_bq( $start_date, $end_date );
		set_transient( $cache_key, $payload, self::CACHE_TTL );
		return $payload;
	}

	/**
	 * BQ path — computes all 19 Audience tab metrics for the window.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_via_bq( string $start_date, string $end_date ): array {
		$proxy = new BigQuery_Proxy_Client();
		$start = new \DateTimeImmutable( $start_date );
		$end   = new \DateTimeImmutable( $end_date );
		$payload = [
			'window'                             => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			// Reach.
			'active_readers'                     => self::active_readers_via_bq( $proxy, $start, $end ),
			'pageviews'                          => self::pageviews_via_bq( $proxy, $start, $end ),
			'avg_sessions_per_reader'            => self::avg_sessions_per_reader_via_bq( $proxy, $start, $end ),
			'newsletter_signups'                 => self::newsletter_signups_via_bq( $proxy, $start, $end ),
			// Time trends.
			'new_vs_returning_over_time'         => self::new_vs_returning_over_time_via_bq( $proxy, $start, $end ),
			'readership_by_day_of_week'          => self::readership_by_day_of_week_via_bq( $proxy, $start, $end ),
			'readership_by_hour_of_day'          => self::readership_by_hour_of_day_via_bq( $proxy, $start, $end ),
			// Traffic sources.
			'traffic_sources_breakdown'          => self::traffic_sources_breakdown_via_bq( $proxy, $start, $end ),
			'top_campaigns'                      => self::top_campaigns_via_bq( $proxy, $start, $end ),
			// Composition (pies only).
			'device_breakdown'                   => self::device_breakdown_via_bq( $proxy, $start, $end ),
			'newsletter_subscriber_composition'  => self::newsletter_subscriber_composition_via_bq( $proxy, $start, $end ),
			'logged_in_vs_anonymous_composition' => self::logged_in_vs_anonymous_composition_via_bq( $proxy, $start, $end ),
			'supporter_type'                     => self::supporter_type_via_bq( $proxy, $start, $end ),
			// Geographic.
			'top_regions'                        => self::top_regions_via_bq( $proxy, $start, $end ),
			'top_cities'                         => self::top_cities_via_bq( $proxy, $start, $end ),
			// Content performance.
			'top_pages'                          => self::top_pages_via_bq( $proxy, $start, $end ),
			'top_authors_by_reader_count'        => self::top_authors_by_reader_count_via_bq( $proxy, $start, $end ),
			// Content performance (BQ-only, now enabled).
			'top_categories'                     => self::top_categories_via_bq( $proxy, $start, $end ),
			'returning_reader_rate_strict'       => self::returning_reader_rate_via_bq( $proxy, $start, $end ),
		];
		return $payload;
	}

	/*
	===================================================================
	 * BigQuery proxy shapers (NPPD-1729)
	 * ===================================================================
	 */

	/**
	 * Dispatch a catalog query and shape its first-row column into a scalar payload.
	 *
	 * @param BigQuery_Proxy_Client $proxy      Proxy client.
	 * @param string                $query_name Catalog query name.
	 * @param string                $column     Column to read from the first row.
	 * @param string                $type       'count' | 'decimal'.
	 * @param \DateTimeInterface    $start      Window start.
	 * @param \DateTimeInterface    $end        Window end.
	 * @return array
	 */
	private static function proxy_scalar( BigQuery_Proxy_Client $proxy, string $query_name, string $column, string $type, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$rows = $proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => $type,
				'error'      => $rows->get_error_message(),
			];
		}
		if ( empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( $column, $rows[0] ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => $type,
			];
		}
		$value = $rows[0][ $column ];
		if ( null === $value || ! is_numeric( $value ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => $type,
			];
		}
		return [
			'value'      => 'count' === $type ? (int) $value : (float) $value,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Dispatch a catalog query and shape all rows into a rows payload. The SQL
	 * column aliases are the row keys and ARE the frontend contract — for the
	 * raw passthrough metrics they reach the React components unremapped.
	 *
	 * Those aliases are defined in the companion `newspack-manager-admin` repo
	 * (the `audience_*`/`engagement_*` query builders, NPPD-1729 PR #475), which
	 * lives outside this monorepo. An alias change there would silently blank the
	 * corresponding card with green CI here, so #475 must merge/deploy first and
	 * any alias edit there must be checked against the fixtures these metrics
	 * match (`fixtures/audience-fixture.php`, `engagement-fixture.php`).
	 *
	 * @param BigQuery_Proxy_Client $proxy      Proxy client.
	 * @param string                $query_name Catalog query name.
	 * @param string                $type       'breakdown' | 'table' | 'timeseries'.
	 * @param \DateTimeInterface    $start      Window start.
	 * @param \DateTimeInterface    $end        Window end.
	 * @return array
	 */
	private static function proxy_rows( BigQuery_Proxy_Client $proxy, string $query_name, string $type, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$rows = $proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return [
				'rows'       => [],
				'computable' => false,
				'type'       => $type,
				'error'      => $rows->get_error_message(),
			];
		}
		if ( ! is_array( $rows ) ) {
			return [
				'rows'       => [],
				'computable' => false,
				'type'       => $type,
			];
		}
		return [
			'rows'       => array_values( $rows ),
			'computable' => true,
			'type'       => $type,
		];
	}

	/*
	===================================================================
	 * BigQuery reach scalars (NPPD-1729 Task B1)
	 * ===================================================================
	 */

	/**
	 * Active Readers via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function active_readers_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_scalar( $proxy, 'audience_active_readers', 'active_readers', 'count', $start, $end );
	}

	/**
	 * Pageviews via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function pageviews_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_scalar( $proxy, 'audience_pageviews', 'pageviews', 'count', $start, $end );
	}

	/**
	 * Newsletter Signups via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function newsletter_signups_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_scalar( $proxy, 'audience_newsletter_signups', 'newsletter_signups', 'count', $start, $end );
	}

	/**
	 * Avg Sessions per Reader via BigQuery — sessions / active_readers from one row.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function avg_sessions_per_reader_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$rows = $proxy->query( 'audience_avg_sessions_per_reader', $start, $end );
		// Preserve the proxy error on an outage so the Scorecard renders its
		// unavailable state instead of a literal 0 (consumers key on `error`).
		if ( is_wp_error( $rows ) ) {
			return [
				'value'       => 0,
				'computable'  => false,
				'type'        => 'decimal',
				'numerator'   => 0,
				'denominator' => 0,
				'error'       => $rows->get_error_message(),
			];
		}
		if ( empty( $rows ) || ! is_array( $rows[0] ) ) {
			return [
				'value'       => 0,
				'computable'  => false,
				'type'        => 'decimal',
				'numerator'   => 0,
				'denominator' => 0,
			];
		}
		$sessions = (int) ( $rows[0]['sessions'] ?? 0 );
		$readers  = (int) ( $rows[0]['active_readers'] ?? 0 );
		return [
			'value'       => $readers > 0 ? (float) $sessions / $readers : 0,
			'computable'  => $readers > 0,
			'type'        => 'decimal',
			'numerator'   => $sessions,
			'denominator' => $readers,
		];
	}

	/*
	===================================================================
	 * BigQuery breakdown / table / timeseries methods (NPPD-1729 Task B2)
	 * ===================================================================
	 */

	/**
	 * New vs Returning Over Time via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function new_vs_returning_over_time_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$payload = self::proxy_rows( $proxy, 'audience_new_vs_returning_over_time', 'timeseries', $start, $end );
		// Rename the BQ aliases (day/new_readers/returning_readers) to the frontend
		// contract keys (date/new/returning). `day` is already 'Ymd' from event_date.
		$payload['rows'] = array_map(
			function ( $row ) {
				return [
					'date'      => (string) ( $row['day'] ?? '' ),
					'new'       => (int) ( $row['new_readers'] ?? 0 ),
					'returning' => (int) ( $row['returning_readers'] ?? 0 ),
				];
			},
			$payload['rows']
		);
		return $payload;
	}

	/**
	 * Readership by Day of Week via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function readership_by_day_of_week_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$payload = self::proxy_rows( $proxy, 'audience_readership_by_day_of_week', 'breakdown', $start, $end );

		// BigQuery DAYOFWEEK is 1-7 with Sunday=1; the frontend renders day NAMES in
		// Monday→Sunday order. Map each numeric bucket to its name, then reorder so a
		// row's position matches the fixture (only days present in the data appear).
		$names = [
			1 => __( 'Sunday', 'newspack-plugin' ),
			2 => __( 'Monday', 'newspack-plugin' ),
			3 => __( 'Tuesday', 'newspack-plugin' ),
			4 => __( 'Wednesday', 'newspack-plugin' ),
			5 => __( 'Thursday', 'newspack-plugin' ),
			6 => __( 'Friday', 'newspack-plugin' ),
			7 => __( 'Saturday', 'newspack-plugin' ),
		];
		// Display order: Monday(2) … Saturday(7), Sunday(1) last.
		$order = [ 2, 3, 4, 5, 6, 7, 1 ];

		$by_dow = [];
		foreach ( $payload['rows'] as $row ) {
			$dow = (int) ( $row['day_of_week'] ?? 0 );
			if ( isset( $names[ $dow ] ) ) {
				$by_dow[ $dow ] = (int) ( $row['active_readers'] ?? 0 );
			}
		}

		$rows = [];
		foreach ( $order as $dow ) {
			if ( array_key_exists( $dow, $by_dow ) ) {
				$rows[] = [
					'day_of_week'    => $names[ $dow ],
					'active_readers' => $by_dow[ $dow ],
				];
			}
		}
		$payload['rows'] = $rows;
		return $payload;
	}

	/**
	 * Traffic Sources Breakdown via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function traffic_sources_breakdown_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'audience_traffic_sources_breakdown', 'breakdown', $start, $end );
	}

	/**
	 * Top Campaigns via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function top_campaigns_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'audience_top_campaigns', 'table', $start, $end );
	}

	/**
	 * Device Breakdown via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function device_breakdown_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'audience_device_breakdown', 'breakdown', $start, $end );
	}

	/**
	 * Newsletter Subscriber Composition via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function newsletter_subscriber_composition_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$payload = self::proxy_rows( $proxy, 'audience_newsletter_subscriber_composition', 'breakdown', $start, $end );
		return self::relabel_composition(
			$payload,
			[
				'newsletter subscriber' => __( 'Newsletter subscriber', 'newspack-plugin' ),
				'not subscribed'        => __( 'Not subscribed', 'newspack-plugin' ),
			]
		);
	}

	/**
	 * Remap a `segment`/`reader_count` composition payload to the frontend pie
	 * contract `label`/`value`, relabeling each known SQL segment string to its
	 * display label. Unknown segments fall back to their raw segment string so a
	 * SQL drift surfaces visibly rather than vanishing.
	 *
	 * @param array                $payload Proxy rows payload.
	 * @param array<string,string> $labels  segment string → display label.
	 * @return array
	 */
	private static function relabel_composition( array $payload, array $labels ): array {
		$payload['rows'] = array_map(
			function ( $row ) use ( $labels ) {
				$segment = (string) ( $row['segment'] ?? '' );
				return [
					'label' => $labels[ $segment ] ?? $segment,
					'value' => (int) ( $row['reader_count'] ?? 0 ),
				];
			},
			$payload['rows']
		);
		return $payload;
	}

	/**
	 * Logged-In vs Anonymous Composition via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function logged_in_vs_anonymous_composition_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$payload = self::proxy_rows( $proxy, 'audience_logged_in_vs_anonymous_composition', 'breakdown', $start, $end );
		return self::relabel_composition(
			$payload,
			[
				'logged in' => __( 'Logged in', 'newspack-plugin' ),
				'anonymous' => __( 'Anonymous', 'newspack-plugin' ),
			]
		);
	}

	/**
	 * Supporter Type via BigQuery.
	 *
	 * Adapts to which products the publisher actually sells, mirroring the GA4
	 * path's product-gating and slice-fold logic:
	 *
	 * - Neither subscriptions nor donations configured → hidden_in_v1 payload
	 *   (no products to segment by; UI skips the card entirely).
	 * - Both products → four buckets pass through as-is.
	 * - Subscriptions only → fold "Both" into "Subscriber", "Donor only" into
	 *   "Logged-in only" — same two-slice shape as the GA4 path.
	 * - Donations only → fold "Both" into "Donor", "Subscriber only" into
	 *   "Logged-in only" — same two-slice shape as the GA4 path.
	 *
	 * BQ rows have keys `segment` ∈ {Both, Subscriber only, Donor only,
	 * Logged-in only} and `reader_count`.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function supporter_type_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$products = self::detect_supporter_products();

		if ( ! $products['subscriptions'] && ! $products['donations'] ) {
			return self::bq_only_payload( 'no subscription or donation products configured' );
		}

		$payload = self::proxy_rows( $proxy, 'audience_supporter_type', 'breakdown', $start, $end );

		// Surface a proxy outage as-is rather than folding it into a zero-value
		// pie — otherwise a failure reads as "no data" and the error is lost.
		if ( ! empty( $payload['error'] ) ) {
			return $payload;
		}

		// Both products → all four buckets, relabeled from the proxy's
		// segment/reader_count to the frontend pie contract label/value.
		if ( $products['subscriptions'] && $products['donations'] ) {
			return self::relabel_composition(
				$payload,
				[
					'Subscriber only' => __( 'Subscriber only', 'newspack-plugin' ),
					'Donor only'      => __( 'Donor only', 'newspack-plugin' ),
					'Both'            => __( 'Both', 'newspack-plugin' ),
					'Logged-in only'  => __( 'Logged-in only', 'newspack-plugin' ),
				]
			);
		}

		// Single-product publishers: fold the four BQ segments down to the two
		// relevant slices, mirroring the GA4 path's bucket-merging arithmetic.
		$counts = [
			'both'       => 0,
			'sub_only'   => 0,
			'donor_only' => 0,
			'logged_in'  => 0,
		];
		foreach ( $payload['rows'] as $row ) {
			$segment = $row['segment'] ?? '';
			$count   = (int) ( $row['reader_count'] ?? 0 );
			switch ( $segment ) {
				case 'Both':
					$counts['both'] += $count;
					break;
				case 'Subscriber only':
					$counts['sub_only'] += $count;
					break;
				case 'Donor only':
					$counts['donor_only'] += $count;
					break;
				case 'Logged-in only':
					$counts['logged_in'] += $count;
					break;
			}
		}

		if ( $products['subscriptions'] ) {
			// Subscriptions only: Both → Subscriber; Donor only → Logged-in only.
			$rows = [
				[
					'label' => __( 'Subscriber', 'newspack-plugin' ),
					'value' => $counts['sub_only'] + $counts['both'],
				],
				[
					'label' => __( 'Logged-in only', 'newspack-plugin' ),
					'value' => $counts['logged_in'] + $counts['donor_only'],
				],
			];
		} else {
			// Donations only: Both → Donor; Subscriber only → Logged-in only.
			$rows = [
				[
					'label' => __( 'Donor', 'newspack-plugin' ),
					'value' => $counts['donor_only'] + $counts['both'],
				],
				[
					'label' => __( 'Logged-in only', 'newspack-plugin' ),
					'value' => $counts['logged_in'] + $counts['sub_only'],
				],
			];
		}

		$total = array_sum( array_column( $rows, 'value' ) );
		return [
			'rows'       => $rows,
			'computable' => $total > 0,
			'type'       => 'breakdown',
		];
	}

	/**
	 * Top Regions via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function top_regions_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'audience_top_regions', 'table', $start, $end );
	}

	/**
	 * Top Cities via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function top_cities_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'audience_top_cities', 'table', $start, $end );
	}

	/**
	 * Top Pages via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function top_pages_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$payload = self::proxy_rows( $proxy, 'audience_top_pages', 'table', $start, $end );
		// The proxy carries post_id + page_url for keying/dedup; the frontend table
		// renders only page_title/unique_readers/pageviews. Drop the extra columns.
		$payload['rows'] = array_map(
			function ( $row ) {
				return [
					'page_title'     => (string) ( $row['page_title'] ?? '' ),
					'unique_readers' => (int) ( $row['unique_readers'] ?? 0 ),
					'pageviews'      => (int) ( $row['pageviews'] ?? 0 ),
				];
			},
			$payload['rows']
		);
		return $payload;
	}

	/**
	 * Top Authors by Reader Count via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function top_authors_by_reader_count_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'audience_top_authors_by_reader_count', 'table', $start, $end );
	}

	/**
	 * Top Categories via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function top_categories_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'audience_top_categories', 'table', $start, $end );
	}

	/**
	 * Returning Reader Rate (strict) via BQ. Single-row rate payload.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function returning_reader_rate_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$rows = $proxy->query( 'audience_returning_reader_rate', $start, $end );
		// Preserve the proxy error on an outage so the Scorecard renders its
		// unavailable state instead of a literal 0 (consumers key on `error`).
		if ( is_wp_error( $rows ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => 'rate',
				'error'      => $rows->get_error_message(),
			];
		}
		if ( empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( 'returning_reader_rate', $rows[0] ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => 'rate',
			];
		}
		$rate = $rows[0]['returning_reader_rate'];
		if ( null === $rate || ! is_numeric( $rate ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => 'rate',
			];
		}
		return [
			'value'      => (float) $rate,
			'computable' => (float) $rate <= 1.0,
			'type'       => 'rate',
		];
	}

	/**
	 * Readership by Hour of Day via BQ. BQ returns UTC hours; shift by the site's
	 * whole-hour UTC offset so the chart matches the publisher's local clock.
	 *
	 * @param BigQuery_Proxy_Client $proxy        Proxy client.
	 * @param \DateTimeInterface    $start        Window start.
	 * @param \DateTimeInterface    $end          Window end.
	 * @param int|null              $offset_hours Whole-hour UTC offset; null = derive from wp_timezone().
	 * @return array
	 */
	public static function readership_by_hour_of_day_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end, ?int $offset_hours = null ): array {
		$payload = self::proxy_rows( $proxy, 'audience_readership_by_hour_of_day', 'breakdown', $start, $end );
		if ( empty( $payload['rows'] ) ) {
			return $payload;
		}
		// Derive the whole-hour UTC offset from the window START, not "now": a
		// window that falls under a different DST offset than the current date
		// would otherwise be shifted by the wrong amount.
		if ( null === $offset_hours ) {
			$offset_hours = (int) round( wp_timezone()->getOffset( $start ) / 3600 );
		}
		// Shift each UTC hour to local time, then emit the frontend contract key
		// `hour` as a 2-char zero-padded string ('00'..'23'); the raw int
		// `hour_of_day` alias is dropped.
		foreach ( $payload['rows'] as &$row ) {
			$local_hour      = ( (int) ( $row['hour_of_day'] ?? 0 ) + $offset_hours + 24 ) % 24;
			$active_readers  = (int) ( $row['active_readers'] ?? 0 );
			$row             = [
				'hour'           => str_pad( (string) $local_hour, 2, '0', STR_PAD_LEFT ),
				'active_readers' => $active_readers,
			];
		}
		unset( $row );
		// The shift leaves rows in their original UTC order, so re-sort by the
		// local hour to keep a stable 0→23 x-axis — otherwise a non-UTC site's
		// chart is rotated and wraps at midnight (e.g. 19,20,…,23,00,…,18).
		usort(
			$payload['rows'],
			static function ( $a, $b ) {
				return (int) $a['hour'] <=> (int) $b['hour'];
			}
		);
		return $payload;
	}

	/**
	 * Detect which supporter products the publisher sells, to shape (or hide) the
	 * Supporter Type pie. Read-only apart from the shared donation classifier's own
	 * 1-hour cache priming on a miss.
	 *
	 * Donations resolve through the single shared {@see Donation_Product_Classifier}
	 * (NPPD-1767) — the same source Subscribers (Tab 6), Donors (Tab 7), and the Tab 3
	 * funnels use: the union of the canonical donation family, products a publisher
	 * checkbox-flags via `_newspack_is_donation`, and their variations. The previous
	 * raw `newspack_donation_product_id` option alone missed checkbox-only donations
	 * and let a donation-flagged subscription product double-count as a subscription,
	 * so this one pie could contradict the Donors/Subscribers numbers beside it.
	 *
	 * Subscriptions detect off product type but exclude the donation set, so the two
	 * categories stay complementary: a membership product flagged as a donation counts
	 * as a donation here (matching Tabs 6/7), not a subscription.
	 *
	 * @return array{subscriptions:bool,donations:bool}
	 */
	private static function detect_supporter_products(): array {
		$donation_ids  = Donation_Product_Classifier::get_donation_product_ids();
		$has_donations = ! empty( $donation_ids );

		$has_subscriptions = false;
		if ( class_exists( 'WC_Subscriptions' ) && function_exists( 'wc_get_products' ) ) {
			$sub_ids = wc_get_products(
				[
					'type'   => [ 'subscription', 'variable-subscription' ],
					'status' => 'publish',
					'limit'  => -1,
					'return' => 'ids',
				]
			);
			// Drop products the publisher designated as donations so a flagged
			// subscription-type product doesn't count as both categories.
			$sub_ids           = array_diff( array_map( 'intval', $sub_ids ), $donation_ids );
			$has_subscriptions = ! empty( $sub_ids );
		}

		return [
			'subscriptions' => $has_subscriptions,
			'donations'     => $has_donations,
		];
	}

	/**
	 * Standard payload for metrics that are hidden in v1 because they require
	 * BigQuery (UI skips rendering on `hidden_in_v1`).
	 *
	 * @return array
	 */
	private static function hidden_in_v1_payload(): array {
		return [
			'value'        => null,
			'computable'   => false,
			'hidden_in_v1' => true,
		];
	}

	/**
	 * Hidden_in_v1 payload that records why the metric is unavailable (e.g. a
	 * publisher with no supporter products). The UI skips rendering on
	 * `hidden_in_v1`; the reason is for docs/diagnostics.
	 *
	 * @param string $reason Short machine-ish reason.
	 * @return array
	 */
	private static function bq_only_payload( string $reason ): array {
		return [
			'value'        => null,
			'computable'   => false,
			'hidden_in_v1' => true,
			'reason'       => $reason,
		];
	}
}
