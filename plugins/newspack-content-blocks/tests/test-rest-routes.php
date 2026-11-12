<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests the REST routes the Content Loop block uses.
 *
 * @package Newspack_Content_Blocks
 */

/**
 * Route registration and the authors endpoint.
 */
class RestRoutesTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Every route the block needs is registered, under the namespaces it used before.
	 */
	public function test_routes_registered() {
		$routes = rest_get_server()->get_routes();
		foreach ( [
			'/newspack-blocks/v1/articles',
			'/newspack-blocks/v1/newspack-blocks-posts',
			'/newspack-blocks/v1/newspack-blocks-posts-batch',
			'/newspack-blocks/v1/newspack-blocks-specific-posts',
			'/newspack-content-blocks/v1/authors',
		] as $route ) {
			self::assertArrayHasKey( $route, $routes, "$route is registered." );
		}
		self::assertArrayNotHasKey( '/newspack-blocks/v1/video-playlist', $routes, 'The video playlist route stays in newspack-blocks.' );
	}

	/**
	 * The public articles route (the "Load more" button) works with only this plugin
	 * and WordPress loaded.
	 */
	public function test_articles_route_without_newspack_plugin() {
		self::assertFalse( class_exists( '\Newspack\Reader_Activation' ), 'Newspack plugin is not loaded.' );
		$post_ids = self::factory()->post->create_many( 4, [ 'post_status' => 'publish' ] );
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_query_params(
			[
				'page'        => 2,
				'postsToShow' => 2,
				'showAuthor'  => 1,
				'showAvatar'  => 1,
				'showDate'    => 1,
			]
		);
		$response = rest_do_request( $request );
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		self::assertArrayHasKey( 'items', $data );
		self::assertCount( 2, $data['items'], 'Page 2 holds the remaining posts.' );
		self::assertStringContainsString( '<article', $data['items'][0]['html'] );
		self::assertEmpty( array_diff( $data['ids'], $post_ids ) );
	}

	/**
	 * Request the authors route.
	 *
	 * @param array $params Query params.
	 * @return WP_REST_Response
	 */
	private static function request_authors( $params = [] ) {
		$request = new WP_REST_Request( 'GET', '/newspack-content-blocks/v1/authors' );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Only people who can edit posts get author suggestions.
	 */
	public function test_authors_route_requires_edit_posts() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, self::request_authors()->get_status() );

		wp_set_current_user( 0 );
		self::assertSame( 401, self::request_authors()->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::assertSame( 200, self::request_authors()->get_status() );
	}

	/**
	 * Search matches users with an author role by name, and returns what the picker reads.
	 */
	public function test_authors_route_search() {
		$jane = self::factory()->user->create(
			[
				'role'         => 'author',
				'user_login'   => 'jane',
				'display_name' => 'Jane Writer',
			]
		);
		$bob  = self::factory()->user->create(
			[
				'role'         => 'author',
				'user_login'   => 'bob',
				'display_name' => 'Bob Other',
			]
		);
		$sub  = self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_login'   => 'janesub',
				'display_name' => 'Jane Subscriber',
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$data = self::request_authors(
			[
				'search'   => 'jane',
				'per_page' => 20,
				'fields'   => 'id,name',
			]
		)->get_data();
		self::assertSame( [ $jane ], wp_list_pluck( $data, 'id' ), 'Only the matching author is returned, not a subscriber.' );
		self::assertSame( 'Jane Writer', $data[0]['name'] );
		self::assertArrayNotHasKey( 'email', $data[0] );
		self::assertArrayNotHasKey( 'registered', $data[0] );

		$data = self::request_authors(
			[
				'include'  => "$jane,$bob",
				'per_page' => 100,
				'fields'   => 'id,name',
			]
		)->get_data();
		self::assertEqualsCanonicalizing( [ $jane, $bob ], wp_list_pluck( $data, 'id' ), 'Saved authors resolve by ID.' );
		self::assertNotContains( $sub, wp_list_pluck( $data, 'id' ) );
	}

	/**
	 * Contact details are not part of the response, however they are requested.
	 */
	public function test_authors_route_never_returns_email() {
		$jane = self::factory()->user->create(
			[
				'role'       => 'author',
				'user_email' => 'jane-private@example.org',
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$response = self::request_authors(
			[
				'include' => (string) $jane,
				'fields'  => 'id,name,email,login',
			]
		);
		self::assertStringNotContainsString( 'jane-private@example.org', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * With Co-Authors Plus, guest authors are suggested too, and a user linked to a guest
	 * author is represented by the guest author.
	 */
	public function test_authors_route_includes_guest_authors() {
		self::assertTrue( class_exists( 'CoAuthors_Guest_Authors' ), 'The test stub stands in for Co-Authors Plus.' );
		$guest = self::factory()->post->create(
			[
				'post_type'   => 'guest-author',
				'post_status' => 'publish',
				'post_title'  => 'Gustav Guest',
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$data = self::request_authors(
			[
				'search' => 'Gustav',
				'fields' => 'id,name',
			]
		)->get_data();
		self::assertSame( [ $guest ], wp_list_pluck( $data, 'id' ) );
		self::assertSame( 'Gustav Guest', $data[0]['name'] );
	}
}
