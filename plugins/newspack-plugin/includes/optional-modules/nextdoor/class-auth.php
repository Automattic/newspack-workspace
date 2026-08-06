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
	 * Transient prefix for the pending OAuth state value, one per user.
	 */
	const STATE_TRANSIENT_PREFIX = 'newspack_nextdoor_oauth_state_';

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
	 * @param string $redirect_uri Redirect URI, which must match the one the authorization was requested with.
	 * @return array|\WP_Error Token data, or an error if the grant failed or carried no token.
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
				isset( $error_data['error_description'] ) ? $error_data['error_description'] : __( 'OAuth error', 'newspack-plugin' ),
				[ 'status' => $code ]
			);
		}

		return self::parse_token_response( $body );
	}

	/**
	 * Refresh access token.
	 *
	 * A successful refresh replaces the stored token; anything else leaves it alone.
	 *
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $refresh_token Refresh token issued alongside the access token.
	 * @return array|\WP_Error Token data, or an error if the refresh failed or carried no token.
	 */
	public static function refresh_access_token( $client_id, $client_secret, $refresh_token ) {
		$body = [
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
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
				isset( $error_data['error_description'] ) ? $error_data['error_description'] : __( 'Token refresh error', 'newspack-plugin' ),
				[ 'status' => $code ]
			);
		}

		$token_data = self::parse_token_response( $body );

		if ( is_wp_error( $token_data ) ) {
			return $token_data;
		}

		Nextdoor::update_settings( self::apply_token_response( Nextdoor::get_settings(), $token_data ) );

		return $token_data;
	}

	/**
	 * Read a token payload out of an OAuth response body.
	 *
	 * A 200 is not a grant: the body can be malformed, or carry an error object. Both
	 * decode to something without a token, and storing that would replace a working
	 * connection with nothing.
	 *
	 * @param string $body Raw response body.
	 * @return array|\WP_Error Token data, or an error if the body carries no access token.
	 */
	private static function parse_token_response( $body ) {
		$token_data = json_decode( $body, true );

		if ( ! is_array( $token_data ) || empty( $token_data['access_token'] ) || ! is_string( $token_data['access_token'] ) ) {
			return new \WP_Error(
				'nextdoor_oauth_invalid_response',
				__( 'Nextdoor did not return an access token. Please try connecting again.', 'newspack-plugin' ),
				[ 'status' => 502 ]
			);
		}

		return $token_data;
	}

	/**
	 * Apply a token payload to a settings array.
	 *
	 * @param array $settings   Nextdoor settings.
	 * @param array $token_data Token data from a grant or refresh.
	 * @return array Settings carrying the new token.
	 */
	private static function apply_token_response( $settings, $token_data ) {
		$settings['access_token']     = $token_data['access_token'];
		$settings['token_expires_at'] = isset( $token_data['expires_in'] ) && is_numeric( $token_data['expires_in'] ) ? time() + (int) $token_data['expires_in'] : 0;

		// Nextdoor rotates the refresh token, and omits it when it has not changed.
		if ( ! empty( $token_data['refresh_token'] ) && is_string( $token_data['refresh_token'] ) ) {
			$settings['refresh_token'] = $token_data['refresh_token'];
		}

		return $settings;
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
	 * Create and store a single-use OAuth state value for the current user.
	 *
	 * @return string State value to send to Nextdoor.
	 */
	public static function create_oauth_state() {
		$state = wp_generate_password( 32, false );

		set_transient( self::STATE_TRANSIENT_PREFIX . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

		return $state;
	}

	/**
	 * Consume the stored OAuth state and compare it with the returned one.
	 *
	 * @param string $state State returned by Nextdoor.
	 * @return bool
	 */
	public static function verify_oauth_state( $state ) {
		$key    = self::STATE_TRANSIENT_PREFIX . get_current_user_id();
		$stored = get_transient( $key );

		delete_transient( $key );

		if ( ! is_string( $state ) || ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, $state );
	}

	/**
	 * Redirect back to the settings screen carrying an error message.
	 *
	 * @param string $message Message to show.
	 * @return void
	 */
	private static function redirect_with_error( $message ) {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'                 => 'newspack-settings',
					'nextdoor_oauth_error' => rawurlencode( $message ),
				],
				admin_url( 'admin.php' )
			) . '#social'
		);
		exit;
	}

	/**
	 * Decide whether an OAuth callback may be acted on.
	 *
	 * The callback fires on `init`, ahead of the admin's own authentication and on every
	 * front-end request too. Without the capability gate anyone could hand the site an
	 * authorization code and have it bound to the publisher's integration; it runs first
	 * so that nobody else can consume the state this site issued.
	 *
	 * @param string $state State returned by Nextdoor.
	 * @return true|\WP_Error True when the callback belongs to this administrator.
	 */
	public static function authorize_oauth_callback( $state ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'nextdoor_oauth_forbidden',
				__( 'You are not allowed to connect a Nextdoor account.', 'newspack-plugin' )
			);
		}

		if ( ! self::verify_oauth_state( $state ) ) {
			return new \WP_Error(
				'nextdoor_oauth_invalid_state',
				__( 'This Nextdoor connection could not be verified. Please try connecting again.', 'newspack-plugin' )
			);
		}

		return true;
	}

	/**
	 * Handle OAuth callback.
	 *
	 * @return void
	 */
	public static function handle_oauth_callback() {
		if ( ! isset( $_GET['nextdoor_oauth_callback'] ) || ! isset( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$authorized = self::authorize_oauth_callback( $state );

		if ( is_wp_error( $authorized ) ) {
			// Whoever this is has no settings screen to be sent back to.
			if ( 'nextdoor_oauth_forbidden' === $authorized->get_error_code() ) {
				return;
			}

			self::redirect_with_error( $authorized->get_error_message() );
		}

		$settings = Nextdoor::get_settings();

		if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			self::redirect_with_error( __( 'Nextdoor client credentials not configured.', 'newspack-plugin' ) );
		}

		$token_response = self::get_access_token(
			$settings['client_id'],
			$settings['client_secret'],
			$code,
			// The token exchange has to repeat the redirect URI the authorization was
			// requested with, state included, or the grant is refused.
			Nextdoor::get_redirect_uri( $state )
		);

		if ( is_wp_error( $token_response ) ) {
			self::redirect_with_error( $token_response->get_error_message() );
		}

		Nextdoor::update_settings( self::apply_token_response( $settings, $token_response ) );

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
	 * Whether the connection still holds, without calling Nextdoor.
	 *
	 * Reports what `validate_token()` would, read off the stored token: a token that is
	 * not near expiry is usable, and one that is can be renewed as long as a refresh
	 * token was stored with it. False still means the publisher has to reconnect.
	 *
	 * @return bool
	 */
	public static function has_usable_token() {
		$settings = Nextdoor::get_settings();

		if ( empty( $settings['access_token'] ) ) {
			return false;
		}

		if ( ! self::needs_token_refresh() ) {
			return true;
		}

		return ! empty( $settings['refresh_token'] );
	}

	/**
	 * Validate and refresh token if needed.
	 *
	 * Refreshing is a blocking remote call, so callers that only report on the connection
	 * should use `has_usable_token()` instead.
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

		if ( empty( $settings['refresh_token'] ) ) {
			return false;
		}

		// Refresh the token.
		$refresh_response = self::refresh_access_token(
			$settings['client_id'],
			$settings['client_secret'],
			$settings['refresh_token']
		);

		return ! is_wp_error( $refresh_response );
	}
}

Auth::init();
