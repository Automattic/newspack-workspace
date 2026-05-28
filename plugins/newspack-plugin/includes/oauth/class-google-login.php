<?php
/**
 * Newspack's Google Login handling.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Google Login flow.
 */
class Google_Login {
	const EMAIL_TRANSIENT_PREFIX = 'newspack_google_email_';
	const AUTH_CALLBACK          = 'newspack_google_login_callback';
	const CSRF_TOKEN_NAMESPACE   = 'google_login';

	const REQUIRED_SCOPES = [
		'https://www.googleapis.com/auth/userinfo.email', // User's email address.
	];

	/**
	 * Constructor.
	 *
	 * @codeCoverageIgnore
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_api_endpoints' ] );
		add_action( 'template_redirect', [ __CLASS__, 'oauth_callback' ] );
	}

	/**
	 * Register the endpoints.
	 *
	 * @codeCoverageIgnore
	 */
	public static function register_api_endpoints() {
		// Get Google login status.
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/login/google/register',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_google_login_register' ],
				'permission_callback' => [ __CLASS__, 'api_check_if_oauth_configured' ],
				'args'                => [
					'metadata' => [
						'required' => false,
					],
				],
			]
		);
		// Start Google login flow.
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/login/google',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_google_auth_get_url' ],
				'permission_callback' => [ __CLASS__, 'api_check_if_oauth_configured' ],
			]
		);
	}

	/**
	 * Check capabilities for using API.
	 *
	 * @codeCoverageIgnore
	 * @return bool|WP_Error
	 */
	public static function api_check_if_oauth_configured() {
		if ( ! Google_OAuth::is_oauth_configured() ) {
			self::handle_error( __( 'Google OAuth is not configured.', 'newspack-plugin' ) );
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-plugin' ),
				[
					'status' => 403,
				]
			);
		}
		return true;
	}

	/**
	 * Start the Google OAuth2 flow by obtaining a URL to the Google consent screen.
	 *
	 * @return WP_REST_Response Response with the URL.
	 */
	public static function api_google_auth_get_url() {
		$csrf_token = OAuth::generate_csrf_token( self::CSRF_TOKEN_NAMESPACE );
		if ( $csrf_token === false ) {
			/* translators: %s is the unique id of the visitor. */
			self::handle_error( sprintf( __( 'Failed to save the CSRF token for id: %s', 'newspack-plugin' ), OAuth::get_unique_id() ) );
			return new WP_Error( 'newspack_google_login', __( 'Failed to save the CSRF token.', 'newspack-plugin' ) );
		}
		$url = Google_OAuth::google_auth_get_url(
			[
				'csrf_token'     => $csrf_token,
				'scope'          => implode( ' ', self::REQUIRED_SCOPES ),
				'redirect_after' => add_query_arg( self::AUTH_CALLBACK, wp_create_nonce( self::AUTH_CALLBACK ), get_home_url() ),
			]
		);
		if ( is_wp_error( $url ) ) {
			/* translators: %s is the error message */
			self::handle_error( sprintf( __( 'Failed to get Google OAuth URL: %s', 'newspack-plugin' ), $url->get_error_message() ), [ 'csrf_token' => $csrf_token ] );
			return $url;
		}
		return rest_ensure_response( $url );
	}

	/**
	 * OAuth callback.
	 */
	public static function oauth_callback() {
		if ( ! isset( $_GET[ self::AUTH_CALLBACK ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( $_GET[ self::AUTH_CALLBACK ] ), self::AUTH_CALLBACK ) ) {
			/* translators: %s is a unique user id */
			self::handle_error( sprintf( __( 'Nonce verification failed for id: %s', 'newspack-plugin' ), OAuth::get_unique_id() ) );
			wp_die( esc_html__( 'Invalid nonce.', 'newspack-plugin' ) );
			return;
		}

		if ( ! isset( $_REQUEST['csrf_token'] ) || ! isset( $_REQUEST['access_token'] ) ) {
			self::handle_error( __( 'CSRF token or access token missing.', 'newspack-plugin' ) );
			wp_die( esc_html__( 'Invalid request', 'newspack-plugin' ) );
			return;
		}

		$saved_csrf_token = OAuth::retrieve_csrf_token( self::CSRF_TOKEN_NAMESPACE );

		if ( $_REQUEST['csrf_token'] !== $saved_csrf_token ) {
			self::handle_error(
				/* translators: %s is a unique user id */
				sprintf( __( 'CSRF token verification failed for id: %s', 'newspack-plugin' ), OAuth::get_unique_id() ),
				[
					'saved_csrf_token'   => $saved_csrf_token,
					'request_csrf_token' => sanitize_text_field( $_REQUEST['csrf_token'] ),
				]
			);
			\wp_die( \esc_html__( 'Authentication failed.', 'newspack-plugin' ) );
		}

		$user_email = Google_OAuth::validate_token_and_get_email_address( sanitize_text_field( $_REQUEST['access_token'] ), self::REQUIRED_SCOPES );
		if ( is_wp_error( $user_email ) ) {
			self::handle_error(
				/* translators: %s is the error message */
				sprintf( __( 'Failed validating user: %s', 'newspack-plugin' ), $user_email->get_error_message() ),
				[
					'email'      => $user_email,
					'csrf_token' => $saved_csrf_token,
				]
			);
			\wp_die( \esc_html__( 'Authentication failed.', 'newspack-plugin' ) );
		}

		Logger::log( 'Got user email from Google: ' . $user_email );

		// Associate the email address with the a unique ID for later retrieval.
		$uid = OAuth::get_unique_id();
		$set_transient_result = OAuth_Transients::set( $uid, 'email', $user_email );
		// If transient setting failed, the email address will not be available for the registration endpoint.
		if ( $set_transient_result === false ) {
			self::handle_error(
				/* translators: %s is a unique user id */
				sprintf( __( 'Failed setting email transient for id: %s', 'newspack-plugin' ), $uid ),
				[
					'email'      => $user_email,
					'csrf_token' => $saved_csrf_token,
				]
			);
			\wp_die( \esc_html__( 'Authentication failed.', 'newspack-plugin' ) );
		}

		/** Close window if it's a popup. */
		?>
		<script type="text/javascript" data-amp-plus-allowed>
			// Debug logging function that uses the centralized logging from newspackReaderActivation.
			const debugLog = function( level ) {
				if ( window.opener && window.opener.newspackReaderActivation && window.opener.newspackReaderActivation.debugLog ) {
					window.opener.newspackReaderActivation.debugLog.apply( null, [level].concat( Array.prototype.slice.call( arguments, 1 ) ) );
				}
			};

			debugLog('log', '[Google OAuth Callback] Script running, window.opener:', window.opener);
			if ( window.opener ) {
				// Use postMessage for cross-origin communication (handles COOP restrictions).
				try {
					debugLog('log', '[Google OAuth Callback] Sending postMessage to origin:', window.location.origin);
					window.opener.postMessage( 'google-oauth-success', window.location.origin );
					debugLog('log', '[Google OAuth Callback] postMessage sent successfully');
				} catch ( e ) {
					debugLog('error', '[Google OAuth Callback] postMessage failed:', e);
					// Fallback: postMessage might fail in some edge cases.
				}

				debugLog('log', '[Google OAuth Callback] Closing window');
				window.close();
			} else {
				debugLog('log', '[Google OAuth Callback] No window.opener found');
			}
		</script>
		<?php
	}

	/**
	 * Handle issue.
	 *
	 * @param string $message The message to log.
	 * @param string $error_data Additional data to log.
	 */
	private static function handle_error( $message, $error_data = [] ) {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		$data = [
			'uid'          => OAuth::get_unique_id(),
			'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : 'N/A',
			'referrer'     => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url( $_SERVER['HTTP_REFERER'] ) : 'N/A',
			'request_time' => isset( $_SERVER['REQUEST_TIME'] ) ? gmdate( 'Y-m-d\TH:i:s', intval( $_SERVER['REQUEST_TIME'] ) ) : 'N/A',
		];
		// phpcs:enable
		$data = array_merge( $data, $error_data );

		Logger::error(
			sprintf(
				// Translators: %1$s is the error message, %2$s is the user agent.
				__( '%1$s | Details: %2$s', 'newspack-plugin' ),
				$message,
				\wp_json_encode( $data, JSON_PRETTY_PRINT )
			)
		);
		do_action( 'newspack_google_login_error', new WP_Error( 'newspack_google_login', $message ) );

		do_action(
			'newspack_log',
			'newspack_google_login',
			$message,
			[
				'type'       => 'error',
				'data'       => $data,
				'user_email' => $data['email'] ?? '',
				'file'       => 'newspack_google_login',
			]
		);
	}

	/**
	 * Get Google authentication status.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function api_google_login_register( $request ) {
		$uid = OAuth::get_unique_id();
		// Retrieve the email address associated with the unique ID when the user was authenticated.
		$email    = OAuth_Transients::get( $uid, 'email' );
		$metadata = [];
		if ( $request->get_param( 'metadata' ) ) {
			try {
				$metadata = json_decode( $request->get_param( 'metadata' ), true );
			} catch ( \Throwable $th ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fail silently.
			}
		}
		if ( ! isset( $metadata['current_page_url'] ) ) {
			$referrer = $request->get_header( 'referer' );
			if ( \wp_http_validate_url( $referrer ) ) {
				$metadata['current_page_url'] = $referrer;
			}
		}
		if ( $email ) {
			do_action(
				'newspack_log',
				'newspack_google_login',
				'Google Login Success',
				[
					'type'       => 'debug',
					'data'       => [
						'uid' => OAuth::get_unique_id(),
					],
					'user_email' => $email,
					'file'       => 'newspack_google_login',
				]
			);

			$existing_user = \get_user_by( 'email', $email );
			$message       = __( 'Thank you for registering!', 'newspack-plugin' );
			$data          = [
				'email'         => $email,
				'authenticated' => true,
				'sso'           => true,
				'existing_user' => $existing_user ? true : false,
			];

			if ( $existing_user ) {
				$metadata['login_method'] = 'google';

				// Update user meta with connected account info.
				\update_user_meta( $existing_user->ID, Reader_Activation::CONNECTED_ACCOUNT, 'google' );

				// Log the user in.
				$result  = Reader_Activation::set_current_reader( $existing_user->ID );
				$message = __( 'Thank you for signing in!', 'newspack-plugin' );
			} else {
				$metadata['registration_method'] = 'google';
				$result = Reader_Activation::register_reader( $email, '', true, $metadata );
				// At this point the user will be logged in.
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$current_user = \wp_get_current_user();
			$data['metadata'] = $metadata;
			$data['verified'] = $current_user ? Reader_Activation::is_reader_verified( $current_user ) : false;
			return \rest_ensure_response(
				[
					'data'    => $data,
					'message' => $message,
				]
			);
		} else {
			/* translators: %s is a unique user id */
			self::handle_error( sprintf( __( 'Missing email for unique id: %s', 'newspack-plugin' ), $uid ) );
			return new \WP_Error( 'newspack_google_login', __( 'Failed to retrieve email address. Please try again.', 'newspack-plugin' ) );
		}
	}
}
new Google_Login();
