<?php
/**
 * Class Test Constant Contact Controller
 *
 * @package Newspack_Newsletters
 */

/**
 * Covers how the Constant Contact REST routes match a newsletter ID.
 *
 * Hooks `rest_dispatch_request` to report the matched route and the
 * sanitized ID instead of calling the Constant Contact API.
 */
class Constant_Contact_Controller_Test extends WP_UnitTestCase {
	/**
	 * Set up a REST server, an editor and Constant Contact as the provider.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		Newspack_Newsletters::set_service_provider( 'constant_contact' );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		add_filter( 'rest_dispatch_request', [ $this, 'short_circuit_dispatch' ], 10, 3 );
	}

	/**
	 * Reset the REST server.
	 */
	public function tear_down() {
		remove_filter( 'rest_dispatch_request', [ $this, 'short_circuit_dispatch' ], 10 );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Return the matched route and the ID instead of running the callback.
	 *
	 * @param mixed           $result  Dispatch result.
	 * @param WP_REST_Request $request Request.
	 * @param string          $route   Matched route.
	 * @return array
	 */
	public function short_circuit_dispatch( $result, $request, $route ) {
		unset( $result );
		return [
			'route' => $route,
			'id'    => $request['id'],
		];
	}

	/**
	 * Create a draft newsletter.
	 *
	 * @return int Post ID.
	 */
	private function make_newsletter() {
		return self::factory()->post->create(
			[
				'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);
	}

	/**
	 * The editor's retrieve request reaches the retrieve route with the post ID.
	 */
	public function test_retrieve_route_receives_newsletter_id() {
		$post_id = $this->make_newsletter();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-newsletters/v1/constant_contact/' . $post_id . '/retrieve' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/retrieve', $response->get_data()['route'] );
		$this->assertSame( $post_id, $response->get_data()['id'] );
	}

	/**
	 * The route without a suffix still retrieves the campaign.
	 */
	public function test_bare_route_receives_newsletter_id() {
		$post_id = $this->make_newsletter();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-newsletters/v1/constant_contact/' . $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $post_id, $response->get_data()['id'] );
	}

	/**
	 * An unknown suffix is no longer captured as part of the ID.
	 */
	public function test_unknown_suffix_does_not_match_a_route() {
		$post_id = $this->make_newsletter();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-newsletters/v1/constant_contact/' . $post_id . '/unknown' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'] ?? null );
	}
}
