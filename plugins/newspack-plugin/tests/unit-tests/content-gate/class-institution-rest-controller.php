<?php
/**
 * Tests for the institution REST controller.
 *
 * @package Newspack\Tests\Content_Gate
 */

use Newspack\Institution;
use Newspack\Institution_REST_Controller;

/**
 * Test the read and write gates on the institution route.
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
	 * A user holding neither capability, logged in.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * A user holding neither capability, logged in.
	 *
	 * @var int
	 */
	private $author_id;

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
		$this->subscriber_id  = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->author_id      = $this->factory->user->create( [ 'role' => 'author' ] );
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
	 *
	 * The body assertion runs first: PHPUnit stops at the first failing
	 * assertion, so if the status check ran first, a regression that returns
	 * 200 with the institution's data would never reach the body check at all.
	 */
	public function test_logged_out_collection_read_is_refused() {
		$response = $this->read_collection( 0, 'view' );

		$this->assertStringNotContainsString(
			'Test University',
			wp_json_encode( $response->get_data() ),
			'A refused response must not carry institution data.'
		);
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A logged-out caller cannot read a single institution.
	 */
	public function test_logged_out_item_read_is_refused() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', $this->route . '/' . $this->institution_id );
		$response = rest_do_request( $request );

		$this->assertStringNotContainsString( 'Test University', wp_json_encode( $response->get_data() ) );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A logged-in caller with neither capability cannot read the collection.
	 *
	 * Every logged-in WordPress role, Subscriber included, holds the primitive
	 * 'read' capability, so this — not the logged-out tests above — is what
	 * proves the gate checks READ_CAPABILITY specifically rather than merely
	 * "is someone logged in". Mutation #1 in the report shows this directly:
	 * lowering READ_CAPABILITY to 'read' leaves the logged-out tests green but
	 * turns this one red.
	 */
	public function test_subscriber_collection_read_is_refused() {
		$response = $this->read_collection( $this->subscriber_id, 'view' );

		$this->assertStringNotContainsString( 'Test University', wp_json_encode( $response->get_data() ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An Author — who can edit their own posts but not edit_others_posts —
	 * cannot read the collection either.
	 */
	public function test_author_collection_read_is_refused() {
		$response = $this->read_collection( $this->author_id, 'view' );

		$this->assertStringNotContainsString( 'Test University', wp_json_encode( $response->get_data() ) );
		$this->assertSame( 403, $response->get_status() );
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

	/**
	 * A trashed institution cannot be read via REST, even by a caller who
	 * holds READ_CAPABILITY.
	 *
	 * The controller's get_item_permissions_check() defers to the parent once
	 * its own check passes, rather than returning true unconditionally, so the
	 * parent's tail call to check_read_permission() still applies: that method
	 * only lets a non-published post through for a caller who also holds this
	 * post type's read_post capability, which — like every other capability on
	 * this post type — is mapped to RULES_CAPABILITY, not READ_CAPABILITY.
	 */
	public function test_trashed_institution_read_is_refused_for_editor() {
		wp_trash_post( $this->institution_id );
		wp_set_current_user( $this->editor_id );
		$request  = new WP_REST_Request( 'GET', $this->route . '/' . $this->institution_id );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An administrator, who holds RULES_CAPABILITY, can still view a trashed
	 * institution — matching how core already treats trashed content for a
	 * caller privileged enough to manage it.
	 */
	public function test_trashed_institution_readable_by_administrator() {
		wp_trash_post( $this->institution_id );
		wp_set_current_user( $this->admin_id );
		$request  = new WP_REST_Request( 'GET', $this->route . '/' . $this->institution_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	// =========================================================================
	// Write path — nothing here changes the write gate; these prove it's still
	// intact after the read-side broadening above, and stay red if it isn't.
	// =========================================================================

	/**
	 * An Editor — who holds READ_CAPABILITY but not RULES_CAPABILITY — cannot
	 * update an institution via REST.
	 *
	 * This is the test that would have caught the scoping gap: an earlier
	 * revision of check_update_permission() broadened unconditionally to
	 * READ_CAPABILITY, which this same method also gates real PATCH/PUT
	 * requests with (core's update_item_permissions_check() calls it). Without
	 * the collection-read scoping, this test fails with a 200 instead of 403.
	 */
	public function test_editor_cannot_update_institution_via_rest() {
		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'PATCH', $this->route . '/' . $this->institution_id );
		$request->set_param( 'title', 'Hijacked University' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'Test University', get_post( $this->institution_id )->post_title );
	}

	/**
	 * An administrator can still update an institution via REST.
	 */
	public function test_administrator_can_update_institution_via_rest() {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PATCH', $this->route . '/' . $this->institution_id );
		$request->set_param( 'title', 'Renamed University' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Renamed University', get_post( $this->institution_id )->post_title );
	}

	/**
	 * An Editor cannot create an institution via REST.
	 */
	public function test_editor_cannot_create_institution_via_rest() {
		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'POST', $this->route );
		$request->set_param( 'title', 'New University' );
		$request->set_param( 'status', 'publish' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An administrator can still create an institution via REST.
	 */
	public function test_administrator_can_create_institution_via_rest() {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', $this->route );
		$request->set_param( 'title', 'New University' );
		$request->set_param( 'status', 'publish' );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'New University', $response->get_data()['title']['raw'] );
	}

	/**
	 * An Editor cannot delete an institution via REST.
	 */
	public function test_editor_cannot_delete_institution_via_rest() {
		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'DELETE', $this->route . '/' . $this->institution_id );
		$request->set_param( 'force', true );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNotNull( get_post( $this->institution_id ) );
	}

	/**
	 * An administrator can still delete an institution via REST.
	 */
	public function test_administrator_can_delete_institution_via_rest() {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'DELETE', $this->route . '/' . $this->institution_id );
		$request->set_param( 'force', true );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( get_post( $this->institution_id ) );
	}
}
