<?php
/**
 * Tests for the institution REST controller.
 *
 * @package Newspack\Tests\Content_Gate
 */

use Newspack\Institution;
use Newspack\Institution_REST_Controller;

/**
 * Test the read gate on the institution route.
 *
 * @group content-gate
 */
class Newspack_Test_Institution_REST_Controller extends WP_UnitTestCase {

	/**
	 * An institution with rules stored on it.
	 *
	 * @var int
	 */
	private $institution_id;

	/**
	 * A user holding the read capability but not the rules capability.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * A user holding both.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * The collection route for the institution post type.
	 *
	 * @var string
	 */
	private $route;

	/**
	 * Build the fixtures and register the routes.
	 *
	 * The rest_api_init action runs per test rather than once for the class:
	 * WP's test framework restores a once-per-process $wp_filter snapshot
	 * between tests, so anything registered in setUpBeforeClass silently decays.
	 */
	public function set_up() {
		parent::set_up();

		$this->route          = '/wp/v2/' . Institution::POST_TYPE;
		$this->editor_id      = $this->factory->user->create( [ 'role' => 'editor' ] );
		$this->admin_id       = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$this->institution_id = $this->factory->post->create(
			[
				'post_type'   => Institution::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test University',
			]
		);
		update_post_meta( $this->institution_id, Institution::META_PREFIX . 'email_domain', 'test-university.example' );
		update_post_meta( $this->institution_id, Institution::META_PREFIX . 'ip_range', '10.0.0.0/8' );

		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch a read of the collection as the given user.
	 *
	 * @param int    $user_id User to act as; 0 for a logged-out caller.
	 * @param string $context Request context.
	 * @return WP_REST_Response
	 */
	private function read_collection( $user_id, $context = 'edit' ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'GET', $this->route );
		$request->set_param( 'context', $context );
		return rest_do_request( $request );
	}

	/**
	 * The route is served by this controller and not the default one.
	 *
	 * Without this, a class that fails to load leaves get_rest_controller()
	 * returning null and the route unregistered — a silent 404 for the wizard,
	 * with every other test in this file still passing for the wrong reason.
	 */
	public function test_route_uses_this_controller() {
		$controller = get_post_type_object( Institution::POST_TYPE )->get_rest_controller();
		$this->assertInstanceOf( Institution_REST_Controller::class, $controller );
	}

	/**
	 * A logged-out caller cannot read the collection.
	 */
	public function test_logged_out_collection_read_is_refused() {
		$response = $this->read_collection( 0, 'view' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringNotContainsString(
			'Test University',
			wp_json_encode( $response->get_data() ),
			'A refused response must not carry institution data.'
		);
	}

	/**
	 * A logged-out caller cannot read a single institution.
	 */
	public function test_logged_out_item_read_is_refused() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', $this->route . '/' . $this->institution_id );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringNotContainsString( 'Test University', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * A caller with the read capability may use the edit context.
	 *
	 * Both dropdown consumers request context=edit because they read
	 * title.raw. Core would refuse them: it gates edit context on this post
	 * type's edit_posts, which is mapped to manage_options. If this test ever
	 * fails, those dropdowns are empty in the editor.
	 */
	public function test_read_capability_may_use_edit_context() {
		$response = $this->read_collection( $this->editor_id, 'edit' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'Test University', $data[0]['title']['raw'] );
	}
}
