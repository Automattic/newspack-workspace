<?php
/**
 * Tests for the institution REST controller.
 *
 * @package Newspack\Tests\Content_Gate
 */

use Newspack\Block_Visibility;
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
	 * A user holding neither capability, logged in.
	 *
	 * @var int
	 */
	private $contributor_id;

	/**
	 * A user holding both.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * A user holding RULES_CAPABILITY but not READ_CAPABILITY — an ops or
	 * billing role scoped to manage_options without content capabilities.
	 *
	 * @var int
	 */
	private $rules_only_id;

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
	 *
	 * WP_UnitTestCase_Base::tear_down() also calls unregister_all_meta_keys()
	 * after every test, which clears the global meta-key registry for every
	 * post type, not only this one. Institution::register_meta() only runs
	 * once, on the process-wide 'init' action fired at bootstrap, so without
	 * the explicit call below the first test in the file would see the
	 * registered meta fields and every test after it would not: the fields
	 * registered via register_post_meta() would already have been wiped by
	 * the previous test's tear-down.
	 */
	public function set_up() {
		parent::set_up();

		$this->route          = '/wp/v2/' . Institution::POST_TYPE;
		$this->editor_id      = $this->factory->user->create( [ 'role' => 'editor' ] );
		$this->subscriber_id  = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->author_id      = $this->factory->user->create( [ 'role' => 'author' ] );
		$this->contributor_id = $this->factory->user->create( [ 'role' => 'contributor' ] );
		$this->admin_id       = $this->factory->user->create( [ 'role' => 'administrator' ] );

		// A built-in role never isolates RULES_CAPABILITY from READ_CAPABILITY —
		// administrator holds both — so this user's capabilities are composed
		// directly rather than assigned from a built-in role, to exercise a
		// caller who holds manage_options alone.
		$this->rules_only_id = $this->factory->user->create( [ 'role' => '' ] );
		$rules_only_user     = new \WP_User( $this->rules_only_id );
		$rules_only_user->add_cap( 'read' );
		$rules_only_user->add_cap( 'manage_options' );

		$this->institution_id = $this->factory->post->create(
			[
				'post_type'   => Institution::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test University',
			]
		);
		update_post_meta( $this->institution_id, Institution::META_PREFIX . 'email_domain', 'test-university.example' );
		update_post_meta( $this->institution_id, Institution::META_PREFIX . 'ip_range', '10.0.0.0/8' );
		update_post_meta( $this->institution_id, Institution::META_PREFIX . 'reader_data', 'org=test-university' );

		Institution::register_meta();
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
	 * Dispatch the request the block editor's visibility panel sends for its
	 * institution picker, as the given user.
	 *
	 * Mirrors the path in src/content-gate/access-rule-option-sources.ts. The
	 * panel asks for per_page=-1, which api-fetch's fetch-all middleware turns
	 * into per_page=100 and walks by page, so that is what reaches the server.
	 * _fields is applied the way serve_request() would apply it, since
	 * rest_do_request() skips rest_post_dispatch.
	 *
	 * @param int $user_id User to act as.
	 * @return WP_REST_Response
	 */
	private function read_as_block_visibility_panel( $user_id ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'GET', $this->route );
		$request->set_query_params(
			[
				'context'  => 'edit',
				'status'   => 'publish',
				'orderby'  => 'title',
				'order'    => 'asc',
				'per_page' => 100,
				'page'     => 1,
				'_fields'  => 'id,title',
			]
		);
		return rest_filter_response_fields( rest_do_request( $request ), rest_get_server(), $request );
	}

	/**
	 * Assert that a 'meta' value is the empty-object shape the strip produces.
	 *
	 * Checks both that the value is an object, not an array — the strip casts
	 * to stdClass so the field's JSON type stays object regardless of caller —
	 * and that it carries no properties. Checking type alone would pass even
	 * if every rule field were still present, which would quietly defeat the
	 * withholding tests that call this.
	 *
	 * @param mixed $meta The 'meta' value from a response body.
	 */
	private function assert_meta_withheld( $meta ) {
		$this->assertInstanceOf( \stdClass::class, $meta, 'The stripped meta field must serialize as a JSON object, not an array.' );
		$this->assertSame( [], get_object_vars( $meta ), 'No stored field may reach a caller without the rules capability.' );
	}

	/**
	 * The read-side meta guard is attached at registration.
	 *
	 * `show_in_rest => true` would drop it, leaving the route's controller as the
	 * only thing between these three fields and an anonymous reader.
	 */
	public function test_meta_read_guard_is_wired_into_the_registration() {
		$keys = get_registered_meta_keys( 'post', Institution::POST_TYPE );

		foreach ( [ 'email_domain', 'ip_range', 'reader_data' ] as $suffix ) {
			$key = Institution::META_PREFIX . $suffix;
			$this->assertArrayHasKey( $key, $keys, "$key is registered." );
			$this->assertIsArray(
				$keys[ $key ]['show_in_rest'],
				"$key must declare show_in_rest as an array; `true` drops the read guard."
			);
			$this->assertSame(
				[ Institution::class, 'redact_meta_for_unauthorized' ],
				$keys[ $key ]['show_in_rest']['prepare_callback'],
				"$key must carry the read-side guard, which runs whichever controller serves the route."
			);
		}
	}

	/**
	 * The guard withholds the value from a reader without RULES_CAPABILITY.
	 *
	 * Asserted directly because its purpose is to hold when the controller does not:
	 * the route's gate depends on rest_controller_class surviving registration, and a
	 * plugin rebuilding register_post_type_args drops it silently. Every other read
	 * test in this class goes through the controller, so none of them would notice.
	 */
	public function test_meta_read_guard_withholds_without_the_rules_capability() {
		wp_set_current_user( 0 );
		$this->assertSame( '', Institution::redact_meta_for_unauthorized( 'example.org' ), 'Anonymous gets nothing.' );

		wp_set_current_user( $this->subscriber_id );
		$this->assertSame( '', Institution::redact_meta_for_unauthorized( 'example.org' ), 'A subscriber gets nothing.' );

		wp_set_current_user( $this->editor_id );
		$this->assertSame(
			'',
			Institution::redact_meta_for_unauthorized( 'example.org' ),
			'An editor holds READ_CAPABILITY but not RULES_CAPABILITY, and the controller blanks meta for them too.'
		);

		wp_set_current_user( $this->admin_id );
		$this->assertSame(
			'example.org',
			Institution::redact_meta_for_unauthorized( 'example.org' ),
			'An administrator holds RULES_CAPABILITY and sees the stored value.'
		);
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
	 * "is someone logged in": lowering READ_CAPABILITY to 'read' leaves the
	 * logged-out tests green but turns this one red.
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

	/**
	 * An Editor reading a single institution sees the row but not the rules.
	 *
	 * Deliberately omits context=edit: on the single-item route, edit context
	 * refuses the read tier by design — get_item_permissions_check() defers
	 * to the parent once its own check passes, and reading_collection is
	 * false here, so the parent's own edit-context gate (mapped to
	 * RULES_CAPABILITY) still applies. Without this test, the single-item
	 * read path is entirely unpinned: get_item_permissions_check() could
	 * refuse every non-administrator, or the strip in
	 * prepare_item_for_response() could be dropped for this route, and every
	 * other test in this file would stay green.
	 */
	/**
	 * A filter cannot re-add the stored fields after the strip.
	 *
	 * The strip resists this only because it runs after the parent's
	 * `rest_prepare_np_institution` filters, and nothing else pins that ordering --
	 * swapping the two statements in prepare_item_for_response() leaves every other
	 * test in this file green.
	 */
	public function test_a_filter_cannot_re_add_the_stored_fields() {
		$filter = function ( $response ) {
			$data         = $response->get_data();
			$data['meta'] = [ Institution::META_PREFIX . 'ip_range' => 'READDED' ];
			$response->set_data( $data );
			return $response;
		};
		add_filter( 'rest_prepare_' . Institution::POST_TYPE, $filter );

		try {
			wp_set_current_user( $this->editor_id );
			$response = rest_do_request( new WP_REST_Request( 'GET', $this->route . '/' . $this->institution_id ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assert_meta_withheld( $response->get_data()['meta'] );

			// The control: the filter really did run, so the assertion above is not
			// passing because nothing happened.
			wp_set_current_user( $this->admin_id );
			$admin = rest_do_request( new WP_REST_Request( 'GET', $this->route . '/' . $this->institution_id ) );
			$this->assertSame(
				'READDED',
				$admin->get_data()['meta'][ Institution::META_PREFIX . 'ip_range' ],
				'Precondition: the filter reaches the response for a caller who may see meta.'
			);
		} finally {
			remove_filter( 'rest_prepare_' . Institution::POST_TYPE, $filter );
		}
	}

	/**
	 * A filter returning a bare array does not take the route down.
	 *
	 * The parent's last statement is `apply_filters( "rest_prepare_{$post_type}", ... )`
	 * and a filter may return anything; core allows for that, which is why
	 * prepare_response_for_collection() carries its own instanceof guard. Without
	 * normalising, get_data() on an array is a fatal -- and the strip never runs, so
	 * the failure is not merely noisy.
	 */
	public function test_a_filter_returning_an_array_does_not_break_the_route() {
		$filter = function () {
			return [
				'id'   => 1,
				'meta' => [ Institution::META_PREFIX . 'ip_range' => 'LEAKED' ],
			];
		};
		add_filter( 'rest_prepare_' . Institution::POST_TYPE, $filter );

		try {
			wp_set_current_user( $this->editor_id );
			$response = rest_do_request( new WP_REST_Request( 'GET', $this->route . '/' . $this->institution_id ) );

			$this->assertSame( 200, $response->get_status(), 'The route survives a filter returning a bare array.' );
			$this->assert_meta_withheld( $response->get_data()['meta'] );
		} finally {
			remove_filter( 'rest_prepare_' . Institution::POST_TYPE, $filter );
		}
	}

	/**
	 * The read tier cannot enumerate drafts.
	 *
	 * Refused by core's sanitize_post_statuses(), which gates non-public statuses on
	 * this post type's edit_posts -- not by anything in this class. Since
	 * get_items_permissions_check() is replaced wholesale, that is an inherited
	 * dependency rather than a stated guarantee, and pinning it here means a later
	 * get_collection_params() override cannot open draft enumeration unnoticed.
	 */
	public function test_read_capability_cannot_enumerate_drafts() {
		$draft_id = $this->factory->post->create(
			[
				'post_type'   => Institution::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Draft institution',
			]
		);

		wp_set_current_user( $this->editor_id );

		// The status is asserted as "not a success" rather than a specific code:
		// core refuses this at parameter sanitisation, so it is a 400 rather than a
		// 403, and pinning that number would couple this to core's choice of error
		// rather than to the guarantee.
		foreach ( [ 'draft', 'private', 'any' ] as $status ) {
			$request = new WP_REST_Request( 'GET', $this->route );
			$request->set_param( 'status', $status );
			$response = rest_do_request( $request );

			$this->assertGreaterThanOrEqual(
				400,
				$response->get_status(),
				"An editor must not enumerate institutions with status=$status."
			);
		}

		// And the draft stays out of the default collection, which is the guarantee
		// the status refusal exists to protect.
		$response = rest_do_request( new WP_REST_Request( 'GET', $this->route ) );
		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertNotContains( $draft_id, $ids, 'A draft institution must not reach the read tier.' );
	}

	/**
	 * An editor reads the item successfully but sees no stored fields.
	 */
	public function test_editor_item_read_returns_200_without_the_rules() {
		wp_set_current_user( $this->editor_id );
		$response = rest_do_request( new WP_REST_Request( 'GET', $this->route . '/' . $this->institution_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assert_meta_withheld( $response->get_data()['meta'] );
	}

	/**
	 * A caller holding RULES_CAPABILITY but not READ_CAPABILITY still owns
	 * this route — the Audience wizard grants it access via
	 * Wizard::$capability, and it already sees every field once past the
	 * read gate — so it must not be locked out of the institutions list.
	 *
	 * Uses view context, which goes through check_read_permission() —
	 * unmodified from core, admitting any published post regardless of
	 * capability — so this exercises only get_items_permissions_check()
	 * above. It does not exercise check_update_permission(), which core also
	 * filters edit-context collections through: see
	 * test_rules_capability_alone_can_read_collection_with_edit_context()
	 * below for that path, which this test alone does not cover — a caller
	 * admitted here could still see an empty list at context=edit and this
	 * test would not catch it.
	 */
	public function test_rules_capability_alone_can_read_collection_and_see_rules() {
		$response = $this->read_collection( $this->rules_only_id, 'view' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '10.0.0.0/8', $data[0]['meta'][ Institution::META_PREFIX . 'ip_range' ] );
		$this->assertSame( 'test-university.example', $data[0]['meta'][ Institution::META_PREFIX . 'email_domain' ] );
	}

	/**
	 * The same caller — RULES_CAPABILITY but not READ_CAPABILITY — reading
	 * with context=edit, the context both real consumers
	 * (block-visibility.tsx and access-rule-control.tsx) actually request.
	 *
	 * This is the case the view-context test above does not cover: an
	 * edit-context collection read is filtered a second time, per item, by
	 * check_update_permission(), which is a separate method from
	 * get_items_permissions_check() and must independently admit
	 * RULES_CAPABILITY. Before that method was widened, this exact request
	 * returned 200 with an empty item array — passing the route-level gate
	 * and then losing every item to the per-item one, which the view-context
	 * test above cannot detect because it never reaches that second check.
	 */
	public function test_rules_capability_alone_can_read_collection_with_edit_context() {
		$response = $this->read_collection( $this->rules_only_id, 'edit' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $data, 'A caller who owns this route must still see its items at context=edit.' );
		$this->assertSame( '10.0.0.0/8', $data[0]['meta'][ Institution::META_PREFIX . 'ip_range' ] );
	}

	/**
	 * The panel and the read gate name one capability.
	 *
	 * The panel is the only non-administrator consumer of this route, so the two
	 * gates have to agree: a panel shown to a role the route refuses renders an
	 * empty institution picker with nothing failing, which is how NPPM-3128 was
	 * found. Both sides reference Block_Visibility::CONFIGURE_CAPABILITY; this
	 * pins that the route did not go back to a literal of its own.
	 */
	public function test_read_capability_is_the_block_visibility_panel_capability() {
		$this->assertSame( Block_Visibility::CONFIGURE_CAPABILITY, Institution_REST_Controller::READ_CAPABILITY );
		$this->assertSame( 'edit_others_posts', Institution_REST_Controller::READ_CAPABILITY );
	}

	/**
	 * Every role that is shown the panel can fill its institution picker, and
	 * no role that is refused the panel can read the names.
	 *
	 * Asserted per built-in role against the panel's own capability, so a change
	 * to either gate that leaves them disagreeing turns this red.
	 */
	public function test_panel_request_succeeds_for_exactly_the_roles_shown_the_panel() {
		$users = [
			'administrator' => $this->admin_id,
			'editor'        => $this->editor_id,
			'author'        => $this->author_id,
			'contributor'   => $this->contributor_id,
			'subscriber'    => $this->subscriber_id,
		];
		foreach ( $users as $role => $user_id ) {
			$response  = $this->read_as_block_visibility_panel( $user_id );
			$sees      = 200 === $response->get_status() && in_array( $this->institution_id, wp_list_pluck( (array) $response->get_data(), 'id' ), true );
			$has_panel = user_can( $user_id, Block_Visibility::CONFIGURE_CAPABILITY );

			$this->assertSame( $has_panel, $sees, "The $role role must read institution names exactly when it is shown the panel." );
		}
	}

	/**
	 * An Editor reads institution names through the panel's request, and gets
	 * the id and title only.
	 */
	public function test_editor_reads_institution_names_through_the_panel_request() {
		$response = $this->read_as_block_visibility_panel( $this->editor_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( [ 'id', 'title' ], array_keys( $data[0] ) );
		$this->assertSame( $this->institution_id, $data[0]['id'] );
		$this->assertSame( 'Test University', $data[0]['title']['raw'] );
	}

	/**
	 * An Author or a Contributor cannot read institution names through the
	 * panel's request.
	 *
	 * Edit context is what the panel sends, and the tests above that refuse these
	 * roles use view context, so this is the one that covers the panel's path.
	 */
	public function test_panel_request_is_refused_for_author_and_contributor() {
		$refused = [
			'author'      => $this->author_id,
			'contributor' => $this->contributor_id,
		];
		foreach ( $refused as $role => $user_id ) {
			$response = $this->read_as_block_visibility_panel( $user_id );

			$this->assertStringNotContainsString(
				'Test University',
				wp_json_encode( $response->get_data() ),
				"A refused $role response must not carry institution names."
			);
			$this->assertSame( 403, $response->get_status(), "The $role role must be refused." );
		}
	}

	/**
	 * Each stored access-rule field reaches an administrator and nobody below.
	 *
	 * Names all three fields, so a field that stops being registered, or starts
	 * reaching an Editor, fails here by name.
	 */
	public function test_each_rule_field_is_administrator_only() {
		$expected = [
			Institution::META_PREFIX . 'email_domain' => 'test-university.example',
			Institution::META_PREFIX . 'ip_range'     => '10.0.0.0/8',
			Institution::META_PREFIX . 'reader_data'  => 'org=test-university',
		];

		$admin = $this->read_collection( $this->admin_id, 'edit' );
		$this->assertSame( 200, $admin->get_status() );
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $admin->get_data()[0]['meta'][ $key ], "An administrator reads $key." );
		}

		$editor = $this->read_collection( $this->editor_id, 'edit' );
		$this->assertSame( 200, $editor->get_status() );
		$this->assert_meta_withheld( $editor->get_data()[0]['meta'] );
		foreach ( $expected as $value ) {
			$this->assertStringNotContainsString( $value, wp_json_encode( $editor->get_data() ), 'No rule value may reach an Editor.' );
		}
	}

	/**
	 * A caller without the rules capability sees no stored rules.
	 */
	public function test_rules_are_withheld_without_the_rules_capability() {
		$response = $this->read_collection( $this->editor_id, 'edit' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assert_meta_withheld( $data[0]['meta'] );
	}

	/**
	 * A caller with the rules capability sees them.
	 */
	public function test_rules_are_returned_with_the_rules_capability() {
		$response = $this->read_collection( $this->admin_id, 'edit' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '10.0.0.0/8', $data[0]['meta'][ Institution::META_PREFIX . 'ip_range' ] );
		$this->assertSame( 'test-university.example', $data[0]['meta'][ Institution::META_PREFIX . 'email_domain' ] );
	}

	/**
	 * Field selection cannot sidestep the strip.
	 *
	 * Core's rest_filter_response_fields() is hooked to rest_post_dispatch,
	 * which WP_REST_Server applies in serve_request() and
	 * serve_batch_request_v1() but NOT in dispatch(). rest_do_request() calls
	 * dispatch(), so simply setting _fields on a dispatched request never runs
	 * the filter at all and the test would pass without exercising anything.
	 * Applying the filter here is what serve_request() would have done.
	 */
	public function test_field_selection_cannot_recover_the_rules() {
		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'GET', $this->route );
		$request->set_param( 'context', 'edit' );
		$request->set_param( '_fields', 'meta' );

		$response = rest_filter_response_fields( rest_do_request( $request ), rest_get_server(), $request );
		$data     = $response->get_data();

		$this->assert_meta_withheld( $data[0]['meta'] );
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
