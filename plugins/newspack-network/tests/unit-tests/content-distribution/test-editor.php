<?php
/**
 * Class TestEditor
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Incoming_Post;
use WP_REST_Request;

/**
 * Test what the content-distribution editor exposes to the block editor.
 *
 * @group content-distribution-editor
 */
class TestEditor extends \WP_UnitTestCase {
	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * The block editor sends every registered meta key back with any meta edit, so a post
	 * that never stored the unlinked flag must still save the meta it was served.
	 */
	public function test_save_with_served_meta_succeeds_on_post_without_unlinked_flag() {
		$post_id = self::factory()->post->create();
		$this->assertFalse( metadata_exists( 'post', $post_id, Incoming_Post::UNLINKED_META ) );

		$get = new WP_REST_Request( 'GET', "/wp/v2/posts/$post_id" );
		$get->set_param( 'context', 'edit' );
		$meta = rest_do_request( $get )->get_data()['meta'];

		$update = new WP_REST_Request( 'POST', "/wp/v2/posts/$post_id" );
		$update->set_body_params( [ 'meta' => $meta ] );
		$response = rest_do_request( $update );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}
}
