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
use Newspack_Network\Content_Distribution\Admin;
use Newspack_Network\Content_Distribution\API;
use Newspack_Network\Content_Distribution\Incoming_Post;
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
	 * The 'distribute' route's permission callback.
	 *
	 * @var callable
	 */
	private $permission_callback;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Hub_Node::HUB_NODES_SYNCED_OPTION, $this->network );
		Data_Events::$mock_dispatch_return = true;

		// Clear any existing routes.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		API::register_routes();

		$routes = rest_get_server()->get_routes();
		$route  = $routes['/newspack-network/v1/content-distribution/distribute/(?P<post_id>\d+)'][0];

		$this->permission_callback = $route['permission_callback'];
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		Data_Events::$mock_dispatch_return = true;
		// Discard the server this class registered its routes on, so no later test inherits it.
		$GLOBALS['wp_rest_server'] = null;
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
	 * Build a bare distribute request for the given post ID.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return WP_REST_Request
	 */
	private function make_request( $post_id ) {
		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/content-distribution/distribute/' . $post_id );
		$request->set_param( 'post_id', $post_id );

		return $request;
	}

	/**
	 * An author cannot distribute a post authored by another user, even
	 * though the 'author' role is granted the distribute capability by
	 * default; the capability check alone doesn't guard against posting
	 * someone else's post ID.
	 */
	public function test_author_cannot_distribute_others_post() {
		$author       = $this->factory->user->create( [ 'role' => 'author' ] );
		$other_author = $this->factory->user->create( [ 'role' => 'author' ] );
		$post         = $this->factory->post->create( [ 'post_author' => $other_author ] );

		wp_set_current_user( $author );

		$this->assertFalse( ( $this->permission_callback )( $this->make_request( $post ) ) );
	}

	/**
	 * An author can distribute their own post.
	 */
	public function test_author_can_distribute_own_post() {
		$author = $this->factory->user->create( [ 'role' => 'author' ] );
		$post   = $this->factory->post->create( [ 'post_author' => $author ] );

		wp_set_current_user( $author );

		$this->assertTrue( ( $this->permission_callback )( $this->make_request( $post ) ) );
	}

	/**
	 * The other half of the '&&': edit rights on the post are not enough on
	 * their own, the distribute capability is still required.
	 */
	public function test_edit_rights_without_capability_cannot_distribute() {
		$author = $this->factory->user->create( [ 'role' => 'author' ] );
		$post   = $this->factory->post->create( [ 'post_author' => $author ] );

		// A user-level deny overrides the role grant.
		get_userdata( $author )->add_cap( Admin::CAPABILITY, false );

		wp_set_current_user( $author );

		$this->assertTrue( current_user_can( 'edit_post', $post ) );
		$this->assertFalse( ( $this->permission_callback )( $this->make_request( $post ) ) );
	}

	/**
	 * A post ID that resolves to no post is refused, so the handler is never
	 * reached with nothing to distribute.
	 */
	public function test_missing_post_cannot_be_distributed() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertFalse( ( $this->permission_callback )( $this->make_request( 0 ) ) );
		$this->assertFalse( ( $this->permission_callback )( $this->make_request( PHP_INT_MAX ) ) );
	}

	/**
	 * The UI hides distribution for syndicated copies; the route must refuse
	 * them too, or a direct request would give the copy a second lineage.
	 */
	public function test_incoming_post_cannot_be_distributed() {
		$post = $this->factory->post->create();
		update_post_meta( $post, Incoming_Post::PAYLOAD_META, [ 'post_id' => 1 ] );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$request = $this->make_request( $post );
		$request->set_param( 'urls', [ 'https://node.test' ] );

		$response = API::distribute( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'A post received from the network cannot be distributed.', $response->get_error_message() );
	}

	/**
	 * The handler refuses a missing post on its own rather than leaning on the
	 * permission callback having returned do_not_allow first.
	 */
	public function test_distribute_returns_404_for_a_missing_post() {
		$request = $this->make_request( PHP_INT_MAX );
		$request->set_param( 'urls', [ $this->network[0]['url'] ] );
		$request->set_param( 'status_on_publish', 'draft' );

		$result = API::distribute( $request );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
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
