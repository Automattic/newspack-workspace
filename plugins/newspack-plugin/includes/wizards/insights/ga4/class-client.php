<?php
/**
 * Newspack Insights — GA4 Data API client.
 *
 * Generic primitives for GA4 Data API `runReport` calls, used by the Insights
 * metric orchestrators (Audience, Engagement, future tabs) for the v1 GA4-first
 * dispatch path while the BigQuery proxy (NPPD-1630) is built. The orchestrators
 * compose their own request bodies; this client knows nothing about specific
 * metrics, tabs, or dimensions.
 *
 * Auth reuses Newspack's existing Google connection — the same
 * `\Newspack\Google_OAuth::get_oauth2_credentials()` token the GA4 Admin API
 * client already relies on. The Newspack `analytics` scope covers both the
 * Admin API and the Data API, so no publisher reconnection is needed.
 *
 * Custom dimension detection is authoritative: before issuing a query that
 * references any `customEvent:<param>` dimension, the client checks the params
 * actually registered on the property via
 * `\Newspack\GA4_Custom_Dimensions::get_registered_parameter_names()` and
 * returns a `custom_dimension_missing` WP_Error (listing the missing params) so
 * the affected MetricCard can render the spec's overlay. This folds in what was
 * scoped as a v1.1 "boot-time probe" — the data is already available.
 *
 * Source: generalized from the GA4 Data API client in
 * Automattic/newspack-gate-intelligence (NGI_GA4), dropping the gate-specific
 * orchestration. See NPPD-1647.
 *
 * @package Newspack
 */

namespace Newspack\Insights\GA4;

use Newspack\Google_OAuth;
use Newspack\GA4_Custom_Dimensions;

defined( 'ABSPATH' ) || exit;

/**
 * GA4 Data API client.
 */
final class Client {

	/**
	 * GA4 Data API base URL. The property and method are appended as
	 * `/{property_id}:runReport`.
	 */
	const DATA_API_URL = 'https://analyticsdata.googleapis.com/v1beta/properties';

	/**
	 * Per-request memo of registered EVENT-scoped custom-dimension parameter
	 * names (or a WP_Error), keyed by property ID. Orchestrators issue several
	 * reports per request — and batch_run_reports() calls run_report() in a loop
	 * — so the registered-dimension lookup (an Admin API round-trip) is cached
	 * for the duration of the request to avoid amplifying calls and latency.
	 *
	 * @var array<string,string[]|\WP_Error>
	 */
	private static $registered_dimensions_cache = [];

	/**
	 * Run a single GA4 Data API report.
	 *
	 * @param string $property_id The publisher's GA4 property ID (numeric, no "properties/" prefix).
	 * @param array  $body        The runReport request body (dateRanges, dimensions, metrics, dimensionFilter, etc.).
	 *
	 * @return array|\WP_Error Decoded report response on success, WP_Error on failure.
	 *
	 * Error codes:
	 *   - 'missing_dependency': the Newspack Google_OAuth class is unavailable.
	 *   - 'not_connected': no Newspack Google connection / credentials.
	 *   - 'no_token': credentials present but the access token is empty.
	 *   - 'custom_dimension_missing': the query references customEvent:<param>
	 *     dimensions not registered on the property. WP_Error data carries
	 *     `[ 'dimensions' => string[] ]` (the missing parameter names).
	 *   - 'ga4_api_error': non-2xx response from the Data API. Data carries
	 *     `[ 'http_code' => int ]`.
	 *   - HTTP/network errors bubble up from wp_remote_post() as their own WP_Error.
	 */
	public static function run_report( string $property_id, array $body ) {
		if ( '' === trim( $property_id ) ) {
			return new \WP_Error(
				'invalid_property_id',
				__( 'A GA4 property ID is required to run a report.', 'newspack-plugin' )
			);
		}

		// Authoritative custom-dimension pre-check, before spending the API call.
		$requested = self::extract_custom_dimensions( $body );
		if ( ! empty( $requested ) ) {
			$registered = self::get_registered_parameter_names_cached( $property_id );
			// Only block on a definitive answer. If the registered list can't be
			// resolved (WP_Error), fall through and let the Data API respond — a
			// genuinely missing dimension surfaces as an API error rather than
			// silently suppressing the metric.
			if ( ! is_wp_error( $registered ) ) {
				$missing = array_values( array_diff( $requested, $registered ) );
				if ( ! empty( $missing ) ) {
					return new \WP_Error(
						'custom_dimension_missing',
						sprintf(
							/* translators: %s: comma-separated list of GA4 custom dimension parameter names. */
							__( 'GA4 custom dimension(s) not registered on this property: %s', 'newspack-plugin' ),
							implode( ', ', $missing )
						),
						[ 'dimensions' => $missing ]
					);
				}
			}
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			self::DATA_API_URL . '/' . rawurlencode( $property_id ) . ':runReport',
			[
				'timeout' => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'ga4_api_error',
				is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code,
				[ 'http_code' => $code ]
			);
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Run multiple GA4 Data API reports sequentially. Convenience wrapper for
	 * orchestrators that need several reports to compose one metric (ratios,
	 * multi-tuple geo filters, etc.). Each result is independent: a per-report
	 * failure is returned as a WP_Error in that slot, never aborting the batch.
	 *
	 * @param string $property_id The publisher's GA4 property ID.
	 * @param array  $bodies      Map of caller-chosen label => runReport request body.
	 *
	 * @return array Map of the same labels => (array response | WP_Error).
	 */
	public static function batch_run_reports( string $property_id, array $bodies ): array {
		$results = [];
		foreach ( $bodies as $key => $body ) {
			$results[ $key ] = self::run_report( $property_id, $body );
		}
		return $results;
	}

	/**
	 * Registered EVENT-scoped custom-dimension parameter names for a property,
	 * memoized for the duration of the request. The result (success or WP_Error)
	 * is cached so repeated reports in one request — including batch runs — make
	 * at most one Admin API lookup per property.
	 *
	 * @param string $property_id The GA4 property ID.
	 * @return string[]|\WP_Error
	 */
	private static function get_registered_parameter_names_cached( string $property_id ) {
		if ( ! array_key_exists( $property_id, self::$registered_dimensions_cache ) ) {
			// Pass the queried property through so the Admin API lookup matches the
			// Data API property — not whatever Site Kit happens to have configured.
			self::$registered_dimensions_cache[ $property_id ] = GA4_Custom_Dimensions::get_registered_parameter_names( $property_id );
		}
		return self::$registered_dimensions_cache[ $property_id ];
	}

	/**
	 * Resolve a Google access token from Newspack's existing OAuth connection.
	 *
	 * @return string|\WP_Error
	 */
	private static function get_access_token() {
		if ( ! class_exists( '\Newspack\Google_OAuth' ) ) {
			return new \WP_Error(
				'missing_dependency',
				__( 'The Newspack plugin is required for GA4 Data API access.', 'newspack-plugin' )
			);
		}
		if ( ! Google_OAuth::is_oauth_configured() ) {
			return new \WP_Error(
				'not_connected',
				__( 'Connect Google Analytics in Newspack → Connections to see this tab.', 'newspack-plugin' )
			);
		}
		$credentials = Google_OAuth::get_oauth2_credentials();
		if ( ! $credentials ) {
			return new \WP_Error(
				'not_connected',
				__( 'Connect Google Analytics in Newspack → Connections to see this tab.', 'newspack-plugin' )
			);
		}
		$token = $credentials->getAccessToken();
		if ( empty( $token ) ) {
			return new \WP_Error(
				'no_token',
				__( 'Google credentials found but the access token is empty. Reconnect in Newspack → Connections.', 'newspack-plugin' )
			);
		}
		return $token;
	}

	/**
	 * Collect the `customEvent:<param>` parameter names referenced anywhere in a
	 * runReport body — both in the `dimensions` array and inside the
	 * `dimensionFilter` expression tree. The latter matters because article-scope
	 * queries often reference `customEvent:post_id` only in a filter on an
	 * otherwise dimensionless report (e.g. a screenPageViews count).
	 *
	 * @param array $body The runReport request body.
	 * @return string[] Unique parameter names without the `customEvent:` prefix.
	 */
	private static function extract_custom_dimensions( array $body ): array {
		$names = [];

		foreach ( $body['dimensions'] ?? [] as $dimension ) {
			$name = $dimension['name'] ?? '';
			if ( self::is_custom_event_field( $name ) ) {
				$names[] = self::strip_custom_event_prefix( $name );
			}
		}

		if ( ! empty( $body['dimensionFilter'] ) && is_array( $body['dimensionFilter'] ) ) {
			$names = array_merge( $names, self::extract_from_filter_expression( $body['dimensionFilter'] ) );
		}

		// Drop empty names (e.g. a bare "customEvent:" with no parameter) so they
		// never reach the missing-dimension diff or the user-facing message.
		$names = array_filter(
			$names,
			function ( $name ) {
				return '' !== $name;
			}
		);

		return array_values( array_unique( $names ) );
	}

	/**
	 * Recursively walk a GA4 FilterExpression, collecting customEvent parameter
	 * names from leaf filters. Handles `filter`, `andGroup`, `orGroup`, and
	 * `notExpression` nodes.
	 *
	 * @param array $expression A GA4 FilterExpression node.
	 * @return string[] Parameter names without the `customEvent:` prefix.
	 */
	private static function extract_from_filter_expression( array $expression ): array {
		$names = [];

		$field = $expression['filter']['fieldName'] ?? '';
		if ( self::is_custom_event_field( $field ) ) {
			$names[] = self::strip_custom_event_prefix( $field );
		}

		foreach ( [ 'andGroup', 'orGroup' ] as $group ) {
			$expressions = $expression[ $group ]['expressions'] ?? [];
			if ( is_array( $expressions ) ) {
				foreach ( $expressions as $sub ) {
					if ( is_array( $sub ) ) {
						$names = array_merge( $names, self::extract_from_filter_expression( $sub ) );
					}
				}
			}
		}

		if ( ! empty( $expression['notExpression'] ) && is_array( $expression['notExpression'] ) ) {
			$names = array_merge( $names, self::extract_from_filter_expression( $expression['notExpression'] ) );
		}

		return $names;
	}

	/**
	 * Whether a GA4 field name references a custom event-scoped dimension.
	 *
	 * @param string $field A GA4 dimension/field name.
	 * @return bool Whether it is a customEvent:<param> reference.
	 */
	private static function is_custom_event_field( $field ): bool {
		return is_string( $field ) && 0 === strpos( $field, 'customEvent:' );
	}

	/**
	 * Strip the `customEvent:` prefix from a field name to get the bare param.
	 *
	 * @param string $field A `customEvent:<param>` field name.
	 * @return string The bare parameter name.
	 */
	private static function strip_custom_event_prefix( string $field ): string {
		return substr( $field, strlen( 'customEvent:' ) );
	}
}
