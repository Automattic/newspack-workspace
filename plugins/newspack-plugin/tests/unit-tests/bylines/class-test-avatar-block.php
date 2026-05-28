<?php
/**
 * Test Avatar Block functionality.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\Avatar\Avatar_Block
 */

namespace Newspack\Tests\Unit\Bylines;

use Newspack\Bylines;
use Newspack\Blocks\Avatar\Avatar_Block;
use Newspack\Author_Rest_Fields;

/**
 * Test class for the Avatar Block.
 *
 * @group byline-block
 */
class Test_Avatar_Block extends \WP_UnitTestCase {

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
	 * Create shared fixtures once for the entire suite.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$author_id = wp_insert_user(
			[
				'user_login'   => 'avatartestauthor',
				'user_email'   => 'avatartestauthor@example.com',
				'display_name' => 'Avatar Test Author',
				'user_pass'    => 'password',
				'role'         => 'author',
			]
		);

		self::$post_id = wp_insert_post(
			[
				'post_author' => self::$author_id,
				'post_status' => 'publish',
				'post_title'  => 'Avatar Test Post',
			]
		);
	}

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once NEWSPACK_ABSPATH . 'src/blocks/avatar/class-avatar-block.php';

		Bylines::register_post_meta();

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack/avatar' ) ) {
			Avatar_Block::register_block();
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE );
		delete_post_meta( self::$post_id, Bylines::META_KEY_BYLINE );

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack/avatar' ) ) {
			unregister_block_type( 'newspack/avatar' );
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
	private function render_avatar_block( $attributes = [], $post_id = null ) {
		if ( null === $post_id ) {
			$post_id = self::$post_id;
		}

		\WP_Block_Supports::$block_to_render = [
			'blockName' => 'newspack/avatar',
			'attrs'     => $attributes,
		];

		$block = new \WP_Block(
			[
				'blockName' => 'newspack/avatar',
				'attrs'     => $attributes,
			],
			[ 'postId' => $post_id ]
		);

		$output = Avatar_Block::render_block( $attributes, '', $block );

		\WP_Block_Supports::$block_to_render = null;

		return $output;
	}

	/**
	 * Test render_block returns empty string for invalid post ID.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_empty_for_invalid_post() {
		$block = new \WP_Block(
			[
				'blockName' => 'newspack/avatar',
				'attrs'     => [],
			]
		);

		$output = Avatar_Block::render_block( [], '', $block );

		$this->assertEmpty( $output, 'Should return empty string for missing post ID.' );
	}

	/**
	 * Test render_block shows default author avatar when no custom byline.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_default_author() {
		$output = $this->render_avatar_block();

		$this->assertStringContainsString( 'Avatar Test Author', $output, 'Should contain author name as alt text.' );
		$this->assertStringContainsString( 'wp-block-newspack-avatar__image', $output, 'Should have avatar image class.' );
		$this->assertStringContainsString( '<img', $output, 'Should contain an img tag.' );
	}

	/**
	 * Test render_block shows custom byline author avatar when byline has author shortcodes.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_custom_byline_with_author() {
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta(
			self::$post_id,
			Bylines::META_KEY_BYLINE,
			'By [Author id=' . self::$author_id . ']Avatar Test Author[/Author]'
		);

		$output = $this->render_avatar_block();

		$this->assertStringContainsString( 'Avatar Test Author', $output, 'Should show byline author avatar.' );
		$this->assertStringContainsString( '<img', $output, 'Should contain an img tag.' );
	}

	/**
	 * Test render_block returns empty when custom byline is active but has NO author shortcodes.
	 *
	 * This is the key bug: when a custom byline like "By Staff Reporter" is used without
	 * [Author] shortcodes, the avatar block should NOT fall back to the default WP author.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_empty_when_custom_byline_has_no_author_shortcodes() {
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( self::$post_id, Bylines::META_KEY_BYLINE, 'By Staff Reporter' );

		$output = $this->render_avatar_block();

		$this->assertEmpty( $output, 'Should return empty when custom byline has no author shortcodes.' );
	}

	/**
	 * Test render_block returns empty when custom byline is active with text-only content.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_empty_when_custom_byline_text_only() {
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( self::$post_id, Bylines::META_KEY_BYLINE, 'By the Editorial Board' );

		$output = $this->render_avatar_block();

		$this->assertEmpty( $output, 'Should return empty for text-only custom byline with no author shortcodes.' );
	}

	/**
	 * Test render_block falls back to default author when byline is inactive.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_fallback_when_byline_inactive() {
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, false );
		update_post_meta( self::$post_id, Bylines::META_KEY_BYLINE, 'By Staff Reporter' );

		$output = $this->render_avatar_block();

		$this->assertStringContainsString( 'Avatar Test Author', $output, 'Should fall back to default author when byline is inactive.' );
	}

	/**
	 * Test render_block with linkToAuthorArchive attribute.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_link_to_author_archive() {
		$output = $this->render_avatar_block( [ 'linkToAuthorArchive' => true ] );

		$this->assertStringContainsString( '<a href=', $output, 'Should contain author link.' );
		$this->assertStringContainsString( 'wp-block-newspack-avatar__link', $output, 'Should have link class.' );
	}

	/**
	 * Test render_block without linkToAuthorArchive.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_no_link_to_author_archive() {
		$output = $this->render_avatar_block( [ 'linkToAuthorArchive' => false ] );

		$this->assertStringNotContainsString( '<a href=', $output, 'Should not contain author link.' );
	}

	/**
	 * Test render_block shows only the shortcode author for a mixed byline
	 * (one [Author] shortcode plus plain text).
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_mixed_byline_one_shortcode_and_text() {
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta(
			self::$post_id,
			Bylines::META_KEY_BYLINE,
			'By [Author id=' . self::$author_id . ']Avatar Test Author[/Author] and the editorial team'
		);

		$output = $this->render_avatar_block();

		$this->assertStringContainsString( 'Avatar Test Author', $output, 'Should show the shortcode author avatar.' );
		// Only one avatar wrapper should be present.
		$this->assertSame( 1, substr_count( $output, 'newspack-avatar-wrapper' ), 'Should render exactly one avatar.' );
	}

	/**
	 * Test render_block shows multiple avatars when byline has multiple author shortcodes.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_multiple_author_shortcodes() {
		$second_author_id = static::factory()->user->create(
			[
				'user_login'   => 'avatartestauthor2',
				'user_email'   => 'avatartestauthor2@example.com',
				'display_name' => 'Second Author',
				'role'         => 'author',
			]
		);

		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta(
			self::$post_id,
			Bylines::META_KEY_BYLINE,
			'[Author id=' . self::$author_id . ']Avatar Test Author[/Author] and [Author id=' . $second_author_id . ']Second Author[/Author]'
		);

		$output = $this->render_avatar_block();

		$this->assertStringContainsString( 'Avatar Test Author', $output, 'Should show first author.' );
		$this->assertStringContainsString( 'Second Author', $output, 'Should show second author.' );
		$this->assertSame( 2, substr_count( $output, 'newspack-avatar-wrapper' ), 'Should render two avatars.' );
	}

	/**
	 * Test render_block returns empty when byline references a deleted user.
	 *
	 * @covers \Newspack\Blocks\Avatar\Avatar_Block::render_block
	 */
	public function test_render_block_empty_when_byline_author_deleted() {
		update_post_meta( self::$post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta(
			self::$post_id,
			Bylines::META_KEY_BYLINE,
			'[Author id=999999]Deleted User[/Author]'
		);

		$output = $this->render_avatar_block();

		// get_user_by returns false for non-existent IDs, so array_filter removes it.
		$this->assertEmpty( $output, 'Should return empty when byline author no longer exists.' );
	}

	/**
	 * Test get_author_info returns enriched data for a WP author (no CAP).
	 *
	 * @covers \Newspack\Author_Rest_Fields::get_author_info
	 */
	public function test_get_author_info_default_wp_author() {
		$post   = [ 'id' => self::$post_id ];
		$result = Author_Rest_Fields::get_author_info( $post );

		$this->assertIsArray( $result, 'Should return an array.' );
		$this->assertCount( 1, $result, 'Should return exactly one author.' );

		$author = $result[0];
		$this->assertSame( self::$author_id, $author['id'], 'Author ID should match.' );
		$this->assertSame( 'Avatar Test Author', $author['display_name'], 'Display name should match.' );
		$this->assertArrayHasKey( 'author_link', $author, 'Should have author_link.' );
		$this->assertArrayHasKey( 'user_nicename', $author, 'Should have user_nicename.' );
		$this->assertFalse( $author['is_guest'], 'WP users should not be guests.' );
		$this->assertArrayHasKey( 'avatar_urls', $author, 'Should have avatar_urls.' );
		$this->assertIsArray( $author['avatar_urls'], 'avatar_urls should be an array.' );
	}

	/**
	 * Test get_author_info returns empty array for non-existent author.
	 *
	 * @covers \Newspack\Author_Rest_Fields::get_author_info
	 */
	public function test_get_author_info_non_existent_author() {
		$post_id = wp_insert_post(
			[
				'post_author' => 999999,
				'post_status' => 'publish',
				'post_title'  => 'Orphaned Post',
			]
		);

		$result = Author_Rest_Fields::get_author_info( [ 'id' => $post_id ] );

		$this->assertSame( [], $result, 'Should return empty array for non-existent author.' );

		wp_delete_post( $post_id, true );
	}
}
