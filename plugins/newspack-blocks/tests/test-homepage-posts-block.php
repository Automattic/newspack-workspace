<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class HomepagePostsBlockTest
 *
 * @package Newspack_Blocks
 */

require_once __DIR__ . '/class-newspack-tag-labels-stub.php';

/**
 * Homepage Posts Block test case.
 */
class HomepagePostsBlockTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Post types registered during a test, unregistered in tear_down() so the
	 * suite stays order-independent even if a test fails mid-way.
	 *
	 * @var string[]
	 */
	private $registered_post_types = [];

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		foreach ( $this->registered_post_types as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}
		$this->registered_post_types = [];
		parent::tear_down();
	}

	/**
	 * Register a non-viewable (private, not in REST) CPT for the duration of a test.
	 *
	 * @param string $name Post type name.
	 * @return string The registered post type name.
	 */
	private function register_non_viewable_cpt( $name = 'newspack_secret_cpt' ) {
		register_post_type(
			$name,
			[
				'public'       => false,
				'show_in_rest' => false,
				'supports'     => [ 'title', 'editor' ],
			]
		);
		$this->registered_post_types[] = $name;
		return $name;
	}

	/**
	 * Register a publicly viewable CPT for the duration of a test.
	 *
	 * @param string $name Post type name.
	 * @return string The registered post type name.
	 */
	private function register_viewable_cpt( $name = 'newspack_public_cpt' ) {
		register_post_type(
			$name,
			[
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => [ 'title', 'editor' ],
			]
		);
		$this->registered_post_types[] = $name;
		return $name;
	}

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
		$secret    = $this->register_non_viewable_cpt();
		$secret_id = self::factory()->post->create(
			[
				'post_type'    => $secret,
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
		$request->set_param( 'postType', [ $secret ] );
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
	}

	/**
	 * Mixed input keeps the viewable post types and drops the non-viewable ones.
	 */
	public function test_articles_endpoint_filters_mixed_post_types() {
		$secret     = $this->register_non_viewable_cpt();
		$secret_id  = self::factory()->post->create(
			[
				'post_type'   => $secret,
				'post_status' => 'publish',
			]
		);
		$regular_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ 'post', $secret ] );
		$request->set_param( 'postsToShow', 10 );
		$ids = $controller->get_items( $request )->get_data()['ids'];

		self::assertContains( $regular_id, $ids, 'The viewable post type is kept.' );
		self::assertNotContains( $secret_id, $ids, 'The non-viewable post type is dropped.' );
	}

	/**
	 * A publicly viewable custom post type is still returned by the endpoint.
	 */
	public function test_articles_endpoint_allows_viewable_post_types() {
		$public    = $this->register_viewable_cpt();
		$public_id = self::factory()->post->create(
			[
				'post_type'   => $public,
				'post_status' => 'publish',
			]
		);
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ $public ] );
		$request->set_param( 'postsToShow', 10 );
		$ids = $controller->get_items( $request )->get_data()['ids'];

		self::assertContains(
			$public_id,
			$ids,
			'A publicly viewable post type must still be returned by the public articles endpoint.'
		);
	}

	/**
	 * The specific-posts selection mode must not surface a non-viewable post by ID
	 * (requested post type is itself non-viewable — the empty-guard path).
	 */
	public function test_articles_endpoint_excludes_non_viewable_in_specific_posts_mode() {
		$secret    = $this->register_non_viewable_cpt();
		$secret_id = self::factory()->post->create(
			[
				'post_type'   => $secret,
				'post_status' => 'publish',
			]
		);
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ $secret ] );
		$request->set_param( 'specificMode', 1 );
		$request->set_param( 'specificPosts', [ $secret_id ] );
		$request->set_param( 'postsToShow', 10 );
		$ids = $controller->get_items( $request )->get_data()['ids'];

		self::assertNotContains(
			$secret_id,
			$ids,
			'Specific-posts mode must not surface a non-viewable post by ID.'
		);
	}

	/**
	 * Specific-posts mode must not surface a non-viewable post by ID even when the
	 * requested postType is viewable. This reaches the WP_Query post_type + post__in
	 * intersection (the realistic attack), not the empty-guard short-circuit.
	 */
	public function test_articles_endpoint_excludes_non_viewable_specific_post_under_viewable_type() {
		$secret    = $this->register_non_viewable_cpt();
		$secret_id = self::factory()->post->create(
			[
				'post_type'   => $secret,
				'post_status' => 'publish',
			]
		);
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ 'post' ] ); // Viewable — survives the filter.
		$request->set_param( 'specificMode', 1 );
		$request->set_param( 'specificPosts', [ $secret_id ] );
		$request->set_param( 'postsToShow', 10 );
		$ids = $controller->get_items( $request )->get_data()['ids'];

		self::assertNotContains(
			$secret_id,
			$ids,
			'A non-viewable post requested by ID must not surface even under a viewable postType.'
		);
	}

	/**
	 * The newspack_tag_labels REST field exposes the { flag, link } shape
	 * returned by \Newspack\Tag_Labels, normalized to a 0-indexed list.
	 *
	 * Locks in the cross-repo contract: the field passes through whatever
	 * \Newspack\Tag_Labels::get_labels_for_post() returns, so the plugin,
	 * blocks, and theme must agree on this shape.
	 */
	public function test_tag_labels_rest_field_shape() {
		if ( ! property_exists( '\Newspack\Tag_Labels', 'stub_labels' ) ) {
			$this->markTestSkipped( 'Real \Newspack\Tag_Labels present; stub-based contract test skipped.' );
		}
		$post_id = self::factory()->post->create();

		// Keyed input (as returned by Tag_Labels::get_labels_for_post()).
		\Newspack\Tag_Labels::$stub_labels = [
			42 => [
				'flag' => 'Breaking',
				'link' => 'https://example.org/tag/breaking/',
			],
		];

		$result = Newspack_Blocks_API::newspack_blocks_get_tag_labels( [ 'id' => $post_id ] );

		self::assertIsArray( $result );
		self::assertSame( [ 0 ], array_keys( $result ), 'Keyed input is normalized to a 0-indexed list.' );
		self::assertArrayHasKey( 'flag', $result[0] );
		self::assertArrayHasKey( 'link', $result[0] );
		self::assertSame( 'Breaking', $result[0]['flag'] );
		self::assertSame( 'https://example.org/tag/breaking/', $result[0]['link'] );

		\Newspack\Tag_Labels::$stub_labels = null;
	}

	/**
	 * The newspack_tag_labels REST field returns false when there are no labels.
	 */
	public function test_tag_labels_rest_field_empty_returns_false() {
		if ( ! property_exists( '\Newspack\Tag_Labels', 'stub_labels' ) ) {
			$this->markTestSkipped( 'Real \Newspack\Tag_Labels present; stub-based contract test skipped.' );
		}
		$post_id = self::factory()->post->create();

		\Newspack\Tag_Labels::$stub_labels = [];
		self::assertFalse( Newspack_Blocks_API::newspack_blocks_get_tag_labels( [ 'id' => $post_id ] ) );

		\Newspack\Tag_Labels::$stub_labels = null;
		self::assertFalse( Newspack_Blocks_API::newspack_blocks_get_tag_labels( [ 'id' => $post_id ] ) );
	}

	/**
	 * A non-viewable post type explicitly opted in via the
	 * newspack_blocks_articles_allowed_post_types filter is served by the endpoint,
	 * without loosening the gate for other non-viewable types.
	 */
	public function test_articles_endpoint_allows_filter_allowlisted_post_type() {
		$allowed  = $this->register_non_viewable_cpt( 'newspack_allowed_cpt' );
		$excluded = $this->register_non_viewable_cpt( 'newspack_secret_cpt' );
		$allowed_id  = self::factory()->post->create( [ 'post_type' => $allowed, 'post_status' => 'publish' ] );
		$excluded_id = self::factory()->post->create( [ 'post_type' => $excluded, 'post_status' => 'publish' ] );

		$filter = function ( $types ) use ( $allowed ) {
			$types[] = $allowed;
			return $types;
		};
		add_filter( 'newspack_blocks_articles_allowed_post_types', $filter );
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ $allowed, $excluded ] );
		$request->set_param( 'postsToShow', 10 );
		try {
			$ids = $controller->get_items( $request )->get_data()['ids'];
		} finally {
			// Always drop the global filter so a failure here can't leak into later tests.
			remove_filter( 'newspack_blocks_articles_allowed_post_types', $filter );
		}

		self::assertContains( $allowed_id, $ids, 'An allow-listed non-viewable post type is served.' );
		self::assertNotContains( $excluded_id, $ids, 'A non-viewable post type not on the allow-list is still dropped.' );
	}

	/**
	 * Opting a post type into the endpoint opts in its published posts only. The
	 * status gate in build_articles_query() is what keeps unpublished posts of an
	 * allow-listed type away from an anonymous caller, so assert it directly.
	 */
	public function test_articles_endpoint_allowlist_still_hides_unpublished() {
		$allowed = $this->register_non_viewable_cpt( 'newspack_status_cpt' );
		$published_id = self::factory()->post->create( [ 'post_type' => $allowed, 'post_status' => 'publish' ] );
		$draft_id     = self::factory()->post->create( [ 'post_type' => $allowed, 'post_status' => 'draft' ] );
		$private_id   = self::factory()->post->create( [ 'post_type' => $allowed, 'post_status' => 'private' ] );

		$filter = function ( $types ) use ( $allowed ) {
			$types[] = $allowed;
			return $types;
		};
		add_filter( 'newspack_blocks_articles_allowed_post_types', $filter );
		wp_set_current_user( 0 );

		$controller = new WP_REST_Newspack_Articles_Controller();
		$request    = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
		$request->set_param( 'postType', [ $allowed ] );
		$request->set_param( 'postsToShow', 10 );
		try {
			$ids = $controller->get_items( $request )->get_data()['ids'];
		} finally {
			// Always drop the global filter so a failure here can't leak into later tests.
			remove_filter( 'newspack_blocks_articles_allowed_post_types', $filter );
		}

		self::assertContains( $published_id, $ids, 'A published post of an allow-listed type is served.' );
		self::assertNotContains( $draft_id, $ids, 'A draft of an allow-listed type stays hidden from an anonymous caller.' );
		self::assertNotContains( $private_id, $ids, 'A private post of an allow-listed type stays hidden from an anonymous caller.' );
	}

	/**
	 * The filter_excerpt() method routes its content through Block_Visibility sanitization
	 * before excerpt_remove_blocks(), verifying the integration point that strips gated
	 * content from excerpts built by the homepage-posts block.
	 */
	public function test_filter_excerpt_sanitizes_via_block_visibility() {
		// Skip if the real Block_Visibility is present; the stub-based test is for verification
		// when newspack-plugin is not loaded.
		if ( ! property_exists( '\Newspack\Block_Visibility', 'sanitization_was_called' ) ) {
			$this->markTestSkipped( 'Real \Newspack\Block_Visibility present; stub-based wiring test skipped.' );
		}

		// Create a post with a gated group block (would be withheld from anonymous users).
		$gate    = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$content = '<!-- wp:paragraph --><p>PUBLICMARK</p><!-- /wp:paragraph -->'
			. '<!-- wp:group ' . $gate . ' --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>SECRETMARK</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $content,
				'post_excerpt' => '',
			]
		);

		// Reset the flag and call filter_excerpt.
		\Newspack\Block_Visibility::reset_sanitization_for_tests();
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		Newspack_Blocks::filter_excerpt( [ 'excerptLength' => 999, 'showExcerpt' => true ] );
		$excerpt = get_the_excerpt( $post_id );
		Newspack_Blocks::remove_excerpt_filter();

		// Verify: Block_Visibility sanitization was called (the integration point).
		self::assertTrue(
			\Newspack\Block_Visibility::$sanitization_was_called,
			'Block_Visibility::strip_blocks_hidden_from_public() must be called by filter_excerpt().'
		);

		// Verify *when*: the call has to land before excerpt_remove_blocks(), which
		// unwraps core/group and destroys the access-control attributes the real
		// implementation matches on. The stub's marker removal would succeed either
		// way, so without this the test passes while production strips nothing.
		self::assertStringContainsString(
			'newspackAccessControl',
			\Newspack\Block_Visibility::$received_content,
			'Sanitization must run while the block attributes are still intact.'
		);
		self::assertStringContainsString(
			'<!-- wp:group',
			\Newspack\Block_Visibility::$received_content,
			'Sanitization must run before the block structure is flattened.'
		);

		// Verify: gated content was stripped; public content remains.
		self::assertStringNotContainsString( 'SECRETMARK', $excerpt, 'Gated block content must not appear in excerpt.' );
		self::assertStringContainsString( 'PUBLICMARK', $excerpt, 'Public block content must remain in excerpt.' );

		unset( $GLOBALS['post'] );
	}
}
