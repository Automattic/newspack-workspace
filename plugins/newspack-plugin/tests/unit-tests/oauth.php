<?php
/**
 * Tests OAuth features.
 *
 * @package Newspack\Tests
 */

use Newspack\OAuth;
use Newspack\Google_OAuth;
use Newspack\Google_Login;
use Newspack\Google_Services_Connection;

/**
 * Tests OAuth features.
 */
class Newspack_Test_OAuth extends WP_UnitTestCase {
	private function login_admin_user() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
	}

	private static function set_api_key() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( ! defined( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME' ) ) {
			define( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME', 'newspack-manager-api-key-option-name' );
		}
		update_option( NEWSPACK_MANAGER_API_KEY_OPTION_NAME, '123abc' );
	}

	/**
	 * Base class for all things OAuth.
	 */
	public static function test_oauth_base() {
		self::assertFalse(
			OAuth::get_proxy_api_key(),
			'Proxy API key is false until configured.'
		);
		self::set_api_key();
		self::assertEquals(
			'123abc',
			OAuth::get_proxy_api_key(),
			'Proxy API key is as expected after configured.'
		);
	}

	/**
	 * Google OAuth flow.
	 */
	public function test_oauth_google() {
		self::expectException( Exception::class );
		self::assertFalse(
			OAuth::authenticate_proxy_url( 'google', '/wp-json/newspack-google' ),
			'Proxy URL getting throws until configured.'
		);

		self::set_api_key();
		if ( ! defined( 'NEWSPACK_GOOGLE_OAUTH_PROXY' ) ) {
			define( 'NEWSPACK_GOOGLE_OAUTH_PROXY', 'http://dummy.proxy' );
		}

		/**
		 * First step is redirecting the user to the OAuth consent screen.
		 * The final URL will be constructed by the WPCOM endpoint.
		 */
		$consent_page_params = Google_OAuth::get_google_auth_url_params();
		$csrf_token          = $consent_page_params['csrf_token'];
		self::assertEquals(
			$consent_page_params,
			[
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/dfp https://www.googleapis.com/auth/analytics https://www.googleapis.com/auth/analytics.edit',
				'redirect_after' => 'http://example.org/wp-admin/admin.php?page=newspack-settings',
				'csrf_token'     => $csrf_token,
			],
			'The consent page request params are as expected.'
		);

		/**
		 * After the user consents, they will be redirected to another WPCOM endpoint.
		 * WPCOM proxy will obtain credentials and redirect the user back to their site.
		 */
		$proxy_response = [
			'access_token'  => 'access-token-123',
			'refresh_token' => 'refresh-token-123',
			'csrf_token'    => $csrf_token,
			'expires_at'    => time() + 3600,
		];
		Google_OAuth::api_google_auth_save_details( $proxy_response );

		self::assertEquals(
			[],
			Google_OAuth::get_google_auth_saved_data(),
			'The auth data is not readable for just anyone.'
		);

		self::login_admin_user();

		self::assertEquals(
			[
				'access_token'  => $proxy_response['access_token'],
				'refresh_token' => $proxy_response['refresh_token'],
				'expires_at'    => $proxy_response['expires_at'],
			],
			Google_OAuth::get_google_auth_saved_data(),
			'The saved credentials are as expected.'
		);

		/**
		 * A OAuth2 object, as defined in Google's google/auth library, is exposed for
		 * easy interaction with Google PHP libraries.
		 */
		$oauth2_object = Google_Services_Connection::get_oauth2_credentials();
		self::assertEquals(
			$oauth2_object->getAccessToken(),
			$proxy_response['access_token'],
			'The OAuth2 object returns the access token.'
		);

		/**
		 * Credentials can be removed.
		 */
		Google_OAuth::remove_credentials();
		$auth_data = Google_OAuth::get_google_auth_saved_data();
		self::assertEquals(
			$auth_data,
			[],
			'Credentials are empty after removal.'
		);
		self::assertEquals(
			Google_Services_Connection::get_oauth2_credentials(),
			false,
			'OAuth2 object getter return false after credentials are removed.'
		);
	}

	/**
	 * Stub Google's oauth2/v1/tokeninfo endpoint with a canned response body.
	 *
	 * @param array $token_info Fields to return as the tokeninfo JSON body.
	 */
	private function stub_tokeninfo( array $token_info ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $token_info ) {
				if ( false !== strpos( $url, 'oauth2/v1/tokeninfo' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( $token_info ),
					];
				}
				return $pre;
			},
			10,
			3
		);
	}

	/**
	 * The site's own Google OAuth client id that the sign-in flow validates tokens against.
	 *
	 * Configured via a filter so the test is independent of how the value is sourced.
	 *
	 * @param string $client_id Expected client id.
	 */
	private function set_expected_client_id( $client_id ) {
		add_filter( 'newspack_google_oauth_expected_client_id', fn() => $client_id );
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		delete_option( Google_OAuth::CLIENT_ID_OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Google's tokeninfo returns the owner's email for any email-scoped access token,
	 * regardless of which OAuth client requested it. The sign-in flow should therefore
	 * confirm the token was issued to this site's own client id before using its email:
	 * validate_token_and_get_email_address() compares the token's audience / issued_to
	 * against the configured client id and rejects a token issued to a different client.
	 */
	public function test_rejects_access_token_issued_to_a_different_client() {
		$this->set_expected_client_id( 'site-client-id.apps.googleusercontent.com' );
		$this->stub_tokeninfo(
			[
				'issued_to'      => 'other-client-id.apps.googleusercontent.com',
				'audience'       => 'other-client-id.apps.googleusercontent.com',
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email',
				'email'          => 'reader@example.com',
				'verified_email' => true,
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_Login::REQUIRED_SCOPES );

		self::assertTrue(
			is_wp_error( $result ),
			'A token whose audience is a different client id must be rejected.'
		);
	}

	/**
	 * A token issued to the site's own client id is accepted and resolves to its email.
	 *
	 * Guards against the audience check over-rejecting valid sign-ins.
	 */
	public function test_accepts_access_token_issued_to_configured_client() {
		$this->set_expected_client_id( 'site-client-id.apps.googleusercontent.com' );
		$this->stub_tokeninfo(
			[
				'issued_to'      => 'site-client-id.apps.googleusercontent.com',
				'audience'       => 'site-client-id.apps.googleusercontent.com',
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email',
				'email'          => 'reader@example.com',
				'verified_email' => true,
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_Login::REQUIRED_SCOPES );

		self::assertEquals(
			'reader@example.com',
			$result,
			'A token issued to the configured client id must be accepted.'
		);
	}

	/**
	 * A Google account whose email address is not verified should not be used to sign in.
	 */
	public function test_rejects_access_token_with_unverified_email() {
		$this->set_expected_client_id( 'site-client-id.apps.googleusercontent.com' );
		$this->stub_tokeninfo(
			[
				'issued_to'      => 'site-client-id.apps.googleusercontent.com',
				'audience'       => 'site-client-id.apps.googleusercontent.com',
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email',
				'email'          => 'reader@example.com',
				'verified_email' => false,
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_Login::REQUIRED_SCOPES );

		self::assertTrue(
			is_wp_error( $result ),
			'A token whose email is not verified must be rejected.'
		);
	}

	/**
	 * When no expected client id is known yet (e.g. before the proxy has reported one),
	 * the audience check is skipped so existing sign-ins keep working; the email is returned.
	 */
	public function test_accepts_token_when_no_expected_client_id_is_configured() {
		// No expected client id configured (filter not set, option empty).
		$this->stub_tokeninfo(
			[
				'issued_to'      => 'any-client-id.apps.googleusercontent.com',
				'audience'       => 'any-client-id.apps.googleusercontent.com',
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email',
				'email'          => 'reader@example.com',
				'verified_email' => true,
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_Login::REQUIRED_SCOPES );

		self::assertEquals(
			'reader@example.com',
			$result,
			'With no expected client id configured, a verified token should still be accepted.'
		);
	}

	/**
	 * A Google account email flagged verified as the string "false" must not be trusted.
	 *
	 * Google's tokeninfo has historically returned this field as a boolean or a string.
	 */
	public function test_rejects_access_token_with_string_false_verified_email() {
		$this->set_expected_client_id( 'site-client-id.apps.googleusercontent.com' );
		$this->stub_tokeninfo(
			[
				'issued_to'      => 'site-client-id.apps.googleusercontent.com',
				'audience'       => 'site-client-id.apps.googleusercontent.com',
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email',
				'email'          => 'reader@example.com',
				'verified_email' => 'false',
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_Login::REQUIRED_SCOPES );

		self::assertTrue(
			is_wp_error( $result ),
			'A token whose verified_email is the string "false" must be rejected.'
		);
	}

	/**
	 * A token with no verified_email field at all must be rejected.
	 */
	public function test_rejects_access_token_with_missing_verified_email() {
		$this->set_expected_client_id( 'site-client-id.apps.googleusercontent.com' );
		$this->stub_tokeninfo(
			[
				'issued_to' => 'site-client-id.apps.googleusercontent.com',
				'audience'  => 'site-client-id.apps.googleusercontent.com',
				'scope'     => 'https://www.googleapis.com/auth/userinfo.email',
				'email'     => 'reader@example.com',
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_Login::REQUIRED_SCOPES );

		self::assertTrue(
			is_wp_error( $result ),
			'A token with no verified_email field must be rejected.'
		);
	}

	/**
	 * The client id is matched against issued_to when the audience field is absent.
	 */
	public function test_accepts_token_matched_by_issued_to_when_audience_absent() {
		$this->set_expected_client_id( 'site-client-id.apps.googleusercontent.com' );
		$this->stub_tokeninfo(
			[
				'issued_to'      => 'site-client-id.apps.googleusercontent.com',
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email',
				'email'          => 'reader@example.com',
				'verified_email' => true,
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_Login::REQUIRED_SCOPES );

		self::assertEquals( 'reader@example.com', $result, 'A token matched by issued_to should be accepted.' );
	}

	/**
	 * Admin-scoped tokens (Ad Manager / Analytics) with a matching audience still pass.
	 *
	 * The check lives in the validator shared by the admin connection flow, so this
	 * guards against a regression there.
	 */
	public function test_accepts_admin_scoped_token_with_matching_audience() {
		$this->set_expected_client_id( 'site-client-id.apps.googleusercontent.com' );
		$this->stub_tokeninfo(
			[
				'issued_to'      => 'site-client-id.apps.googleusercontent.com',
				'audience'       => 'site-client-id.apps.googleusercontent.com',
				'scope'          => implode( ' ', Google_OAuth::REQUIRED_SCOPES ),
				'email'          => 'admin@example.com',
				'verified_email' => true,
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_OAuth::REQUIRED_SCOPES );

		self::assertEquals( 'admin@example.com', $result, 'An admin-scoped token with a matching audience should be accepted.' );
	}

	/**
	 * The client id reported by the proxy /start response is stored for later checks.
	 */
	public function test_start_response_client_id_is_persisted() {
		self::set_api_key();
		if ( ! defined( 'NEWSPACK_GOOGLE_OAUTH_PROXY' ) ) {
			define( 'NEWSPACK_GOOGLE_OAUTH_PROXY', 'http://dummy.proxy' );
		}
		delete_option( Google_OAuth::CLIENT_ID_OPTION_NAME );

		// Stub the proxy /start response with a url and a client id.
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'newspack-oauth-proxy/v1/start' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode(
							[
								'url'       => 'https://accounts.google.com/o/oauth2/v2/auth?stub',
								'client_id' => 'site-client-id.apps.googleusercontent.com',
							]
						),
					];
				}
				return $pre;
			},
			10,
			3
		);

		Google_OAuth::google_auth_get_url(
			[
				'csrf_token'     => 'csrf-token-123',
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email',
				'redirect_after' => 'https://example.org/',
			]
		);

		self::assertEquals(
			'site-client-id.apps.googleusercontent.com',
			get_option( Google_OAuth::CLIENT_ID_OPTION_NAME ),
			'The client id from the /start response should be stored.'
		);
	}

	/**
	 * When a client id is expected but the token carries neither audience nor
	 * issued_to, the token is rejected (closed by default).
	 */
	public function test_rejects_token_without_audience_or_issued_to() {
		$this->set_expected_client_id( 'site-client-id.apps.googleusercontent.com' );
		$this->stub_tokeninfo(
			[
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email',
				'email'          => 'reader@example.com',
				'verified_email' => true,
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_Login::REQUIRED_SCOPES );

		self::assertTrue(
			is_wp_error( $result ),
			'A token carrying neither audience nor issued_to must be rejected when a client id is expected.'
		);
	}

	/**
	 * Admin-scoped tokens (Ad Manager / Analytics) with a mismatched audience are
	 * rejected too — the shared validator protects the admin connection flow.
	 */
	public function test_rejects_admin_scoped_token_with_mismatched_audience() {
		$this->set_expected_client_id( 'site-client-id.apps.googleusercontent.com' );
		$this->stub_tokeninfo(
			[
				'issued_to'      => 'other-client-id.apps.googleusercontent.com',
				'audience'       => 'other-client-id.apps.googleusercontent.com',
				'scope'          => implode( ' ', Google_OAuth::REQUIRED_SCOPES ),
				'email'          => 'admin@example.com',
				'verified_email' => true,
			]
		);

		$result = Google_OAuth::validate_token_and_get_email_address( 'some-access-token', Google_OAuth::REQUIRED_SCOPES );

		self::assertTrue(
			is_wp_error( $result ),
			'An admin-scoped token with a mismatched audience must be rejected.'
		);
	}
}
