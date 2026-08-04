<?php
/**
 * Class TestApi
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

require_once __DIR__ . '/mock-data-events.php';

use Newspack\Data_Events;
use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Content_Distribution\API;
use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Hub\Node as Hub_Node;
use WP_REST_Request;

/**
 * Test the content-distribution REST API.
 *
 * @group content-distribution-api
 */
class TestApi extends \WP_UnitTestCase {
	/**
	 * "Mocked" network nodes.
	 *
	 * @var array
	 */
	protected $network = [
		[
			'id'    => 1234,
			'title' => 'Test Node',
			'url'   => 'https://node.test',
		],
	];

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Hub_Node::HUB_NODES_SYNCED_OPTION, $this->network );
		Data_Events::$mock_dispatch_return = true;
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		Data_Events::$mock_dispatch_return = true;
		parent::tear_down();
	}

	/**
	 * Build a distributable post and a distribute request for it.
	 *
	 * @return array The post ID and the WP_REST_Request.
	 */
	private function make_distribute_request() {
		$author  = $this->factory->user->create( [ 'role' => 'editor' ] );
		$post_id = $this->factory->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_author' => $author,
			]
		);

		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/content-distribution/distribute/' . $post_id );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'urls', [ $this->network[0]['url'] ] );
		$request->set_param( 'status_on_publish', 'draft' );

		return [ $post_id, $request ];
	}

	/**
	 * A failed dispatch must be surfaced as an error, not a 200 response.
	 */
	public function test_distribute_returns_error_when_dispatch_fails() {
		Data_Events::$mock_dispatch_return = new \WP_Error(
			'newspack_data_events_action_not_registered',
			'Action not registered.'
		);

		list( , $request ) = $this->make_distribute_request();
		$result            = API::distribute( $request );

		$this->assertWPError(
			$result,
			'distribute() must return a WP_Error when Data_Events::dispatch() fails.'
		);
		$this->assertSame(
			500,
			$result->get_error_data()['status'],
			'A failed dispatch is a server-side condition and must return HTTP 500.'
		);
	}

	/**
	 * A failed dispatch must not write the payload hash, and must leave the destination
	 * recorded in distribution meta, so the next post update retries distribution.
	 */
	public function test_distribute_does_not_store_payload_hash_when_dispatch_fails() {
		Data_Events::$mock_dispatch_return = new \WP_Error(
			'newspack_data_events_action_not_registered',
			'Action not registered.'
		);

		list( $post_id, $request ) = $this->make_distribute_request();
		API::distribute( $request );

		$this->assertEmpty(
			get_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, true ),
			'The payload hash must not be stored when dispatch fails.'
		);
		$this->assertNotEmpty(
			get_post_meta( $post_id, Outgoing_Post::DISTRIBUTED_POST_META, true ),
			'The destination must stay recorded so the next save retries distribution.'
		);
	}

	/**
	 * The happy path is unaffected: a successful dispatch stores the payload hash.
	 */
	public function test_distribute_stores_payload_hash_on_success() {
		Data_Events::$mock_dispatch_return = null; // Real dispatch() returns void on success.

		list( $post_id, $request ) = $this->make_distribute_request();
		$result                    = API::distribute( $request );

		$this->assertNotWPError( $result, 'distribute() must succeed when dispatch succeeds.' );
		$this->assertNotEmpty(
			get_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, true ),
			'The payload hash must be stored on a successful dispatch.'
		);
	}
}
