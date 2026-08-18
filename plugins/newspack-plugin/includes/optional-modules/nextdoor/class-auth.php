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
	 * The grant Nextdoor refused, as a map of settings key to hashed credential.
	 *
	 * Its own option, not a settings key: recording a refusal races a renewal, and writing
	 * the whole settings array would put a stale snapshot back over the winner's.
	 */
	const REFUSAL_OPTION = 'newspack_nextdoor_refused_grant';

	/**
	 * Transient holding off the next refresh attempt, and how long it holds for.
	 *
	 * A grant Nextdoor states no expiry for is due on every request, and a refresh that
	 * cannot be made to work costs the full timeout each time it is retried. Without a
	 * pause between attempts either one prices every editor load at a blocking round trip.
	 */
	const REFRESH_COOLOFF_TRANSIENT = 'newspack_nextdoor_refresh_cooloff';
	const REFRESH_COOLOFF_SECONDS   = 60;

	/**
	 * What the held-off refresh settled.
	 *
	 * Only an attempt that came back an error says the stored token cannot be used now. One
	 * still in flight, or held off for a grant Nextdoor stated no expiry for, says nothing
	 * about it, and answering those as unusable would report an outage that is not happening.
	 */
	const REFRESH_HELD_FAILED = 'failed';
	const REFRESH_HELD_OK     = 'ok';

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
	 * The payload is returned whether or not it was applied, so a caller treating it as
	 * proof of a working connection has to check the stored settings itself.
	 *
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $refresh_token Refresh token issued alongside the access token.
	 * @return array|\WP_Error Token data, applied unless the grant it describes was replaced, or an error if the refresh failed or carried no token.
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

			// Only `invalid_grant` means the token is dead. Other 4xx are configuration
			// errors and 5xx are transient, and recording either would send the publisher
			// through a reconnection they cannot undo.
			$is_refusal = is_array( $error_data ) && isset( $error_data['error'] ) && 'invalid_grant' === $error_data['error'];

			if ( $is_refusal ) {
				self::record_token_refusal( [ 'refresh_token' => $refresh_token ] );
			}

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

		$settings = Nextdoor::get_settings();

		// The call can take 30 seconds. If a disconnect or fresh sign-in replaced the grant
		// meanwhile, applying this would revive dropped credentials or overwrite a newer one.
		if ( $settings['refresh_token'] !== $refresh_token ) {
			return $token_data;
		}

		Nextdoor::update_settings( self::apply_token_response( $settings, $token_data ) );
		self::clear_token_refusal();

		return $token_data;
	}

	/**
	 * Read a token payload out of an OAuth response body.
	 *
	 * A 200 is not a grant: a malformed body or an error object both decode to something
	 * without a token, and storing that would replace a working connection with nothing.
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
		// No expiry means nothing vouches for the token, so it is stamped as already due.
		$expires_in = isset( $token_data['expires_in'] ) && is_numeric( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : 0;

		$settings['access_token']     = $token_data['access_token'];
		$settings['token_expires_at'] = time() + $expires_in;

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
	 * The callback fires on `init`, before the admin authenticates and on front-end requests
	 * too. Without this gate anyone could bind their own authorization code to the
	 * publisher's integration, or consume the state this site issued.
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
		if ( ! isset( $_GET['nextdoor_oauth_callback'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Declining at Nextdoor returns an error and no code, so the publisher would otherwise
		// land back on a closed card with nothing said. The state still has to be consumed.
		$denied = ! isset( $_GET['code'] ) && isset( $_GET['error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $_GET['code'] ) && ! $denied ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// An authorization code is opaque and may carry a percent sequence, which
		// `sanitize_text_field()` would drop, exchanging a credential that is quietly short.
		$code  = isset( $_GET['code'] ) ? Nextdoor::sanitize_credential( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$authorized = self::authorize_oauth_callback( $state );

		if ( is_wp_error( $authorized ) ) {
			// Whoever this is has no settings screen to be sent back to.
			if ( 'nextdoor_oauth_forbidden' === $authorized->get_error_code() ) {
				return;
			}

			self::redirect_with_error( $authorized->get_error_message() );
		}

		if ( $denied ) {
			$description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			self::redirect_with_error(
				'' !== $description ? $description : __( 'Nextdoor did not authorize the connection. Please try connecting again.', 'newspack-plugin' )
			);
		}

		$settings = Nextdoor::get_settings();

		if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			self::redirect_with_error( __( 'Nextdoor client credentials not configured.', 'newspack-plugin' ) );
		}

		$token_response = self::get_access_token(
			$settings['client_id'],
			$settings['client_secret'],
			$code,
			// Must repeat the redirect URI the authorization used, state included.
			Nextdoor::get_redirect_uri( $state )
		);

		if ( is_wp_error( $token_response ) ) {
			self::redirect_with_error( $token_response->get_error_message() );
		}

		// The exchange can take 30 seconds, so the earlier snapshot is stale by now.
		$settings = Nextdoor::get_settings();

		// A fresh grant, not a renewal: the stored refresh token belongs to the authorization
		// being replaced and would renew the wrong connection.
		$settings['refresh_token'] = '';

		$settings = self::apply_token_response( $settings, $token_response );

		// Nothing in the grant identifies the account, so the claimed page may not belong to
		// it. Re-claiming costs one press; keeping it could publish to the wrong page.
		$settings['page_id'] = '';

		Nextdoor::update_settings( $settings );
		self::clear_token_refusal();
		// What the last attempt settled was about the grant this one replaces, and holding a
		// failure over would answer the reconnection the publisher just made with an outage.
		delete_transient( self::REFRESH_COOLOFF_TRANSIENT );

		wp_safe_redirect( admin_url( 'admin.php?page=newspack-settings&oauth_success=1#social' ) );
		exit;
	}

	/**
	 * Hold off the next refresh attempt, recording what this one settled.
	 *
	 * @param bool $failed Whether the stored grant should be treated as failing until the
	 *                     hold-off lapses. What the call concluded rather than what the
	 *                     request answered: a refusal of a grant another request has since
	 *                     replaced says nothing about what is stored now.
	 * @return void
	 */
	private static function hold_off_refresh( $failed = false ) {
		set_transient(
			self::REFRESH_COOLOFF_TRANSIENT,
			$failed ? self::REFRESH_HELD_FAILED : self::REFRESH_HELD_OK,
			self::REFRESH_COOLOFF_SECONDS
		);
	}

	/**
	 * Try to replace an access token Nextdoor rejected.
	 *
	 * A rejected access token is not a rejected grant: the refresh token is issued
	 * separately and may still be honoured. Recording a refusal first would foreclose that,
	 * because a recorded refusal stops any later refresh being attempted, leaving a full
	 * re-authorisation as the only way back.
	 *
	 * @param string $token_used Access token the rejected request carried.
	 * @return bool False only when there is nothing to renew with, which is the one case
	 *              where the rejection is all the evidence there will be. A refresh that
	 *              was attempted has already recorded whatever it proved.
	 */
	public static function renew_rejected_token( $token_used ) {
		$settings = Nextdoor::get_settings();

		// Another request already rotated it, so the rejection describes a token that is no
		// longer the stored one.
		if ( $settings['access_token'] !== $token_used ) {
			return true;
		}

		if ( empty( $settings['refresh_token'] ) || empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			return false;
		}

		// Held off since the last attempt, so this request learns nothing either way.
		if ( false !== get_transient( self::REFRESH_COOLOFF_TRANSIENT ) ) {
			return true;
		}

		self::hold_off_refresh();

		// Called for what it settles rather than what it returns: a refused refresh records
		// itself against the grant it used, and any other failure says nothing about the
		// token, where recording a refusal the request cannot support is the worse mistake.
		$refresh_response = self::refresh_access_token(
			$settings['client_id'],
			$settings['client_secret'],
			$settings['refresh_token']
		);

		// Recorded as what this call concluded rather than what the request answered, the
		// same way the refusal above is. The hold-off is site-wide, so a refusal of a grant
		// another request rotated meanwhile would answer a healthy connection with an outage.
		$lost_the_race = Nextdoor::get_settings()['refresh_token'] !== $settings['refresh_token'];

		self::hold_off_refresh( is_wp_error( $refresh_response ) && ! $lost_the_race );

		return true;
	}

	/**
	 * Record that Nextdoor refused the stored grant.
	 *
	 * Persisted, so the card the publisher is sent to reports what the request that
	 * discovered the refusal did.
	 *
	 * @param array $expected Settings values the refusal was about, and what the record is
	 *                        keyed on. Nothing is recorded if they no longer match what is
	 *                        stored: a concurrent refresh or sign-in has since replaced the
	 *                        grant. Checked here, not by the caller, so no window opens.
	 * @return void
	 */
	public static function record_token_refusal( array $expected ) {
		if ( empty( $expected ) ) {
			return;
		}

		$settings = Nextdoor::get_settings();

		foreach ( $expected as $key => $value ) {
			if ( ! isset( $settings[ $key ] ) || $settings[ $key ] !== $value ) {
				return;
			}
		}

		// Hashed so the option never holds a second copy of a token, and keyed on the
		// credential so replacing it lapses the record by value rather than by every future
		// write path remembering to clear it.
		update_option( self::REFUSAL_OPTION, array_map( 'wp_hash', $expected ), false );
	}

	/**
	 * Forget a recorded refusal, because a fresh grant has replaced what was refused.
	 *
	 * @return void
	 */
	public static function clear_token_refusal() {
		delete_option( self::REFUSAL_OPTION );
	}

	/**
	 * Whether a recorded refusal still describes the stored credentials.
	 *
	 * @param array $settings Nextdoor settings.
	 * @return bool
	 */
	private static function is_token_refused( $settings ) {
		$refused = get_option( self::REFUSAL_OPTION );

		if ( ! is_array( $refused ) || empty( $refused ) ) {
			return false;
		}

		foreach ( $refused as $key => $hash ) {
			if ( ! isset( $settings[ $key ] ) || wp_hash( $settings[ $key ] ) !== $hash ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if token needs refresh.
	 *
	 * @return bool
	 */
	public static function needs_token_refresh() {
		$settings = Nextdoor::get_settings();

		if ( empty( $settings['token_expires_at'] ) ) {
			// No expiry says nothing about how long the grant lasts, so it is treated as due.
			return true;
		}

		return ( $settings['token_expires_at'] - 300 ) < time();
	}

	/**
	 * Whether the connection still holds, without calling Nextdoor.
	 *
	 * Reports what `validate_token()` would, read off the stored token. A refused grant is
	 * unusable however much life the access token has left, since a refusal can come from
	 * the content API as well as from a refresh. False means the publisher has to reconnect.
	 *
	 * @return bool
	 */
	public static function has_usable_token() {
		$settings = Nextdoor::get_settings();

		if ( empty( $settings['access_token'] ) ) {
			return false;
		}

		if ( self::is_token_refused( $settings ) ) {
			return false;
		}

		if ( ! self::needs_token_refresh() ) {
			return true;
		}

		return ! empty( $settings['refresh_token'] ) && ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] );
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
		// The cheap read answers every case a refresh could not fix, so a connection already
		// known to be unrenewable never spends a blocking request on each editor load.
		if ( ! self::has_usable_token() ) {
			return false;
		}

		$settings = Nextdoor::get_settings();

		if ( ! self::needs_token_refresh() ) {
			return true;
		}

		// Report what is stored rather than spending another blocking request: a grant with
		// no stated expiry is due on every one, and an outage answers each at the timeout.
		// An attempt that already failed is the exception, since the caller turns that into
		// the same "try again shortly" it would have given the failure itself.
		$held = get_transient( self::REFRESH_COOLOFF_TRANSIENT );

		if ( false !== $held ) {
			return self::REFRESH_HELD_FAILED !== $held && self::has_usable_token();
		}

		self::hold_off_refresh();

		$refresh_response = self::refresh_access_token(
			$settings['client_id'],
			$settings['client_secret'],
			$settings['refresh_token']
		);

		// A refresh replaced mid-flight comes back honoured but unapplied, so only the stored
		// settings say whether what replaced it is usable.
		if ( ! is_wp_error( $refresh_response ) ) {
			self::hold_off_refresh();
			return self::has_usable_token();
		}

		// Losing a refresh race is not a failure: the winner rotated the token while this
		// request was in flight, so its refusal says nothing about what is stored now.
		$lost_the_race = Nextdoor::get_settings()['refresh_token'] !== $settings['refresh_token'];

		// Recorded as what this call concluded rather than what the request answered, since
		// the hold-off is site-wide and would otherwise report a connection this very call
		// found healthy as unreachable to every other request for the next minute.
		self::hold_off_refresh( ! $lost_the_race );

		return $lost_the_race && self::has_usable_token();
	}
}

Auth::init();
