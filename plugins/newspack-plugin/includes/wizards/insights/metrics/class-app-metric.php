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
			$account_id = isset( $account['account'] ) ? self::strip_resource_prefix( (string) $account['account'] ) : '';
			foreach ( $account['propertySummaries'] ?? [] as $property ) {
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

		$cache_key = self::METRICS_CACHE_PREFIX . md5( $property . '|' . $start_date . '|' . $end_date );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$metrics = self::compute_reach_metrics( $property, $start_date, $end_date );
		set_transient( $cache_key, $metrics, self::METRICS_CACHE_TTL );
		return $metrics;
	}

	/**
	 * Compose + run the Reach-section GA4 reports for a property/window.
	 *
	 * @param string $property   GA4 property ID.
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_reach_metrics( string $property, string $start_date, string $end_date ): array {
		$range = [
			'dateRanges' => [
				[
					'startDate' => $start_date,
					'endDate'   => $end_date,
				],
			],
		];

		return [
			'active_users' => self::scalar_report( $property, $range + [ 'metrics' => [ [ 'name' => 'activeUsers' ] ] ], 'count' ),
			'new_users'    => self::scalar_report( $property, $range + [ 'metrics' => [ [ 'name' => 'newUsers' ] ] ], 'count' ),
			'sessions'     => self::scalar_report( $property, $range + [ 'metrics' => [ [ 'name' => 'sessions' ] ] ], 'count' ),
			'platform'     => self::breakdown_report(
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
			'app_version'  => self::breakdown_report(
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
	}

	/**
	 * Run a single-scalar GA4 report and shape it as a scorecard payload. A
	 * report failure (or a non-numeric/absent value) yields a non-computable
	 * payload so the card degrades gracefully instead of showing a wrong number.
	 *
	 * @param string $property GA4 property ID.
	 * @param array  $body     runReport body.
	 * @param string $type     Payload type ('count'|'decimal'|'rate'|'duration').
	 * @return array
	 */
	private static function scalar_report( string $property, array $body, string $type ): array {
		return self::parse_scalar_result( Client::run_report( $property, $body ), $type );
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
			'active_users' => [
				'value'      => 892,
				'computable' => true,
				'type'       => 'count',
			],
			'new_users'    => [
				'value'      => 150,
				'computable' => true,
				'type'       => 'count',
			],
			'sessions'     => [
				'value'      => 12790,
				'computable' => true,
				'type'       => 'count',
			],
			'platform'     => [
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
			'app_version'  => [
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
