<?php
/**
 * Session Hydration.
 *
 * Bridges the gap between authentication and the next page load by providing
 * a fresh wp_rest nonce via a short-lived CID-to-user binding.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

use Newspack\Reader_Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Session Hydration class.
 */
final class Session_Hydration {

	/**
	 * Transient TTL in seconds (2 minutes).
	 */
	const TRANSIENT_TTL = 2 * MINUTE_IN_SECONDS;

	/**
	 * Transient key prefix.
	 */
	const TRANSIENT_PREFIX = 'newspack_cid_';

	/**
	 * The admin-ajax action that returns a nonce for the current session.
	 */
	const NONCE_ACTION = 'newspack_reader_nonce';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::NONCE_ACTION, [ __CLASS__, 'ajax_get_nonce' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'wp_login', [ __CLASS__, 'on_wp_login' ], 10, 2 );
		add_action( 'newspack_registered_reader_via_woo', [ __CLASS__, 'on_woo_customer_created' ], 10, 2 );
	}

	/**
	 * Bind CID on login.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       Authenticated user object.
	 */
	public static function on_wp_login( $user_login, $user ) {
		self::bind_cid( $user->ID );
	}

	/**
	 * Bind CID on WooCommerce customer creation.
	 *
	 * @param string $email   Email address.
	 * @param int    $user_id The created user id.
	 */
	public static function on_woo_customer_created( $email, $user_id ) {
		self::bind_cid( $user_id );
		// Set the auth reader cookie so the frontend listener can detect authentication
		// and trigger hydration. WooCommerce checkout bypasses wp_login, so this cookie
		// would not otherwise be set.
		$user = \get_userdata( $user_id );
		if ( $user ) {
			Reader_Activation::set_auth_reader_cookie( $user );
		}
	}

	/**
	 * Get the transient key for a CID value.
	 *
	 * Hashes the CID to prevent long or hostile cookie values from exceeding
	 * WordPress transient name limits.
	 *
	 * @param string $cid Client ID value.
	 *
	 * @return string Transient key.
	 */
	public static function get_transient_key( $cid ) {
		return self::TRANSIENT_PREFIX . md5( $cid );
	}

	/**
	 * Bind a CID cookie to a user ID via a short-lived transient.
	 *
	 * Call this after a user authenticates or an account is created.
	 *
	 * @param int $user_id The authenticated user's ID.
	 */
	public static function bind_cid( $user_id ) {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$cid = isset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ) ? sanitize_text_field( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ) : '';
		if ( empty( $cid ) ) {
			return;
		}
		set_transient( self::get_transient_key( $cid ), (int) $user_id, self::TRANSIENT_TTL );
	}

	/**
	 * Get a nonce for the current session and the user it belongs to.
	 *
	 * A nonce only authenticates whoever holds the cookie, so the user ID lets a
	 * page opened as one reader refuse a nonce for another. Without that check,
	 * a page left open after a second account logs in to the same browser could
	 * write to that account.
	 *
	 * @return array{nonce: string, user_id: int}
	 */
	public static function get_nonce_data(): array {
		return [
			'nonce'   => \wp_create_nonce( 'wp_rest' ),
			'user_id' => \get_current_user_id(),
		];
	}

	/**
	 * Send a nonce for the current session.
	 *
	 * This is an admin-ajax action rather than a REST route because admin-ajax only
	 * lets allowed origins read the response, where a REST route has to enforce that
	 * itself. Other sites must never be able to read a nonce.
	 */
	public static function ajax_get_nonce(): void {
		\wp_send_json( self::get_nonce_data() );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		if ( ! Reader_Activation::is_enabled() ) {
			return;
		}
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/reader/session',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'handle_hydration' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
			]
		);
	}

	/**
	 * Permission callback for the hydration endpoint.
	 *
	 * This endpoint cannot rely on X-WP-Nonce for authentication since
	 * providing a nonce is its purpose. Instead, it validates the user
	 * directly from auth cookies. It also refuses other origins, since no nonce
	 * protects it and it returns a nonce and the reader's data.
	 *
	 * @return true|\WP_Error
	 */
	public static function permission_callback() {
		$origin = \get_http_origin();
		if ( $origin && ! \is_allowed_http_origin( $origin ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Origin not allowed.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}

		$user_id = \wp_validate_auth_cookie( '', 'logged_in' );
		if ( ! $user_id ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'newspack-plugin' ),
				[ 'status' => 401 ]
			);
		}
		\wp_set_current_user( $user_id );
		if ( ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Reader account required.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Handle session hydration request.
	 *
	 * Validates the CID-to-user binding and returns a fresh wp_rest nonce.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_hydration() {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$cid = isset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ) ? sanitize_text_field( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ) : '';
		if ( empty( $cid ) ) {
			return new \WP_Error(
				'newspack_session_invalid',
				__( 'Invalid session.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}

		$transient_key  = self::get_transient_key( $cid );
		$stored_user_id = get_transient( $transient_key );

		if ( false === $stored_user_id || (int) $stored_user_id !== \get_current_user_id() ) {
			return new \WP_Error(
				'newspack_session_invalid',
				__( 'Invalid session.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}

		$data = self::get_nonce_data();

		/**
		 * Filters the session hydration response data.
		 *
		 * Allows other components to attach read-only data to the hydration
		 * response so the frontend can initialize state without additional
		 * requests.
		 *
		 * IMPORTANT: This endpoint authenticates via cookies without a CSRF
		 * nonce (providing a nonce is its purpose), so a cross-site request can
		 * trigger it. Callbacks MUST NOT write anything derived from the request.
		 * The one write allowed is recomputing server-derived data for the
		 * authenticated user from server state alone, which a forged request can
		 * only make correct (see Gate_Access_Reader_Data and
		 * Active_Subscriptions_Reader_Data).
		 *
		 * @param array $data    Response data containing 'nonce' and 'user_id'.
		 * @param int   $user_id The authenticated user's ID.
		 */
		$data = apply_filters( 'newspack_session_hydration_response', $data, \get_current_user_id() );

		// One-time use: delete the transient after assembling the full response
		// so it is not burned if a filter callback throws.
		delete_transient( $transient_key );

		return new \WP_REST_Response( $data );
	}
}
Session_Hydration::init();
