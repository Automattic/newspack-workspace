<?php
/**
 * Class Test_Fullscreen_Iframe
 *
 * @package Newspack_Blocks
 */

/**
 * A post whose content is taken over by a fullscreen Iframe block.
 */
class Test_Fullscreen_Iframe extends WP_UnitTestCase {
	/**
	 * Post with a paragraph followed by a fullscreen Iframe block.
	 *
	 * @var int
	 */
	private $fullscreen_post;

	/**
	 * Ordinary post.
	 *
	 * @var int
	 */
	private $ordinary_post;

	/**
	 * Create both posts.
	 */
	public function set_up() {
		parent::set_up();
		$this->fullscreen_post = self::factory()->post->create(
			[
				'post_content' => '<!-- wp:paragraph --><p>Intro text for the listing.</p><!-- /wp:paragraph -->'
					. '<!-- wp:newspack-blocks/iframe {"src":"https://example.test/embed","isFullScreen":true} /-->',
				'post_excerpt' => '',
			]
		);
		$this->ordinary_post   = self::factory()->post->create( [ 'post_content' => '<!-- wp:paragraph --><p>Ordinary body.</p><!-- /wp:paragraph -->' ] );
	}

	/**
	 * Whether Campaigns prompts are suppressed for the rest of this request.
	 */
	private function prompts_suppressed() {
		return (bool) apply_filters( 'newspack_popups_assess_has_disabled_popups', false );
	}

	/**
	 * Render a post's content the way a listing or a secondary loop does.
	 *
	 * @param int $post_id Post ID.
	 * @return string Filtered content.
	 */
	private function render_content( $post_id ) {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		wp_reset_postdata();
		return $content;
	}

	/**
	 * Generating the excerpt keeps the post's text and leaves prompts alone.
	 */
	public function test_excerpt_keeps_the_post_text_and_leaves_prompts_alone() {
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $this->fullscreen_post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$excerpt = get_the_excerpt( $this->fullscreen_post );
		wp_reset_postdata();

		$this->assertStringContainsString( 'Intro text for the listing', $excerpt );
		$this->assertFalse( $this->prompts_suppressed() );
	}

	/**
	 * The fullscreen post's own page hides the page behind the iframe and suppresses prompts.
	 */
	public function test_fullscreen_post_page_takes_over_the_page() {
		$this->go_to( get_permalink( $this->fullscreen_post ) );

		$this->assertContains( 'newspack-post-with-fullscreen-iframe', get_body_class() );
		$this->assertTrue( $this->prompts_suppressed() );
	}

	/**
	 * Restriction does not change the takeover: a metered reader still gets the iframe, and
	 * the stylesheet leaves the page visible when a gate replaces it.
	 */
	public function test_fullscreen_post_page_takes_over_whatever_the_restriction() {
		add_filter( 'newspack_is_post_restricted', '__return_true' );
		$this->go_to( get_permalink( $this->fullscreen_post ) );

		$this->assertContains( 'newspack-post-with-fullscreen-iframe', get_body_class() );
		$this->assertTrue( $this->prompts_suppressed() );
	}

	/**
	 * Rendering a fullscreen post inside another post's page leaves that page alone.
	 */
	public function test_other_post_page_is_unaffected_by_a_rendered_fullscreen_post() {
		$this->go_to( get_permalink( $this->ordinary_post ) );
		$this->render_content( $this->fullscreen_post );

		$this->assertNotContains( 'newspack-post-with-fullscreen-iframe', get_body_class() );
		$this->assertFalse( $this->prompts_suppressed() );
	}

	/**
	 * Outside a single-post page the fullscreen post's content is still the iframe alone.
	 */
	public function test_content_is_still_replaced_outside_the_post_page() {
		$this->go_to( home_url( '/' ) );
		$content = $this->render_content( $this->fullscreen_post );

		$this->assertStringNotContainsString( 'Intro text for the listing', $content );
	}
}
