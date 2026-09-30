<?php
/**
 * Tests for who can manage Network nodes.
 *
 * @package Newspack_Network
 */

use Newspack_Network\Hub\Nodes;

/**
 * Node management capabilities.
 *
 * @group node-management
 */
class TestNodeManagement extends WP_UnitTestCase {

	/**
	 * The node's URL.
	 */
	const NODE_URL = 'https://managed-node.example.test';

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Node post ID.
	 *
	 * @var int
	 */
	private $node_id;

	/**
	 * The node's secret key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Register the post type the way a Hub does, and create a node.
	 */
	public function set_up() {
		parent::set_up();

		// The suite's site has no Hub role, so the post type is not registered on init.
		Nodes::register_post_type();

		$this->admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		$this->node_id = self::factory()->post->create(
			[
				'post_type'   => Nodes::POST_TYPE_SLUG,
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			]
		);
		$this->secret_key = 'test-secret-key-value';
		update_post_meta( $this->node_id, 'node-url', self::NODE_URL );
		update_post_meta( $this->node_id, 'secret-key', $this->secret_key );
	}

	/**
	 * Unregister the post type so it does not leak into other tests.
	 */
	public function tear_down() {
		unregister_post_type( Nodes::POST_TYPE_SLUG );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The post type's primitive capabilities.
	 *
	 * @return array
	 */
	private function primitive_caps() {
		$caps = get_post_type_object( Nodes::POST_TYPE_SLUG )->cap;
		return [
			'create_posts'       => $caps->create_posts,
			'edit_posts'         => $caps->edit_posts,
			'edit_others_posts'  => $caps->edit_others_posts,
			'publish_posts'      => $caps->publish_posts,
			'delete_posts'       => $caps->delete_posts,
			'read_private_posts' => $caps->read_private_posts,
		];
	}

	/**
	 * An editor cannot list, add, edit or delete nodes.
	 */
	public function test_editor_cannot_manage_nodes() {
		foreach ( $this->primitive_caps() as $name => $cap ) {
			$this->assertFalse( user_can( $this->editor_id, $cap ), "Editor should lack $name on nodes." );
		}

		wp_set_current_user( $this->editor_id );
		$caps = get_post_type_object( Nodes::POST_TYPE_SLUG )->cap;
		$this->assertFalse( current_user_can( $caps->edit_post, $this->node_id ), 'Editor should not edit a node.' );
		$this->assertFalse( current_user_can( 'edit_post', $this->node_id ), 'Editor should not edit a node.' );
		$this->assertFalse( current_user_can( 'delete_post', $this->node_id ), 'Editor should not delete a node.' );
	}

	/**
	 * An editor cannot edit or delete a node they are the author of.
	 */
	public function test_editor_cannot_manage_own_node() {
		wp_update_post(
			[
				'ID'          => $this->node_id,
				'post_author' => $this->editor_id,
			]
		);
		wp_set_current_user( $this->editor_id );
		$this->assertFalse( current_user_can( 'edit_post', $this->node_id ) );
		$this->assertFalse( current_user_can( 'delete_post', $this->node_id ) );
	}

	/**
	 * An administrator can list, add, edit and delete nodes.
	 */
	public function test_administrator_can_manage_nodes() {
		foreach ( $this->primitive_caps() as $name => $cap ) {
			$this->assertTrue( user_can( $this->admin_id, $cap ), "Administrator should have $name on nodes." );
		}

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( current_user_can( 'edit_post', $this->node_id ) );
		$this->assertTrue( current_user_can( 'delete_post', $this->node_id ) );
	}

	/**
	 * Registering the post type leaves manage_options itself untouched.
	 *
	 * Mapping meta capabilities to manage_options with map_meta_cap on would make
	 * core resolve every manage_options check as a post capability.
	 */
	public function test_manage_options_resolves_normally() {
		$this->assertTrue( user_can( $this->admin_id, 'manage_options' ) );
		$this->assertFalse( user_can( $this->editor_id, 'manage_options' ) );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}
}
