<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class HomepagePostsBlockTest
 *
 * @package Newspack_Content_Blocks
 */


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
		self::assertFalse( \Newspack_Content_Blocks\Blocks::should_reset_deduplication_per_render_pass() );

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
		self::assertTrue( \Newspack_Content_Blocks\Blocks::should_reset_deduplication_per_render_pass() );

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
		$custom_type = $this->register_viewable_cpt( 'some-type' );
		$cases       = [
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
					'post_type'      => [ $custom_type ],
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
			$result = \Newspack_Content_Blocks\Blocks::build_articles_query( $case['block_attributes'], 'newspack-blocks/homepage-articles' );
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
		$query_args       = \Newspack_Content_Blocks\Blocks::build_articles_query( $block_attributes, 'newspack-blocks/homepage-articles' );
		$query            = new WP_Query( $query_args );

		self::assertEquals( 1, count( $query->posts ), 'There is one post returned.' );
		self::assertEquals( $post_id, $query->posts[0]->ID, 'The post returned is the one with the CAP author assigned.' );
	}

	/**
	 * The linked guest author lookup used by the author filter works without
	 * newspack-blocks' authors controller.
	 */
	public function test_linked_guest_author_lookup() {
		$method = new ReflectionMethod( \Newspack_Content_Blocks\Blocks::class, 'get_linked_guest_author' );
		$method->setAccessible( true );

		self::assertFalse( $method->invoke( null, 'nobody' ), 'No guest author is linked to an unknown login.' );

		$guest_id = self::factory()->post->create(
			[
				'post_type'  => 'guest-author',
				'meta_input' => [ 'cap-linked_account' => 'linked-login' ],
			]
		);
		$found    = $method->invoke( null, 'linked-login' );
		self::assertInstanceOf( WP_Post::class, $found );
		self::assertSame( $guest_id, $found->ID );
	}

	/**
	 * Core's block-renderer route is open to anyone with edit_posts, so the block's own
	 * server render must not list a non-viewable post type either.
	 */
	public function test_block_render_excludes_non_viewable_post_types() {
		$secret = $this->register_non_viewable_cpt();
		self::factory()->post->create(
			[
				'post_type'   => $secret,
				'post_status' => 'publish',
				'post_title'  => 'Secret CPT title',
			]
		);
		self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Regular post title',
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/newspack-blocks/homepage-articles' );
		$request->set_query_params(
			[
				'context'    => 'edit',
				'attributes' => [ 'postType' => [ $secret ] ],
			]
		);
		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status(), 'The block-renderer route answers the request.' );
		self::assertStringNotContainsString( 'Secret CPT title', $response->get_data()['rendered'], 'The render does not list a non-viewable post type.' );
		self::assertStringNotContainsString( 'Regular post title', $response->get_data()['rendered'], 'The render returns nothing rather than substituting the default post type.' );

		$request->set_query_params(
			[
				'context'    => 'edit',
				'attributes' => [ 'postType' => [ 'post' ] ],
			]
		);
		self::assertStringContainsString( 'Regular post title', rest_do_request( $request )->get_data()['rendered'], 'The same request for a viewable post type renders its posts.' );
	}

	/**
	 * The blocks render with newspack-plugin absent, as on a plain WordPress install.
	 */
	public function test_renders_without_newspack_plugin() {
		self::assertFalse( class_exists( 'Newspack\\Newspack' ), 'newspack-plugin is not loaded in this suite.' );

		self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		$homepage = render_block(
			[
				'blockName'    => 'newspack-blocks/homepage-articles',
				'attrs'        => [ 'postsToShow' => 3 ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		self::assertSame( 3, substr_count( $homepage, '<article' ), 'The Content Loop renders three articles.' );
		self::assertStringContainsString( 'wpnbha', $homepage );

		self::reset_dedup_globals();

		$carousel = render_block(
			[
				'blockName'    => 'newspack-blocks/carousel',
				'attrs'        => [ 'postsToShow' => 3 ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		self::assertStringContainsString( 'wpnbpc', $carousel, 'The Carousel renders.' );
	}

	/**
	 * The front-end byline formatter keeps live author-archive links.
	 *
	 * \Newspack_Content_Blocks\Homepage_Articles\format_byline() is shared by the front end and the editor
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

		$byline = \Newspack_Content_Blocks\Homepage_Articles\format_byline( \Newspack_Content_Blocks\Blocks::prepare_authors() );

		self::assertStringContainsString(
			get_author_posts_url( $author_id, 'frank-fixture' ),
			$byline,
			'The front-end byline keeps the live author-archive link.'
		);
		self::assertStringContainsString( 'Frank Fixture', $byline, 'The author name renders in the front-end byline.' );

		wp_reset_postdata();
	}

	/**
	 * The block server render must not emit the `cat-links` class on tag
	 * labels: per-section `.cat-links a` styling must never recolor them
	 * (NPPM-3049, the block-side counterpart of the NPPM-3048 theme fix).
	 */
	public function test_display_tag_labels_renders_without_cat_links() {
		ob_start();
		\Newspack_Content_Blocks\Blocks::display_tag_labels(
			[
				[
					'flag' => 'Opinion',
					'link' => 'https://example.org/tag/opinion/',
				],
			]
		);
		$html = ob_get_clean();

		self::assertStringContainsString( '<div class="newspack-tag-labels tag-labels">', $html, 'Wrapper is a div carrying exactly the tag-labels classes.' );
		self::assertStringNotContainsString( 'cat-links', $html, 'Wrapper must not carry cat-links (NPPM-3049).' );
		self::assertStringContainsString( 'class="newspack-tag-label tag-label flag"', $html, 'Inner labels keep the tag-label flag classes.' );
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
		\Newspack_Content_Blocks\Blocks::display_tag_labels( null );
		self::assertSame( '', ob_get_clean(), 'Empty labels must render nothing, not an empty wrapper.' );
	}

	/**
	 * `display_tag_labels()` passes a label with no `link` through, and it renders
	 * as an unlinked span. This suite loads the test stub rather than the plugin,
	 * so this checks the blocks caller against the stub's copy of the NPPM-3051
	 * logic; the plugin's own suite pins the fix itself.
	 */
	public function test_display_tag_labels_renders_flag_only_label_as_span() {
		ob_start();
		\Newspack_Content_Blocks\Blocks::display_tag_labels( [ [ 'flag' => 'Opinion' ] ] );
		$html = ob_get_clean();

		self::assertStringContainsString( '<span class="newspack-tag-label tag-label flag">Opinion</span>', $html );
		self::assertStringNotContainsString( '<a ', $html, 'A label without a link must not render an anchor.' );
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

		\Newspack_Content_Blocks\Blocks::filter_excerpt( [ 'excerptLength' => 999, 'showExcerpt' => true ] );
		$excerpt = get_the_excerpt( $post_id );
		\Newspack_Content_Blocks\Blocks::remove_excerpt_filter();

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
			\Newspack_Content_Blocks\Blocks::filter_excerpt( [ 'excerptLength' => 999, 'showExcerpt' => true ] );
			$excerpt = get_the_excerpt( $post_id );
		} finally {
			// Unhook so later tests don't see Jetpack's paywall as active.
			\Newspack_Content_Blocks\Blocks::remove_excerpt_filter();
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
			\Newspack_Content_Blocks\Blocks::sanitize_post_subtitle( 'A <em>real</em> <strong>subtitle</strong>' ),
			'Allowed inline tags survive sanitization.'
		);
		self::assertEqualHTML(
			'<a href="https://example.com" target="_blank" rel="noopener">Link</a>',
			\Newspack_Content_Blocks\Blocks::sanitize_post_subtitle( '<a href="https://example.com" target="_blank" rel="noopener">Link</a>' ),
			'<body>',
			'A link keeps href, target and rel.'
		);
	}

	/**
	 * Markup outside the allowlist is removed rather than escaped, so nothing reaches
	 * the editor component that assigns this value as HTML.
	 */
	public function test_sanitize_post_subtitle_removes_disallowed_markup() {
		$sanitized = \Newspack_Content_Blocks\Blocks::sanitize_post_subtitle( '<img src=x onerror="STEAL()">' );

		self::assertStringNotContainsString( '<img', $sanitized, 'A disallowed element is removed.' );
		self::assertStringNotContainsString( 'STEAL', $sanitized, 'Its handler goes with it.' );
		self::assertStringNotContainsString(
			'onmouseover',
			\Newspack_Content_Blocks\Blocks::sanitize_post_subtitle( '<em onmouseover="STEAL()">hover</em>' ),
			'An event handler on an allowed element is stripped.'
		);
	}

	/**
	 * Create a published post that a plugin reports as updated later than its
	 * stored modified date, the way a live blog's newest update dates its page.
	 *
	 * @param int $published Publish timestamp.
	 * @param int $updated   Modified timestamp to report.
	 * @return int Post ID.
	 */
	private function create_post_updated_at( $published, $updated ) {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $published ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $published ),
			]
		);
		foreach ( [ 'get_the_modified_date' => 'date_format', 'get_the_modified_time' => 'time_format' ] as $hook => $default_format ) {
			add_filter(
				$hook,
				function ( $the_time, $format, $post ) use ( $post_id, $updated, $default_format ) {
					if ( ! $post instanceof WP_Post || $post->ID !== $post_id ) {
						return $the_time;
					}
					return wp_date( '' === $format ? get_option( $default_format ) : $format, $updated );
				},
				10,
				3
			);
		}
		return $post_id;
	}

	/**
	 * Render a Homepage Posts block showing only the given post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Block HTML.
	 */
	private function render_card( $post_id ) {
		return render_block(
			[
				'blockName'    => 'newspack-blocks/homepage-articles',
				'attrs'        => [
					'specificMode'  => true,
					'specificPosts' => [ $post_id ],
					'showDate'      => true,
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * A card whose post is set to show its updated date reports when the post
	 * was last updated instead of when it was published, so a post that keeps
	 * changing doesn't look old on the homepage.
	 */
	public function test_card_shows_updated_date_instead_of_publish_date() {
		$published = time() - 3 * HOUR_IN_SECONDS;
		$updated   = time() - 5 * MINUTE_IN_SECONDS;
		$post_id   = $this->create_post_updated_at( $published, $updated );
		add_filter(
			'newspack_blocks_display_updated_date',
			function ( $display, $post ) use ( $post_id ) {
				return $post->ID === $post_id ? true : $display;
			},
			10,
			2
		);

		$html = $this->render_card( $post_id );

		self::assertStringContainsString(
			'<span class="updated-date">Updated <time class="entry-date updated" datetime="' . wp_date( DATE_W3C, $updated ) . '">' . wp_date( get_option( 'date_format' ), $updated ) . '</time></span>',
			$html,
			'The card shows the updated date with its label.'
		);
		self::assertStringNotContainsString( wp_date( DATE_W3C, $published ), $html, 'The card leaves out the publish date.' );
	}

	/**
	 * The card's updated date goes through its own formatting filter, which is
	 * where a "time ago" setting rewrites it.
	 */
	public function test_card_updated_date_text_is_filterable() {
		$post_id = $this->create_post_updated_at( time() - 3 * HOUR_IN_SECONDS, time() - 5 * MINUTE_IN_SECONDS );
		add_filter( 'newspack_blocks_display_updated_date', '__return_true' );
		add_filter(
			'newspack_blocks_formatted_displayed_updated_date',
			function ( $date, $post ) use ( $post_id ) {
				return $post->ID === $post_id ? '5 minutes ago' : $date;
			},
			10,
			2
		);

		self::assertStringContainsString( '">5 minutes ago</time>', $this->render_card( $post_id ), 'The card shows the filtered updated date text.' );
	}

	/**
	 * Cards show the publish date unless a plugin asks for the updated date.
	 */
	public function test_card_shows_publish_date_by_default() {
		$published = time() - 3 * HOUR_IN_SECONDS;
		$post_id   = $this->create_post_updated_at( $published, time() - 5 * MINUTE_IN_SECONDS );

		$html = $this->render_card( $post_id );

		self::assertStringContainsString( 'class="entry-date published" datetime="' . wp_date( DATE_W3C, $published ) . '"', $html, 'The card shows the publish date.' );
		self::assertStringNotContainsString( 'updated-date', $html, 'The card has no updated date.' );
	}
}
