<?php
/**
 * Tests for the Comments Panel block.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\Comments_Panel\Comments_Panel_Block
 */

use Newspack\Blocks\Comments_Panel\Comments_Panel_Block;

/**
 * Test class for the Comments Panel block.
 *
 * @group comments-panel-block
 */
class Newspack_Test_Comments_Panel_Block extends WP_UnitTestCase {

	const BLOCK_NAME = 'newspack/comments-panel';

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once NEWSPACK_ABSPATH . 'src/blocks/comments-panel/class-comments-panel-block.php';

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			Comments_Panel_Block::register_block();
		}
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			unregister_block_type( self::BLOCK_NAME );
		}

		parent::tear_down();
	}

	/**
	 * Render the block for a post, passing the post ID as block context.
	 *
	 * @param int $post_id Post ID.
	 * @return string Rendered HTML.
	 */
	private function render_for_post( int $post_id ): string {
		$parsed_block = [
			'blockName'    => self::BLOCK_NAME,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<p>inner</p>',
			'innerContent' => [ '<p>inner</p>' ],
		];

		return ( new WP_Block( $parsed_block, [ 'postId' => $post_id ] ) )->render();
	}

	/**
	 * Renders when comments are open.
	 */
	public function test_renders_when_comments_open() {
		$post_id = self::factory()->post->create( [ 'comment_status' => 'open' ] );

		$this->assertStringContainsString( 'inner', $this->render_for_post( $post_id ) );
	}

	/**
	 * Renders nothing when comments are closed and the post has no comments.
	 */
	public function test_hidden_when_comments_closed_and_none_exist() {
		$post_id = self::factory()->post->create( [ 'comment_status' => 'closed' ] );

		$this->assertSame( '', $this->render_for_post( $post_id ) );
	}

	/**
	 * Renders when comments are closed but the post already has comments.
	 */
	public function test_renders_when_comments_closed_but_comments_exist() {
		$post_id = self::factory()->post->create( [ 'comment_status' => 'closed' ] );
		self::factory()->comment->create( [ 'comment_post_ID' => $post_id ] );

		$this->assertStringContainsString( 'inner', $this->render_for_post( $post_id ) );
	}
}
