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
			'window'                             => [ 'start' => $start_date, 'end' => $end_date ],
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
			return [ 'value' => 0, 'computable' => false, 'type' => $type, 'error' => $rows->get_error_message() ];
		}
		if ( empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( $column, $rows[0] ) ) {
			return [ 'value' => 0, 'computable' => false, 'type' => $type ];
		}
		$value = $rows[0][ $column ];
		if ( null === $value || ! is_numeric( $value ) ) {
			return [ 'value' => 0, 'computable' => false, 'type' => $type ];
		}
		return [
			'value'      => 'count' === $type ? (int) $value : (float) $value,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Dispatch a catalog query and shape all rows into a rows payload. The SQL
	 * column aliases are the row keys (chosen to match the display contract).
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
			return [ 'rows' => [], 'computable' => false, 'type' => $type, 'error' => $rows->get_error_message() ];
		}
		if ( ! is_array( $rows ) ) {
			return [ 'rows' => [], 'computable' => false, 'type' => $type ];
		}
		return [ 'rows' => array_values( $rows ), 'computable' => true, 'type' => $type ];
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
		if ( is_wp_error( $rows ) || empty( $rows ) || ! is_array( $rows[0] ) ) {
			return [ 'value' => 0, 'computable' => false, 'type' => 'decimal', 'numerator' => 0, 'denominator' => 0 ];
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
		return self::proxy_rows( $proxy, 'audience_new_vs_returning_over_time', 'timeseries', $start, $end );
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
		return self::proxy_rows( $proxy, 'audience_readership_by_day_of_week', 'breakdown', $start, $end );
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
		return self::proxy_rows( $proxy, 'audience_newsletter_subscriber_composition', 'breakdown', $start, $end );
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
		return self::proxy_rows( $proxy, 'audience_logged_in_vs_anonymous_composition', 'breakdown', $start, $end );
	}

	/**
	 * Supporter Type via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function supporter_type_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'audience_supporter_type', 'breakdown', $start, $end );
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
		return self::proxy_rows( $proxy, 'audience_top_pages', 'table', $start, $end );
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
		if ( is_wp_error( $rows ) || empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( 'returning_reader_rate', $rows[0] ) ) {
			return [ 'value' => 0, 'computable' => false, 'type' => 'rate' ];
		}
		$rate = $rows[0]['returning_reader_rate'];
		if ( null === $rate || ! is_numeric( $rate ) ) {
			return [ 'value' => 0, 'computable' => false, 'type' => 'rate' ];
		}
		return [ 'value' => (float) $rate, 'computable' => (float) $rate <= 1.0, 'type' => 'rate' ];
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
		if ( null === $offset_hours ) {
			$offset_hours = (int) round( (int) wp_timezone()->getOffset( new \DateTimeImmutable( 'now' ) ) / 3600 );
		}
		foreach ( $payload['rows'] as &$row ) {
			if ( isset( $row['hour_of_day'] ) ) {
				$row['hour_of_day'] = ( (int) $row['hour_of_day'] + $offset_hours + 24 ) % 24;
			}
		}
		unset( $row );
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

}
