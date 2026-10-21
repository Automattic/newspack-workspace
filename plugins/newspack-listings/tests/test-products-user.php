<?php
/**
 * Class Products_User Test
 *
 * Covers the capability filter applied to self-serve listing customers: the
 * broad `edit_posts` check is granted from the server-side edit screen and a
 * short list of editor reads, the per-listing editing flow keeps working, and
 * per-post edits reach only the customer's own listings, their revisions and
 * the media the customer uploaded.
 *
 * @package Newspack_Listings
 */

use Newspack_Listings\Core;
use Newspack_Listings\Products;
use Newspack_Listings\Products_User;

/**
 * Test the self-serve listing customer capability filter.
 */
class ProductsUserTest extends WP_UnitTestCase {
	/**
	 * Listing post types a self-serve customer can own.
	 *
	 * @var string[]
	 */
	private $listing_types = [ 'marketplace', 'event', 'place' ];

	/**
	 * Value of the admin page global before the test.
	 *
	 * @var string|null
	 */
	private $original_pagenow;

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		$this->original_pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
		new Products_User();
		$GLOBALS['pagenow'] = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_REQUEST           = [];
		$_GET               = [];
		$_POST              = [];
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( null === $this->original_pagenow ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->original_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$_REQUEST = [];
		$_GET     = [];
		$_POST    = [];
		parent::tear_down();
	}

	/**
	 * Create a user the way a completed self-serve order leaves them.
	 *
	 * @return int User ID.
	 */
	private function create_listing_customer() {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, Products::CUSTOMER_META_KEYS['is_listings_customer'], 1 );
		$user = new WP_User( $user_id );
		$user->add_cap( 'edit_posts' );
		$user->add_cap( 'edit_published_posts' );
		$user->add_cap( 'upload_files' );
		return $user_id;
	}

	/**
	 * Create a draft listing of a given type.
	 *
	 * @param int    $author_id Author of the listing.
	 * @param string $type      Listing type slug.
	 * @return int Listing post ID.
	 */
	private function create_listing( $author_id, $type = 'marketplace' ) {
		return self::factory()->post->create(
			[
				'post_type'   => Core::NEWSPACK_LISTINGS_POST_TYPES[ $type ],
				'post_author' => $author_id,
				'post_status' => 'draft',
			]
		);
	}

	/**
	 * Send a REST request as the current user, with request values mirrored into the superglobals as a real request would.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Body or query parameters.
	 * @return int Response status.
	 */
	private function rest_status( $method, $route, $params = [] ) {
		$_REQUEST = $params;
		$_GET     = $params;
		$request  = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request )->get_status();
	}

	/**
	 * Request values a REST caller can send, which must not widen the broad edit check.
	 *
	 * @return array[] Request values.
	 */
	public function request_values() {
		return [
			'context' => [ [ 'context' => 'edit' ] ],
			'action'  => [ [ 'action' => 'edit' ] ],
		];
	}

	/**
	 * The broad edit check does not follow request values.
	 *
	 * @dataProvider request_values
	 *
	 * @param array $request Request values.
	 */
	public function test_broad_edit_check_ignores_request_values( $request ) {
		$customer_id = $this->create_listing_customer();
		$_REQUEST    = $request;

		$this->assertFalse( user_can( $customer_id, 'edit_posts' ) );
	}

	/**
	 * New posts and listings cannot be created over REST, with or without request values.
	 */
	public function test_rest_creation_is_refused() {
		wp_set_current_user( $this->create_listing_customer() );
		$routes = [ '/wp/v2/posts' ];
		foreach ( $this->listing_types as $type ) {
			$routes[] = '/wp/v2/' . Core::NEWSPACK_LISTINGS_POST_TYPES[ $type ];
		}

		foreach ( $routes as $route ) {
			foreach ( [ [], [ 'context' => 'edit' ], [ 'action' => 'edit' ] ] as $extra ) {
				$status = $this->rest_status( 'POST', $route, array_merge( [ 'title' => 'New' ], $extra ) );
				$this->assertSame( 403, $status, $route . ' ' . wp_json_encode( $extra ) );
			}
		}
	}

	/**
	 * The broad edit check holds on the editor screen of the customer's own listing.
	 */
	public function test_broad_edit_check_holds_on_own_listing_screen() {
		$customer_id        = $this->create_listing_customer();
		$listing_id         = $this->create_listing( $customer_id );
		$GLOBALS['pagenow'] = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_GET['post']       = $listing_id;
		$_REQUEST['post']   = $listing_id;

		$this->assertTrue( user_can( $customer_id, 'edit_posts' ) );
	}

	/**
	 * Saving from the editor screen posts the listing id in the body, not the query string.
	 */
	public function test_broad_edit_check_holds_when_screen_posts_listing_id_in_body() {
		$customer_id        = $this->create_listing_customer();
		$listing_id         = $this->create_listing( $customer_id );
		$GLOBALS['pagenow'] = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_POST['post_ID']   = $listing_id;

		$this->assertTrue( user_can( $customer_id, 'edit_posts' ) );
	}

	/**
	 * A listing id that arrives only through the merged request values does not identify the screen.
	 */
	public function test_broad_edit_check_ignores_listing_id_outside_get_and_post() {
		$customer_id        = $this->create_listing_customer();
		$listing_id         = $this->create_listing( $customer_id );
		$GLOBALS['pagenow'] = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_REQUEST['post']   = $listing_id;

		$this->assertFalse( user_can( $customer_id, 'edit_posts' ) );
	}

	/**
	 * The editor screen of a listing owned by someone else does not widen the check.
	 */
	public function test_broad_edit_check_refused_on_another_users_listing_screen() {
		$customer_id        = $this->create_listing_customer();
		$other_id           = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$listing_id         = $this->create_listing( $other_id );
		$GLOBALS['pagenow'] = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_GET['post']       = $listing_id;
		$_REQUEST['post']   = $listing_id;

		$this->assertFalse( user_can( $customer_id, 'edit_posts' ) );
	}

	/**
	 * A non-listing post named on the editor screen does not widen the check.
	 */
	public function test_broad_edit_check_refused_on_non_listing_screen() {
		$customer_id        = $this->create_listing_customer();
		$post_id            = self::factory()->post->create( [ 'post_author' => $customer_id ] );
		$GLOBALS['pagenow'] = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_GET['post']       = $post_id;
		$_REQUEST['post']   = $post_id;

		$this->assertFalse( user_can( $customer_id, 'edit_posts' ) );
	}

	/**
	 * Editing, autosaving and submitting an owned listing keeps working over REST for every listing type.
	 */
	public function test_owned_listing_edit_flow_over_rest() {
		$customer_id = $this->create_listing_customer();
		wp_set_current_user( $customer_id );

		foreach ( $this->listing_types as $type ) {
			$route      = '/wp/v2/' . Core::NEWSPACK_LISTINGS_POST_TYPES[ $type ];
			$listing_id = $this->create_listing( $customer_id, $type );

			$this->assertSame( 200, $this->rest_status( 'POST', "$route/$listing_id", [ 'title' => 'Edited' ] ), $type . ' update' );
			$autosave = [
				'title'   => 'Draft',
				'content' => 'Body',
			];
			$this->assertSame( 200, $this->rest_status( 'POST', "$route/$listing_id/autosaves", $autosave ), $type . ' autosave' );
			$this->assertSame( 200, $this->rest_status( 'POST', "$route/$listing_id", [ 'status' => 'pending' ] ), $type . ' submit' );
		}
	}

	/**
	 * Reads the editor makes with the broad edit check keep working, with the request values the editor sends.
	 */
	public function test_editor_reads_over_rest() {
		wp_set_current_user( $this->create_listing_customer() );
		$params = [ 'context' => 'edit' ];

		foreach ( [ '/wp/v2/posts', '/wp/v2/media', '/wp/v2/block-renderer/core/calendar', '/wp/v2/block-renderer/core/rss' ] as $route ) {
			$this->assertSame( 200, $this->rest_status( 'GET', $route, $params ), $route );
		}
	}

	/**
	 * Routes outside the editor's read list stay refused, with or without request values.
	 */
	public function test_other_broad_edit_routes_refused_over_rest() {
		wp_set_current_user( $this->create_listing_customer() );

		foreach ( [ [], [ 'context' => 'edit' ] ] as $extra ) {
			$this->assertSame( 403, $this->rest_status( 'GET', '/wp/v2/block-patterns/patterns', $extra ), wp_json_encode( $extra ) );
		}
	}

	/**
	 * The broad edit check is not granted to a non-GET request sent to a route whose reads are allowed.
	 */
	public function test_broad_edit_check_refused_for_non_get_requests_to_read_routes() {
		wp_set_current_user( $this->create_listing_customer() );

		$this->assertSame( 403, $this->rest_status( 'POST', '/wp/v2/block-renderer/core/calendar', [ 'context' => 'edit' ] ) );
	}

	/**
	 * A read dispatched from inside a write does not leave the write's later checks widened.
	 */
	public function test_nested_read_does_not_widen_enclosing_write() {
		wp_set_current_user( $this->create_listing_customer() );
		add_filter(
			'rest_request_before_callbacks',
			function( $response, $handler, $request ) {
				if ( 'POST' === $request->get_method() && '/wp/v2/posts' === $request->get_route() ) {
					rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
				}
				return $response;
			},
			20,
			3
		);

		$this->assertSame( 403, $this->rest_status( 'POST', '/wp/v2/posts', [ 'title' => 'New' ] ) );
	}

	/**
	 * Another user's post cannot be edited, with or without request values.
	 */
	public function test_another_users_post_cannot_be_edited() {
		$customer_id = $this->create_listing_customer();
		$other_id    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$post_id     = self::factory()->post->create( [ 'post_author' => $other_id ] );
		wp_set_current_user( $customer_id );

		foreach ( [ [], [ 'context' => 'edit' ] ] as $extra ) {
			$this->assertSame( 403, $this->rest_status( 'POST', "/wp/v2/posts/$post_id", array_merge( [ 'title' => 'Changed' ], $extra ) ) );
		}
	}

	/**
	 * A post the customer wrote that is not a listing cannot be edited or published, here or over REST.
	 */
	public function test_own_non_listing_post_cannot_be_edited() {
		$customer_id = $this->create_listing_customer();
		$post_id     = self::factory()->post->create(
			[
				'post_author' => $customer_id,
				'post_status' => 'draft',
			]
		);

		// A site that lets customers publish still may not have them publish this post.
		( new WP_User( $customer_id ) )->add_cap( 'publish_posts' );

		$this->assertFalse( user_can( $customer_id, 'edit_post', $post_id ) );
		$this->assertFalse( user_can( $customer_id, 'publish_post', $post_id ) );
		wp_set_current_user( $customer_id );
		$this->assertSame( 403, $this->rest_status( 'POST', "/wp/v2/posts/$post_id", [ 'title' => 'Changed' ] ) );
	}

	/**
	 * The meta and comments of a post the customer wrote that is not a listing cannot be edited either.
	 */
	public function test_own_non_listing_post_meta_and_comments_cannot_be_edited() {
		$customer_id = $this->create_listing_customer();
		$post_id     = self::factory()->post->create( [ 'post_author' => $customer_id ] );
		$comment_id  = self::factory()->comment->create( [ 'comment_post_ID' => $post_id ] );

		$this->assertFalse( user_can( $customer_id, 'edit_post_meta', $post_id, 'some_key' ) );
		$this->assertFalse( user_can( $customer_id, 'edit_comment', $comment_id ) );
		wp_set_current_user( $customer_id );
		$this->assertSame( 403, $this->rest_status( 'POST', "/wp/v2/comments/$comment_id", [ 'content' => 'Changed' ] ) );
	}

	/**
	 * A per-post check for a user ID that no longer resolves is left to core rather than failing.
	 */
	public function test_per_post_check_for_missing_user_does_not_fail() {
		$post_id = self::factory()->post->create();

		$this->assertNotContains( 'do_not_allow', map_meta_cap( 'edit_post', PHP_INT_MAX, $post_id ) );
	}

	/**
	 * A revision of a post the customer wrote that is not a listing cannot be edited.
	 */
	public function test_own_non_listing_revision_cannot_be_edited() {
		$customer_id = $this->create_listing_customer();
		$post_id     = self::factory()->post->create(
			[
				'post_author' => $customer_id,
				'post_status' => 'draft',
			]
		);
		$revision_id = _wp_put_post_revision( get_post( $post_id ) );

		$this->assertFalse( user_can( $customer_id, 'edit_post', $revision_id ) );
	}

	/**
	 * Each of the customer's own listings, and its revisions, can be edited outside the editor screen.
	 */
	public function test_own_listing_can_be_edited_per_post() {
		$customer_id = $this->create_listing_customer();

		foreach ( $this->listing_types as $type ) {
			$listing_id = $this->create_listing( $customer_id, $type );
			$this->assertTrue( user_can( $customer_id, 'edit_post', $listing_id ), $type );

			$revision_id = _wp_put_post_revision( get_post( $listing_id ) );
			$this->assertTrue( user_can( $customer_id, 'edit_post', $revision_id ), $type . ' revision' );
		}
	}

	/**
	 * Media the customer uploaded can be edited, as the editor does after an upload.
	 */
	public function test_own_attachment_can_be_edited() {
		$customer_id   = $this->create_listing_customer();
		$listing_id    = $this->create_listing( $customer_id );
		$attachment_id = self::factory()->attachment->create(
			[
				'post_author'    => $customer_id,
				'post_parent'    => $listing_id,
				'post_mime_type' => 'image/jpeg',
			]
		);

		$this->assertTrue( user_can( $customer_id, 'edit_post', $attachment_id ) );
		wp_set_current_user( $customer_id );
		$this->assertSame( 200, $this->rest_status( 'POST', "/wp/v2/media/$attachment_id", [ 'alt_text' => 'Alt' ] ) );
	}
}
