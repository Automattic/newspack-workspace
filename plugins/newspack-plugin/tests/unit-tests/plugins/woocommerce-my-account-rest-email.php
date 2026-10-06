<?php
/**
 * Tests that the core REST users endpoint cannot change a reader's email.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_My_Account;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * A reader's address changes only through the verified My Account flow.
 */
class Newspack_Test_WooCommerce_My_Account_REST_Email extends WP_UnitTestCase {

	/**
	 * A reader with an account.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up a reader and the guard for each test.
	 *
	 * The guard is registered only when Reader Activation is enabled at load
	 * time, so the test hooks it directly.
	 */
	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.test',
			]
		);
		add_filter( 'rest_request_before_callbacks', [ WooCommerce_My_Account::class, 'rest_prevent_email_update' ], 10, 3 );
	}

	/**
	 * Remove the guard.
	 */
	public function tear_down() {
		remove_filter( 'rest_request_before_callbacks', [ WooCommerce_My_Account::class, 'rest_prevent_email_update' ], 10 );
		parent::tear_down();
	}

	/**
	 * Send a user update through the REST server.
	 *
	 * @param string $route  Route, e.g. /wp/v2/users/me.
	 * @param array  $params Body parameters.
	 *
	 * @return WP_REST_Response
	 */
	private function update_user( $route, $params ) {
		$request = new WP_REST_Request( 'PUT', $route );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A reader cannot change their own email through either users route.
	 */
	public function test_reader_cannot_change_own_email() {
		wp_set_current_user( $this->user_id );

		foreach ( [ '/wp/v2/users/me', '/wp/v2/users/' . $this->user_id ] as $route ) {
			$response = $this->update_user( $route, [ 'email' => 'someone@example.test' ] );

			$this->assertSame( 403, $response->get_status(), $route );
			$this->assertSame( 'newspack_rest_email_update_not_allowed', $response->get_data()['code'], $route );
			$this->assertSame( 'reader@example.test', get_userdata( $this->user_id )->user_email, $route );
		}
	}

	/**
	 * Sending the current address back, as a client saving the whole profile
	 * does, is not a change and is allowed along with the other fields.
	 */
	public function test_reader_can_update_profile_with_unchanged_email() {
		wp_set_current_user( $this->user_id );

		$response = $this->update_user(
			'/wp/v2/users/me',
			[
				'email' => 'reader@example.test',
				'name'  => 'New Name',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'New Name', get_userdata( $this->user_id )->display_name );
	}

	/**
	 * Users who can edit other users keep the core behavior.
	 */
	public function test_admin_can_change_a_reader_email() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		$response = $this->update_user( '/wp/v2/users/' . $this->user_id, [ 'email' => 'changed@example.test' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'changed@example.test', get_userdata( $this->user_id )->user_email );
	}

	/**
	 * Creating a user is not an email change.
	 */
	public function test_admin_can_create_a_user() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/users' );
		$request->set_body_params(
			[
				'username' => 'newreader',
				'email'    => 'newreader@example.test',
				'password' => wp_generate_password(),
			]
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
	}
}
