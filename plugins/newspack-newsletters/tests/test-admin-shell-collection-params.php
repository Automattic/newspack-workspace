<?php
/**
 * Class Test Admin Shell Collection Params
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Admin_Shell_Collection_Params;

/**
 * Tests the raised `per_page` ceiling on the list screens' collections.
 *
 * Core caps `per_page` at 100, which is what makes "All" fan out into one
 * request per 100 rows. Each round trip costs a full WordPress bootstrap
 * while the rows themselves are cheap, so the ceiling is what a
 * publisher waits on.
 */
class Admin_Shell_Collection_Params_Test extends WP_UnitTestCase {
	/**
	 * Set up a REST server with the filters registered.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

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
	 * Every collection a list screen reads accepts the raised ceiling.
	 */
	public function test_every_list_collection_accepts_the_raised_ceiling() {
		foreach ( Admin_Shell_Collection_Params::get_collections() as $rest_base ) {
			$params = apply_filters( 'rest_' . $rest_base . '_collection_params', [ 'per_page' => [ 'maximum' => 100 ] ] );
			$this->assertSame(
				Admin_Shell_Collection_Params::MAX_PER_PAGE,
				$params['per_page']['maximum'],
				'Expected a raised ceiling for: ' . $rest_base
			);
		}
	}

	/**
	 * The raised ceiling reaches the live route, not just the filter.
	 */
	public function test_request_above_the_core_cap_is_accepted() {
		self::factory()->post->create_many(
			3,
			[
				'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		$request->set_param( 'per_page', Admin_Shell_Collection_Params::MAX_PER_PAGE );
		$request->set_param( 'context', 'edit' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The ceiling is still a ceiling: past it, the request is rejected
	 * rather than silently clamped.
	 */
	public function test_request_above_the_raised_ceiling_is_rejected() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		$request->set_param( 'per_page', Admin_Shell_Collection_Params::MAX_PER_PAGE + 1 );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * Collections outside the list screens keep core's cap — this raises
	 * the ceiling where the admin shell needs it, not site-wide.
	 */
	public function test_unrelated_collections_keep_the_core_cap() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'per_page', 101 );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}
}
