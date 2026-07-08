<?php
/**
 * Newspack Insights — App (mobile app) Metric orchestrator (Tab 10, NPPD-1882).
 *
 * The App tab reports mobile-app analytics for Pugpig ("Bolt") app publishers,
 * live from the GA4 Data API against a **publisher-selected** app property (not
 * Site Kit's web property). App data never lands in BigQuery, so this tab is
 * GA4-only.
 *
 * Two layers: the connection + property-selection layer (detect the Newspack
 * Google connection, enumerate GA4 properties across account boundaries via
 * `accountSummaries.list`, persist the chosen app property), and the windowed
 * metric orchestration ({@see self::get_metrics()}) that runs the GA4 reports
 * for the selected property. PR1 ships the Reach section; Engagement,
 * Notifications, Editions, and retention follow.
 *
 * Auth reuses Newspack's own Google OAuth via {@see \Newspack\Insights\GA4\Client}
 * — proven live for both same-account and separate-account (Firebase) app
 * properties.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Insights\GA4\Client;

defined( 'ABSPATH' ) || exit;

/**
 * App (Tab 10) metric orchestrator.
 */
final class App_Metric {

	/**
	 * Option storing the publisher-selected GA4 app property ID. Absent/empty
	 * means "not yet chosen" — the tab shows the property picker. An empty POST
	 * deletes the option (revert to picker) rather than storing an empty string,
	 * so a cleared selection re-enters the default path.
	 */
	const OPTION_PROPERTY_ID = 'newspack_insights_app_property_id';

	/**
	 * Windowed-metrics transient TTL. GA4 Data API has per-property quotas, so the
	 * per-window result is cached; the key includes the property id + window.
	 */
	const METRICS_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Metrics transient key prefix.
	 */
	const METRICS_CACHE_PREFIX = 'newspack_insights_app_metrics_v1:';

	/**
	 * Retention transient TTL + key prefix. Retention is window-independent (its
	 * own trailing complete-week cohorts), so it's cached per property — not per
	 * window — and for a day, so a date-range change doesn't re-run it.
	 */
	const RETENTION_CACHE_TTL    = DAY_IN_SECONDS;
	const RETENTION_CACHE_PREFIX = 'newspack_insights_app_retention_v1:';

	/**
	 * Retention curve length — nth-weeks after first open (0..N-1).
	 */
	const RETENTION_NTH_WEEKS = 4;

	/**
	 * Weekly acquisition cohorts averaged into the retention curve.
	 */
	const RETENTION_COHORTS = 4;

	/**
	 * Top-N rows kept for a Tier-2 KG breakdown (top sections/authors, etc.).
	 */
	const TOP_ROWS_LIMIT = 8;

	/**
	 * Whether this site is a Pugpig app publisher, read at runtime from the
	 * Newspack Manager companion plugin (same PHP process on managed sites).
	 * Guarded with class_exists so non-managed sites degrade cleanly.
	 *
	 * @return bool
	 */
	public static function is_app_publisher(): bool {
		return class_exists( '\Newspack_Manager\Pugpig\Pugpig' )
			&& \Newspack_Manager\Pugpig\Pugpig::is_enabled();
	}

	/**
	 * Whether a usable Newspack Google OAuth credential exists. Authoritative
	 * (resolves/refreshes the token) — not the misleading is_oauth_configured().
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return Client::has_valid_credentials();
	}

	/**
	 * The saved app property ID, or '' when none is chosen.
	 *
	 * @return string
	 */
	public static function get_selected_property_id(): string {
		$value = get_option( self::OPTION_PROPERTY_ID, '' );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Persist (or clear) the selected app property ID. An empty value deletes the
	 * option so the tab reverts to the picker rather than storing a poisoned ''.
	 *
	 * @param string $property_id Numeric GA4 property ID, or '' to clear.
	 * @return void
	 */
	public static function set_selected_property_id( string $property_id ): void {
		$property_id = trim( $property_id );
		if ( '' === $property_id ) {
			delete_option( self::OPTION_PROPERTY_ID );
			return;
		}
		update_option( self::OPTION_PROPERTY_ID, $property_id );
	}

	/**
	 * Flattened list of GA4 properties the connected identity can see, across all
	 * accounts, for the picker. Each row: account/property IDs + display names.
	 * The account name matters because the app property often lives in a separate
	 * (e.g. Firebase-default) account from the web property.
	 *
	 * @return array<int,array{account_id:string,account_name:string,property_id:string,property_name:string}>|\WP_Error
	 */
	public static function list_available_properties() {
		$summaries = Client::account_summaries();
		if ( is_wp_error( $summaries ) ) {
			return $summaries;
		}
		return self::flatten_property_summaries( $summaries );
	}

	/**
	 * Flatten GA Admin API `accountSummary` objects into pickable property rows.
	 * Pure (no network) so it's unit-testable against captured summary shapes.
	 *
	 * @param array $summaries List of GA Admin API `accountSummary` objects.
	 * @return array<int,array{account_id:string,account_name:string,property_id:string,property_name:string}>
	 */
	public static function flatten_property_summaries( array $summaries ): array {
		$properties = [];
		foreach ( $summaries as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			$account_name = isset( $account['displayName'] ) ? (string) $account['displayName'] : '';
			// Resource names look like "accounts/123"; keep the bare numeric ID.
			$account_id         = isset( $account['account'] ) ? self::strip_resource_prefix( (string) $account['account'] ) : '';
			$property_summaries = $account['propertySummaries'] ?? [];
			if ( ! is_array( $property_summaries ) ) {
				continue;
			}
			foreach ( $property_summaries as $property ) {
				if ( ! is_array( $property ) ) {
					continue;
				}
				$property_id = isset( $property['property'] ) ? self::strip_resource_prefix( (string) $property['property'] ) : '';
				if ( '' === $property_id ) {
					continue;
				}
				$properties[] = [
					'account_id'    => $account_id,
					'account_name'  => $account_name,
					'property_id'   => $property_id,
					'property_name' => isset( $property['displayName'] ) ? (string) $property['displayName'] : $property_id,
				];
			}
		}
		return $properties;
	}

	/**
	 * The App tab's config payload — drives the frontend's connect → select →
	 * render state machine. `properties` is only populated when connected (the
	 * enumeration call needs a token). On an enumeration failure `properties` is
	 * an empty list and `properties_error` carries the reason, so the picker can
	 * show an error state without blanking the tab.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		// Dev smoke path: with fixture mode on, present a connected state with
		// sample properties so the picker → selected flow is demoable without a
		// live Google connection. Never enabled in production.
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return self::get_fixture_config();
		}

		$connected           = self::is_connected();
		$properties          = [];
		$properties_error    = null;
		$selected_is_visible = false;
		$selected            = self::get_selected_property_id();

		if ( $connected ) {
			$listed = self::list_available_properties();
			if ( is_wp_error( $listed ) ) {
				$properties_error = $listed->get_error_message();
			} else {
				$properties = $listed;
				foreach ( $properties as $property ) {
					if ( $property['property_id'] === $selected ) {
						$selected_is_visible = true;
						break;
					}
				}
			}
		}

		return [
			'is_app_publisher'    => self::is_app_publisher(),
			'connected'           => $connected,
			'selected_property'   => '' !== $selected ? $selected : null,
			'selected_is_visible' => $selected_is_visible,
			'properties'          => $properties,
			'properties_error'    => $properties_error,
			'settings_url'        => admin_url( 'admin.php?page=newspack-settings' ),
		];
	}

	/**
	 * Windowed metric payloads for the selected app property. Returns a
	 * `tab_error` payload when there's no property or connection, so the tab can
	 * surface a single banner instead of N failed reports. Cached per
	 * property+window (GA4 quota). PR1 ships the Reach section; more sections
	 * follow.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array Keyed metric payloads, or `[ 'tab_error' => ... ]`.
	 */
	public static function get_metrics( string $start_date, string $end_date ): array {
		if ( defined( 'NEWSPACK_INSIGHTS_FIXTURE_MODE' ) && NEWSPACK_INSIGHTS_FIXTURE_MODE ) {
			return self::get_fixture_metrics();
		}

		$property = self::get_selected_property_id();
		if ( '' === $property ) {
			return [ 'tab_error' => 'no_property' ];
		}
		if ( ! self::is_connected() ) {
			return [ 'tab_error' => 'not_connected' ];
		}

		// Window-dependent metrics (Reach, Engagement, Notifications, Editions),
		// cached per property+window.
		$window_key = self::METRICS_CACHE_PREFIX . md5( $property . '|' . $start_date . '|' . $end_date );
		$window     = get_transient( $window_key );
		if ( ! is_array( $window ) ) {
			$window = self::compute_metrics( $property, $start_date, $end_date );
			set_transient( $window_key, $window, self::METRICS_CACHE_TTL );
		}

		// Retention is window-independent (its own trailing complete-week cohorts);
		// cache it per property with a daily TTL so date-range changes reuse it.
		$retention_key = self::RETENTION_CACHE_PREFIX . md5( $property );
		$retention     = get_transient( $retention_key );
		if ( ! is_array( $retention ) ) {
			$retention = self::compute_retention( $property );
			set_transient( $retention_key, $retention, self::RETENTION_CACHE_TTL );
		}

		return array_merge( $window, [ 'retention' => $retention ] );
	}

	/**
	 * Run one GA4 report counting a set of events, returning `[ ok, counts ]` where
	 * `counts` maps every requested event name to its integer count (0 when
	 * absent). A report failure yields `[ false, [] ]` so callers can mark their
	 * cards non-computable rather than showing a wrong zero.
	 *
	 * @param string   $property GA4 property ID.
	 * @param array    $range    The dateRanges wrapper.
	 * @param string[] $events   Event names to count.
	 * @return array{0:bool,1:array<string,int>}
	 */
	private static function event_counts_report( string $property, array $range, array $events ): array {
		$result = Client::run_report(
			$property,
			$range + [
				'dimensions'      => [ [ 'name' => 'eventName' ] ],
				'metrics'         => [ [ 'name' => 'eventCount' ] ],
				'dimensionFilter' => [
					'filter' => [
						'fieldName'    => 'eventName',
						'inListFilter' => [ 'values' => $events ],
					],
				],
			]
		);
		if ( is_wp_error( $result ) ) {
			return [ false, [] ];
		}
		$counts = array_fill_keys( $events, 0 );
		foreach ( $result['rows'] ?? [] as $row ) {
			$name = $row['dimensionValues'][0]['value'] ?? '';
			if ( array_key_exists( $name, $counts ) ) {
				$counts[ $name ] = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			}
		}
		return [ true, $counts ];
	}

	/**
	 * A computable count scorecard payload.
	 *
	 * @param int $value Count.
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
	 * A rate scorecard payload from numerator/denominator (0–1). Non-computable
	 * when the denominator is zero.
	 *
	 * @param int $numerator   Numerator.
	 * @param int $denominator Denominator.
	 * @return array
	 */
	private static function rate_payload( int $numerator, int $denominator ): array {
		if ( $denominator <= 0 ) {
			return self::not_computable( 'rate' );
		}
		return [
			'value'       => $numerator / $denominator,
			'computable'  => true,
			'type'        => 'rate',
			'numerator'   => $numerator,
			'denominator' => $denominator,
		];
	}

	/**
	 * A non-computable payload of the given type (graceful failure).
	 *
	 * @param string $type Payload type.
	 * @return array
	 */
	private static function not_computable( string $type ): array {
		return [
			'value'      => 0,
			'computable' => false,
			'type'       => $type,
		];
	}

	/**
	 * A non-computable rows payload (graceful failure for breakdown/timeseries).
	 *
	 * @param string $type Payload type.
	 * @return array
	 */
	private static function not_computable_rows( string $type ): array {
		return [
			'rows'       => [],
			'computable' => false,
			'type'       => $type,
		];
	}

	/**
	 * The EVENT-scoped custom-dimension parameter names registered on the app
	 * property (the Pugpig "KG" dims). Reuses the same detection the GA4 client
	 * relies on. Empty array on any failure — Tier-2 cards then render their
	 * "not configured" state rather than a wrong number.
	 *
	 * @param string $property GA4 property ID.
	 * @return string[]
	 */
	private static function registered_kg_params( string $property ): array {
		if ( ! class_exists( '\Newspack\GA4_Custom_Dimensions' ) ) {
			return [];
		}
		$params = \Newspack\GA4_Custom_Dimensions::get_registered_parameter_names( $property );
		return is_array( $params ) ? $params : [];
	}

	/**
	 * Tier-2 breakdown by a Pugpig `KG` custom dimension. When the dimension isn't
	 * registered on the property, returns a `not_configured` payload (the card
	 * renders the "unlock" note); otherwise runs the top-N breakdown and drops
	 * `(not set)`/empty rows.
	 *
	 * @param string   $property   GA4 property ID.
	 * @param array    $range      The dateRanges wrapper.
	 * @param string[] $registered Registered KG parameter names.
	 * @param string   $kg_param   KG parameter (without the `customEvent:` prefix).
	 * @param string   $metric     GA4 metric apiName.
	 * @param string   $dim_key    Output key for the dimension value.
	 * @param string   $metric_key Output key for the metric value.
	 * @return array
	 */
	private static function kg_breakdown( string $property, array $range, array $registered, string $kg_param, string $metric, string $dim_key, string $metric_key ): array {
		if ( ! in_array( $kg_param, $registered, true ) ) {
			return [
				'rows'           => [],
				'computable'     => false,
				'not_configured' => true,
				'type'           => 'breakdown',
			];
		}

		$payload = self::breakdown_report(
			$property,
			$range + [
				'dimensions' => [ [ 'name' => 'customEvent:' . $kg_param ] ],
				'metrics'    => [ [ 'name' => $metric ] ],
				'orderBys'   => [
					[
						'metric' => [ 'metricName' => $metric ],
						'desc'   => true,
					],
				],
				'limit'      => self::TOP_ROWS_LIMIT,
			],
			$dim_key,
			$metric_key
		);

		if ( ! empty( $payload['rows'] ) ) {
			$payload['rows'] = array_values(
				array_filter(
					$payload['rows'],
					static function ( $row ) use ( $dim_key ) {
						$value = (string) ( $row[ $dim_key ] ?? '' );
						return '' !== $value && '(not set)' !== $value;
					}
				)
			);
		}
		return $payload;
	}

	/**
	 * Weekly-cohort retention curve. Uses several *complete* weekly acquisition
	 * cohorts (each old enough that all {@see self::RETENTION_NTH_WEEKS} nth-weeks
	 * have elapsed, so the tail isn't deflated by too-recent users) and aggregates
	 * them into an average return-rate curve: week 0 is 100% by definition, week N
	 * is Σ active at nth-week N ÷ Σ cohort size. GA4 cohort reports require
	 * absolute, Sunday-aligned dates (relative dates 400).
	 *
	 * @param string $property GA4 property ID.
	 * @return array timeseries payload.
	 */
	private static function compute_retention( string $property ): array {
		// Anchor entirely in UTC so cohort week boundaries don't drift by a day on
		// non-UTC servers (GA4 cohort dates must be Sunday..Saturday aligned).
		try {
			$last_saturday = new \DateTimeImmutable( 'last saturday', new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return self::not_computable_rows( 'timeseries' );
		}

		$cohorts = [];
		for ( $i = 0; $i < self::RETENTION_COHORTS; $i++ ) {
			$weeks_back = ( self::RETENTION_NTH_WEEKS - 1 ) + $i;
			$saturday   = $last_saturday->modify( '-' . ( $weeks_back * 7 ) . ' days' );
			$sunday     = $saturday->modify( '-6 days' );
			$cohorts[]  = [
				'name'      => 'c' . $i,
				'dimension' => 'firstSessionDate',
				'dateRange' => [
					'startDate' => $sunday->format( 'Y-m-d' ),
					'endDate'   => $saturday->format( 'Y-m-d' ),
				],
			];
		}

		$result = Client::run_report(
			$property,
			[
				'cohortSpec' => [
					'cohorts'      => $cohorts,
					'cohortsRange' => [
						'granularity' => 'WEEKLY',
						'startOffset' => 0,
						'endOffset'   => self::RETENTION_NTH_WEEKS - 1,
					],
				],
				'dimensions' => [ [ 'name' => 'cohort' ], [ 'name' => 'cohortNthWeek' ] ],
				'metrics'    => [ [ 'name' => 'cohortActiveUsers' ] ],
			]
		);
		return self::parse_retention_result( $result );
	}

	/**
	 * Aggregate a GA4 cohort result into an average retention curve. Pure, so the
	 * math is unit-testable without the network.
	 *
	 * @param array|\WP_Error $result GA4 cohort runReport result.
	 * @return array timeseries payload { rows:[{week,retention}], computable, type }.
	 */
	public static function parse_retention_result( $result ): array {
		if ( is_wp_error( $result ) ) {
			return self::not_computable_rows( 'timeseries' );
		}
		$by_week = [];
		foreach ( $result['rows'] ?? [] as $row ) {
			// dimensions are [ cohort, cohortNthWeek ]; cohortNthWeek is "0000"..
			$nth               = (int) ( $row['dimensionValues'][1]['value'] ?? 0 );
			$val               = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			$by_week[ $nth ]   = ( $by_week[ $nth ] ?? 0 ) + $val;
		}
		if ( empty( $by_week[0] ) ) {
			return self::not_computable_rows( 'timeseries' );
		}
		$base = $by_week[0];
		ksort( $by_week );
		$rows = [];
		foreach ( $by_week as $week => $active ) {
			$rows[] = [
				'week'      => $week,
				'retention' => (float) ( $active / $base ),
			];
		}
		return [
			'rows'       => $rows,
			'computable' => true,
			'type'       => 'timeseries',
		];
	}

	/**
	 * Compose + run the GA4 reports for a property/window. Reach + Engagement
	 * scalars batch into one runReport (GA4 caps a request at 10 metrics);
	 * platform + app-version are one breakdown report each; Notifications +
	 * Editions come from one event-count report. Later sections add their own
	 * keys here.
	 *
	 * @param string $property   GA4 property ID.
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_metrics( string $property, string $start_date, string $end_date ): array {
		$range = [
			'dateRanges' => [
				[
					'startDate' => $start_date,
					'endDate'   => $end_date,
				],
			],
		];

		$scalars = self::scalars_report(
			$property,
			$range,
			[
				// Reach.
				'active_users'        => [
					'metric' => 'activeUsers',
					'type'   => 'count',
				],
				'new_users'           => [
					'metric' => 'newUsers',
					'type'   => 'count',
				],
				'sessions'            => [
					'metric' => 'sessions',
					'type'   => 'count',
				],
				// Engagement.
				'avg_engagement_time' => [
					'metric' => 'averageSessionDuration',
					'type'   => 'duration',
				],
				'engagement_rate'     => [
					'metric' => 'engagementRate',
					'type'   => 'rate',
				],
				'engaged_sessions'    => [
					'metric' => 'engagedSessions',
					'type'   => 'count',
				],
				'screens_per_session' => [
					'metric' => 'screenPageViewsPerSession',
					'type'   => 'decimal',
				],
				'screen_views'        => [
					'metric' => 'screenPageViews',
					'type'   => 'count',
				],
			]
		);

		$breakdowns = [
			'platform'    => self::breakdown_report(
				$property,
				$range + [
					'dimensions' => [ [ 'name' => 'platform' ] ],
					'metrics'    => [ [ 'name' => 'activeUsers' ] ],
					'orderBys'   => [
						[
							'metric' => [ 'metricName' => 'activeUsers' ],
							'desc'   => true,
						],
					],
				],
				'platform',
				'active_users'
			),
			'app_version' => self::breakdown_report(
				$property,
				$range + [
					'dimensions' => [ [ 'name' => 'appVersion' ] ],
					'metrics'    => [ [ 'name' => 'activeUsers' ] ],
					'orderBys'   => [
						[
							'metric' => [ 'metricName' => 'activeUsers' ],
							'desc'   => true,
						],
					],
					'limit'      => 10,
				],
				'app_version',
				'active_users'
			),
		];

		// Notifications + Editions: one event-count report for all the event-based
		// metrics. Missing events count as 0; a report failure marks the cards
		// non-computable rather than showing a wrong zero.
		[ $ev_ok, $ev ] = self::event_counts_report(
			$property,
			$range,
			[
				'notification_receive',
				'notification_open',
				'BoltNotificationStatusChange',
				'BoltDownloadStarted',
				'BoltDownloadCompleted',
				'BoltEditionOpened',
			]
		);

		$events = [
			// Notifications.
			'notification_open_rate'   => $ev_ok ? self::rate_payload( $ev['notification_open'], $ev['notification_receive'] ) : self::not_computable( 'rate' ),
			'notifications_received'   => $ev_ok ? self::count_payload( $ev['notification_receive'] ) : self::not_computable( 'count' ),
			'notification_opt_changes' => $ev_ok ? self::count_payload( $ev['BoltNotificationStatusChange'] ) : self::not_computable( 'count' ),
			// Editions.
			'downloads_started'        => $ev_ok ? self::count_payload( $ev['BoltDownloadStarted'] ) : self::not_computable( 'count' ),
			'downloads_completed'      => $ev_ok ? self::count_payload( $ev['BoltDownloadCompleted'] ) : self::not_computable( 'count' ),
			'download_completion_rate' => $ev_ok ? self::rate_payload( $ev['BoltDownloadCompleted'], $ev['BoltDownloadStarted'] ) : self::not_computable( 'rate' ),
			'edition_opens'            => $ev_ok ? self::count_payload( $ev['BoltEditionOpened'] ) : self::not_computable( 'count' ),
		];

		// Tier-2: content + audience-composition breakdowns keyed on the Pugpig
		// "KG" custom dimensions. Each renders its "not configured" state where the
		// dimension isn't registered on the property (auto-registration is 2b).
		$registered = self::registered_kg_params( $property );
		$content    = [
			'top_sections'   => self::kg_breakdown( $property, $range, $registered, 'KGSection', 'screenPageViews', 'section', 'views' ),
			'top_authors'    => self::kg_breakdown( $property, $range, $registered, 'KGAuthor', 'screenPageViews', 'author', 'views' ),
			'subscriber_mix' => self::kg_breakdown( $property, $range, $registered, 'KGSubscriberStatus', 'activeUsers', 'status', 'users' ),
			'content_cost'   => self::kg_breakdown( $property, $range, $registered, 'KGStoryCost', 'screenPageViews', 'cost', 'views' ),
		];

		return array_merge( $scalars, $breakdowns, $events, $content );
	}


	/**
	 * Shape a GA4 runReport result (or WP_Error) as a scorecard payload. Pure, so
	 * the graceful-failure branches are unit-testable without the network.
	 *
	 * @param array|\WP_Error $result GA4 runReport result.
	 * @param string          $type   Payload type.
	 * @return array
	 */
	public static function parse_scalar_result( $result, string $type ): array {
		$raw = is_wp_error( $result ) ? null : ( $result['rows'][0]['metricValues'][0]['value'] ?? null );
		return self::parse_scalar_value( $raw, $type );
	}

	/**
	 * Shape a single raw GA4 metric value (string|null) as a scorecard payload.
	 * Absent / non-numeric → non-computable.
	 *
	 * @param string|null $raw  Raw metric value.
	 * @param string      $type Payload type.
	 * @return array
	 */
	private static function parse_scalar_value( $raw, string $type ): array {
		if ( null === $raw || ! is_numeric( $raw ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => $type,
			];
		}
		return [
			'value'      => 'count' === $type ? (int) $raw : (float) $raw,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Run one GA4 report for several scalar metrics at once (GA4 caps a request at
	 * 10 metrics) and shape each into its scorecard payload, keyed by the caller's
	 * output key. A report failure degrades every card gracefully.
	 *
	 * @param string $property GA4 property ID.
	 * @param array  $range    The dateRanges wrapper.
	 * @param array  $specs    output_key => [ 'metric' => apiName, 'type' => payload type ].
	 * @return array output_key => scorecard payload.
	 */
	private static function scalars_report( string $property, array $range, array $specs ): array {
		$metrics = [];
		foreach ( $specs as $spec ) {
			$metrics[] = [ 'name' => $spec['metric'] ];
		}
		$result = Client::run_report( $property, $range + [ 'metrics' => $metrics ] );

		$out = [];
		$i   = 0;
		foreach ( $specs as $key => $spec ) {
			$raw         = is_wp_error( $result ) ? null : ( $result['rows'][0]['metricValues'][ $i ]['value'] ?? null );
			$out[ $key ] = self::parse_scalar_value( $raw, $spec['type'] );
			++$i;
		}
		return $out;
	}

	/**
	 * Run a one-dimension GA4 breakdown report and shape it as a rows payload.
	 *
	 * @param string $property   GA4 property ID.
	 * @param array  $body       runReport body.
	 * @param string $dim_key    Output key for the dimension value.
	 * @param string $metric_key Output key for the (integer) metric value.
	 * @return array
	 */
	private static function breakdown_report( string $property, array $body, string $dim_key, string $metric_key ): array {
		return self::parse_breakdown_result( Client::run_report( $property, $body ), $dim_key, $metric_key );
	}

	/**
	 * Shape a GA4 runReport result (or WP_Error) as a rows payload. Pure, so it's
	 * unit-testable without the network.
	 *
	 * @param array|\WP_Error $result     GA4 runReport result.
	 * @param string          $dim_key    Output key for the dimension value.
	 * @param string          $metric_key Output key for the (integer) metric value.
	 * @return array
	 */
	public static function parse_breakdown_result( $result, string $dim_key, string $metric_key ): array {
		if ( is_wp_error( $result ) ) {
			return [
				'rows'       => [],
				'computable' => false,
				'type'       => 'breakdown',
			];
		}
		$rows = [];
		foreach ( $result['rows'] ?? [] as $row ) {
			$rows[] = [
				$dim_key    => $row['dimensionValues'][0]['value'] ?? '',
				$metric_key => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
			];
		}
		return [
			'rows'       => $rows,
			'computable' => true,
			'type'       => 'breakdown',
		];
	}

	/**
	 * Canned Reach metrics for fixture/dev mode, so the sections render without a
	 * live GA4 connection. Numbers are illustrative.
	 *
	 * @return array
	 */
	private static function get_fixture_metrics(): array {
		return [
			'active_users'             => [
				'value'      => 892,
				'computable' => true,
				'type'       => 'count',
			],
			'new_users'                => [
				'value'      => 150,
				'computable' => true,
				'type'       => 'count',
			],
			'sessions'                 => [
				'value'      => 12790,
				'computable' => true,
				'type'       => 'count',
			],
			'avg_engagement_time'      => [
				'value'      => 1130,
				'computable' => true,
				'type'       => 'duration',
			],
			'engagement_rate'          => [
				'value'      => 0.83,
				'computable' => true,
				'type'       => 'rate',
			],
			'engaged_sessions'         => [
				'value'      => 10600,
				'computable' => true,
				'type'       => 'count',
			],
			'screens_per_session'      => [
				'value'      => 6.2,
				'computable' => true,
				'type'       => 'decimal',
			],
			'screen_views'             => [
				'value'      => 70473,
				'computable' => true,
				'type'       => 'count',
			],
			'notification_open_rate'   => [
				'value'       => 140 / 614,
				'computable'  => true,
				'type'        => 'rate',
				'numerator'   => 140,
				'denominator' => 614,
			],
			'notifications_received'   => [
				'value'      => 614,
				'computable' => true,
				'type'       => 'count',
			],
			'notification_opt_changes' => [
				'value'      => 123,
				'computable' => true,
				'type'       => 'count',
			],
			'downloads_started'        => [
				'value'      => 35495,
				'computable' => true,
				'type'       => 'count',
			],
			'downloads_completed'      => [
				'value'      => 33805,
				'computable' => true,
				'type'       => 'count',
			],
			'download_completion_rate' => [
				'value'       => 33805 / 35495,
				'computable'  => true,
				'type'        => 'rate',
				'numerator'   => 33805,
				'denominator' => 35495,
			],
			'edition_opens'            => [
				'value'      => 4180,
				'computable' => true,
				'type'       => 'count',
			],
			'retention'                => [
				'rows'       => [
					[
						'week'      => 0,
						'retention' => 1.0,
					],
					[
						'week'      => 1,
						'retention' => 0.55,
					],
					[
						'week'      => 2,
						'retention' => 0.32,
					],
					[
						'week'      => 3,
						'retention' => 0.18,
					],
				],
				'computable' => true,
				'type'       => 'timeseries',
			],
			'platform'                 => [
				'rows'       => [
					[
						'platform'     => 'iOS',
						'active_users' => 590,
					],
					[
						'platform'     => 'Android',
						'active_users' => 302,
					],
				],
				'computable' => true,
				'type'       => 'breakdown',
			],
			'app_version'              => [
				'rows'       => [
					[
						'app_version'  => '1.2',
						'active_users' => 840,
					],
					[
						'app_version'  => '1.1',
						'active_users' => 30,
					],
					[
						'app_version'  => '1.0',
						'active_users' => 22,
					],
				],
				'computable' => true,
				'type'       => 'breakdown',
			],
			// Tier-2: KG custom-dimension breakdowns. In fixture mode these render
			// as configured; on a real property they carry `not_configured` until
			// the dimensions are registered (auto-registration is Tier-2b).
			'top_sections'             => [
				'rows'       => [
					[
						'section' => 'News',
						'views'   => 7078,
					],
					[
						'section' => 'Life & Culture',
						'views'   => 5417,
					],
					[
						'section' => 'Obituaries',
						'views'   => 4306,
					],
					[
						'section' => 'Sports',
						'views'   => 1223,
					],
					[
						'section' => 'Opinion',
						'views'   => 716,
					],
				],
				'computable' => true,
				'type'       => 'breakdown',
			],
			'top_authors'              => [
				'rows'       => [
					[
						'author' => 'Alex Rivera',
						'views'  => 3120,
					],
					[
						'author' => 'Jordan Lee',
						'views'  => 2540,
					],
					[
						'author' => 'Sam Okafor',
						'views'  => 1980,
					],
					[
						'author' => 'Casey Nguyen',
						'views'  => 1210,
					],
				],
				'computable' => true,
				'type'       => 'breakdown',
			],
			'subscriber_mix'           => [
				'rows'       => [
					[
						'status' => 'ExistingSubscriber',
						'users'  => 483,
					],
					[
						'status' => 'None',
						'users'  => 473,
					],
					[
						'status' => 'InactiveSubscriber',
						'users'  => 139,
					],
				],
				'computable' => true,
				'type'       => 'breakdown',
			],
			'content_cost'             => [
				'rows'       => [
					[
						'cost'  => 'Free',
						'views' => 52140,
					],
					[
						'cost'  => 'Paid',
						'views' => 18320,
					],
					[
						'cost'  => 'Sample',
						'views' => 2110,
					],
				],
				'computable' => true,
				'type'       => 'breakdown',
			],
		];
	}

	/**
	 * Canned config for fixture/dev mode: connected, with generic sample
	 * properties across two accounts (one a Firebase-default, to mirror the
	 * separate-account case). Reflects the real saved property so picking → saving
	 * → the selected state works end-to-end locally. No real publisher names.
	 *
	 * @return array
	 */
	private static function get_fixture_config(): array {
		$properties = [
			[
				'account_id'    => '100000001',
				'account_name'  => 'Example News',
				'property_id'   => '200000001',
				'property_name' => 'Example News — Web',
			],
			[
				'account_id'    => '100000002',
				'account_name'  => 'Default Account for Firebase',
				'property_id'   => '200000002',
				'property_name' => 'example-news-app',
			],
		];

		$selected            = self::get_selected_property_id();
		$selected_is_visible = false;
		foreach ( $properties as $property ) {
			if ( $property['property_id'] === $selected ) {
				$selected_is_visible = true;
				break;
			}
		}

		return [
			'is_app_publisher'    => true,
			'connected'           => true,
			'selected_property'   => '' !== $selected ? $selected : null,
			'selected_is_visible' => $selected_is_visible,
			'properties'          => $properties,
			'properties_error'    => null,
			'settings_url'        => admin_url( 'admin.php?page=newspack-settings' ),
		];
	}

	/**
	 * Strip a GA Admin API resource prefix ("accounts/", "properties/") to the
	 * bare trailing ID.
	 *
	 * @param string $resource Resource name, e.g. "properties/459878437".
	 * @return string
	 */
	private static function strip_resource_prefix( string $resource ): string {
		$pos = strrpos( $resource, '/' );
		return false === $pos ? $resource : substr( $resource, $pos + 1 );
	}
}
