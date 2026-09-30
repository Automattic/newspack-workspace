<?php
/**
 * Tests for who can manage Network nodes and when a node's key is shown.
 *
 * @package Newspack_Network
 */

use Newspack_Network\Hub\Connect_Node;
use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Nodes;

/**
 * Node management capabilities, pairing record and node details screen.
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

	/**
	 * Build a retrieve-key request.
	 *
	 * @param string $nonce The connection nonce.
	 * @return WP_REST_Request
	 */
	private function retrieve_key_request( $nonce ) {
		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/retrieve-key' );
		$request->set_param( 'site', self::NODE_URL );
		$request->set_param( 'nonce', $nonce );
		return $request;
	}

	/**
	 * A successful key retrieval records that the node has paired, and returns the
	 * key as before.
	 */
	public function test_retrieve_key_records_pairing() {
		$nonce = Connect_Node::generate_nonce( $this->node_id );

		$before   = time();
		$response = Connect_Node::handle_retrieve_key( $this->retrieve_key_request( $nonce ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'secret_key' => $this->secret_key ], $response->get_data() );
		$this->assertGreaterThanOrEqual( $before, (int) get_post_meta( $this->node_id, 'paired-at', true ) );
		$this->assertTrue( ( new Node( $this->node_id ) )->is_paired() );
	}

	/**
	 * A retrieval with a wrong nonce records nothing.
	 */
	public function test_failed_retrieve_key_does_not_record_pairing() {
		Connect_Node::generate_nonce( $this->node_id );

		$response = Connect_Node::handle_retrieve_key( $this->retrieve_key_request( 'wrong-nonce' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', get_post_meta( $this->node_id, 'paired-at', true ) );
		$this->assertFalse( ( new Node( $this->node_id ) )->is_paired() );
	}

	/**
	 * Render the node details box.
	 *
	 * @return string
	 */
	private function render_details() {
		wp_set_current_user( $this->admin_id );
		ob_start();
		Nodes::node_details_metabox_content( get_post( $this->node_id ) );
		return ob_get_clean();
	}

	/**
	 * The key and link button are shown until the node pairs.
	 */
	public function test_details_show_key_before_pairing() {
		$output = $this->render_details();
		$this->assertStringContainsString( $this->secret_key, $output );
		$this->assertStringContainsString( 'Link the site', $output );
	}

	/**
	 * Once the node has paired, the details show when instead of the key.
	 */
	public function test_details_hide_key_after_pairing() {
		update_post_meta( $this->node_id, 'paired-at', strtotime( '2026-01-15 12:00:00 UTC' ) );

		$output = $this->render_details();
		$this->assertStringNotContainsString( $this->secret_key, $output );
		$this->assertStringNotContainsString( 'Link the site', $output );
		$this->assertStringContainsString( 'Linked on', $output );
		$this->assertStringContainsString( '2026', $output );
	}
}
