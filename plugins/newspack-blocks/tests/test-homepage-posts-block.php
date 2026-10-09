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
		self::reset_dedup_globals();
		wp_reset_postdata();
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
	 * Clear the request-scoped deduplication globals between tests.
	 */
	private static function reset_dedup_globals() {
		unset( $GLOBALS['newspack_blocks_post_id'], $GLOBALS['newspack_blocks_all_specific_posts_ids'], $GLOBALS['newspack_blocks_hpb_all_blocks'] );
	}

	/**
	 * Create published posts and a page holding two Content Loop blocks of one
	 * post each, and make that page the current post.
	 *
	 * @param int $posts_count How many posts to create.
	 * @return WP_Post The page.
	 */
	private function create_dedup_page( $posts_count = 3 ) {
		self::reset_dedup_globals();
		for ( $i = 0; $i < $posts_count; $i++ ) {
			self::factory()->post->create(
				[
					'post_status' => 'publish',
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() - ( $i + 1 ) * HOUR_IN_SECONDS ),
				]
			);
		}
		$block   = '<!-- wp:newspack-blocks/homepage-articles {"postsToShow":1} /-->';
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $block . "\n" . $block,
			]
		);
		$GLOBALS['post'] = get_post( $page_id );
		setup_postdata( $GLOBALS['post'] );
		return $GLOBALS['post'];
	}

	/**
	 * Apply `the_content` to a post and return the IDs the pass has marked as rendered.
	 *
	 * @param WP_Post $post The post.
	 * @return int[] Deduplicated post IDs after the pass.
	 */
	private function render_pass( $post ) {
		apply_filters( 'the_content', $post->post_content );
		return array_keys( (array) ( $GLOBALS['newspack_blocks_post_id'] ?? [] ) );
	}

	/**
	 * Make the request look like wp-admin, where `is_admin()` is true.
	 */
	private function enter_admin() {
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( 'edit-page' );
		self::assertTrue( is_admin(), 'The request is treated as an admin request.' );
	}

	/**
	 * On the front end, deduplication accumulates for the whole request, so a
	 * second pass over the same content excludes what the first one rendered.
	 */
	public function test_dedup_state_is_kept_across_render_passes_on_front_end() {
		$page = $this->create_dedup_page();
		self::assertFalse( Newspack_Blocks::should_reset_deduplication_per_render_pass() );

		$first = $this->render_pass( $page );
		self::assertCount( 2, $first, 'Two blocks of one post each render two distinct posts.' );

		$second = $this->render_pass( $page );
		self::assertCount( 3, $second, 'The second pass keeps excluding the posts the first pass rendered.' );
	}

	/**
	 * Outside the front end (admin, REST, cron, CLI) every top-level pass starts
	 * from scratch, so re-rendering the same content returns the same posts.
	 */
	public function test_dedup_state_resets_per_render_pass_outside_front_end() {
		$this->enter_admin();
		$page = $this->create_dedup_page();
		self::assertTrue( Newspack_Blocks::should_reset_deduplication_per_render_pass() );

		$first = $this->render_pass( $page );
		self::assertCount( 2, $first, 'Two blocks of one post each render two distinct posts.' );

		$second = $this->render_pass( $page );
		self::assertSame( $first, $second, 'A second pass over the same content renders the same posts.' );
	}

	/**
	 * A `the_content` application nested inside a pass (a Query Loop rendering
	 * Post Content, for instance) must not wipe the state of the outer pass.
	 */
	public function test_nested_content_render_does_not_reset_dedup_state() {
		$this->enter_admin();
		$page = $this->create_dedup_page();

		$nested_calls = 0;
		$nested       = function ( $content ) use ( &$nested_calls ) {
			if ( 0 === $nested_calls++ ) {
				apply_filters( 'the_content', '<p>nested</p>' );
			}
			return $content;
		};
		// After do_blocks (9), so the outer pass has already rendered its blocks.
		add_filter( 'the_content', $nested, 10 );
		$ids = $this->render_pass( $page );
		remove_filter( 'the_content', $nested, 10 );

		self::assertSame( 2, $nested_calls, 'The filter ran for the outer pass and once more for the nested one.' );
		self::assertCount( 2, $ids, 'The outer pass keeps the posts it rendered before the nested pass.' );
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
			[
				'block_attributes'        => [
					'postsToShow' => 3,
					'moreButton'  => true,
				],
				'resulting_query_partial' => [
					'posts_per_page' => 3,
					'post_status'    => [ 'publish' ],
					'post_type'      => [ 'post' ],
					'tax_query'      => [],
					'no_found_rows'  => false,
				],
				'description'             => 'A More button needs the total to know whether there is a next page',
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
	 * Load More offers a next page exactly while posts remain: every request in the
	 * chain returns posts, and together they return every matching post exactly once,
	 * whether or not "Allow duplicate content" is on.
	 *
	 * @dataProvider deduplicate_settings
	 *
	 * @param bool $deduplicate The block's deduplicate attribute.
	 */
	public function test_articles_endpoint_offers_next_url_exactly_while_posts_remain( $deduplicate ) {
		$category_id = self::factory()->category->create();
		$post_ids    = [];
		for ( $i = 0; $i < 7; $i++ ) {
			// Distinct dates keep the date ordering, and so each page's contents, deterministic.
			$post_ids[] = self::factory()->post->create(
				[
					'post_status'   => 'publish',
					'post_category' => [ $category_id ],
					'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01' ) + $i * HOUR_IN_SECONDS ),
				]
			);
		}
		wp_set_current_user( 0 );

		// Follow the chain the way the block's Load More script does: each request is the
		// previous response's next URL plus the IDs of every post shown so far. The first
		// request carries no page param, so it must be served as page 1 and stands in for
		// the block's server render.
		$params   = [
			'postsToShow' => 3,
			'moreButton'  => 1,
			'categories'  => [ $category_id ],
			'deduplicate' => $deduplicate ? 1 : 0,
		];
		$seen_ids = [];
		// Bounded so a next URL that never empties fails the test instead of hanging it.
		for ( $i = 0; $i < 10; $i++ ) {
			$request = new WP_REST_Request( 'GET', '/newspack-blocks/v1/articles' );
			$request->set_query_params( array_merge( $params, [ 'exclude_ids' => implode( ',', $seen_ids ) ] ) );
			$response = rest_do_request( $request );
			self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$data = $response->get_data();
			// A next URL that leads to no posts would leave readers a Load More click that does nothing.
			self::assertNotEmpty( $data['ids'], 'Every request in the Load More chain returns posts.' );
			$seen_ids = array_merge( $seen_ids, $data['ids'] );
			if ( empty( $data['next'] ) ) {
				break;
			}
			wp_parse_str( (string) wp_parse_url( $data['next'], PHP_URL_QUERY ), $params );
		}

		self::assertEqualsCanonicalizing( $post_ids, $seen_ids, 'Following the next URLs returns every post exactly once.' );
	}

	/**
	 * Values of the block's deduplicate attribute.
	 *
	 * @return array[]
	 */
	public function deduplicate_settings() {
		return [
			'allow duplicate content off' => [ true ],
			'allow duplicate content on'  => [ false ],
		];
	}

	/**
	 * The editor posts payload never carries an unsafe URL scheme.
	 *
	 * post_link resolves from the newspack_sponsor_url / newspack_supporter_url
	 * meta, which is stored through sanitize_text_field and so can hold any
	 * string. The editor renders it as an href, and a modified click follows it,
	 * so the payload applies the same protocol allowlist the front end gets from
	 * esc_url() in templates/article.php.
	 */
	public function test_editor_posts_endpoint_strips_unsafe_link_schemes() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, 'newspack_sponsor_url', 'javascript:alert(1)' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'GET', '/newspack-blocks/v1/newspack-blocks-posts' );
		$request->set_param( 'postsToShow', 10 );
		$posts = rest_do_request( $request )->get_data();

		$posts_by_id = array_column( $posts, null, 'id' );
		self::assertArrayHasKey( $post_id, $posts_by_id, 'The editor posts endpoint returns the post.' );
		self::assertStringNotContainsString(
			'javascript:',
			(string) $posts_by_id[ $post_id ]['post_link'],
			'The editor payload does not carry a javascript: URL for the preview to render as an href.'
		);
	}

	/**
	 * The editor sponsor payload never carries an unsafe URL scheme.
	 *
	 * The preview renders sponsor_url as the href on the sponsor logo and byline,
	 * choosing a link or plain text by whether the value is truthy, so an unsafe
	 * scheme has to arrive as an empty string rather than reach the href.
	 */
	public function test_editor_sponsor_payload_strips_unsafe_sponsor_url() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		\Newspack_Sponsors\Sponsors_Stub::$stub_sponsors = [
			[
				'sponsor_flag'   => 'Sponsored',
				'sponsor_name'   => 'Example Sponsor',
				'sponsor_url'    => 'javascript:alert(1)',
				'sponsor_byline' => 'Sponsored by',
				'sponsor_id'     => 1001,
				'sponsor_scope'  => 'native',
			],
		];
		try {
			$sponsor_info = Newspack_Blocks_API::newspack_blocks_sponsor_info( [ 'id' => $post_id ] );
		} finally {
			\Newspack_Sponsors\Sponsors_Stub::$stub_sponsors = null;
		}

		self::assertSame( '', $sponsor_info[0]['sponsor_url'], 'An unsafe sponsor URL arrives empty, so the preview renders the sponsor unlinked.' );
	}

	/**
	 * The editor posts payload carries real author-archive links.
	 *
	 * Navigation is prevented at the preview container (see
	 * shared/js/inert-preview.js), so the payload keeps the same URLs the front
	 * end renders — which is what lets an editor ctrl/middle-click a previewed
	 * post open in a new tab.
	 */
	public function test_editor_posts_endpoint_carries_live_author_links() {
		$author_id = self::factory()->user->create(
			[
				'role'          => 'author',
				'display_name'  => 'Nia Fixture',
				'user_nicename' => 'nia-fixture',
			]
		);
		$authored_post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_author' => $author_id,
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'GET', '/newspack-blocks/v1/newspack-blocks-posts' );
		$request->set_param( 'postsToShow', 10 );
		$posts = rest_do_request( $request )->get_data();

		$posts_by_id = array_column( $posts, null, 'id' );
		self::assertArrayHasKey( $authored_post_id, $posts_by_id, 'The editor posts endpoint returns the authored post.' );
		self::assertStringContainsString(
			get_author_posts_url( $author_id, 'nia-fixture' ),
			$posts_by_id[ $authored_post_id ]['newspack_post_byline'],
			'The editor byline carries the live author-archive link; the preview container is what prevents navigation.'
		);
	}

	/**
	 * The editor byline never carries an unsafe URL scheme from a byline filter.
	 *
	 * newspack_blocks_post_byline output reaches the payload as markup the
	 * preview renders, and a modified click on one of its links reaches the
	 * browser, so the payload applies the same wp_kses_post() the front end does.
	 */
	public function test_editor_byline_strips_unsafe_schemes_from_filtered_byline() {
		$author_id        = self::factory()->user->create(
			[
				'role'          => 'author',
				'user_nicename' => 'kai-fixture',
			]
		);
		$authored_post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_author' => $author_id,
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$append_unsafe_link = function ( $byline ) {
			return $byline . ' <a href="javascript:alert(1)">Filtered link</a>';
		};
		add_filter( 'newspack_blocks_post_byline', $append_unsafe_link );
		try {
			$request = new WP_REST_Request( 'GET', '/newspack-blocks/v1/newspack-blocks-posts' );
			$request->set_param( 'postsToShow', 10 );
			$posts = rest_do_request( $request )->get_data();
		} finally {
			remove_filter( 'newspack_blocks_post_byline', $append_unsafe_link );
		}

		$byline = array_column( $posts, null, 'id' )[ $authored_post_id ]['newspack_post_byline'];
		self::assertStringNotContainsString( 'javascript:', $byline, 'The editor byline does not carry a javascript: URL for the preview to render as an href.' );
		self::assertStringContainsString( get_author_posts_url( $author_id, 'kai-fixture' ), $byline, 'Safe author links survive.' );
	}

	/**
	 * The front-end byline formatter keeps live author-archive links.
	 *
	 * newspack_blocks_format_byline() is shared by the front end and the editor
	 * payload, so it must stay free of editor-only concerns. This pins that:
	 * link rewriting here would break every reader-facing author link, and the
	 * editor does not need it — the preview container blocks navigation.
	 */
	public function test_front_end_byline_formatter_keeps_live_author_links() {
		$author_id = self::factory()->user->create(
			[
				'role'          => 'author',
				'display_name'  => 'Frank Fixture',
				'user_nicename' => 'frank-fixture',
			]
		);
		$post_id   = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_author' => $author_id,
			]
		);

		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$byline = newspack_blocks_format_byline( Newspack_Blocks::prepare_authors() );

		self::assertStringContainsString(
			get_author_posts_url( $author_id, 'frank-fixture' ),
			$byline,
			'The front-end byline keeps the live author-archive link.'
		);
		self::assertStringContainsString( 'Frank Fixture', $byline, 'The author name renders in the front-end byline.' );

		wp_reset_postdata();
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
	 * The newspack_tag_labels REST field never carries an unsafe URL scheme.
	 *
	 * Tag-label links come from get_term_link(), which a term_link filter can
	 * change. The editor renders them as hrefs and a modified click follows
	 * them, so the payload applies the protocol allowlist the front end gets
	 * from esc_url().
	 */
	public function test_tag_labels_rest_field_strips_unsafe_link_schemes() {
		if ( ! property_exists( '\Newspack\Tag_Labels', 'stub_labels' ) ) {
			$this->markTestSkipped( 'Real \Newspack\Tag_Labels present; stub-based contract test skipped.' );
		}
		$post_id = self::factory()->post->create();

		\Newspack\Tag_Labels::$stub_labels = [
			[
				'flag' => 'Breaking',
				'link' => 'javascript:alert(1)',
			],
		];
		try {
			$result = Newspack_Blocks_API::newspack_blocks_get_tag_labels( [ 'id' => $post_id ] );
		} finally {
			\Newspack\Tag_Labels::$stub_labels = null;
		}

		self::assertSame( '', $result[0]['link'], 'An unsafe tag-label link arrives empty.' );
		self::assertSame( 'Breaking', $result[0]['flag'], 'The label itself is unchanged.' );
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
	 * The block server render must not emit the `cat-links` class on tag
	 * labels: per-section `.cat-links a` styling must never recolor them
	 * (NPPM-3049, the block-side counterpart of the NPPM-3048 theme fix).
	 */
	public function test_display_tag_labels_renders_without_cat_links() {
		ob_start();
		Newspack_Blocks::display_tag_labels(
			[
				[
					'flag' => 'Opinion',
					'link' => 'https://example.org/tag/opinion/',
				],
			]
		);
		$html = ob_get_clean();

		self::assertStringContainsString( '<div class="tag-labels">', $html, 'Wrapper is a div carrying exactly the tag-labels class.' );
		self::assertStringNotContainsString( 'cat-links', $html, 'Wrapper must not carry cat-links (NPPM-3049).' );
		self::assertStringContainsString( 'class="tag-label flag"', $html, 'Inner labels keep the tag-label flag classes.' );
	}

	/**
	 * Empty labels produce no output, not an empty wrapper.
	 *
	 * `null` and `[]` reach the same early return, so one call covers both. What
	 * this catches is a wrapper, or the space that trails it, being echoed when
	 * there is nothing to put inside.
	 */
	public function test_display_tag_labels_outputs_nothing_for_empty_labels() {
		ob_start();
		Newspack_Blocks::display_tag_labels( null );
		self::assertSame( '', ob_get_clean(), 'Empty labels must render nothing, not an empty wrapper.' );
	}

	/**
	 * A label with no `link` renders as an unlinked span, without an "undefined
	 * array key" warning on the way (NPPM-3051). PHPUnit converts warnings to
	 * exceptions here, so the warning itself fails the test.
	 */
	public function test_display_tag_labels_renders_flag_only_label_as_span() {
		ob_start();
		Newspack_Blocks::display_tag_labels( [ [ 'flag' => 'Opinion' ] ] );
		$html = ob_get_clean();

		self::assertStringContainsString( '<span class="tag-label flag">Opinion</span>', $html );
		self::assertStringNotContainsString( '<a ', $html, 'A label without a link must not render an anchor.' );
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

	/**
	 * Access levels and content shapes for the Jetpack paywall excerpt test.
	 *
	 * @return array[] Whether Jetpack's paywall is active, the access level, whether the
	 *                 post has a Paywall block, and whether the text above and below
	 *                 that point should reach the excerpt.
	 */
	public function jetpack_paywall_excerpt_cases() {
		return [
			'gated post with a Paywall block'      => [ true, 'subscribers', true, true, false ],
			'gated post without a Paywall block'   => [ true, 'subscribers', false, false, false ],
			'ungated post with a Paywall block'    => [ true, '', true, true, true ],
			'gated post while paywall is inactive' => [ false, 'subscribers', true, true, true ],
		];
	}

	/**
	 * A Jetpack-gated post's excerpt stops at the Paywall block, since the excerpt
	 * is built from raw content that Jetpack's the_content paywall never sees.
	 *
	 * @dataProvider jetpack_paywall_excerpt_cases
	 *
	 * @param bool   $paywall_active    Whether Jetpack's paywall is hooked on the_content.
	 * @param string $access_level      Value of Jetpack's access-level post meta.
	 * @param bool   $has_paywall_block Whether the content includes a Paywall block.
	 * @param bool   $shows_teaser      Whether the text above the Paywall block appears.
	 * @param bool   $shows_paywalled   Whether the text below the Paywall block appears.
	 */
	public function test_excerpt_of_jetpack_gated_post_stops_at_paywall_block( $paywall_active, $access_level, $has_paywall_block, $shows_teaser, $shows_paywalled ) {
		$content = '<!-- wp:paragraph --><p>TEASERMARK</p><!-- /wp:paragraph -->'
			. ( $has_paywall_block ? '<!-- wp:jetpack/paywall /-->' : '' )
			. '<!-- wp:paragraph --><p>PAYWALLEDMARK</p><!-- /wp:paragraph -->';
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $content,
				'post_excerpt' => '',
			]
		);
		if ( $access_level ) {
			update_post_meta( $post_id, '_jetpack_newsletter_access', $access_level );
		}

		$jetpack_paywall_callback = 'Automattic\Jetpack\Extensions\Subscriptions\add_paywall';
		if ( $paywall_active ) {
			add_filter( 'the_content', $jetpack_paywall_callback, 8 );
		}
		try {
			$GLOBALS['post'] = get_post( $post_id );
			setup_postdata( $GLOBALS['post'] );
			Newspack_Blocks::filter_excerpt( [ 'excerptLength' => 999, 'showExcerpt' => true ] );
			$excerpt = get_the_excerpt( $post_id );
		} finally {
			// Unhook so later tests don't see Jetpack's paywall as active.
			Newspack_Blocks::remove_excerpt_filter();
			remove_filter( 'the_content', $jetpack_paywall_callback, 8 );
			unset( $GLOBALS['post'] );
		}

		self::assertSame( $shows_teaser, str_contains( $excerpt, 'TEASERMARK' ), 'Text above the Paywall block.' );
		self::assertSame( $shows_paywalled, str_contains( $excerpt, 'PAYWALLEDMARK' ), 'Text below the Paywall block.' );
	}

	/**
	 * The subtitle allowlist keeps the inline formatting an editor may write.
	 */
	public function test_sanitize_post_subtitle_keeps_allowed_markup() {
		self::assertSame(
			'A <em>real</em> <strong>subtitle</strong>',
			Newspack_Blocks::sanitize_post_subtitle( 'A <em>real</em> <strong>subtitle</strong>' ),
			'Allowed inline tags survive sanitization.'
		);
		self::assertSame(
			'<a href="https://example.com" target="_blank" rel="noopener">Link</a>',
			Newspack_Blocks::sanitize_post_subtitle( '<a href="https://example.com" target="_blank" rel="noopener">Link</a>' ),
			'A link keeps href, target and rel.'
		);
	}

	/**
	 * Markup outside the allowlist is removed rather than escaped, so nothing reaches
	 * the editor component that assigns this value as HTML.
	 */
	public function test_sanitize_post_subtitle_removes_disallowed_markup() {
		$sanitized = Newspack_Blocks::sanitize_post_subtitle( '<img src=x onerror="STEAL()">' );

		self::assertStringNotContainsString( '<img', $sanitized, 'A disallowed element is removed.' );
		self::assertStringNotContainsString( 'STEAL', $sanitized, 'Its handler goes with it.' );
		self::assertStringNotContainsString(
			'onmouseover',
			Newspack_Blocks::sanitize_post_subtitle( '<em onmouseover="STEAL()">hover</em>' ),
			'An event handler on an allowed element is stripped.'
		);
	}

	/**
	 * The editor endpoint returns the whole registered-meta bag, and the Homepage Posts
	 * editor component assigns the subtitle as HTML. A value stored while the theme had
	 * no sanitize_callback is still raw in the database, so the endpoint filters it.
	 */
	public function test_editor_posts_endpoint_sanitizes_post_subtitle() {
		// Registered without a sanitize_callback on purpose: this is the pre-fix theme,
		// and therefore the state of every subtitle stored before the fix ships.
		register_post_meta(
			'post',
			'newspack_post_subtitle',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			]
		);
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, 'newspack_post_subtitle', '<em>Kept</em><img src=x onerror="STEAL()">' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'GET', '/newspack-blocks/v1/newspack-blocks-posts' );
		$request->set_param( 'postsToShow', 10 );
		$posts = rest_do_request( $request )->get_data();

		$posts_by_id = array_column( $posts, null, 'id' );
		self::assertArrayHasKey( $post_id, $posts_by_id, 'The endpoint returns the carrier post.' );

		$subtitle = $posts_by_id[ $post_id ]['meta']['newspack_post_subtitle'];
		self::assertStringNotContainsString( 'onerror', $subtitle, 'The payload does not reach the editor.' );
		self::assertStringNotContainsString( '<img', $subtitle, 'The disallowed element does not reach the editor.' );
		self::assertStringContainsString( '<em>Kept</em>', $subtitle, 'Allowed formatting still reaches the editor.' );

		unregister_post_meta( 'post', 'newspack_post_subtitle' );
	}
}
