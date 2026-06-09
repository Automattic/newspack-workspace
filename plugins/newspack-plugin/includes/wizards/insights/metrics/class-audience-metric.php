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
	 * Resolve the GA4 property ID from Site Kit's stored settings.
	 *
	 * @return string Numeric property ID, or '' when none is connected.
	 */
	private static function resolve_property_id(): string {
		$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
		return is_array( $settings ) && ! empty( $settings['propertyID'] ) ? (string) $settings['propertyID'] : '';
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
			'banner_text' => __( 'Connect Google Analytics in Newspack → Connections to see this tab.', 'newspack-plugin' ),
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
	 * BQ path — v1 stub. Every metric returns a not_implemented error with the
	 * same key set as the GA4 path; NPPD-1630 fills these in. BQ-only metrics
	 * stay hidden_in_v1 even here (their GA4 counterpart can never compute them).
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_via_bq( string $start_date, string $end_date ): array {
		$keys = [
			'active_readers',
			'pageviews',
			'avg_sessions_per_reader',
			'newsletter_signups',
			'new_vs_returning_over_time',
			'readership_by_day_of_week',
			'readership_by_hour_of_day',
			'traffic_sources_breakdown',
			'top_campaigns',
			'device_breakdown',
			'newsletter_subscriber_composition',
			'logged_in_vs_anonymous_composition',
			'supporter_type',
			'top_regions',
			'top_cities',
			'top_pages',
			'top_authors_by_reader_count',
		];
		$payload = [
			'window' => [
				'start' => $start_date,
				'end'   => $end_date,
			],
		];
		foreach ( $keys as $key ) {
			$payload[ $key ] = self::not_implemented_payload();
		}
		$payload['top_categories']               = self::bq_only_payload( 'available when BigQuery catalog ships' );
		$payload['returning_reader_rate_strict'] = self::hidden_in_v1_payload();
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
	 * Detect which supporter products the publisher sells. Side-effect free:
	 * donations are inferred from the saved donation-product option, and
	 * subscriptions from the presence of a published WooCommerce Subscriptions
	 * product. Used to shape (or hide) the Supporter Type pie.
	 *
	 * @return array{subscriptions:bool,donations:bool}
	 */
	private static function detect_supporter_products(): array {
		$has_donations = (int) get_option( 'newspack_donation_product_id', 0 ) > 0;

		$has_subscriptions = false;
		if ( class_exists( 'WC_Subscriptions' ) && function_exists( 'wc_get_products' ) ) {
			$subs = wc_get_products(
				[
					'type'   => [ 'subscription', 'variable-subscription' ],
					'status' => 'publish',
					'limit'  => 1,
					'return' => 'ids',
				]
			);
			$has_subscriptions = ! empty( $subs );
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
		$body['dimensionFilter'] = self::custom_event_present_filter( 'author' );
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
