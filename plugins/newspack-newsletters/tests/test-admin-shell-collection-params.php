<?php
/**
 * Class Test Admin Shell Collection Params
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Admin_Shell_Collection_Params;
use Newspack_Newsletters\Ads;

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
	 * The advertisers screen is the one collection served by the terms
	 * controller, whose filter is keyed on the taxonomy rather than a
	 * rest_base, so it is worth asserting end to end.
	 */
	public function test_the_advertiser_taxonomy_accepts_the_raised_ceiling() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Ads::ADVERTISER_TAX );
		$request->set_param( 'per_page', Admin_Shell_Collection_Params::MAX_PER_PAGE );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
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

	/**
	 * The newsletters CPT is public, so without a capability gate the
	 * raised ceiling would let any anonymous caller ask one request to
	 * render ten times as many rows as core allows.
	 */
	public function test_a_logged_out_caller_is_held_to_the_core_cap() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		$request->set_param( 'per_page', Admin_Shell_Collection_Params::CORE_MAX_PER_PAGE + 1 );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * The gate is on the raise, not on the collection: core's own ceiling
	 * still works for everyone.
	 */
	public function test_a_logged_out_caller_can_still_reach_the_core_cap() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		$request->set_param( 'per_page', Admin_Shell_Collection_Params::CORE_MAX_PER_PAGE );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
	}

	/**
	 * A subscriber can read the collection but has no business asking for
	 * a thousand rows of it.
	 */
	public function test_a_subscriber_is_held_to_the_core_cap() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		$request->set_param( 'per_page', Admin_Shell_Collection_Params::MAX_PER_PAGE );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * The gate covers every collection the raise touches, not just the
	 * newsletters CPT. The layouts CPT only registers for a user who can
	 * edit others' posts, so for a logged-out caller it is not routable at
	 * all, which is the same guarantee reached earlier.
	 */
	public function test_the_gate_covers_every_raised_collection() {
		wp_set_current_user( 0 );

		foreach ( Admin_Shell_Collection_Params::get_collections() as $rest_base ) {
			$request = new WP_REST_Request( 'GET', '/wp/v2/' . $rest_base );
			$request->set_param( 'per_page', Admin_Shell_Collection_Params::MAX_PER_PAGE );

			$this->assertContains(
				rest_do_request( $request )->get_status(),
				[ 400, 404 ],
				'Expected the core cap to hold for a logged-out caller on: ' . $rest_base
			);
		}
	}
}
