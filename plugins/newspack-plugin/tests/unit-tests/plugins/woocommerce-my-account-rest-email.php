<?php
/**
 * Tests that the core REST users endpoint cannot change the email of an
 * account below editor.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_My_Account;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Accounts below editor change their address only through a flow that
 * verifies it.
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
	 * The plugin loads before the test bootstrap enables Reader Activation, so
	 * the guard is not registered at load and is hooked here instead.
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
	 * Accounts below editor are refused too: the email domain rule treats a
	 * non-reader as verified, so an author's unverified address would count.
	 */
	public function test_author_cannot_change_own_email() {
		$author_id = self::factory()->user->create(
			[
				'role'       => 'author',
				'user_email' => 'author@example.test',
			]
		);
		wp_set_current_user( $author_id );

		$response = $this->update_user( '/wp/v2/users/me', [ 'email' => 'author-new@example.test' ] );

		$this->assertSame( 'newspack_rest_email_update_not_allowed', $response->get_data()['code'] );
		$this->assertSame( 'author@example.test', get_userdata( $author_id )->user_email );
	}

	/**
	 * A batched update goes through the same guard.
	 */
	public function test_reader_cannot_change_own_email_in_a_batch() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_body_params(
			[
				'requests' => [
					[
						'method' => 'PUT',
						'path'   => '/wp/v2/users/' . $this->user_id,
						'body'   => [ 'email' => 'someone@example.test' ],
					],
				],
			]
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 'newspack_rest_email_update_not_allowed', $response->get_data()['responses'][0]['body']['code'] );
		$this->assertSame( 'reader@example.test', get_userdata( $this->user_id )->user_email );
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
	 * A request for someone else's record gets core's answer whether or not
	 * the guessed address is right, so the guard cannot be used to confirm
	 * which address belongs to which account.
	 */
	public function test_response_does_not_reveal_another_users_email() {
		$other_reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$route           = '/wp/v2/users/' . $this->user_id;

		foreach ( [ 0, $other_reader_id ] as $caller_id ) {
			wp_set_current_user( $caller_id );

			$right_guess = $this->update_user( $route, [ 'email' => 'reader@example.test' ] );
			$wrong_guess = $this->update_user( $route, [ 'email' => 'wrong@example.test' ] );

			$this->assertSame( 'rest_cannot_edit', $right_guess->get_data()['code'], "caller $caller_id" );
			$this->assertSame( 'rest_cannot_edit', $wrong_guess->get_data()['code'], "caller $caller_id" );
		}
	}

	/**
	 * Accounts that can edit others' posts keep the core behavior for their own
	 * address.
	 */
	public function test_staff_can_change_own_email() {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$response = $this->update_user( '/wp/v2/users/me', [ 'email' => 'editor-new@example.test' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'editor-new@example.test', get_userdata( $editor_id )->user_email );
	}

	/**
	 * Admins changing a reader's address keep the core behavior.
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
}
