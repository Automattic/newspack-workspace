<?php
/**
 * Test Byline Block functionality.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\Byline\Byline_Block
 */

namespace Newspack\Tests\Unit\Bylines;

use Newspack\Bylines;
use Newspack\Blocks\Byline\Byline_Block;

/**
 * Test class for the Byline Block.
 *
 * @todo Add tests for CoAuthors Plus integration (requires CAP mocks).
 *
 * @group byline-block
 */
class Test_Byline_Block extends \WP_UnitTestCase {

	/**
	 * Test post ID.
	 *
	 * @var int
	 */
	protected static $post_id;

	/**
	 * Test author ID.
	 *
	 * @var int
	 */
	protected static $author_id;

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once NEWSPACK_ABSPATH . 'src/blocks/byline/class-byline-block.php';

		self::$author_id = static::factory()->user->create(
			[
				'user_login'   => 'testauthor',
				'user_email'   => 'testauthor@example.com',
				'display_name' => 'Test Author',
				'role'         => 'author',
			]
		);

		self::$post_id = static::factory()->post->create(
			[
				'post_author' => self::$author_id,
				'post_status' => 'publish',
			]
		);

		Bylines::register_post_meta();

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack/byline' ) ) {
			Byline_Block::register_block();
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE );
		delete_post_meta( self::$post_id, Bylines::META_KEY_BYLINE );

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack/byline' ) ) {
			unregister_block_type( 'newspack/byline' );
		}

		parent::tear_down();
	}

	/**
	 * Helper method to render block with proper WordPress context.
	 *
	 * @param array $attributes Block attributes.
	 * @param int   $post_id    Post ID for context.
	 * @return string Rendered HTML.
	 */
	private function render_byline_block( $attributes = [], $post_id = null ) {
		if ( null === $post_id ) {
			$post_id = self::$post_id;
		}

		\WP_Block_Supports::$block_to_render = [
			'blockName' => 'newspack/byline',
			'attrs'     => $attributes,
		];

		$block          = new \stdClass();
		$block->context = [ 'postId' => $post_id ];

		$output = Byline_Block::render_block( $attributes, '', $block );

		\WP_Block_Supports::$block_to_render = null;

		return $output;
	}

	/**
	 * Test render_block returns empty string for invalid post ID.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 */
	public function test_render_block_empty_for_invalid_post() {
		$output = $this->render_byline_block( [], 0 );

		$this->assertEmpty( $output, 'Should return empty string for invalid post ID.' );
	}

	/**
	 * Test render_block returns default author when no custom byline or CAP.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_default_author
	 * @covers \Newspack\Blocks\Byline\Byline_Block::get_translated_prefix
	 */
	public function test_render_block_default_author() {
		$attributes = [
			'prefix'              => 'By',
			'linkToAuthorArchive' => true,
		];

		$output = $this->render_byline_block( $attributes );

		$this->assertStringContainsString( 'Test Author', $output, 'Should contain author name.' );
		$this->assertStringContainsString( 'wp-block-newspack-byline', $output, 'Should have byline block class.' );
		$this->assertStringContainsString( 'class="byline"', $output, 'Should have byline class.' );
		$this->assertStringContainsString( 'By ', $output, 'Should contain prefix.' );
		$this->assertStringContainsString( '<a', $output, 'Should contain link when linkToAuthorArchive is true.' );
	}

	/**
	 * Test render_block respects linkToAuthorArchive = false.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_default_author
	 */
	public function test_render_block_default_author_no_link() {
		$attributes = [
			'prefix'              => 'By',
			'linkToAuthorArchive' => false,
		];

		$output = $this->render_byline_block( $attributes );

		$this->assertStringContainsString( 'Test Author', $output, 'Should contain author name.' );
		$this->assertStringNotContainsString( '<a', $output, 'Should not contain link when linkToAuthorArchive is false.' );
		$this->assertStringContainsString( '<span class="fn n">', $output, 'Should have span instead of link.' );
	}

	/**
	 * Test render_block uses custom byline when active.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_custom_byline
	 */
	public function test_render_block_custom_byline() {
		// Activate custom byline with non-existent author ID.
		// When author ID doesn't exist, the shortcode text is used as fallback.
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( self::$post_id, Bylines::META_KEY_BYLINE, 'By [Author id=999]Jane Doe[/Author]' );

		$attributes = [
			'prefix'              => 'Published by ',
			'linkToAuthorArchive' => true,
		];

		$output = $this->render_byline_block( $attributes );

		// Custom bylines include their own prefix, so block prefix should be ignored.
		$this->assertStringContainsString( 'Jane Doe', $output, 'Should contain custom byline author name.' );
		$this->assertStringContainsString( 'wp-block-newspack-byline', $output, 'Should have byline block class.' );
		// The prefix from attributes should NOT appear because custom bylines have their own.
		$this->assertStringNotContainsString( 'Published by ', $output, 'Should not contain block prefix for custom byline.' );
	}

	/**
	 * Test render_block priority: custom byline takes precedence over default author.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_custom_byline
	 */
	public function test_render_block_custom_byline_priority() {
		// Activate custom byline with different name than default author.
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( self::$post_id, Bylines::META_KEY_BYLINE, 'By [Author id=999]Custom Author[/Author]' );

		$output = $this->render_byline_block( [] );

		// Should show custom byline, not the default author "Test Author".
		$this->assertStringContainsString( 'Custom Author', $output, 'Should show custom byline author.' );
		$this->assertStringNotContainsString( 'Test Author', $output, 'Should not show default author when custom byline is active.' );
	}

	/**
	 * Test render_block falls back to default when custom byline is inactive.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_default_author
	 */
	public function test_render_block_fallback_when_custom_byline_inactive() {
		// Set byline content but don't activate it.
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, false );
		update_post_meta( self::$post_id, Bylines::META_KEY_BYLINE, 'By [Author id=999]Custom Author[/Author]' );

		$output = $this->render_byline_block( [] );

		// Should show default author since custom byline is not active.
		$this->assertStringContainsString( 'Test Author', $output, 'Should fall back to default author when custom byline is inactive.' );
	}

	/**
	 * Test render_block falls back to default when custom byline is empty.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_default_author
	 */
	public function test_render_block_fallback_when_custom_byline_empty() {
		// Activate custom byline but leave content empty.
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( self::$post_id, Bylines::META_KEY_BYLINE, '' );

		$output = $this->render_byline_block( [] );

		// Should show default author since custom byline is empty.
		$this->assertStringContainsString( 'Test Author', $output, 'Should fall back to default author when custom byline is empty.' );
	}

	/**
	 * Test render_block with custom prefix.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_default_author
	 * @covers \Newspack\Blocks\Byline\Byline_Block::get_translated_prefix
	 */
	public function test_render_block_custom_prefix() {
		$attributes = [
			'prefix'              => 'Written by ',
			'linkToAuthorArchive' => true,
		];

		$output = $this->render_byline_block( $attributes );

		$this->assertStringContainsString( 'Written by ', $output, 'Should contain custom prefix.' );
	}

	/**
	 * Test render_block uses postId from context.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_default_author
	 */
	public function test_render_block_uses_context_post_id() {
		// Create another post with a different author.
		$another_author_id = static::factory()->user->create(
			[
				'display_name' => 'Another Author',
			]
		);
		$another_post_id   = static::factory()->post->create(
			[
				'post_author' => $another_author_id,
			]
		);

		$output = $this->render_byline_block( [], $another_post_id );

		$this->assertStringContainsString( 'Another Author', $output, 'Should use author from context postId.' );
		$this->assertStringNotContainsString( 'Test Author', $output, 'Should not show author from different post.' );

		// Clean up.
		wp_delete_post( $another_post_id, true );
		wp_delete_user( $another_author_id );
	}

	/**
	 * Test render_block returns empty for post with no author.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_default_author
	 */
	public function test_render_block_no_author() {
		// Create a post with author 0.
		$no_author_post_id = static::factory()->post->create(
			[
				'post_author' => 0,
			]
		);

		$output = $this->render_byline_block( [], $no_author_post_id );

		$this->assertEmpty( $output, 'Should return empty string for post with no author.' );

		// Clean up.
		wp_delete_post( $no_author_post_id, true );
	}

	/**
	 * Test default attributes are applied.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_block
	 * @covers \Newspack\Blocks\Byline\Byline_Block::render_default_author
	 * @covers \Newspack\Blocks\Byline\Byline_Block::get_translated_prefix
	 */
	public function test_render_block_default_attributes() {
		// Pass empty attributes to use defaults.
		$output = $this->render_byline_block( [] );

		// Default prefix is "By" (space is added during assembly).
		$this->assertStringContainsString( 'By ', $output, 'Should use default prefix with space.' );
		// Default linkToAuthorArchive is true.
		$this->assertStringContainsString( '<a', $output, 'Should link to archive by default.' );
	}

	/**
	 * Test format_author_list with single author.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::format_author_list
	 */
	public function test_format_author_list_single_author() {
		$author_links = [ '<span class="author">John Doe</span>' ];

		$result = ( new \ReflectionMethod( Byline_Block::class, 'format_author_list' ) )->invoke( null, $author_links );

		$this->assertEquals( '<span class="author">John Doe</span>', $result, 'Single author should be returned as-is.' );
	}

	/**
	 * Test format_author_list with multiple authors.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::format_author_list
	 */
	public function test_format_author_list_multiple_authors() {
		$method = new \ReflectionMethod( Byline_Block::class, 'format_author_list' );

		// Two authors.
		$two_authors = [
			'<span class="author">John Doe</span>',
			'<span class="author">Jane Smith</span>',
		];
		$result      = $method->invoke( null, $two_authors );
		$this->assertStringContainsString( 'John Doe', $result );
		$this->assertStringContainsString( 'Jane Smith', $result );
		$this->assertStringContainsString( ' and ', $result );

		// Three authors.
		$three_authors = [
			'<span class="author">John Doe</span>',
			'<span class="author">Jane Smith</span>',
			'<span class="author">Bob Wilson</span>',
		];
		$result        = $method->invoke( null, $three_authors );
		$this->assertStringContainsString( 'John Doe', $result );
		$this->assertStringContainsString( 'Jane Smith', $result );
		$this->assertStringContainsString( 'Bob Wilson', $result );
	}

	/**
	 * Test format_author_list with empty array.
	 *
	 * @covers \Newspack\Blocks\Byline\Byline_Block::format_author_list
	 */
	public function test_format_author_list_empty() {
		$result = ( new \ReflectionMethod( Byline_Block::class, 'format_author_list' ) )->invoke( null, [] );

		$this->assertEmpty( $result, 'Empty array should return empty string.' );
	}
}
