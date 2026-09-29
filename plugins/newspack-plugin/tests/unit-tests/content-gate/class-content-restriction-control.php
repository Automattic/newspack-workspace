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
	 * @return int Gate ID.
	 */
	private function create_regwall_gate_for_posts() {
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
					'require_verification' => false,
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
}
