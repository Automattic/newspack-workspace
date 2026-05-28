<?php
/**
 * Tests OAuth features.
 *
 * @package Newspack\Tests
 */

use Newspack\OAuth;
use Newspack\Google_OAuth;
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
}
