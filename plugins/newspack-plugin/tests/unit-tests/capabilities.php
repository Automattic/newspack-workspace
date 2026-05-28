<?php
/**
 * Test_Capabilities class.
 *
 * @package Newspack
 */

use Newspack\Capabilities;

/**
 * Class Test_Capabilities
 */
class Test_Capabilities extends WP_UnitTestCase {
	/**
	 * Test the map_capabilities method.
	 */
	public function test_capabilities_mapping() {
		// Mock the post types.
		$this->mock_post_type_object( 'newspack_post' );

		$user_all_caps = [
			'edit_posts'   => true,
			'delete_posts' => false,
		];
		$required_caps = [ 'edit_newspack_posts' ];

		$result = Capabilities::map_capabilities( $user_all_caps, $required_caps );
		$this->assertEquals( array_merge( $user_all_caps, [ 'edit_newspack_posts' => true ] ), $result, 'User with edit_posts cap should get the edit_newspack_post cap, too' );

		$user_all_caps = [
			'edit_posts'   => false,
			'delete_posts' => false,
		];
		$result = Capabilities::map_capabilities( $user_all_caps, $required_caps );
		$this->assertEquals( $user_all_caps, $result, 'User without edit_posts cap should not get the edit_newspack_post cap' );

		$user_all_caps = [
			'edit_posts'   => true,
			'delete_posts' => true,
		];
		$required_caps = [ 'edit_newspack_posts', 'delete_newspack_posts' ];
		$result = Capabilities::map_capabilities( $user_all_caps, $required_caps );
		$this->assertEquals(
			array_merge(
				$user_all_caps,
				[
					'edit_newspack_posts'   => true,
					'delete_newspack_posts' => true,
				]
			),
			$result,
			'Multiple required caps are supported.'
		);

		add_filter(
			'newspack_capabilities_map',
			fn() => [
				// 'newspack_post' caps should inherit from 'page'.
				'newspack_post' => 'page',
			]
		);

		$result = Capabilities::map_capabilities( $user_all_caps, [ 'edit_newspack_posts' ] );
		$this->assertEquals(
			false,
			isset( $result['edit_newspack_posts'] ),
			"User can't edit posts which inherrit caps from pages (even though they can edit posts)."
		);
	}

	/**
	 * Test the current_user_can method.
	 */
	public function test_capabilities_current_user_can() {
		// Create a test user with administrator role.
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		$this->assertTrue( Capabilities::current_user_can( 'edit_others_posts', 'post' ), 'Administrator should be able to edit others posts' );
		// Test with custom post type.
		$this->mock_post_type_object( 'admin_test_type' );
		$this->assertTrue( Capabilities::current_user_can( 'edit_posts', 'admin_test_type' ), 'Administrator should be able to edit custom post type' );

		// Create a test user with editor role.
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		$this->assertTrue( Capabilities::current_user_can( 'edit_posts', 'post' ), 'Editor should be able to edit posts' );
		$this->mock_post_type_object( 'test_post_type' );
		$this->assertTrue( Capabilities::current_user_can( 'edit_posts', 'test_post_type' ), 'Editor should be able to edit custom post type' );

		// Create a test user with subscriber role.
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );
		$this->assertFalse( Capabilities::current_user_can( 'edit_posts', 'post' ), 'Subscriber should not be able to edit posts' );
	}

	/**
	 * Test current_user_can method with no user logged in.
	 */
	public function test_capabilities_current_user_can_no_user() {
		// Make sure no user is logged in.
		wp_set_current_user( 0 );
		$this->assertFalse( Capabilities::current_user_can( 'edit_posts', 'post' ), 'Anonymous user should not be able to edit posts' );
		$this->assertFalse( Capabilities::current_user_can( 'publish_posts', 'post' ), 'Anonymous user should not be able to publish posts' );
		$this->assertFalse( Capabilities::current_user_can( 'delete_posts', 'post' ), 'Anonymous user should not be able to delete posts' );
	}

	/**
	 * Test current_user_can method with empty/invalid parameters.
	 */
	public function test_capabilities_current_user_can_invalid_parameters() {
		// Create a test user with editor role.
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		$this->assertFalse( Capabilities::current_user_can( '', 'post' ), 'Should return false for empty capability' );
		$this->assertFalse( Capabilities::current_user_can( 'edit_posts', '' ), 'Should return false for empty post type' );
		$this->assertFalse( Capabilities::current_user_can( null, 'post' ), 'Should return false for null capability' );
		$this->assertFalse( Capabilities::current_user_can( 'edit_posts', null ), 'Should return false for null post type' );
	}

	/**
	 * Mock a post type object.
	 *
	 * @param string $post_type Post type name.
	 */
	private function mock_post_type_object( $post_type ) {
		register_post_type(
			$post_type,
			[
				'capability_type' => $post_type,
				'map_meta_cap'    => true,
			]
		);

		// Map capabilities to regular post.
		add_filter(
			'newspack_capabilities_map',
			function( $capabilities_map ) use ( $post_type ) {
				$capabilities_map[ $post_type ] = 'post';
				return $capabilities_map;
			}
		);
	}
}
