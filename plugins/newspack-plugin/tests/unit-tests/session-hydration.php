<?php
/**
 * Tests the Session Hydration functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Session_Hydration;
use Newspack\Reader_Activation;

/**
 * Tests the Session Hydration functionality.
 */
class Newspack_Test_Session_Hydration extends WP_UnitTestCase {
	/**
	 * Test CID cookie name.
	 *
	 * @var string
	 */
	private static $test_cid = 'testcid12345';

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'newspack_reader_activation_enabled', '__return_true' );
		do_action( 'rest_api_init' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		parent::tear_down();
		remove_filter( 'newspack_reader_activation_enabled', '__return_true' );
		unset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		delete_transient( Session_Hydration::get_transient_key( self::$test_cid ) );
	}

	/**
	 * Set the logged_in auth cookie for a user so wp_validate_auth_cookie() works.
	 *
	 * @param int $user_id User ID.
	 */
	private function set_auth_cookie( $user_id ) { // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + DAY_IN_SECONDS, 'logged_in' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Clear the logged_in auth cookie.
	 */
	private function clear_auth_cookie() { // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Test that CID binding creates a transient mapping CID to user ID.
	 */
	public function test_bind_cid_to_user() {
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader@test.com' ] );
		$_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] = self::$test_cid; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		Session_Hydration::bind_cid( $user_id );

		$stored_user_id = get_transient( Session_Hydration::get_transient_key( self::$test_cid ) );
		$this->assertEquals( $user_id, $stored_user_id );

		unset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		wp_delete_user( $user_id );
	}

	/**
	 * Test that CID binding does nothing when cookie is missing.
	 */
	public function test_bind_cid_without_cookie() {
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader2@test.com' ] );
		unset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		Session_Hydration::bind_cid( $user_id );

		$stored = get_transient( Session_Hydration::get_transient_key( self::$test_cid ) );
		$this->assertFalse( $stored );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that the hydration endpoint returns a nonce when CID matches.
	 */
	public function test_hydration_endpoint_success() {
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader3@test.com' ] );
		$_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] = self::$test_cid; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		// Bind the CID.
		Session_Hydration::bind_cid( $user_id );

		// Simulate logged-in user via auth cookie.
		$this->set_auth_cookie( $user_id );

		$request  = new WP_REST_Request( 'GET', '/newspack/v1/reader/session' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertNotEmpty( $data['nonce'] );

		// Transient should be deleted (one-time use).
		$this->assertFalse( get_transient( Session_Hydration::get_transient_key( self::$test_cid ) ) );

		unset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->clear_auth_cookie();
		wp_delete_user( $user_id );
	}

	/**
	 * Test that the hydration endpoint returns 403 when CID doesn't match.
	 */
	public function test_hydration_endpoint_cid_mismatch() {
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader4@test.com' ] );

		// Bind the CID with correct value.
		$_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] = self::$test_cid; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		Session_Hydration::bind_cid( $user_id );

		// Now request with a different CID.
		$_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] = 'wrongcid12345'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$this->set_auth_cookie( $user_id );

		$request  = new WP_REST_Request( 'GET', '/newspack/v1/reader/session' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );

		unset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->clear_auth_cookie();
		wp_delete_user( $user_id );
	}

	/**
	 * Test that the hydration endpoint returns 403 when no CID cookie is present.
	 */
	public function test_hydration_endpoint_no_cookie() {
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader5@test.com' ] );
		unset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$this->set_auth_cookie( $user_id );

		$request  = new WP_REST_Request( 'GET', '/newspack/v1/reader/session' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );

		$this->clear_auth_cookie();
		wp_delete_user( $user_id );
	}

	/**
	 * Test that unauthenticated requests get 401.
	 */
	public function test_hydration_endpoint_unauthenticated() {
		$this->clear_auth_cookie();
		$_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] = self::$test_cid; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$request  = new WP_REST_Request( 'GET', '/newspack/v1/reader/session' );
		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );

		unset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Test that admin accounts are rejected.
	 */
	public function test_hydration_endpoint_rejects_admin() {
		$user_id = $this->factory->user->create(
			[
				'user_email' => 'admin@test.com',
				'role'       => 'administrator',
			]
		);
		$_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] = self::$test_cid; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		Session_Hydration::bind_cid( $user_id );
		$this->set_auth_cookie( $user_id );

		$request  = new WP_REST_Request( 'GET', '/newspack/v1/reader/session' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );

		unset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->clear_auth_cookie();
		wp_delete_user( $user_id );
	}
}
