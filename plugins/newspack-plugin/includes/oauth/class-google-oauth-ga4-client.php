<?php
/**
 * GA4 Admin API client backed by Newspack's Google OAuth credentials.
 *
 * Mirrors the subset of the GA4 Admin API surface used by
 * GA4_Custom_Dimensions (list + create custom dimensions), but calls
 * analyticsadmin.googleapis.com directly with a Bearer token obtained
 * through Newspack's own OAuth proxy rather than delegating to Site Kit.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack-OAuth-backed GA4 Admin API client.
 */
final class Google_OAuth_GA4_Client {
	const BASE_URL = 'https://analyticsadmin.googleapis.com/v1beta';

	/**
	 * OAuth scope required to create GA4 custom dimensions via the Admin API.
	 */
	const EDIT_SCOPE = 'https://www.googleapis.com/auth/analytics.edit';

	/**
	 * OAuth access token.
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Constructor.
	 *
	 * @param string $access_token OAuth access token.
	 */
	private function __construct( $access_token ) {
		$this->access_token = $access_token;
	}

	/**
	 * Build a client using Newspack's saved Google OAuth credentials.
	 *
	 * Returns null if the OAuth proxy is not configured, no credentials are
	 * saved, or the token can't be resolved. Callers should treat null as a
	 * signal to fall back to another auth route.
	 *
	 * @return self|null
	 */
	public static function build() {
		if ( ! class_exists( __NAMESPACE__ . '\\Google_OAuth' ) ) {
			return null;
		}
		if ( ! Google_OAuth::is_oauth_configured() ) {
			return null;
		}
		$credentials = Google_OAuth::get_oauth2_credentials();
		if ( ! $credentials ) {
			return null;
		}
		$token = $credentials->getAccessToken();
		if ( empty( $token ) ) {
			return null;
		}
		return new self( $token );
	}

	/**
	 * Whether Newspack's stored Google OAuth token currently carries the
	 * `analytics.edit` scope. Tokens issued before that scope was added to
	 * Google_OAuth::REQUIRED_SCOPES, or after a publisher revoked it, will not –
	 * in which case the Admin API rejects writes with a 403 and callers should
	 * fall back to another auth route rather than this client.
	 *
	 * @return bool
	 */
	public static function has_edit_scope() {
		return class_exists( __NAMESPACE__ . '\\Google_OAuth' )
			&& Google_OAuth::token_has_scope( self::EDIT_SCOPE );
	}

	/**
	 * List all custom dimensions on a GA4 property.
	 *
	 * @param string $property_id GA4 property ID (numeric, no "properties/" prefix).
	 * @return array<int,array{name:string,parameterName:string,displayName:string,scope:string}>
	 * @throws \RuntimeException On HTTP or API error.
	 */
	public function list_custom_dimensions( $property_id ) {
		$dimensions = [];
		$page_token = null;
		do {
			$url = self::BASE_URL . '/properties/' . rawurlencode( $property_id ) . '/customDimensions';
			if ( $page_token ) {
				$url = add_query_arg( 'pageToken', $page_token, $url );
			}
			$body  = $this->request( 'GET', $url );
			$items = isset( $body['customDimensions'] ) && is_array( $body['customDimensions'] ) ? $body['customDimensions'] : [];
			foreach ( $items as $dimension ) {
				$dimensions[] = [
					'name'          => isset( $dimension['name'] ) ? $dimension['name'] : '',
					'parameterName' => isset( $dimension['parameterName'] ) ? $dimension['parameterName'] : '',
					'displayName'   => isset( $dimension['displayName'] ) ? $dimension['displayName'] : '',
					'scope'         => isset( $dimension['scope'] ) ? $dimension['scope'] : '',
				];
			}
			$page_token = isset( $body['nextPageToken'] ) ? $body['nextPageToken'] : null;
		} while ( $page_token );
		return $dimensions;
	}

	/**
	 * Create an event-scoped custom dimension on a GA4 property.
	 *
	 * @param string $property_id    GA4 property ID.
	 * @param string $parameter_name Event parameter name.
	 * @param string $display_name   Display name shown in the GA4 UI.
	 * @return array Decoded API response.
	 * @throws \RuntimeException On HTTP or API error.
	 */
	public function create_custom_dimension( $property_id, $parameter_name, $display_name ) {
		$url     = self::BASE_URL . '/properties/' . rawurlencode( $property_id ) . '/customDimensions';
		$payload = [
			'parameterName' => $parameter_name,
			'displayName'   => $display_name,
			'scope'         => 'EVENT',
		];
		return $this->request( 'POST', $url, $payload );
	}

	/**
	 * Issue an authenticated request to the Analytics Admin API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    Full URL.
	 * @param array|null $body   Optional JSON body.
	 * @return array Decoded response.
	 * @throws \RuntimeException On HTTP or API error.
	 */
	private function request( $method, $url, $body = null ) {
		$args = [
			'method'  => $method,
			'timeout' => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			'headers' => [
				'Authorization' => 'Bearer ' . $this->access_token,
				'Accept'        => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}
		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $decoded ) && isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: 'HTTP ' . $code;
			throw new \RuntimeException( esc_html( "Analytics Admin API error ($code): $message" ) );
		}
		return is_array( $decoded ) ? $decoded : [];
	}
}
