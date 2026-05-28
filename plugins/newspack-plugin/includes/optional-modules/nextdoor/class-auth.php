<?php
/**
 * Nextdoor OAuth authentication.
 *
 * @package Newspack
 */

namespace Newspack\Nextdoor;

use Newspack\Nextdoor;

defined( 'ABSPATH' ) || exit;

/**
 * Nextdoor OAuth authentication class.
 */
class Auth {
	/**
	 * OAuth base URL.
	 */
	const OAUTH_BASE_URL = 'https://auth.nextdoor.com';

	/**
	 * Initialise.
	 */
	public static function init() {
		add_action( 'init', [ self::class, 'handle_oauth_callback' ] );
	}

	/**
	 * Exchange authorization code for access token.
	 *
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $code Authorization code.
	 * @param string $redirect_uri Redirect URI.
	 * @return array|WP_Error
	 */
	public static function get_access_token( $client_id, $client_secret, $code, $redirect_uri ) {
		$body = [
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'client_id'    => $client_id,
			'redirect_uri' => $redirect_uri,
		];

		$response = wp_safe_remote_post(
			self::OAUTH_BASE_URL . '/v2/token',
			[
				'body'    => $body,
				'timeout' => 30, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
				'headers' => [
					'accept'        => 'application/json',
					'content-type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$error_data = json_decode( $body, true );
			return new \WP_Error(
				'nextdoor_oauth_error',
				isset( $error_data['error_description'] ) ? $error_data['error_description'] : 'OAuth error',
				[ 'status' => $code ]
			);
		}

		return json_decode( $body, true );
	}

	/**
	 * Refresh access token.
	 *
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $access_token Access token.
	 * @return array|WP_Error
	 */
	public static function refresh_access_token( $client_id, $client_secret, $access_token ) {
		$body = [
			'grant_type'    => 'refresh_token',
			'refresh_token' => $access_token,
			'scope'         => implode( ' ', self::get_access_scopes() ),
		];

		$response = wp_safe_remote_post(
			self::OAUTH_BASE_URL . '/v2/token',
			[
				'body'    => $body,
				'timeout' => 30, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
				'headers' => [
					'accept'        => 'application/json',
					'content-type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$error_data = json_decode( $body, true );
			return new \WP_Error(
				'nextdoor_oauth_refresh_error',
				isset( $error_data['error_description'] ) ? $error_data['error_description'] : 'Token refresh error',
				[ 'status' => $code ]
			);
		}

		$token_data = json_decode( $body, true );

		// Update stored settings with new token.
		$settings                     = Nextdoor::get_settings();
		$settings['access_token']     = $token_data['access_token'];
		$settings['token_expires_at'] = time() + $token_data['expires_in'];

		Nextdoor::update_settings( $settings );

		return $token_data;
	}

	/**
	 * Get OAuth access scopes.
	 *
	 * @return array
	 */
	public static function get_access_scopes() {
		$scopes = [
			'content_api',
			'openid',
			'publish_api',
			'entity_page:claim',
			'profile',
			'profile:read',
			'article:write',
			'post:read',
			'post:write',
		];

		/**
		 * Filter Nextdoor OAuth access scopes.
		 * Recommended: Use this filter only if you are familiar with Nextdoor's OAuth requirements.
		 * Removing or altering existing scopes may cause the integration to break.
		 * See: https://developer.nextdoor.com/reference/sharing-get-authorization-code#authorization-code
		 *
		 * @param array $scopes Array of access scopes.
		 */
		return apply_filters( 'newspack_nextdoor_oauth_scopes', $scopes );
	}

	/**
	 * Handle OAuth callback.
	 */
	public static function handle_oauth_callback() {
		if ( ! isset( $_GET['nextdoor_oauth_callback'] ) || ! isset( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$settings = Nextdoor::get_settings();

		if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			wp_safe_redirect( 
				add_query_arg(
					[
						'page'                 => 'newspack-settings',
						'nextdoor_oauth_error' => rawurlencode( __( 'Nextdoor client credentials not configured.', 'newspack-plugin' ) ),
					],
					admin_url( 'admin.php' )
				) . '#social'
			);
			exit;
		}

		$redirect_uri = Nextdoor::get_redirect_uri();

		$token_response = self::get_access_token(
			$settings['client_id'],
			$settings['client_secret'],
			$code,
			$redirect_uri
		);

		if ( is_wp_error( $token_response ) ) {
			wp_safe_redirect( 
				add_query_arg(
					[
						'page'                 => 'newspack-settings',
						'nextdoor_oauth_error' => rawurlencode( $token_response->get_error_message() ),
					],
					admin_url( 'admin.php' )
				) . '#social'
			);
			exit;
		}

		$settings['access_token']     = $token_response['access_token'];
		$settings['token_expires_at'] = time() + $token_response['expires_in'];

		Nextdoor::update_settings( $settings );

		wp_safe_redirect( admin_url( 'admin.php?page=newspack-settings&oauth_success=1#social' ) );
		exit;
	}

	/**
	 * Check if token needs refresh.
	 *
	 * @return bool
	 */
	public static function needs_token_refresh() {
		$settings = Nextdoor::get_settings();

		if ( empty( $settings['token_expires_at'] ) ) {
			return false;
		}

		return ( $settings['token_expires_at'] - 300 ) < time();
	}

	/**
	 * Validate and refresh token if needed.
	 *
	 * @return bool
	 */
	public static function validate_token() {
		$settings = Nextdoor::get_settings();

		if ( empty( $settings['access_token'] ) ) {
			return false;
		}

		// Check if token needs refresh.
		if ( ! self::needs_token_refresh() ) {
			return true; // Token is still valid.
		}

		// Refresh the token.
		$refresh_response = self::refresh_access_token(
			$settings['client_id'],
			$settings['client_secret'],
			$settings['access_token']
		);

		return ! is_wp_error( $refresh_response );
	}
}

Auth::init();
