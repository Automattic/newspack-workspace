<?php
/**
 * Class TestApi
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\API;
use Newspack_Network\Content_Distribution\Incoming_Post;
use WP_REST_Request;

/**
 * Test the Content Distribution REST API.
 */
class TestApi extends \WP_UnitTestCase {
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

		// Clear any existing routes.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		API::register_routes();

		$routes = rest_get_server()->get_routes();
		$route  = $routes['/newspack-network/v1/content-distribution/distribute/(?P<post_id>\d+)'][0];

		$this->permission_callback = $route['permission_callback'];
	}

	/**
	 * An author cannot distribute a post authored by another user, even
	 * though the 'author' role is granted the distribute capability by
	 * default; the capability check alone doesn't guard against posting
	 * someone else's post ID.
	 */
	public function test_author_cannot_distribute_others_post() {
		$author      = $this->factory->user->create( [ 'role' => 'author' ] );
		$other_author = $this->factory->user->create( [ 'role' => 'author' ] );
		$post        = $this->factory->post->create( [ 'post_author' => $other_author ] );

		wp_set_current_user( $author );

		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/content-distribution/distribute/' . $post );
		$request->set_param( 'post_id', $post );

		$this->assertFalse( ( $this->permission_callback )( $request ) );
	}

	/**
	 * An author can distribute their own post.
	 */
	public function test_author_can_distribute_own_post() {
		$author = $this->factory->user->create( [ 'role' => 'author' ] );
		$post   = $this->factory->post->create( [ 'post_author' => $author ] );

		wp_set_current_user( $author );

		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/content-distribution/distribute/' . $post );
		$request->set_param( 'post_id', $post );

		$this->assertTrue( ( $this->permission_callback )( $request ) );
	}

	/**
	 * The UI hides distribution for syndicated copies; the route must refuse
	 * them too, or a direct request would give the copy a second lineage.
	 */
	public function test_incoming_post_cannot_be_distributed() {
		$post = $this->factory->post->create();
		update_post_meta( $post, Incoming_Post::PAYLOAD_META, [ 'post_id' => 1 ] );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/content-distribution/distribute/' . $post );
		$request->set_param( 'post_id', $post );
		$request->set_param( 'urls', [ 'https://node.test' ] );

		$response = API::distribute( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'A post received from the network cannot be distributed.', $response->get_error_message() );
	}
}
