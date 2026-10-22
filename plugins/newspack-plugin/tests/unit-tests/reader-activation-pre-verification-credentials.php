<?php
/**
 * Tests revoking credentials set on a reader account before its address is verified.
 *
 * @package Newspack\Tests
 */

use Newspack\Google_Login;
use Newspack\Magic_Link;
use Newspack\OAuth;
use Newspack\OAuth_Transients;
use Newspack\Reader_Activation;

/**
 * Tests revoking credentials set on a reader account before its address is verified.
 */
class Newspack_Test_RA_Pre_Verification_Credentials extends WP_UnitTestCase {
	const EMAIL = 'owner@example.test';

	/**
	 * The password the registrant sets before the owner verifies.
	 */
	const REGISTRANT_PASSWORD = 'registrant-chosen-password';

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'newspack_reader_activation_enabled', '__return_true' );
		add_filter( 'newspack_magic_link_rate_interval', '__return_zero' );
		// PHPUnit loads the plugin before IS_TEST_ENV is defined, so the hooks inside
		// init()'s is_enabled() block are missing until it runs again.
		Reader_Activation::init();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Register a reader through the sign-in modal path, which signs the registrant in.
	 *
	 * @return int User ID.
	 */
	private static function register_as_registrant() {
		$user_id = Reader_Activation::register_reader( self::EMAIL );
		self::assertIsInt( $user_id );
		self::assertSame( $user_id, get_current_user_id(), 'Registration signs the registrant in.' );
		return $user_id;
	}

	/**
	 * Set a password the way any signed-in reader can: over the core REST API,
	 * without the current password.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $password Password.
	 */
	private static function set_password_over_rest( $user_id, $password ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
		$request->set_param( 'password', $password );
		$response = rest_do_request( $request );
		self::assertSame( 200, $response->get_status(), 'A signed-in reader can set a password over REST.' );
		self::assertTrue( wp_check_password( $password, get_userdata( $user_id )->user_pass, $user_id ) );
	}

	/**
	 * Switch to another browser: nobody signed in, no auth cookie.
	 */
	private static function switch_browser() {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		wp_set_current_user( 0 );
	}

	/**
	 * Sign in with an emailed link, through the same path the link handler uses.
	 *
	 * @param int $user_id User ID.
	 */
	private static function sign_in_with_emailed_link( $user_id ) {
		$token_data = Magic_Link::generate_token( get_userdata( $user_id ) );
		self::assertIsArray( $token_data );
		$authenticate = new ReflectionMethod( Magic_Link::class, 'authenticate' );
		$authenticate->setAccessible( true );
		self::assertTrue( $authenticate->invoke( null, $user_id, $token_data['token'] ) );
	}

	/**
	 * Whether a password signs in to the account.
	 *
	 * @param string $password Password.
	 */
	private static function password_signs_in( $password ) {
		return ! is_wp_error( wp_authenticate( self::EMAIL, $password ) );
	}

	/**
	 * The owner proving the address ends everything the registrant set up before it,
	 * and the owner stays signed in with a code-only account.
	 */
	public function test_proving_the_address_ends_registrant_credentials() {
		$user_id = self::register_as_registrant();
		self::set_password_over_rest( $user_id, self::REGISTRANT_PASSWORD );
		$registrant_cookie = wp_generate_auth_cookie( $user_id, time() + DAY_IN_SECONDS, 'logged_in', wp_get_session_token() );
		WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => 'registrant' ] );
		self::assertTrue( self::password_signs_in( self::REGISTRANT_PASSWORD ) );
		self::assertSame( $user_id, wp_validate_auth_cookie( $registrant_cookie, 'logged_in' ) );

		self::switch_browser();
		self::sign_in_with_emailed_link( $user_id );

		self::assertTrue( Reader_Activation::is_reader_verified( get_userdata( $user_id ) ) );
		self::assertFalse( self::password_signs_in( self::REGISTRANT_PASSWORD ), 'The registrant password must stop working.' );
		self::assertEmpty( WP_Application_Passwords::get_user_application_passwords( $user_id ), 'Application passwords must be deleted.' );
		self::assertFalse( wp_validate_auth_cookie( $registrant_cookie, 'logged_in' ), 'The registrant session must end.' );
		self::assertSame( $user_id, wp_validate_auth_cookie( '', 'logged_in' ), 'The owner must stay signed in.' );
		self::assertTrue( Reader_Activation::is_reader_without_password( $user_id ), 'The sign-in form must offer the owner a code.' );
	}

	/**
	 * Signing in with a code keeps the password of an account registration didn't create,
	 * and of a reader who already verified.
	 */
	public function test_signing_in_with_a_code_keeps_the_owner_password() {
		$user_id = wp_insert_user(
			[
				'user_login' => 'imported-reader',
				'user_email' => self::EMAIL,
				'user_pass'  => self::REGISTRANT_PASSWORD,
			]
		);
		update_user_meta( $user_id, Reader_Activation::READER, true );
		self::sign_in_with_emailed_link( $user_id );
		self::assertTrue( Reader_Activation::is_reader_verified( get_userdata( $user_id ) ) );
		self::assertTrue( self::password_signs_in( self::REGISTRANT_PASSWORD ), 'An account created outside registration keeps its password.' );
		wp_delete_user( $user_id );
		self::switch_browser();

		$user_id = self::register_as_registrant();
		self::switch_browser();
		self::sign_in_with_emailed_link( $user_id );
		self::set_password_over_rest( $user_id, self::REGISTRANT_PASSWORD );
		self::switch_browser();
		self::sign_in_with_emailed_link( $user_id );
		self::assertTrue( self::password_signs_in( self::REGISTRANT_PASSWORD ), 'A verified reader keeps the password they set.' );
	}

	/**
	 * Verifying when core renders the reset form keeps the reset key usable, and
	 * submitting the form keeps the new password.
	 */
	public function test_verifying_through_a_password_reset_keeps_the_new_password() {
		$user_id = self::register_as_registrant();
		self::set_password_over_rest( $user_id, self::REGISTRANT_PASSWORD );
		self::switch_browser();
		$user = get_userdata( $user_id );
		$key  = get_password_reset_key( $user );

		do_action( 'resetpass_form', $user );
		self::assertTrue( Reader_Activation::is_reader_verified( get_userdata( $user_id ) ), 'Opening the reset link verifies the reader.' );
		self::assertFalse( self::password_signs_in( self::REGISTRANT_PASSWORD ), 'The registrant password must stop working.' );
		$checked_user = check_password_reset_key( $key, $user->user_login );
		self::assertNotWPError( $checked_user, 'The reset form must still submit.' );

		reset_password( $checked_user, 'owner-new-password' );
		self::assertTrue( self::password_signs_in( 'owner-new-password' ) );
		self::assertFalse( Reader_Activation::is_reader_without_password( $user_id ), 'The reader has a password again.' );
	}

	/**
	 * Verifying on a WooCommerce reset, which fires only password_reset, keeps the new password.
	 */
	public function test_verifying_on_password_reset_submit_keeps_the_new_password() {
		$user_id = self::register_as_registrant();
		self::switch_browser();

		reset_password( get_userdata( $user_id ), 'owner-new-password' );

		self::assertTrue( Reader_Activation::is_reader_verified( get_userdata( $user_id ) ) );
		self::assertTrue( self::password_signs_in( 'owner-new-password' ) );
		self::assertFalse( Reader_Activation::is_reader_without_password( $user_id ), 'The reader has a password again.' );
	}

	/**
	 * A payment verifies the account but doesn't prove who controls the address,
	 * so it leaves the password alone.
	 */
	public function test_verifying_on_payment_keeps_the_password() {
		$user_id = self::register_as_registrant();
		self::set_password_over_rest( $user_id, self::REGISTRANT_PASSWORD );
		$order = wc_create_order(
			[
				'customer_id' => $user_id,
				'status'      => 'processing',
			]
		);

		Reader_Activation::verify_reader_account( $order->get_id() );

		self::assertTrue( Reader_Activation::is_reader_verified( get_userdata( $user_id ) ) );
		self::assertTrue( self::password_signs_in( self::REGISTRANT_PASSWORD ), 'Paying must not cost the reader their password.' );
	}

	/**
	 * Signing in with Google proves the address, so it verifies an existing reader
	 * and ends what the registrant set up, as an emailed code does.
	 */
	public function test_signing_in_with_google_verifies_an_existing_reader() {
		$user_id = self::register_as_registrant();
		self::set_password_over_rest( $user_id, self::REGISTRANT_PASSWORD );
		$registrant_cookie = wp_generate_auth_cookie( $user_id, time() + DAY_IN_SECONDS, 'logged_in', wp_get_session_token() );
		self::assertSame( $user_id, wp_validate_auth_cookie( $registrant_cookie, 'logged_in' ) );

		self::switch_browser();
		// The OAuth callback stores the address Google verified for the visitor.
		$_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] = 'google-sign-in-visitor'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		OAuth_Transients::set( OAuth::get_unique_id(), 'email', self::EMAIL );
		$response = Google_Login::api_google_login_register( new WP_REST_Request( 'GET', '/newspack/v1/login/google/register' ) );

		self::assertNotWPError( $response );
		self::assertTrue( Reader_Activation::is_reader_verified( get_userdata( $user_id ) ), 'Signing in with Google must verify the reader.' );
		self::assertTrue( $response->get_data()['data']['verified'] );
		self::assertFalse( self::password_signs_in( self::REGISTRANT_PASSWORD ), 'The registrant password must stop working.' );
		self::assertFalse( wp_validate_auth_cookie( $registrant_cookie, 'logged_in' ), 'The registrant session must end.' );
		self::assertSame( $user_id, wp_validate_auth_cookie( '', 'logged_in' ), 'The reader must stay signed in.' );
	}
}
