<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Insertion Test
 *
 * @package Newspack_Popups
 */

/**
 * Insertion test case.
 */
class InsertionTest extends WP_UnitTestCase_PageWithPopups {
	/**
	 * Test popup insertion into a post.
	 */
	public function test_insertion_on_post() {
		self::renderPost();
		$popup_elements     = self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container")]' );
		$popup_text_content = $popup_elements->item( 0 )->textContent;

		self::assertStringContainsString(
			self::$popup_content,
			$popup_text_content,
			'Includes the popup content.'
		);
		self::assertStringContainsString(
			self::$raw_post_content,
			self::$post_content,
			'Includes the original post content.'
		);
	}

	/**
	 * Test popup insertion into a page.
	 */
	public function test_insertion_on_page() {
		self::renderPost( '', null, [], [], 'page' );
		$popup_elements = self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container")]' );

		self::assertEquals(
			1,
			$popup_elements->length,
			'Inserts the inline prompt on a page.'
		);
		self::assertStringContainsString(
			self::$raw_post_content,
			self::$post_content,
			'Includes the original post content.'
		);

		$overlay_content     = 'Hello, world';
		$overlay_id          = self::createPopup( $overlay_content, [ 'placement' => 'center' ] );
		$page_with_shortcode = '[newspack-popups id="' . $overlay_id . '"]';
		self::renderPost( '', $page_with_shortcode, [], [], 'page' );
		$overlay_text_content = self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container")]' )->item( 0 )->textContent;

		self::assertStringContainsString(
			$overlay_content,
			$overlay_text_content,
			'Inserts the overlay prompt on a page.'
		);
	}

	/**
	 * Shortcode handling.
	 */
	public function test_shortcode() {
		// Remove the default popup that would be programmatically inserted.
		wp_delete_post( self::$popup_id );

		$popup_content       = 'Hello, world';
		$popup_id            = self::createPopup( $popup_content );
		$post_with_shortcode = '[newspack-popups id="' . $popup_id . '"]';
		self::renderPost( '', $post_with_shortcode );
		$popup_text_content = self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container")]' )->item( 0 )->textContent;

		self::assertStringContainsString(
			$popup_content,
			$popup_text_content,
			'Shortcode inserts the popup content.'
		);
	}

	/**
	 * Shortcode along with programmatically placed popups handling.
	 */
	public function test_shortcode_and_programmatic() {
		$shortcode_popup_content = 'Hello, world';
		$shortcoded_popup_id     = self::createPopup( $shortcode_popup_content );

		self::renderPost( '', '[newspack-popups id="' . $shortcoded_popup_id . '"]' );

		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Post contains the programatically inserted popup content.'
		);
		self::assertStringContainsString(
			$shortcode_popup_content,
			self::$post_content,
			'Post contains the shortcode-inserted popup content.'
		);
	}

	/**
	 * Single popup preview.
	 */
	public function test_insertion_single_preview() {
		// Remove the default popup that would be programmatically inserted.
		wp_delete_post( self::$popup_id );

		$popup_content = 'Hello, world';
		$popup_id      = self::createPopup( $popup_content, [], [ 'post_status' => 'draft' ] );
		$preview_param = 'pid=' . $popup_id;

		self::renderPost( $preview_param );
		$popup_elements = self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container")]' );

		self::assertEquals(
			0,
			$popup_elements->length,
			'There are no popups, the previewed popup should only be displayed if user is admin.'
		);

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		self::renderPost( $preview_param );

		$popup_elements     = self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container")]' );
		$popup_text_content = $popup_elements->item( 0 )->textContent;

		self::assertStringContainsString(
			$popup_content,
			$popup_text_content,
			'Includes the previewed popup content for a logged-in user.'
		);
	}

	/**
	 * As an admin.
	 */
	public function test_insertion_admin() {
		self::renderPost();
		$popup_elements = self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container")]' );
		self::assertStringContainsString(
			self::$popup_content,
			$popup_elements->item( 0 )->textContent,
			'Includes the popup content for non-logged-in users.'
		);

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		self::renderPost();
		$popup_elements = self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container")]' );
		self::assertStringContainsString(
			self::$popup_content,
			$popup_elements->item( 0 )->textContent,
			'Also includes the popup content for logged-in admin users.'
		);
	}

	/**
	 * Test custom placement popups.
	 */
	public function test_custom_placement_prompt() {
		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'placement' => 'custom1',
				'frequency' => 'always',
			]
		);

		self::renderPost();
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content, since it is a custom placement popup.'
		);

		self::renderPost( '', '<!-- wp:newspack-popups/custom-placement {"customPlacement":"custom1"} /-->' );
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Includes the popup content when the custom placement is present in post content.'
		);
	}

	/**
	 * Test manual placement popups and single prompt block.
	 */
	public function test_prompt_block() {
		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'placement' => 'manual',
				'frequency' => 'always',
			]
		);

		self::renderPost();
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content, since it is a manual-only placement popup.'
		);

		self::renderPost( '', '<!-- wp:newspack-popups/single-prompt {"promptId":' . self::$popup_id . '} /-->' );
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Includes the popup content when the prompt is placed in post content via the Single Prompt block.'
		);
	}

	/**
	 * Category criterion.
	 */
	public function test_criterion_category() {
		self::renderPost();
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Does include the popup content if neither post nor popup have a category.'
		);

		$category_1_id = self::factory()->term->create(
			[
				'name'     => 'Events',
				'taxonomy' => 'category',
				'slug'     => 'events',
			]
		);

		self::renderPost( '', null, [ $category_1_id ] );
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Includes the popup content when the popup does not have a category, but post has.'
		);

		wp_set_post_terms( self::$popup_id, [ $category_1_id ], 'category' );

		self::renderPost();
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content when popup does have a category, but post does not.'
		);

		self::renderPost( '', null, [ $category_1_id ] );
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Includes the popup content when the categories match.'
		);

		$category_2_id = self::factory()->term->create(
			[
				'name'     => 'Health',
				'taxonomy' => 'category',
				'slug'     => 'health',
			]
		);
		self::renderPost( '', null, [ $category_2_id ] );
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content when popup and post have different categories.'
		);
	}

	/**
	 * Tag criterion.
	 */
	public function test_criterion_tag() {
		self::renderPost();
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Does include the popup content if neither post nor popup have tags.'
		);

		$tag_1_id = self::factory()->term->create(
			[
				'name'     => 'Featured',
				'taxonomy' => 'post_tag',
				'slug'     => 'featured',
			]
		);
		self::renderPost( '', null, [], [ $tag_1_id ] );
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Includes the popup content when popup does not have tags, but post has.'
		);

		// Set tag on the popup.
		wp_set_post_terms( self::$popup_id, [ $tag_1_id ], 'post_tag' );

		self::renderPost();
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content when the post has no tags, but popup has.'
		);

		$tag_2_id = self::factory()->term->create(
			[
				'name'     => 'Events',
				'taxonomy' => 'post_tag',
				'slug'     => 'events',
			]
		);
		self::renderPost( '', null, [], [ $tag_2_id ] );
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content when the post tag has a different tag than the popup.'
		);

		self::renderPost( '', null, [], [ $tag_1_id ] );
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Includes the popup content when the tags match.'
		);
	}

	/**
	 * Account related page handling.
	 */
	public function test_account_related_posts() {
		$woo_commerce_account_shortcode = 'woocommerce_my_account';
		$post_with_account_details      = "<!-- wp:shortcode -->[$woo_commerce_account_shortcode]<!-- /wp:shortcode -->";

		// Register WooCommerce shortcode.
		add_shortcode(
			$woo_commerce_account_shortcode,
			function() use ( $post_with_account_details ) {
				return $post_with_account_details;
			}
		);
		self::renderPost( '', $post_with_account_details );

		self::assertFalse( strpos( self::$post_content, self::$popup_content ), 'Popup content not rendered in account-related posts.' );
	}

	/**
	 * Test categories exclusion.
	 */
	public function test_categories_exclusion() {
		$category_to_exclude_id = self::factory()->term->create(
			[
				'name'     => 'Sport',
				'taxonomy' => 'category',
				'slug'     => 'sport',
			]
		);

		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'excluded_categories' => [ $category_to_exclude_id ],
			]
		);

		self::renderPost( '', null, [ $category_to_exclude_id ] );
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content, since the post category is excluded on this popup.'
		);
	}

	/**
	 * Test categories exclusion has priority over inclusion.
	 */
	public function test_categories_exclusion_priority_over_inclusion() {
		$category_to_exclude_id = self::factory()->term->create(
			[
				'name'     => 'Arts',
				'taxonomy' => 'category',
				'slug'     => 'arts',
			]
		);

		wp_set_post_terms( self::$popup_id, [ $category_to_exclude_id ], 'category' );

		self::renderPost( '', null, [ $category_to_exclude_id ] );
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Does contain the popup content, since both post and popup have the same category.'
		);

		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'excluded_categories' => [ $category_to_exclude_id ],
			]
		);

		self::renderPost( '', null, [ $category_to_exclude_id ] );
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content, since the post category is excluded on this popup.'
		);
	}

	/**
	 * Test tags exclusion.
	 */
	public function test_tags_exclusion() {
		$tag_to_exclude_id = self::factory()->term->create(
			[
				'name'     => 'No Prompt',
				'taxonomy' => 'post_tag',
				'slug'     => 'no-prompt',
			]
		);

		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'excluded_tags' => [ $tag_to_exclude_id ],
			]
		);

		self::renderPost( '', null, [], [ $tag_to_exclude_id ] );
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content, since the post tag is excluded on this popup.'
		);
	}

	/**
	 * Block theme archive insertion — inserts after Nth post item.
	 */
	public function test_block_theme_archive_insertion_basic() {
		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 2,
				'archive_insertion_is_repeating' => false,
			]
		);

		$block_content = '
			<ul class="wp-block-post-template">
				<li class="wp-block-post post-type-post">Post 1 content</li>
				<li class="wp-block-post post-type-post">Post 2 content</li>
				<li class="wp-block-post post-type-post">Post 3 content</li>
			</ul>';

		$block = [ 'blockName' => 'core/post-template' ];

		// Create enough posts so $wp_query->post_count > archive_insertion_posts_count (2),
		// preventing the end-of-list fallback from firing in unexpected positions.
		$post_ids = self::factory()->post->create_many( 5 );

		// Navigate to the blog home page (is_home() = true). This avoids any
		// archive_page_types early-return since is_category() etc. are false on is_home().
		$this->go_to( home_url() );

		// Set up a post in the global context — simulates the state after the Query Loop
		// block finishes rendering (global $post = last post in the loop).
		$GLOBALS['post'] = get_post( end( $post_ids ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$result = Newspack_Popups_Inserter::insert_inline_prompt_in_block_theme_archives( $block_content, $block );

		$pos_post2 = strpos( $result, 'Post 2 content' );
		$pos_popup = strpos( $result, self::$popup_content );
		$pos_post3 = strpos( $result, 'Post 3 content' );

		self::assertNotFalse( $pos_popup, 'Campaign HTML is present in the output.' );
		self::assertGreaterThan( $pos_post2, $pos_popup, 'Campaign appears after post 2.' );
		self::assertLessThan( $pos_post3, $pos_popup, 'Campaign appears before post 3.' );
	}

	/**
	 * Block theme archive insertion — repeating every N posts.
	 */
	public function test_block_theme_archive_insertion_repeating() {
		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 2,
				'archive_insertion_is_repeating' => true,
			]
		);

		$block_content = '
			<ul class="wp-block-post-template">
				<li class="wp-block-post post-type-post">Post 1</li>
				<li class="wp-block-post post-type-post">Post 2</li>
				<li class="wp-block-post post-type-post">Post 3</li>
				<li class="wp-block-post post-type-post">Post 4</li>
			</ul>';

		$block = [ 'blockName' => 'core/post-template' ];

		$post_ids = self::factory()->post->create_many( 5 );
		$this->go_to( home_url() );
		$GLOBALS['post'] = get_post( end( $post_ids ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$result = Newspack_Popups_Inserter::insert_inline_prompt_in_block_theme_archives( $block_content, $block );

		self::assertSame(
			2,
			substr_count( $result, self::$popup_content ),
			'Campaign appears twice in repeating mode (after posts 2 and 4).'
		);
	}

	/**
	 * Block theme archive insertion — prompt <li> must stay inside </ul>, not after it.
	 *
	 * Regression: preg_split's lookahead left </ul> in the last part, so the injected
	 * prompt <li> was appended after the closing </ul> tag.
	 */
	public function test_block_theme_archive_insertion_prompt_inside_ul() {
		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => true,
			]
		);

		$block_content = '<ul class="wp-block-post-template"><li class="wp-block-post post-type-post">Post 1</li><li class="wp-block-post post-type-post">Post 2</li></ul>';
		$block         = [ 'blockName' => 'core/post-template' ];

		$post_ids = self::factory()->post->create_many( 5 );
		$this->go_to( home_url() );
		$GLOBALS['post'] = get_post( end( $post_ids ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$result = Newspack_Popups_Inserter::insert_inline_prompt_in_block_theme_archives( $block_content, $block );

		$pos_close_ul = strpos( $result, '</ul>' );
		$pos_popup    = strrpos( $result, self::$popup_content );

		self::assertNotFalse( $pos_popup, 'Campaign HTML is present in the output.' );
		self::assertNotFalse( $pos_close_ul, 'Closing </ul> is present in the output.' );
		self::assertLessThan( $pos_close_ul, $pos_popup, 'Last campaign insertion appears before </ul>, not after it.' );
	}

	/**
	 * Block theme archive insertion — secondary (non-inherited) Query Loops are skipped.
	 */
	public function test_block_theme_archive_insertion_skips_secondary_query_loop() {
		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => false,
			]
		);

		$block_content = '<ul class="wp-block-post-template"><li class="wp-block-post post-type-post">Post 1</li></ul>';
		$block         = [ 'blockName' => 'core/post-template' ];

		$post_ids = self::factory()->post->create_many( 3 );
		$this->go_to( home_url() );
		$GLOBALS['post'] = get_post( end( $post_ids ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Simulate a secondary Query Loop (inherit=false, custom queryId).
		$instance = new WP_Block(
			$block,
			[
				'query'   => [ 'inherit' => false ],
				'queryId' => 42,
			]
		);

		$result = Newspack_Popups_Inserter::insert_inline_prompt_in_block_theme_archives( $block_content, $block, $instance );

		self::assertSame( $block_content, $result, 'Secondary Query Loop content is returned unchanged.' );
	}

	/**
	 * Block theme archive insertion — non-archive page is untouched.
	 */
	public function test_block_theme_archive_insertion_skips_non_archive() {
		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => false,
			]
		);

		$block_content = '<ul class="wp-block-post-template"><li class="wp-block-post">Post 1</li></ul>';
		$block         = [ 'blockName' => 'core/post-template' ];

		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );

		$result = Newspack_Popups_Inserter::insert_inline_prompt_in_block_theme_archives( $block_content, $block );

		self::assertSame( $block_content, $result, 'Block content is unchanged on a single post page.' );
	}

	/**
	 * Block theme archive insertion — non-post-template block is untouched.
	 */
	public function test_block_theme_archive_insertion_skips_other_blocks() {
		$block_content = '<p>Some paragraph</p>';
		$block         = [ 'blockName' => 'core/paragraph' ];

		$this->go_to( home_url() );

		$result = Newspack_Popups_Inserter::insert_inline_prompt_in_block_theme_archives( $block_content, $block );

		self::assertSame( $block_content, $result, 'Non-post-template block content is returned unchanged.' );
	}

	/**
	 * Block theme archive insertion — end-of-list fallback when post count < trigger count.
	 *
	 * The fallback path ($archive_insertion_posts_count >= $wp_query->post_count)
	 * inserts a prompt at the very end of a short list. Verify that the prompt
	 * still lands inside the <ul>, not after it.
	 */
	public function test_block_theme_archive_insertion_end_of_list_fallback() {
		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 10,
				'archive_insertion_is_repeating' => false,
			]
		);

		$block_content = '<ul class="wp-block-post-template"><li class="wp-block-post post-type-post">Post 1</li><li class="wp-block-post post-type-post">Post 2</li></ul>';
		$block         = [ 'blockName' => 'core/post-template' ];

		$post_ids = self::factory()->post->create_many( 2 );
		$this->go_to( home_url() );
		$GLOBALS['post'] = get_post( end( $post_ids ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Force post_count to 2 so the fallback path fires
		// ($archive_insertion_posts_count >= $wp_query->post_count).
		global $wp_query;
		$wp_query->post_count = 2;

		$result = Newspack_Popups_Inserter::insert_inline_prompt_in_block_theme_archives( $block_content, $block );

		$pos_popup    = strpos( $result, self::$popup_content );
		$pos_close_ul = strpos( $result, '</ul>' );

		self::assertNotFalse( $pos_popup, 'Campaign HTML is present via end-of-list fallback.' );
		self::assertLessThan( $pos_close_ul, $pos_popup, 'End-of-list prompt appears before </ul>.' );
	}

	/**
	 * Archive page type gate uses continue, not return — a skipped prompt does not
	 * suppress subsequent prompts in the same loop iteration.
	 */
	public function test_archive_page_type_skip_does_not_suppress_other_prompts() {
		$cat_id = self::factory()->term->create(
			[
				'name'     => 'News',
				'taxonomy' => 'category',
				'slug'     => 'news',
			]
		);

		// Prompt A: restricted to 'tag' only — should be skipped on a category archive.
		$popup_a_content = 'Prompt-A-tag-only';
		$popup_a_id      = self::createPopup(
			$popup_a_content,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => false,
				'archive_page_types'             => [ 'tag' ],
			]
		);

		// Prompt B: restricted to 'category' — should render on a category archive.
		$popup_b_content = 'Prompt-B-category';
		$popup_b_id      = self::createPopup(
			$popup_b_content,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => false,
				'archive_page_types'             => [ 'category' ],
			]
		);

		// Remove the default popup so only A and B are active.
		wp_delete_post( self::$popup_id );

		$post_ids = self::factory()->post->create_many( 3 );
		foreach ( $post_ids as $pid ) {
			wp_set_post_terms( $pid, [ $cat_id ], 'category' );
		}

		$this->go_to( get_category_link( $cat_id ) );
		$GLOBALS['post'] = get_post( end( $post_ids ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$output = Newspack_Popups_Inserter::get_inline_prompt_html_for_archive_pages( 1, 'li' );

		self::assertStringNotContainsString(
			$popup_a_content,
			$output,
			'Prompt restricted to tags is skipped on a category archive.'
		);
		self::assertStringContainsString(
			$popup_b_content,
			$output,
			'Prompt restricted to categories still renders after a prior prompt was skipped.'
		);
	}

	/**
	 * Test tags exclusion has priority over inclusion.
	 */
	public function test_tags_exclusion_priority_over_inclusion() {
		$tag_to_exclude_id = self::factory()->term->create(
			[
				'name'     => 'Excluded Tag',
				'taxonomy' => 'post_tag',
				'slug'     => 'excluded-tag',
			]
		);

		wp_set_post_terms( self::$popup_id, [ $tag_to_exclude_id ], 'tag' );

		self::renderPost( '', null, [], [ $tag_to_exclude_id ] );
		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Does contain the popup content, since both post and popup have the same tag.'
		);

		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'excluded_tags' => [ $tag_to_exclude_id ],
			]
		);

		self::renderPost( '', null, [], [ $tag_to_exclude_id ] );
		self::assertStringNotContainsString(
			self::$popup_content,
			self::$post_content,
			'Does not include the popup content, since the post tag is excluded on this popup.'
		);
	}

	/**
	 * Test that inline prompts still render when post_content has leading whitespace.
	 *
	 * When post_content begins with newline characters (e.g. from migrations or
	 * non-editor save flows), the guard clause in insert_popups_in_content() must
	 * not bail due to a first-line mismatch between the validated and raw content.
	 */
	public function test_insertion_with_leading_whitespace_in_post_content() {
		$post_content_with_leading_newlines = "\n\n<!-- wp:paragraph -->\n<p>Post content.</p>\n<!-- /wp:paragraph -->";

		self::renderPost( '', $post_content_with_leading_newlines );

		self::assertStringContainsString(
			self::$popup_content,
			self::$post_content,
			'Inline prompt renders even when post_content has leading newlines.'
		);
	}

	/**
	 * Configure a static front page with a separate posts page and navigate to it.
	 *
	 * @return int ID of the posts page.
	 */
	private function go_to_posts_page() {
		self::factory()->post->create_many( 3 );

		$page_on_front  = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Front Page',
			]
		);
		$page_for_posts = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Blog',
			]
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_on_front );
		update_option( 'page_for_posts', $page_for_posts );
		$this->go_to( get_permalink( $page_for_posts ) );

		return $page_for_posts;
	}

	/**
	 * Prompt restricted to categories does not appear on blog home (is_home()).
	 */
	public function test_archive_prompt_skipped_on_home_when_not_in_page_types() {
		// Remove the default popup so only the test fixture renders.
		wp_delete_post( self::$popup_id );
		self::createPopup(
			null,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => false,
				'archive_page_types'             => [ 'category' ],
			]
		);

		$this->go_to_posts_page();

		ob_start();
		Newspack_Popups_Inserter::insert_inline_prompt_in_archive_pages( 1 );
		$output = ob_get_clean();

		self::assertEmpty(
			$output,
			'Prompt restricted to categories is not inserted on the blog home page.'
		);
	}

	/**
	 * Prompt with 'home' in archive_page_types renders on blog home (is_home()).
	 */
	public function test_archive_prompt_rendered_on_home_when_in_page_types() {
		// Remove the default popup so only the test fixture renders.
		wp_delete_post( self::$popup_id );
		self::createPopup(
			null,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => false,
				'archive_page_types'             => [ 'home' ],
			]
		);

		$this->go_to_posts_page();

		ob_start();
		Newspack_Popups_Inserter::insert_inline_prompt_in_archive_pages( 1 );
		$output = ob_get_clean();

		self::assertNotEmpty(
			$output,
			'Prompt with home in archive_page_types is inserted on the blog home page.'
		);
	}

	/**
	 * Legacy prompt with no archive_page_types meta row is hidden on blog home.
	 *
	 * Simulates a prompt created before 'home' was an option: register_meta's
	 * legacy default (no 'home') must apply so the prompt does not suddenly
	 * start rendering on the posts page.
	 */
	public function test_archive_prompt_skipped_on_home_when_meta_missing() {
		// Remove the default popup so only the test fixture renders.
		wp_delete_post( self::$popup_id );
		$popup_id = self::createPopup(
			null,
			[
				'placement'                      => 'archives',
				'frequency'                      => 'always',
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => false,
			]
		);
		delete_post_meta( $popup_id, 'archive_page_types' );

		$this->go_to_posts_page();

		ob_start();
		Newspack_Popups_Inserter::insert_inline_prompt_in_archive_pages( 1 );
		$output = ob_get_clean();

		self::assertEmpty(
			$output,
			'Legacy prompt without archive_page_types meta is not inserted on the blog home page.'
		);
	}
}
