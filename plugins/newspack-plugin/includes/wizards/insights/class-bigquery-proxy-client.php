<?php
/**
 * Newspack Insights — BigQuery Proxy Client.
 *
 * Wraps `wp_remote_post` to call the hub's `bigquery-query` REST endpoint.
 * Handles auth (`Newspack_Manager::authenticate_manager_admin_url()`),
 * User-Agent formatting (so the hub can identify the calling site),
 * date conversion (`DateTimeInterface` -> `Ymd` for GA4 daily-shard suffix),
 * and error wrapping (returns `WP_Error` on every failure path).
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use DateTimeInterface;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the hub's BigQuery proxy for catalog queries.
 */
class BigQuery_Proxy_Client {

	/**
	 * The hub endpoint URL with `api_key` already appended, or null if not configured.
	 *
	 * @var string|null
	 */
	private ?string $endpoint_url;

	/**
	 * The hub api key, used as a configuration sentinel (the URL itself includes it).
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 30;

	/**
	 * Logger header so all BQ proxy failures land in one Logstash bucket.
	 *
	 * @var string
	 */
	const LOGGER_HEADER = 'NEWSPACK-INSIGHTS-BIGQUERY';

	/**
	 * Constructor.
	 *
	 * Resolves the hub URL via `Newspack_Manager::authenticate_manager_admin_url()`
	 * when not explicitly provided. Pass explicit values in tests.
	 *
	 * @param string|null $endpoint_url Pre-authenticated hub URL (with api_key). Null to resolve at construction.
	 * @param string|null $api_key      The hub API key. Null to resolve at construction.
	 */
	public function __construct( ?string $endpoint_url = null, ?string $api_key = null ) {
		if ( null === $endpoint_url || null === $api_key ) {
			[ $resolved_url, $resolved_key ] = self::resolve_config();
			$endpoint_url                    = $endpoint_url ?? $resolved_url;
			$api_key                         = $api_key ?? $resolved_key;
		}
		$this->endpoint_url = '' === (string) $endpoint_url ? null : (string) $endpoint_url;
		$this->api_key      = (string) $api_key;
	}

	/**
	 * Resolve the hub URL and api key from the running `Newspack_Manager` plugin.
	 *
	 * @return array{0: string|null, 1: string} `[ $url, $api_key ]`. URL is null if Manager isn't loaded or isn't connected.
	 */
	private static function resolve_config(): array {
		if ( ! class_exists( '\Newspack_Manager' ) ) {
			return [ null, '' ];
		}
		$url     = \Newspack_Manager::authenticate_manager_admin_url( '/wp-json/newspack-manager-admin/v1/bigquery-query' );
		$api_key = \Newspack_Manager::get_manager_admin_api_key();
		return [ false === $url ? null : (string) $url, (string) $api_key ];
	}

	/**
	 * Whether the client has both a URL and a key (i.e. can attempt a call).
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return null !== $this->endpoint_url && '' !== $this->api_key;
	}

	/**
	 * Emit a `newspack_log` action with context about a proxy failure.
	 *
	 * Picked up by Newspack Manager and forwarded to Logstash. Safe to call
	 * even when Logger isn't loaded (no-op).
	 *
	 * @param WP_Error          $error      The error being returned to the caller.
	 * @param string            $query_name The catalog query that failed.
	 * @param DateTimeInterface $start      Window start.
	 * @param DateTimeInterface $end        Window end.
	 * @return void
	 */
	private function log_failure( WP_Error $error, string $query_name, DateTimeInterface $start, DateTimeInterface $end ): void {
		if ( ! class_exists( '\Newspack\Logger' ) ) {
			return;
		}
		\Newspack\Logger::newspack_log(
			'newspack_insights_bigquery_proxy_failed',
			sprintf(
				'[%s] %s: %s',
				$query_name,
				$error->get_error_code(),
				$error->get_error_message()
			),
			[
				'query_name'  => $query_name,
				'error_code'  => $error->get_error_code(),
				'start_date'  => $start->format( 'Ymd' ),
				'end_date'    => $end->format( 'Ymd' ),
				'http_status' => $error->get_error_data()['status'] ?? null,
				'header'      => self::LOGGER_HEADER,
			],
			'error'
		);
	}

	/**
	 * Dispatch a catalog query against the hub. Returns rows on success, `WP_Error` on any failure path.
	 *
	 * Dates are formatted as `YYYYMMDD` in UTC (the GA4 daily-shard partition convention).
	 * Non-UTC inputs are converted before formatting; the caller's `$start`/`$end` are not mutated.
	 *
	 * @param string            $query_name Allowlisted catalog name (e.g. `gates_total_impressions`).
	 * @param DateTimeInterface $start      Window start (Ymd format applied by this method).
	 * @param DateTimeInterface $end        Window end.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function query( string $query_name, DateTimeInterface $start, DateTimeInterface $end ) {
		if ( ! $this->is_configured() ) {
			$error = new WP_Error(
				'bigquery_proxy_not_configured',
				__( 'Newspack Manager is not configured for BigQuery proxy calls.', 'newspack-plugin' )
			);
			$this->log_failure( $error, $query_name, $start, $end );
			return $error;
		}

		$utc = new \DateTimeZone( 'UTC' );

		$body = wp_json_encode(
			[
				'query_name' => $query_name,
				'start_date' => \DateTimeImmutable::createFromInterface( $start )->setTimezone( $utc )->format( 'Ymd' ),
				'end_date'   => \DateTimeImmutable::createFromInterface( $end )->setTimezone( $utc )->format( 'Ymd' ),
			]
		);

		$response = wp_remote_post(
			$this->endpoint_url,
			[
				'headers' => [
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Newspack-Insights/1.0; ' . home_url(),
				],
				'body'    => $body,
				'timeout' => self::REQUEST_TIMEOUT,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->log_failure( $response, $query_name, $start, $end );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( ! json_validate( $raw ) ) {
			$error = new WP_Error(
				'bigquery_proxy_invalid_response',
				sprintf(
					/* translators: %d HTTP status. */
					__( 'BigQuery proxy returned an undecodable response (HTTP %d).', 'newspack-plugin' ),
					$code
				)
			);
			$this->log_failure( $error, $query_name, $start, $end );
			return $error;
		}
		$decoded = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$error_code    = is_array( $decoded ) && isset( $decoded['code'] ) ? (string) $decoded['code'] : 'bigquery_proxy_http_error';
			$error_message = is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : sprintf( 'HTTP %d', $code );
			$error         = new WP_Error( $error_code, $error_message, [ 'status' => $code ] );
			$this->log_failure( $error, $query_name, $start, $end );
			return $error;
		}

		if ( ! is_array( $decoded ) ) {
			$error = new WP_Error(
				'bigquery_proxy_invalid_response',
				__( 'BigQuery proxy returned a non-array success body.', 'newspack-plugin' )
			);
			$this->log_failure( $error, $query_name, $start, $end );
			return $error;
		}

		return $decoded;
	}
}
