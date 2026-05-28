<?php
/**
 * Tests for Primary_Category utility.
 *
 * @package Newspack\Tests
 */

use Newspack\Primary_Category;

// Load mock if Yoast is not available.
if ( ! class_exists( 'WPSEO_Primary_Term' ) ) {
	require_once dirname( __DIR__ ) . '/mocks/yoast-mocks.php';
}

/**
 * Primary_Category test case.
 *
 * @group primary-category
 * @covers \Newspack\Primary_Category
 */
class Test_Primary_Category extends WP_UnitTestCase {

	/**
	 * Original global $post value, saved in set_up and restored in tear_down.
	 *
	 * @var \WP_Post|null
	 */
	private $original_post;

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();
		global $post;
		$this->original_post = $post;
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		global $post;
		$post = $this->original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		delete_option( Primary_Category::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Test is_yoast_active() returns true (mock is loaded).
	 *
	 * @covers \Newspack\Primary_Category::is_yoast_active
	 */
	public function test_is_yoast_active() {
		$this->assertTrue( Primary_Category::is_yoast_active(), 'is_yoast_active() should return true when WPSEO_Primary_Term class exists.' );
	}

	/**
	 * Test is_enabled() returns true by default when Yoast is active.
	 *
	 * @covers \Newspack\Primary_Category::is_enabled
	 */
	public function test_is_enabled_defaults_to_true() {
		delete_option( Primary_Category::OPTION_NAME );
		$this->assertTrue( Primary_Category::is_enabled(), 'is_enabled() should return true by default when Yoast is active and the option is not set.' );
	}

	/**
	 * Test is_enabled() respects the option value.
	 *
	 * @covers \Newspack\Primary_Category::is_enabled
	 */
	public function test_is_enabled_respects_option() {
		update_option( Primary_Category::OPTION_NAME, 0 );
		$this->assertFalse( Primary_Category::is_enabled(), 'is_enabled() should return false when option is set to 0.' );
		update_option( Primary_Category::OPTION_NAME, 1 );
		$this->assertTrue( Primary_Category::is_enabled(), 'is_enabled() should return true when option is set to 1.' );
	}

	/**
	 * Test get() returns false when feature is disabled via option.
	 *
	 * @covers \Newspack\Primary_Category::get
	 */
	public function test_get_returns_false_when_disabled() {
		update_option( Primary_Category::OPTION_NAME, 0 );
		$post_id = self::factory()->post->create();
		$this->assertFalse( Primary_Category::get( $post_id ), 'get() should return false when the feature is disabled.' );
	}

	/**
	 * Test get() returns false with null post ID and no global post.
	 *
	 * @covers \Newspack\Primary_Category::get
	 */
	public function test_get_returns_false_with_null_post_id_no_global() {
		delete_option( Primary_Category::OPTION_NAME );
		unset( $GLOBALS['post'] );
		$this->assertFalse( Primary_Category::get(), 'get() should return false when no post ID is provided and no global post is set.' );
	}

	/**
	 * Test get() returns false when no primary category meta is set.
	 *
	 * @covers \Newspack\Primary_Category::get
	 */
	public function test_get_returns_false_without_primary_meta() {
		$post_id = self::factory()->post->create();
		$this->assertFalse( Primary_Category::get( $post_id ), 'get() should return false when no primary category meta exists.' );
	}

	/**
	 * Test get() returns false when the primary category term has been deleted.
	 *
	 * @covers \Newspack\Primary_Category::get
	 */
	public function test_get_returns_false_when_primary_term_deleted() {
		$post_id  = self::factory()->post->create();
		$category = self::factory()->category->create_and_get( [ 'name' => 'Temp Cat' ] );
		update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category->term_id );
		wp_delete_term( $category->term_id, 'category' );

		$this->assertFalse( Primary_Category::get( $post_id ), 'get() should return false when the primary category term has been deleted.' );
	}

	/**
	 * Test get() returns the primary category term.
	 *
	 * @covers \Newspack\Primary_Category::get
	 */
	public function test_get_returns_primary_category() {
		$post_id  = self::factory()->post->create();
		$category = self::factory()->category->create_and_get( [ 'name' => 'Primary Cat' ] );
		wp_set_post_categories( $post_id, [ $category->term_id ] );
		update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category->term_id );

		$result = Primary_Category::get( $post_id );
		$this->assertInstanceOf( WP_Term::class, $result, 'get() should return a WP_Term instance.' );
		$this->assertEquals( $category->term_id, $result->term_id, 'get() should return the correct primary category term.' );
	}

	/**
	 * Test filter passes through post_tag blocks.
	 *
	 * @covers \Newspack\Primary_Category::filter_post_terms_block
	 */
	public function test_filter_passes_through_tag_blocks() {
		$html   = '<div class="taxonomy-post_tag"><a href="/tag/news/" rel="tag">News</a></div>';
		$parsed = [ 'attrs' => [ 'term' => 'post_tag' ] ];
		$result = Primary_Category::filter_post_terms_block( $html, $parsed, null );
		$this->assertEquals( $html, $result, 'Filter should pass through post_tag blocks unchanged.' );
	}

	/**
	 * Test filter passes through when no term attr (defaults to non-category).
	 *
	 * @covers \Newspack\Primary_Category::filter_post_terms_block
	 */
	public function test_filter_passes_through_when_no_term_attr() {
		$html   = '<div class="taxonomy-post_tag"><a href="/tag/news/" rel="tag">News</a></div>';
		$parsed = [ 'attrs' => [] ];
		$result = Primary_Category::filter_post_terms_block( $html, $parsed, null );
		$this->assertEquals( $html, $result, 'Filter should pass through blocks with no term attribute.' );
	}

	/**
	 * Test filter replaces categories with primary category.
	 *
	 * @covers \Newspack\Primary_Category::filter_post_terms_block
	 */
	public function test_filter_replaces_category_block_content() {
		$post_id  = self::factory()->post->create();
		$primary  = self::factory()->category->create_and_get( [ 'name' => 'Primary Cat' ] );
		$other    = self::factory()->category->create_and_get( [ 'name' => 'Other Cat' ] );
		wp_set_post_categories( $post_id, [ $primary->term_id, $other->term_id ] );
		update_post_meta( $post_id, '_yoast_wpseo_primary_category', $primary->term_id );

		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$html   = '<div class="taxonomy-category wp-block-post-terms"><a href="/cat/other-cat/" rel="tag">Other Cat</a>, <a href="/cat/primary-cat/" rel="tag">Primary Cat</a></div>';
		$parsed = [ 'attrs' => [ 'term' => 'category' ] ];

		$block_instance          = new stdClass();
		$block_instance->context = [ 'postId' => $post_id ];

		$result = Primary_Category::filter_post_terms_block( $html, $parsed, $block_instance );

		$this->assertStringContainsString( 'Primary Cat', $result, 'Filtered output should contain the primary category name.' );
		$this->assertStringNotContainsString( 'Other Cat', $result, 'Filtered output should not contain non-primary categories.' );
		$this->assertStringContainsString( '<div', $result, 'Filtered output should preserve the wrapper tag.' );
		$this->assertStringContainsString( 'taxonomy-category', $result, 'Filtered output should preserve the wrapper classes.' );

		wp_reset_postdata();
	}

	/**
	 * Test filter preserves prefix and suffix.
	 *
	 * @covers \Newspack\Primary_Category::filter_post_terms_block
	 */
	public function test_filter_preserves_prefix_and_suffix() {
		$post_id  = self::factory()->post->create();
		$category = self::factory()->category->create_and_get( [ 'name' => 'Tech' ] );
		wp_set_post_categories( $post_id, [ $category->term_id ] );
		update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category->term_id );

		$html   = '<div class="taxonomy-category"><a href="/cat/tech/" rel="tag">Tech</a></div>';
		$parsed = [
			'attrs' => [
				'term'   => 'category',
				'prefix' => 'Filed under: ',
				'suffix' => '.',
			],
		];

		$block_instance          = new stdClass();
		$block_instance->context = [ 'postId' => $post_id ];

		$result = Primary_Category::filter_post_terms_block( $html, $parsed, $block_instance );

		$this->assertStringContainsString( 'wp-block-post-terms__prefix', $result, 'Filtered output should contain the prefix span.' );
		$this->assertStringContainsString( 'Filed under: ', $result, 'Filtered output should contain the prefix text.' );
		$this->assertStringContainsString( 'wp-block-post-terms__suffix', $result, 'Filtered output should contain the suffix span.' );
	}

	/**
	 * Test filter returns original content when no primary category is set.
	 *
	 * @covers \Newspack\Primary_Category::filter_post_terms_block
	 */
	public function test_filter_returns_original_when_no_primary_category() {
		$post_id = self::factory()->post->create();

		$html   = '<div class="taxonomy-category"><a href="/cat/uncategorized/" rel="tag">Uncategorized</a></div>';
		$parsed = [ 'attrs' => [ 'term' => 'category' ] ];

		$block_instance          = new stdClass();
		$block_instance->context = [ 'postId' => $post_id ];

		$result = Primary_Category::filter_post_terms_block( $html, $parsed, $block_instance );
		$this->assertEquals( $html, $result, 'Filter should return original content when no primary category is set.' );
	}

	/**
	 * Test filter handles malformed HTML gracefully.
	 *
	 * @covers \Newspack\Primary_Category::filter_post_terms_block
	 */
	public function test_filter_handles_malformed_html() {
		$post_id  = self::factory()->post->create();
		$category = self::factory()->category->create_and_get( [ 'name' => 'News' ] );
		wp_set_post_categories( $post_id, [ $category->term_id ] );
		update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category->term_id );

		$malformed = 'just plain text, no HTML tags';
		$parsed    = [ 'attrs' => [ 'term' => 'category' ] ];

		$block_instance          = new stdClass();
		$block_instance->context = [ 'postId' => $post_id ];

		$result = Primary_Category::filter_post_terms_block( $malformed, $parsed, $block_instance );
		$this->assertEquals( $malformed, $result, 'Filter should return original content when HTML is malformed.' );
	}
}
