<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class HomepagePostsBlockTest
 *
 * @package Newspack_Blocks
 */

/**
 * Homepage Posts Block test case.
 */
class HomepagePostsBlockTest extends WP_UnitTestCase_Blocks { // phpcs:ignore
	/**
	 * HPB query from attributes.
	 */
	public function test_hpb_build_articles_query() {
		$cases = [
			[
				'block_attributes'        => [
					'postsToShow' => 5,
				],
				'resulting_query_partial' => [
					'posts_per_page' => 5,
					'post_status'    => [ 'publish' ],
					'post_type'      => [ 'post' ],
					'tax_query'      => [],
				],
				'description'             => 'Default attributes',
			],
			[
				'block_attributes'        => [
					'postsToShow' => 1,
					'postType'    => 'some-type',
					'authors'     => [ 1 ],
				],
				'resulting_query_partial' => [
					'posts_per_page' => 1,
					'post_type'      => 'some-type',
					'author__in'     => [ 1 ],
				],
				'description'             => 'With custom post type and author',
				'ignore_tax_query'        => true,
			],
		];

		foreach ( $cases as $case ) {
			$result = Newspack_Blocks::build_articles_query( $case['block_attributes'], 'newspack-blocks/homepage-articles' );
			if ( isset( $case['ignore_tax_query'] ) && $case['ignore_tax_query'] ) {
				// Tax query is an implementation detail in some cases.
				unset( $result['tax_query'] );
			}
			$this->assertEquals(
				self::get_args_with_defaults( $case['resulting_query_partial'] ),
				$result,
				$case['description']
			);
		}
	}

	/**
	 * Test the query manipulation.
	 */
	public function test_hpb_wp_query() {
		$cap_author = self::create_guest_author();
		$post_id    = self::create_post( $cap_author['term_id'] );

		global $coauthors_plus;
		$coauthors_plus = new CoAuthors_Plus_Mock(); // phpcs:ignore

		// Create another post.
		self::create_post();

		$block_attributes = [
			'postsToShow' => 1,
			'authors'     => [ $cap_author['id'] ],
		];
		$query_args       = Newspack_Blocks::build_articles_query( $block_attributes, 'newspack-blocks/homepage-articles' );
		$query            = new WP_Query( $query_args );

		self::assertEquals( 1, count( $query->posts ), 'There is one post returned.' );
		self::assertEquals( $post_id, $query->posts[0]->ID, 'The post returned is the one with the CAP author assigned.' );
	}

	/**
	 * The public /articles endpoint must not return posts from non-viewable post types.
	 */
	public function test_articles_endpoint_excludes_non_viewable_post_types() {
		register_post_type(
			'newspack_secret_cpt',
			[
				'public'       => false,
				'show_in_rest' => false,
				'supports'     => [ 'title', 'editor' ],
			]
		);
		$secret_id = self::factory()->post->create(
			[
				'post_type'    => 'newspack_secret_cpt',
				'post_status'  => 'publish',
				'post_title'   => 'Secret CPT title',
				'post_content' => 'Secret CPT body.',
			]
		);
		// A regular published post exists, to prove the endpoint returns nothing here
		// rather than silently substituting a different post type.
		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ 'newspack_secret_cpt' ] );
		$request->set_param( 'postsToShow', 10 );
		$ids = $controller->get_items( $request )->get_data()['ids'];

		self::assertNotContains(
			$secret_id,
			$ids,
			'A non-viewable post type must not be returned by the public articles endpoint.'
		);
		self::assertEmpty(
			$ids,
			'A request for only non-viewable post types returns no results, not substituted posts.'
		);

		unregister_post_type( 'newspack_secret_cpt' );
	}

	/**
	 * Mixed input keeps the viewable post types and drops the non-viewable ones.
	 */
	public function test_articles_endpoint_filters_mixed_post_types() {
		register_post_type(
			'newspack_secret_cpt',
			[
				'public'       => false,
				'show_in_rest' => false,
				'supports'     => [ 'title', 'editor' ],
			]
		);
		$secret_id  = self::factory()->post->create(
			[
				'post_type'   => 'newspack_secret_cpt',
				'post_status' => 'publish',
			]
		);
		$regular_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ 'post', 'newspack_secret_cpt' ] );
		$request->set_param( 'postsToShow', 10 );
		$ids = $controller->get_items( $request )->get_data()['ids'];

		self::assertContains( $regular_id, $ids, 'The viewable post type is kept.' );
		self::assertNotContains( $secret_id, $ids, 'The non-viewable post type is dropped.' );

		unregister_post_type( 'newspack_secret_cpt' );
	}

	/**
	 * A publicly viewable custom post type is still returned by the endpoint.
	 */
	public function test_articles_endpoint_allows_viewable_post_types() {
		register_post_type(
			'newspack_public_cpt',
			[
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => [ 'title', 'editor' ],
			]
		);
		$public_id = self::factory()->post->create(
			[
				'post_type'   => 'newspack_public_cpt',
				'post_status' => 'publish',
			]
		);
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ 'newspack_public_cpt' ] );
		$request->set_param( 'postsToShow', 10 );
		$ids = $controller->get_items( $request )->get_data()['ids'];

		self::assertContains(
			$public_id,
			$ids,
			'A publicly viewable post type must still be returned by the public articles endpoint.'
		);

		unregister_post_type( 'newspack_public_cpt' );
	}

	/**
	 * The specific-posts selection mode must not surface a non-viewable post by ID.
	 */
	public function test_articles_endpoint_excludes_non_viewable_in_specific_posts_mode() {
		register_post_type(
			'newspack_secret_cpt',
			[
				'public'       => false,
				'show_in_rest' => false,
				'supports'     => [ 'title', 'editor' ],
			]
		);
		$secret_id = self::factory()->post->create(
			[
				'post_type'   => 'newspack_secret_cpt',
				'post_status' => 'publish',
			]
		);
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ 'newspack_secret_cpt' ] );
		$request->set_param( 'specificMode', 1 );
		$request->set_param( 'specificPosts', [ $secret_id ] );
		$request->set_param( 'postsToShow', 10 );
		$ids = $controller->get_items( $request )->get_data()['ids'];

		self::assertNotContains(
			$secret_id,
			$ids,
			'Specific-posts mode must not surface a non-viewable post by ID.'
		);

		unregister_post_type( 'newspack_secret_cpt' );
	}
}
