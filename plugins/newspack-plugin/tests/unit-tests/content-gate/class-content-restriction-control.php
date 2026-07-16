<?php
/**
 * Tests for Content_Restriction_Control (NPPM-2982).
 *
 * @package Newspack
 */

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
		parent::tear_down();
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
