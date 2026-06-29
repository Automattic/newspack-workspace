<?php
/**
 * Newspack Insights — Audience Metric orchestrator (Tab 1, NPPD-1648).
 *
 * Composes GA4 Data API `runReport` bodies (translated from
 * `~/Sites/insights-docs/formulas/tab-1-audience.md`) and returns
 * MetricCard-ready payloads. Dispatches between GA4 (v1, default) and the
 * BigQuery proxy (v1.1, NPPD-1630 — stubbed here) per the
 * `NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4` constant (default true).
 *
 * Consumes the GA4 client landed in NPPD-1647
 * ({@see \Newspack\Insights\GA4\Client}) and its authoritative
 * custom-dimension detection ({@see \Newspack\GA4_Custom_Dimensions}).
 *
 * Payload shapes:
 *   scalar  : { value, computable, type: count|decimal }
 *   rate    : { value (0-1), computable, type: rate[, numerator, denominator] }
 *             (numerator/denominator included where meaningful; some rates that
 *             come straight from a GA4 metric omit them)
 *   rows    : { rows: [...], computable, type: breakdown|table|timeseries }
 *   overlay : { value: null, computable: false, overlay: { type, dimensions } }
 *   hidden  : { value: null, computable: false, hidden_in_v1: true }
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\GA4\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Audience (Tab 1) metric orchestrator.
 */
final class Audience_Metric {

	const CACHE_TTL        = 15 * MINUTE_IN_SECONDS;
	const CACHE_KEY_PREFIX = 'newspack_insights_audience_v1:';

	/**
	 * Whether this tab uses the GA4 path. Default true; flip the constant
	 * to false once the BQ catalog (NPPD-1630) ships and is validated.
	 *
	 * @return bool
	 */
	private static function use_ga4(): bool {
		return ! defined( 'NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4' ) || NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4;
	}

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
	 * Build the per-window transient cache key. Includes the GA4 property ID so
	 * that a reconnect to a different property never serves the previous
	 * property's cached payload within the TTL.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @param bool   $use_ga4    Whether the GA4 backend is active.
	 * @return string
	 */
	private static function window_cache_key( string $start_date, string $end_date, bool $use_ga4 ): string {
		return self::CACHE_KEY_PREFIX . md5(
			self::resolve_property_id() . '|' . $start_date . '|' . $end_date . '|' . ( $use_ga4 ? 'ga4' : 'bq' )
		);
	}

	/**
	 * Tab-level connection check. Returns a tab_error payload when the GA4
	 * path is active but no usable Google connection / property exists, else
	 * null. Checking once up front avoids N rejected runReport calls.
	 *
	 * @return array|null
	 */
	public static function connection_error(): ?array {
		if ( ! self::use_ga4() ) {
			return null;
		}
		$connected = class_exists( '\Newspack\Google_OAuth' )
			&& \Newspack\Google_OAuth::is_oauth_configured()
			&& '' !== self::resolve_property_id();
		if ( $connected ) {
			return null;
		}
		return [
			'tab_error'   => 'oauth_not_connected',
			'banner_text' => __( 'Audience metrics come from a GA4 property connected through Site Kit. Set up Site Kit to start seeing data here.', 'newspack-plugin' ),
		];
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
	 * Cached single-window computation, keyed by window + backend.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_window_cached( string $start_date, string $end_date ): array {
		$use_ga4   = self::use_ga4();
		$cache_key = self::window_cache_key( $start_date, $end_date, $use_ga4 );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$payload = $use_ga4
			? self::compute_via_ga4( $start_date, $end_date )
			: self::compute_via_bq( $start_date, $end_date );
		set_transient( $cache_key, $payload, self::CACHE_TTL );
		return $payload;
	}

	/**
	 * GA4 path — composes every metric for the window.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_via_ga4( string $start_date, string $end_date ): array {
		$pid = self::resolve_property_id();
		return [
			'window'                             => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			// Reach.
			'active_readers'                     => self::active_readers_via_ga4( $pid, $start_date, $end_date ),
			'pageviews'                          => self::pageviews_via_ga4( $pid, $start_date, $end_date ),
			'avg_sessions_per_reader'            => self::avg_sessions_per_reader_via_ga4( $pid, $start_date, $end_date ),
			'newsletter_signups'                 => self::newsletter_signups_via_ga4( $pid, $start_date, $end_date ),
			// Time trends.
			'new_vs_returning_over_time'         => self::new_vs_returning_over_time_via_ga4( $pid, $start_date, $end_date ),
			'readership_by_day_of_week'          => self::readership_by_day_of_week_via_ga4( $pid, $start_date, $end_date ),
			'readership_by_hour_of_day'          => self::readership_by_hour_of_day_via_ga4( $pid, $start_date, $end_date ),
			// Traffic sources.
			'traffic_sources_breakdown'          => self::traffic_sources_breakdown_via_ga4( $pid, $start_date, $end_date ),
			'top_campaigns'                      => self::top_campaigns_via_ga4( $pid, $start_date, $end_date ),
			// Composition (pies only).
			'device_breakdown'                   => self::device_breakdown_via_ga4( $pid, $start_date, $end_date ),
			'newsletter_subscriber_composition'  => self::newsletter_subscriber_composition_via_ga4( $pid, $start_date, $end_date ),
			'logged_in_vs_anonymous_composition' => self::logged_in_vs_anonymous_composition_via_ga4( $pid, $start_date, $end_date ),
			'supporter_type'                     => self::supporter_type_via_ga4( $pid, $start_date, $end_date ),
			// Geographic.
			'top_regions'                        => self::top_regions_via_ga4( $pid, $start_date, $end_date ),
			'top_cities'                         => self::top_cities_via_ga4( $pid, $start_date, $end_date ),
			// Content performance.
			'top_pages'                          => self::top_pages_via_ga4( $pid, $start_date, $end_date ),
			'top_authors_by_reader_count'        => self::top_authors_by_reader_count_via_ga4( $pid, $start_date, $end_date ),
			// BQ-only (hidden in v1).
			'top_categories'                     => self::bq_only_payload( 'available when BigQuery catalog ships' ),
			'returning_reader_rate_strict'       => self::hidden_in_v1_payload(),
		];
	}

	/**
	 * BQ path — dispatches the 4 reach scalars via the proxy; remaining metrics
	 * remain not_implemented until later tasks (B2+) fill them in. BQ-only metrics
	 * stay hidden_in_v1 even here (their GA4 counterpart can never compute them).
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
			// BQ-only (hidden in v1).
			'top_categories'                     => self::bq_only_payload( 'available when BigQuery catalog ships' ),
			'returning_reader_rate_strict'       => self::hidden_in_v1_payload(),
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

	/*
	===================================================================
	 * GA4-standard metrics
	 * ===================================================================
	 */

	/**
	 * Active Readers — totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function active_readers_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'totalUsers' ] ) );
		return self::scalar( $result, 'count' );
	}

	/**
	 * Pageviews — screenPageViews (conventional Data API metric).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function pageviews_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'screenPageViews' ] ) );
		return self::scalar( $result, 'count' );
	}

	/**
	 * Avg Sessions per Reader — sessions / totalUsers (single report).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function avg_sessions_per_reader_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'sessions', 'totalUsers' ] ) );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$row      = $result['raw']['rows'][0] ?? null;
		$sessions = (int) ( $row['metricValues'][0]['value'] ?? 0 );
		$users    = (int) ( $row['metricValues'][1]['value'] ?? 0 );
		return [
			'value'       => $users > 0 ? $sessions / $users : 0,
			'computable'  => $users > 0,
			'type'        => 'decimal',
			'numerator'   => $sessions,
			'denominator' => $users,
		];
	}

	/**
	 * Newsletter Signups — count of `np_newsletter_subscribed` events in the
	 * window. Fires on every successful Newspack newsletter signup (Registration
	 * block, Subscription Form block, account-creation modal, My Account →
	 * Newsletters). Counts signup *events*, not unique readers; direct-from-ESP
	 * signups outside Newspack flows are not captured. Zero is a valid result.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function newsletter_signups_via_ga4( string $pid, string $s, string $e ): array {
		$body                    = self::body( $s, $e, [], [ 'eventCount' ] );
		$body['dimensionFilter'] = [
			'filter' => [
				'fieldName'    => 'eventName',
				'stringFilter' => [
					'matchType' => 'EXACT',
					'value'     => 'np_newsletter_subscribed',
				],
			],
		];
		return self::scalar( self::safe_run_report( $pid, $body ), 'count' );
	}

	/**
	 * New vs Returning Over Time — date + newVsReturning / totalUsers, pivoted
	 * into one wide row per date carrying parallel `new` and `returning` series
	 * so the UI can draw two color-coded lines on a shared x-axis.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function new_vs_returning_over_time_via_ga4( string $pid, string $s, string $e ): array {
		$body             = self::body( $s, $e, [ 'date', 'newVsReturning' ], [ 'totalUsers' ] );
		$body['orderBys'] = [ [ 'dimension' => [ 'dimensionName' => 'date' ] ] ];
		$result           = self::safe_run_report( $pid, $body );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$by_date = [];
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$date = $row['dimensionValues'][0]['value'] ?? '';
			if ( '' === $date ) {
				continue;
			}
			$type = strtolower( $row['dimensionValues'][1]['value'] ?? '' );
			$val  = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			if ( ! isset( $by_date[ $date ] ) ) {
				$by_date[ $date ] = [
					'date'      => $date,
					'new'       => 0,
					'returning' => 0,
				];
			}
			// GA4 returns "new" / "returning" (plus an occasional empty bucket
			// for unknown); fold anything that isn't "returning" into new.
			if ( 'returning' === $type ) {
				$by_date[ $date ]['returning'] += $val;
			} else {
				$by_date[ $date ]['new'] += $val;
			}
		}
		ksort( $by_date );
		return [
			'rows'       => array_values( $by_date ),
			'computable' => true,
			'type'       => 'timeseries',
		];
	}

	/**
	 * Readership by Day of Week — dayOfWeekName / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function readership_by_day_of_week_via_ga4( string $pid, string $s, string $e ): array {
		// Query the numeric dayOfWeek alongside the name purely to order the rows
		// chronologically (Monday → Sunday); the numeric key is stripped before
		// returning. Bars must read chronologically, not by readership value.
		$result  = self::safe_run_report( $pid, self::body( $s, $e, [ 'dayOfWeek', 'dayOfWeekName' ], [ 'totalUsers' ] ) );
		$payload = self::rows( $result, [ 'day_of_week_index', 'day_of_week' ], [ 'active_readers' ], 'breakdown' );
		return self::order_rows_chronologically( $payload, 'day_of_week_index', true );
	}

	/**
	 * Readership by Hour of Day — hour / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function readership_by_hour_of_day_via_ga4( string $pid, string $s, string $e ): array {
		// Order chronologically by hour (0 → 23), not by readership value. The
		// `hour` value doubles as the chronological key and the display label, so
		// it is sorted in place and kept.
		$result  = self::safe_run_report( $pid, self::body( $s, $e, [ 'hour' ], [ 'totalUsers' ] ) );
		$payload = self::rows( $result, [ 'hour' ], [ 'active_readers' ], 'breakdown' );
		return self::order_rows_chronologically( $payload, 'hour', false, false );
	}

	/**
	 * Traffic Sources Breakdown — sessionDefaultChannelGroup / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function traffic_sources_breakdown_via_ga4( string $pid, string $s, string $e ): array {
		$body   = self::body( $s, $e, [ 'sessionDefaultChannelGroup' ], [ 'totalUsers' ] );
		$body  += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 20;
		$result = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'channel' ], [ 'readers' ], 'breakdown' );
	}

	/**
	 * Top Campaigns — sessionSource/Medium/CampaignName / totalUsers + sessions.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_campaigns_via_ga4( string $pid, string $s, string $e ): array {
		$body          = self::body( $s, $e, [ 'sessionSource', 'sessionMedium', 'sessionCampaignName' ], [ 'totalUsers', 'sessions' ] );
		$body         += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 50;
		$result        = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'source', 'medium', 'campaign' ], [ 'readers', 'sessions' ], 'table' );
	}

	/**
	 * Device Breakdown — deviceCategory / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function device_breakdown_via_ga4( string $pid, string $s, string $e ): array {
		$body   = self::body( $s, $e, [ 'deviceCategory' ], [ 'totalUsers' ] );
		$body  += self::order_by_metric_desc( 'totalUsers' );
		$result = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'device' ], [ 'readers' ], 'breakdown' );
	}

	/**
	 * Top Regions/States — country, region / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_regions_via_ga4( string $pid, string $s, string $e ): array {
		$body          = self::body( $s, $e, [ 'country', 'region' ], [ 'totalUsers' ] );
		$body         += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 50;
		$result        = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'country', 'region' ], [ 'readers' ], 'table' );
	}

	/**
	 * Top Cities — country, region, city / totalUsers.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_cities_via_ga4( string $pid, string $s, string $e ): array {
		$body          = self::body( $s, $e, [ 'country', 'region', 'city' ], [ 'totalUsers' ] );
		$body         += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 50;
		$result        = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'country', 'region', 'city' ], [ 'readers' ], 'table' );
	}

	/*
	===================================================================
	 * GA4-conditional metrics (custom dimensions)
	 * ===================================================================
	 */

	/**
	 * Newsletter Subscriber Composition — same query, two-slice pie.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function newsletter_subscriber_composition_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'customEvent:is_newsletter_subscriber' ], [ 'totalUsers' ] ) );
		return self::yes_composition( $result, __( 'Newsletter subscriber', 'newspack-plugin' ), __( 'Not subscribed', 'newspack-plugin' ) );
	}

	/**
	 * Logged-In vs Anonymous Composition — same query, two-slice pie.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function logged_in_vs_anonymous_composition_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'customEvent:logged_in' ], [ 'totalUsers' ] ) );
		return self::yes_composition( $result, __( 'Logged in', 'newspack-plugin' ), __( 'Anonymous', 'newspack-plugin' ) );
	}

	/**
	 * Supporter Type — composition of the logged-in audience by support status,
	 * grouped on customEvent:is_subscriber × customEvent:is_donor. The slices
	 * adapt to which products the publisher actually sells: both, subscriptions
	 * only, donations only, or — when neither is configured — the metric is
	 * hidden entirely (there is nothing to segment by).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function supporter_type_via_ga4( string $pid, string $s, string $e ): array {
		$products = self::detect_supporter_products();
		if ( ! $products['subscriptions'] && ! $products['donations'] ) {
			return self::bq_only_payload( 'no subscription or donation products configured' );
		}

		// is_subscriber / is_donor are only set for logged-in readers, so
		// requiring is_subscriber present scopes the report to the logged-in
		// audience without adding a third custom-dimension dependency.
		$body                    = self::body( $s, $e, [ 'customEvent:is_subscriber', 'customEvent:is_donor' ], [ 'totalUsers' ] );
		$body['dimensionFilter'] = self::custom_event_present_filter( 'is_subscriber' );
		$result                  = self::safe_run_report( $pid, $body );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}

		$sub_only   = 0;
		$donor_only = 0;
		$both       = 0;
		$neither    = 0;
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$is_sub   = 'yes' === ( $row['dimensionValues'][0]['value'] ?? '' );
			$is_donor = 'yes' === ( $row['dimensionValues'][1]['value'] ?? '' );
			$users    = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			if ( $is_sub && $is_donor ) {
				$both += $users;
			} elseif ( $is_sub ) {
				$sub_only += $users;
			} elseif ( $is_donor ) {
				$donor_only += $users;
			} else {
				$neither += $users;
			}
		}

		if ( $products['subscriptions'] && $products['donations'] ) {
			$rows = [
				[
					'label' => __( 'Subscriber only', 'newspack-plugin' ),
					'value' => $sub_only,
				],
				[
					'label' => __( 'Donor only', 'newspack-plugin' ),
					'value' => $donor_only,
				],
				[
					'label' => __( 'Both', 'newspack-plugin' ),
					'value' => $both,
				],
				[
					'label' => __( 'Logged-in only', 'newspack-plugin' ),
					'value' => $neither,
				],
			];
		} elseif ( $products['subscriptions'] ) {
			$rows = [
				[
					'label' => __( 'Subscriber', 'newspack-plugin' ),
					'value' => $sub_only + $both,
				],
				[
					'label' => __( 'Logged-in only', 'newspack-plugin' ),
					'value' => $neither + $donor_only,
				],
			];
		} else {
			$rows = [
				[
					'label' => __( 'Donor', 'newspack-plugin' ),
					'value' => $donor_only + $both,
				],
				[
					'label' => __( 'Logged-in only', 'newspack-plugin' ),
					'value' => $neither + $sub_only,
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
	 * Top Pages — grouped by pageTitle across all URL types (homepage, listings,
	 * archives, articles). Ranked by unique readers; returns both reader and
	 * pageview counts. No post_id / singular-content filter: this surfaces
	 * whatever pages drive pageviews, not just article posts.
	 *
	 * Trade-off: pageTitle is a display string, not a stable id — distinct URLs
	 * that share a title merge into one row, and a retitled page splits across
	 * two. Accepted here in exchange for working without the post_id dimension.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_pages_via_ga4( string $pid, string $s, string $e ): array {
		$body          = self::body( $s, $e, [ 'pageTitle' ], [ 'totalUsers', 'screenPageViews' ] );
		$body         += self::order_by_metric_desc( 'totalUsers' );
		$body['limit'] = 50;
		$result        = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'page_title' ], [ 'unique_readers', 'pageviews' ], 'table' );
	}

	/**
	 * Top Authors by Reader Count — customEvent:author (overlay if missing). The
	 * author custom dimension is auto-provisioned on every GA4-connected Newspack
	 * site, so it stands on its own without a post_id co-requirement.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_authors_by_reader_count_via_ga4( string $pid, string $s, string $e ): array {
		$body                    = self::body( $s, $e, [ 'customEvent:author' ], [ 'totalUsers', 'screenPageViews' ] );
		$body['dimensionFilter'] = self::custom_event_meaningful_filter( 'author' );
		$body                   += self::order_by_metric_desc( 'totalUsers' );
		$body['limit']           = 25;
		$result                  = self::safe_run_report( $pid, $body );
		return self::rows( $result, [ 'author' ], [ 'unique_readers', 'pageviews' ], 'table' );
	}

	/*
	===================================================================
	 * Shared helpers
	 * ===================================================================
	 */

	/**
	 * Build a base runReport body.
	 *
	 * @param string   $s        Start date YYYY-MM-DD.
	 * @param string   $e        End date YYYY-MM-DD.
	 * @param string[] $dims     Dimension names.
	 * @param string[] $metrics  Metric names.
	 * @return array
	 */
	private static function body( string $s, string $e, array $dims, array $metrics ): array {
		$body = [
			'dateRanges' => [
				[
					'startDate' => $s,
					'endDate'   => $e,
				],
			],
			'metrics'    => array_map(
				function ( $m ) {
					return [ 'name' => $m ];
				},
				$metrics
			),
		];
		if ( ! empty( $dims ) ) {
			$body['dimensions'] = array_map(
				function ( $d ) {
					return [ 'name' => $d ];
				},
				$dims
			);
		}
		return $body;
	}

	/**
	 * Build an orderBys fragment: a single metric, descending.
	 *
	 * @param string $metric Metric name.
	 * @return array
	 */
	private static function order_by_metric_desc( string $metric ): array {
		return [
			'orderBys' => [
				[
					'metric' => [ 'metricName' => $metric ],
					'desc'   => true,
				],
			],
		];
	}

	/**
	 * Build a dimensionFilter asserting a customEvent dimension is present.
	 *
	 * @param string $param Event parameter name.
	 * @return array
	 */
	private static function custom_event_present_filter( string $param ): array {
		return [ 'filter' => self::custom_event_present_expression( $param )['filter'] ];
	}

	/**
	 * Build a dimensionFilter requiring a customEvent dimension to be present and
	 * not GA4's literal "(not set)" placeholder. A bare present-filter (`.+`)
	 * matches "(not set)", which would surface a bogus aggregated row (e.g. a
	 * "(not set)" author from non-article pageviews where the dimension is unset).
	 *
	 * @param string $param Event parameter name.
	 * @return array
	 */
	private static function custom_event_meaningful_filter( string $param ): array {
		return [
			'andGroup' => [
				'expressions' => [
					self::custom_event_present_expression( $param ),
					[
						'notExpression' => [
							'filter' => [
								'fieldName'    => 'customEvent:' . $param,
								'stringFilter' => [
									'matchType' => 'EXACT',
									'value'     => '(not set)',
								],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * A single FilterExpression asserting a customEvent dimension is present.
	 *
	 * @param string $param Event parameter name.
	 * @return array
	 */
	private static function custom_event_present_expression( string $param ): array {
		return [
			'filter' => [
				'fieldName'    => 'customEvent:' . $param,
				'stringFilter' => [
					'matchType' => 'FULL_REGEXP',
					'value'     => '.+',
				],
			],
		];
	}

	/**
	 * Run a report, normalizing WP_Error into payload-shaped failures.
	 *
	 * @param string $property_id Property ID.
	 * @param array  $body        runReport body.
	 * @return array { raw } on success, or { error } / { overlay } on failure.
	 */
	private static function safe_run_report( string $property_id, array $body ): array {
		$result = Client::run_report( $property_id, $body );

		if ( is_wp_error( $result ) ) {
			if ( 'custom_dimension_missing' === $result->get_error_code() ) {
				$data = $result->get_error_data();
				return [
					'value'      => null,
					'computable' => false,
					'overlay'    => [
						'type'       => 'custom_dimension_missing',
						'dimensions' => is_array( $data ) && isset( $data['dimensions'] ) ? $data['dimensions'] : [],
					],
				];
			}
			return [
				'value'      => null,
				'computable' => false,
				'error'      => $result->get_error_message(),
			];
		}

		return [ 'raw' => $result ];
	}

	/**
	 * Reorder a breakdown payload's rows chronologically by a numeric key.
	 *
	 * Time-based charts (day of week, hour of day) must read chronologically
	 * rather than sorted by readership value. Error / overlay payloads pass
	 * through untouched.
	 *
	 * @param array  $payload      rows() output.
	 * @param string $order_key    Row key holding the numeric ordering value.
	 * @param bool   $monday_first Remap GA4 weekday (0=Sun..6=Sat) to Monday-first.
	 * @param bool   $drop_key     Strip the ordering key from the returned rows.
	 * @return array
	 */
	private static function order_rows_chronologically( array $payload, string $order_key, bool $monday_first = false, bool $drop_key = true ): array {
		if ( ! isset( $payload['rows'] ) || ! is_array( $payload['rows'] ) ) {
			return $payload;
		}
		$rows = $payload['rows'];
		usort(
			$rows,
			static function ( $a, $b ) use ( $order_key, $monday_first ) {
				$ai = (int) ( $a[ $order_key ] ?? 0 );
				$bi = (int) ( $b[ $order_key ] ?? 0 );
				if ( $monday_first ) {
					$ai = ( $ai + 6 ) % 7;
					$bi = ( $bi + 6 ) % 7;
				}
				return $ai <=> $bi;
			}
		);
		if ( $drop_key ) {
			foreach ( $rows as &$row ) {
				unset( $row[ $order_key ] );
			}
			unset( $row );
		}
		$payload['rows'] = array_values( $rows );
		return $payload;
	}

	/**
	 * Transform a single scalar metric value.
	 *
	 * @param array  $result safe_run_report result.
	 * @param string $type   'count' or 'decimal'.
	 * @return array
	 */
	private static function scalar( array $result, string $type ): array {
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$raw   = $result['raw']['rows'][0]['metricValues'][0]['value'] ?? null;
		$value = null === $raw
			? ( 'decimal' === $type ? 0.0 : 0 )
			: ( 'decimal' === $type ? (float) $raw : (int) $raw );
		return [
			'value'      => $value,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Transform report rows into a list of associative rows.
	 *
	 * @param array    $result      safe_run_report result.
	 * @param string[] $dim_keys    Output keys for each dimension (in order).
	 * @param string[] $metric_keys Output keys for each metric (in order).
	 * @param string   $type        Payload type token.
	 * @return array
	 */
	private static function rows( array $result, array $dim_keys, array $metric_keys, string $type ): array {
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$out = [];
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$entry = [];
			foreach ( $dim_keys as $i => $key ) {
				$entry[ $key ] = $row['dimensionValues'][ $i ]['value'] ?? null;
			}
			foreach ( $metric_keys as $i => $key ) {
				$raw           = $row['metricValues'][ $i ]['value'] ?? null;
				$entry[ $key ] = null === $raw ? null : ( str_contains( (string) $raw, '.' ) ? (float) $raw : (int) $raw );
			}
			$out[] = $entry;
		}
		return [
			'rows'       => $out,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Two-slice composition (yes vs everything else) for a pie.
	 *
	 * @param array  $result    safe_run_report result.
	 * @param string $yes_label Label for the 'yes' slice.
	 * @param string $no_label  Label for the remainder.
	 * @return array
	 */
	private static function yes_composition( array $result, string $yes_label, string $no_label ): array {
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$yes = 0;
		$no  = 0;
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$dim   = $row['dimensionValues'][0]['value'] ?? '';
			$users = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			if ( 'yes' === $dim ) {
				$yes += $users;
			} else {
				$no += $users;
			}
		}
		return [
			'rows'       => [
				[
					'label' => $yes_label,
					'value' => $yes,
				],
				[
					'label' => $no_label,
					'value' => $no,
				],
			],
			'computable' => ( $yes + $no ) > 0,
			'type'       => 'breakdown',
		];
	}

	/**
	 * Standard payload for BQ-only metrics: hidden in v1 (UI skips rendering).
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
	 * Hidden_in_v1 payload that records why the metric is unavailable in v1
	 * (e.g. a BQ-only metric, or a publisher with no supporter products). The UI
	 * skips rendering on `hidden_in_v1`; the reason is for docs/diagnostics.
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

	/**
	 * Standard payload for the v1 BQ stub path.
	 *
	 * @return array
	 */
	private static function not_implemented_payload(): array {
		return [
			'value'      => null,
			'computable' => false,
			'error'      => __( 'BQ path not yet implemented. See NPPD-1630.', 'newspack-plugin' ),
		];
	}
}
