<?php
/**
 * Class Test api_update_lists.
 *
 * @package Newspack_Newsletters
 */

/**
 * Tests the `/newspack-newsletters/v1/lists` POST route validation rails.
 */
class Api_Update_Lists_Test extends WP_UnitTestCase {
	/**
	 * Set up REST server and an authenticated admin user.
	 */
	public function set_up() {
		parent::set_up();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Reset REST server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * A non-array `lists` payload that the route schema can't coerce
	 * (e.g. an object) is rejected with 400 by the REST layer.
	 */
	public function test_non_array_lists_payload_rejected_via_rest_dispatch() {
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/lists' );
		$request->set_param( 'lists', (object) [ 'id' => 'x' ] );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null );
	}

	/**
	 * Omitting the required `lists` arg is rejected with 400.
	 */
	public function test_missing_lists_payload_rejected_with_400() {
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/lists' );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Defence in depth: a direct controller call (bypassing REST schema
	 * coercion) must reject a non-array `lists` before it reaches
	 * `sanitize_lists()`'s foreach — otherwise PHP 8 raises a TypeError
	 * and the response is a 500.
	 */
	public function test_controller_guard_rejects_non_array_lists() {
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/lists' );
		$request->set_param( 'lists', 'not-an-array' );

		$response = Newspack_Newsletters_Subscription::api_update_lists( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'rest_invalid_param', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] ?? null );
	}
}
