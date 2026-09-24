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
	 * A well-formed /start response returns its url, and the client id it reports is
	 * stored for later checks.
	 *
	 * Pins the happy path: the guards around this must not reject a valid response.
	 */
	public function test_start_response_client_id_is_persisted() {
		delete_option( Google_OAuth::CLIENT_ID_OPTION_NAME );
		$this->stub_start_response(
			wp_json_encode(
				[
					'url'       => 'https://accounts.google.com/o/oauth2/v2/auth?stub',
					'client_id' => 'site-client-id.apps.googleusercontent.com',
				]
			)
		);

		$result = self::start_the_oauth_flow();

		self::assertEquals(
			'https://accounts.google.com/o/oauth2/v2/auth?stub',
			$result,
			'A well-formed /start response should return its url.'
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

	/**
	 * Stub the proxy /start endpoint with a canned response.
	 *
	 * @param string $body          Raw response body.
	 * @param int    $response_code HTTP status the proxy responds with.
	 */
	private function stub_start_response( $body, $response_code = 200 ) {
		self::set_api_key();
		if ( ! defined( 'NEWSPACK_GOOGLE_OAUTH_PROXY' ) ) {
			define( 'NEWSPACK_GOOGLE_OAUTH_PROXY', 'http://dummy.proxy' );
		}
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body, $response_code ) {
				if ( false !== strpos( $url, 'newspack-oauth-proxy/v1/start' ) ) {
					return [
						'response' => [ 'code' => $response_code ],
						'body'     => $body,
					];
				}
				return $pre;
			},
			10,
			3
		);
	}

	/**
	 * Start the OAuth flow against the stubbed proxy.
	 *
	 * @return string|WP_Error
	 */
	private static function start_the_oauth_flow() {
		return Google_OAuth::google_auth_get_url(
			[
				'csrf_token'     => 'csrf-token-123',
				'scope'          => 'https://www.googleapis.com/auth/userinfo.email',
				'redirect_after' => 'https://example.org/',
			]
		);
	}

	/**
	 * The proxy failing with a non-JSON body — an ordinary gateway outage — is reported
	 * as an error rather than escaping as a fatal from a public route.
	 *
	 * This is the failure that NPPM-2971 was filed for: json_decode() returns null and
	 * the pre-fix code dereferenced it, which is a TypeError, not an Exception.
	 */
	public function test_start_non_200_html_body_yields_error() {
		$this->stub_start_response( '<html><body><h1>502 Bad Gateway</h1></body></html>', 502 );

		$result = self::start_the_oauth_flow();

		self::assertTrue( is_wp_error( $result ), 'A 502 with an HTML body must return a WP_Error, not fatal.' );
		self::assertSame(
			'Request failed.',
			$result->get_error_message(),
			'A non-200 response with an unusable body falls back to the generic error text.'
		);
		self::assertSame(
			502,
			$result->get_error_data()['status'],
			'The proxy status code should be preserved on the error, and become the REST response status.'
		);
	}

	/**
	 * A non-200 response whose JSON message is not a string does not produce a WP_Error
	 * carrying an array as its message (which downstream sprintf()/wp_die() would mangle).
	 */
	public function test_start_non_200_non_string_message_yields_generic_error() {
		$this->stub_start_response( wp_json_encode( [ 'message' => [ 'code' => 500 ] ] ), 500 );

		$result = self::start_the_oauth_flow();

		self::assertTrue( is_wp_error( $result ), 'A non-200 response must return a WP_Error.' );
		self::assertSame(
			'Request failed.',
			$result->get_error_message(),
			'A non-string message from the proxy must not become the error message.'
		);
	}

	/**
	 * An unparseable proxy /start response yields a WP_Error rather than dereferencing
	 * null and propagating null downstream.
	 *
	 * The message is asserted deliberately: PHPUnit converts the PHP warning raised by
	 * the unguarded dereference into an Exception, which the method's own catch turns
	 * into a WP_Error — so is_wp_error() alone passes even with the guard removed.
	 */
	public function test_start_response_unparseable_yields_error() {
		$this->stub_start_response( 'not-json' );

		$result = self::start_the_oauth_flow();

		self::assertTrue( is_wp_error( $result ), 'An unparseable /start response must return a WP_Error, not null.' );
		self::assertSame(
			'Could not parse the authentication response.',
			$result->get_error_message(),
			'The guard must be what produced the error, not a swallowed PHP warning.'
		);
	}

	/**
	 * A parseable /start response that is missing the url still yields a WP_Error.
	 */
	public function test_start_response_missing_url_yields_error() {
		$this->stub_start_response( wp_json_encode( [ 'client_id' => 'site-client-id.apps.googleusercontent.com' ] ) );

		$result = self::start_the_oauth_flow();

		self::assertTrue( is_wp_error( $result ), 'A /start response without a url must return a WP_Error.' );
		self::assertSame(
			'Could not parse the authentication response.',
			$result->get_error_message(),
			'The guard must be what produced the error, not a swallowed PHP warning.'
		);
	}

	/**
	 * A /start response whose url is present but empty (or otherwise not a usable
	 * string) yields a WP_Error rather than returning that value.
	 */
	public function test_start_response_empty_url_yields_error() {
		$this->stub_start_response( wp_json_encode( [ 'url' => '' ] ) );

		$result = self::start_the_oauth_flow();

		self::assertTrue( is_wp_error( $result ), 'A /start response with an empty url must return a WP_Error.' );
		self::assertSame(
			'Could not parse the authentication response.',
			$result->get_error_message(),
			'The guard must be what produced the error, not a swallowed PHP warning.'
		);
	}

	/**
	 * The url is handed to a popup opened as about:blank, which inherits this site's
	 * origin — so a non-http(s) scheme must never be returned to the browser.
	 */
	public function test_start_response_non_http_url_yields_error() {
		$this->stub_start_response( wp_json_encode( [ 'url' => 'javascript:alert(document.domain)' ] ) );

		$result = self::start_the_oauth_flow();

		self::assertTrue( is_wp_error( $result ), 'A url with a non-http(s) scheme must return a WP_Error.' );
		self::assertSame(
			'Could not parse the authentication response.',
			$result->get_error_message(),
			'A non-http(s) url must be rejected by the guard.'
		);
	}

	/**
	 * An unusable client id from the proxy must not overwrite a known-good stored one.
	 *
	 * An array is flattened to '' by sanitize_text_field(), and an empty expected client
	 * id is what makes validate_token_and_get_email_address() skip the token audience
	 * check — so storing it would silently disable that check until a later good response.
	 */
	public function test_start_response_unusable_client_id_does_not_replace_stored_value() {
		update_option( Google_OAuth::CLIENT_ID_OPTION_NAME, 'good-client-id.apps.googleusercontent.com', false );
		$this->stub_start_response( '{"url":"https://accounts.google.com/o/oauth2/v2/auth?stub","client_id":[]}' );

		$result = self::start_the_oauth_flow();

		self::assertEquals(
			'https://accounts.google.com/o/oauth2/v2/auth?stub',
			$result,
			'A usable url is still returned when the client id is unusable.'
		);
		self::assertSame(
			'good-client-id.apps.googleusercontent.com',
			Google_OAuth::get_expected_client_id(),
			'An unusable client id must not replace the stored one, which would turn the audience check off.'
		);
	}
}
