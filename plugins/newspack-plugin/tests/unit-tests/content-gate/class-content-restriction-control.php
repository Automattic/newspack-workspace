<?php
/**
 * Tests for Content_Restriction_Control (NPPM-2982).
 *
 * @package Newspack
 */

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;

/**
 * Test_Content_Restriction_Control.
 */
class Test_Content_Restriction_Control extends WP_UnitTestCase {

	/**
	 * Reset registered meta between tests.
	 */
	public function tear_down() {
		foreach ( array_column( (array) Content_Restriction_Control::get_available_post_types(), 'value' ) as $subtype ) {
			unregister_meta_key( 'post', Content_Restriction_Control::IS_EXEMPT_META_KEY, $subtype );
		}
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		$this->reset_post_gates_cache();
		remove_all_filters( 'newspack_reader_activation_enabled' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Clear the request-scoped get_post_gates() memo, so gates created or
	 * deleted mid-test become visible to subsequent restriction checks.
	 */
	private function reset_post_gates_cache() {
		$post_gates_map_property = new ReflectionProperty( Content_Restriction_Control::class, 'post_gates_map' );
		$post_gates_map_property->setAccessible( true );
		$post_gates_map_property->setValue( null, [] );
	}

	/**
	 * Create a published gate with an active registration wall applying to all posts.
	 *
	 * @param bool $require_verification Whether the wall requires a verified email.
	 *
	 * @return int Gate ID.
	 */
	private function create_regwall_gate_for_posts( $require_verification = false ) {
		$regwall_gate_id = Content_Gate::create_gate( [ 'title' => 'Regwall Gate' ] );
		Content_Gate::update_gate_settings(
			$regwall_gate_id,
			[
				'title'         => 'Regwall Gate',
				'status'        => 'publish',
				'priority'      => 1,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => $require_verification,
					'gate_id'              => 0,
				],
				'custom_access' => [
					'active'       => false,
					'metering'     => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'gate_id'      => 0,
					'access_rules' => [],
				],
			]
		);
		$this->reset_post_gates_cache();
		return $regwall_gate_id;
	}

	/**
	 * Create a published gate that only a subscriber to a product passes.
	 *
	 * @param string[] $post_types Post types the gate covers.
	 *
	 * @return int Gate ID.
	 */
	private function create_paid_gate_for( $post_types ) {
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];

		$gate_id = Content_Gate::create_gate( [ 'title' => 'Paid Gate' ] );
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'status'        => 'publish',
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => $post_types,
					],
				],
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'subscription',
								'value' => [ 50 ],
							],
						],
					],
				],
			]
		);
		Content_Gate::flush_gates_cache();
		$this->reset_post_gates_cache();
		return $gate_id;
	}

	/**
	 * The post_has_restrictions() check is the user-agnostic "is this post
	 * gated at all" check (used e.g. by the Extended Access LD+JSON schema):
	 * it must be true for a gated post even for users who themselves have access.
	 */
	public function test_post_has_restrictions_reflects_gates() {
		$this->enable_gates_and_register();
		$gated_post_id = self::factory()->post->create();

		$this->assertFalse(
			Content_Gate::post_has_restrictions( $gated_post_id ),
			'A post with no gates has no restrictions.'
		);

		$this->create_regwall_gate_for_posts();

		$this->assertTrue(
			Content_Gate::post_has_restrictions( $gated_post_id ),
			'A post covered by a published active gate has restrictions.'
		);

		// User-agnostic: an admin (not personally restricted) still sees the post as having restrictions.
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		$this->assertFalse(
			Content_Gate::is_post_restricted( $gated_post_id ),
			'The post is not restricted for an admin.'
		);
		$this->assertTrue(
			Content_Gate::post_has_restrictions( $gated_post_id ),
			'The post still has restrictions regardless of the current user.'
		);
	}

	/**
	 * A post exempted from access control has no restrictions.
	 */
	public function test_post_has_restrictions_respects_exemption() {
		$this->enable_gates_and_register();
		$exempt_post_id = self::factory()->post->create();
		$this->create_regwall_gate_for_posts();
		update_post_meta( $exempt_post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true );

		$this->assertFalse(
			Content_Gate::post_has_restrictions( $exempt_post_id ),
			'An exempt post has no restrictions.'
		);
	}

	/**
	 * Gating stands down when Audience Management is off: a post covered by a
	 * stored gate reports no restrictions, so it is never advertised as gated
	 * while Access Control enforces nothing. Pins parity with is_post_restricted().
	 */
	public function test_post_has_restrictions_stands_down_when_gating_inactive() {
		$this->enable_gates_and_register();
		$gated_post_id = self::factory()->post->create();
		$this->create_regwall_gate_for_posts();

		$this->assertTrue(
			Content_Gate::post_has_restrictions( $gated_post_id ),
			'Sanity: the gate covers the post while gating is active.'
		);

		// Audience Management off => gating is inactive.
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );

		$this->assertFalse(
			Content_Gate::post_has_restrictions( $gated_post_id ),
			'With gating inactive, a gated post reports no restrictions.'
		);
	}

	/**
	 * Runs in a separate process so that other content-gate test classes
	 * defining NEWSPACK_CONTENT_GATES=true in their setUp (a constant, so it
	 * can never become undefined again once defined) can't leak into this
	 * test and make it see the feature as already enabled.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_meta_not_registered_when_feature_disabled() {
		// NEWSPACK_CONTENT_GATES is undefined in the default test env.
		Content_Restriction_Control::register_meta();
		$this->assertFalse(
			registered_meta_key_exists( 'post', Content_Restriction_Control::IS_EXEMPT_META_KEY, 'post' )
		);
	}

	/**
	 * Enable the feature and (re)register meta + strip filters for a test.
	 */
	private function enable_gates_and_register() {
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		Content_Restriction_Control::register_meta();
		rest_get_server(); // Ensure REST server + core routes are initialized.
	}

	/**
	 * A lower-role save carrying the exempt meta should not be blocked; the
	 * meta should simply be dropped instead of hard-failing the whole save.
	 */
	public function test_lower_role_save_with_exempt_meta_is_not_blocked() {
		$this->enable_gates_and_register();
		$author  = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => $author,
			]
		);
		wp_set_current_user( $author );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			[ 'meta' => [ Content_Restriction_Control::IS_EXEMPT_META_KEY => true ] ]
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty(
			get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'The exempt meta must not have been written by a lower role.'
		);
	}

	/**
	 * An editor (who can edit_others_posts) should still be able to set the
	 * exempt meta via a REST save.
	 */
	public function test_editor_can_still_set_exempt_meta() {
		$this->enable_gates_and_register();
		$editor  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		wp_set_current_user( $editor );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			[ 'meta' => [ Content_Restriction_Control::IS_EXEMPT_META_KEY => true ] ]
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue(
			(bool) get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true )
		);
	}

	/**
	 * Skipping access checks grants no say over gate settings. That stays with
	 * whoever can edit other people's posts.
	 */
	public function test_skipping_access_checks_does_not_grant_the_exempt_meta() {
		$this->enable_gates_and_register();
		$author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_author' => $author,
			]
		);
		$skip    = function ( $skips, $user_id ) use ( $author ) {
			return $user_id === $author ? true : $skips;
		};
		add_filter( 'newspack_content_gate_should_skip_access_checks', $skip, 10, 2 );
		wp_set_current_user( $author );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			[ 'meta' => [ Content_Restriction_Control::IS_EXEMPT_META_KEY => true ] ]
		);
		$response = rest_do_request( $request );
		remove_filter( 'newspack_content_gate_should_skip_access_checks', $skip, 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty(
			get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ),
			'The exempt meta must not be written by an account that only skips access checks.'
		);
	}

	/**
	 * An account Access Control never restricts reads a gated post whether or
	 * not it can edit that post.
	 */
	public function test_accounts_that_skip_access_checks_read_gated_posts() {
		$this->enable_gates_and_register();
		$this->create_paid_gate_for( [ 'post' ] );
		$post_id    = self::factory()->post->create();
		$held_id    = self::factory()->user->create( [ 'role' => 'author' ] );
		$skipped_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$skip       = function ( $skips, $user_id ) use ( $skipped_id ) {
			return $user_id === $skipped_id ? true : $skips;
		};
		add_filter( 'newspack_content_gate_should_skip_access_checks', $skip, 10, 2 );
		$held    = Content_Restriction_Control::is_post_restricted( false, $post_id, $held_id );
		$skipped = Content_Restriction_Control::is_post_restricted( false, $post_id, $skipped_id );
		remove_filter( 'newspack_content_gate_should_skip_access_checks', $skip, 10 );

		$this->assertTrue( $held, 'An author meets the paywall on a post they cannot edit.' );
		$this->assertFalse( $skipped, 'An account filtered in does not.' );
	}

	/**
	 * Staff are never gated, including on a post type they cannot edit.
	 */
	public function test_staff_read_gated_posts_of_a_type_they_cannot_edit() {
		$this->enable_gates_and_register();
		register_post_type(
			'np_test_locked',
			[
				'public'          => true,
				'show_in_rest'    => true,
				'capability_type' => 'np_test_locked',
				'map_meta_cap'    => true,
			]
		);
		$this->create_paid_gate_for( [ 'np_test_locked' ] );
		$post_id   = self::factory()->post->create( [ 'post_type' => 'np_test_locked' ] );
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );

		$editor_can_edit   = user_can( $editor_id, 'edit_post', $post_id );
		$editor_restricted = Content_Restriction_Control::is_post_restricted( false, $post_id, $editor_id );
		$author_restricted = Content_Restriction_Control::is_post_restricted( false, $post_id, $author_id );
		unregister_post_type( 'np_test_locked' );

		$this->assertFalse( $editor_can_edit, 'The premise: an editor cannot edit this type.' );
		$this->assertTrue( $author_restricted, 'An author is gated here, which shows the gate covers the type.' );
		$this->assertFalse( $editor_restricted, 'An editor is never gated.' );
	}

	/**
	 * A registration wall that requires a verified email holds readers only.
	 * Reader Activation verifies no one else, so any other account held there
	 * would have no way through.
	 */
	public function test_verification_wall_holds_readers_only() {
		$this->enable_gates_and_register();
		$gate          = Content_Gate::get_gate( $this->create_regwall_gate_for_posts( true ) );
		$is_restricted = function ( $user_id ) use ( $gate ) {
			return Content_Restriction_Control::evaluate_gate( $gate, $user_id )['restricted'];
		};

		$reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertTrue( $is_restricted( $reader_id ), 'An unverified reader is held.' );
		update_user_meta( $reader_id, \Newspack\Reader_Activation::EMAIL_VERIFIED, true );
		$this->assertFalse( $is_restricted( $reader_id ), 'A verified reader passes.' );

		foreach ( [ 'author', 'contributor', 'editor' ] as $role ) {
			$user_id = self::factory()->user->create( [ 'role' => $role ] );
			$this->assertFalse( $is_restricted( $user_id ), "A $role cannot verify, so is not held." );
		}

		// An author who registered as a reader can verify, so is held until they do.
		$reader_author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		update_user_meta( $reader_author_id, \Newspack\Reader_Activation::READER, true );
		$this->assertTrue( $is_restricted( $reader_author_id ), 'A reader who is also an author is held.' );

		$this->assertTrue( $is_restricted( 0 ), 'A logged-out visitor still meets the wall.' );
		$this->assertTrue( $is_restricted( 999999 ), 'An ID that matches no user is held.' );
	}
}
