<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class RevisionRenderSkipTest
 *
 * The Content Loop (homepage-articles) and Carousel blocks each build a full
 * WP_Query when they render. WordPress renders a revision's or autosave's
 * `content.rendered` through `the_content` on the revisions/autosaves REST
 * endpoints, so on busy sites every editor autosave fires those queries for
 * output nobody ever sees. These tests lock in that both blocks skip rendering
 * in that context.
 *
 * @package Newspack_Blocks
 */

/**
 * Revision/autosave render-skip test case.
 */
class RevisionRenderSkipTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		// Clear any captured REST route so the suite stays order-independent. Core
		// listeners on rest_post_dispatch type-hint WP_REST_Response, so pass one.
		apply_filters( 'rest_post_dispatch', new WP_REST_Response(), rest_get_server(), new WP_REST_Request() );
		unset( $GLOBALS['newspack_blocks_post_id'], $GLOBALS['newspack_blocks_all_specific_posts_ids'], $GLOBALS['newspack_blocks_hpb_all_blocks'] );
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * Make the current REST request look like the given route, the way the REST
	 * server does when it dispatches one.
	 *
	 * @param string $route The REST route being served.
	 */
	private function serve_rest_route( $route ) {
		$request = new WP_REST_Request( 'GET', $route );
		apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $request );
	}

	/**
	 * Create published posts for a block to render.
	 *
	 * @param int $count How many posts to create.
	 */
	private function create_published_posts( $count = 3 ) {
		for ( $i = 0; $i < $count; $i++ ) {
			self::factory()->post->create( [ 'post_status' => 'publish' ] );
		}
	}

	/**
	 * The Content Loop block, rendered as it would be on the page.
	 *
	 * @return string The block output.
	 */
	private function render_content_loop() {
		return do_blocks( '<!-- wp:newspack-blocks/homepage-articles {"postsToShow":3} /-->' );
	}

	/**
	 * The Carousel block, rendered as it would be on the page.
	 *
	 * @return string The block output.
	 */
	private function render_carousel() {
		return do_blocks( '<!-- wp:newspack-blocks/carousel {"slidesPerView":1,"postsToShow":3} /-->' );
	}

	/**
	 * The Content Loop must not run its query while a revision is being prepared
	 * for the REST API.
	 */
	public function test_content_loop_skips_render_during_revisions_rest_request() {
		$this->create_published_posts();
		$this->serve_rest_route( '/wp/v2/posts/123/revisions/456' );

		self::assertStringNotContainsString(
			'wpnbha',
			$this->render_content_loop(),
			'The Content Loop must render nothing inside a revisions REST request.'
		);
	}

	/**
	 * The Content Loop must not run its query while an autosave is being prepared
	 * for the REST API.
	 */
	public function test_content_loop_skips_render_during_autosaves_rest_request() {
		$this->create_published_posts();
		$this->serve_rest_route( '/wp/v2/posts/123/autosaves' );

		self::assertStringNotContainsString(
			'wpnbha',
			$this->render_content_loop(),
			'The Content Loop must render nothing inside an autosaves REST request.'
		);
	}

	/**
	 * The skip is scoped to revisions/autosaves: a normal REST request (an editor
	 * fetch of the post itself, say) still renders the block.
	 */
	public function test_content_loop_renders_during_a_non_revision_rest_request() {
		$this->create_published_posts();
		$this->serve_rest_route( '/wp/v2/posts/123' );

		self::assertStringContainsString(
			'wpnbha',
			$this->render_content_loop(),
			'The Content Loop must still render on a non-revision REST request.'
		);
	}

	/**
	 * A front-end render, with no REST request in flight at all, is untouched.
	 */
	public function test_content_loop_renders_on_the_front_end() {
		$this->create_published_posts();

		self::assertStringContainsString(
			'wpnbha',
			$this->render_content_loop(),
			'The Content Loop must render normally on the front end.'
		);
	}

	/**
	 * End-to-end: the block output is absent from a real revisions REST response.
	 */
	public function test_content_loop_absent_from_revisions_rest_response() {
		$this->create_published_posts();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$block   = '<!-- wp:newspack-blocks/homepage-articles {"postsToShow":3} /-->';
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $block,
			]
		);
		wp_save_post_revision( $post_id );
		$revisions = wp_get_post_revisions( $post_id, [ 'posts_per_page' => 1 ] );
		$revision  = array_shift( $revisions );
		self::assertNotEmpty( $revision, 'A revision was created to render.' );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id . '/revisions/' . $revision->ID );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status(), 'The revision is retrievable.' );
		$data = $response->get_data();
		self::assertStringNotContainsString(
			'wpnbha',
			$data['content']['rendered'],
			'The Content Loop must not render inside a revisions REST response.'
		);
	}

	/**
	 * The Carousel must not run its query while a revision is being prepared for
	 * the REST API.
	 */
	public function test_carousel_skips_render_during_revisions_rest_request() {
		$this->create_published_posts();
		$this->serve_rest_route( '/wp/v2/posts/123/revisions/456' );

		self::assertStringNotContainsString(
			'wpnbpc',
			$this->render_carousel(),
			'The Carousel must render nothing inside a revisions REST request.'
		);
	}

	/**
	 * The Carousel must not run its query while an autosave is being prepared for
	 * the REST API.
	 */
	public function test_carousel_skips_render_during_autosaves_rest_request() {
		$this->create_published_posts();
		$this->serve_rest_route( '/wp/v2/posts/123/autosaves' );

		self::assertStringNotContainsString(
			'wpnbpc',
			$this->render_carousel(),
			'The Carousel must render nothing inside an autosaves REST request.'
		);
	}

	/**
	 * The Carousel still renders on a normal REST request.
	 */
	public function test_carousel_renders_during_a_non_revision_rest_request() {
		$this->create_published_posts();
		$this->serve_rest_route( '/wp/v2/posts/123' );

		self::assertStringContainsString(
			'wpnbpc',
			$this->render_carousel(),
			'The Carousel must still render on a non-revision REST request.'
		);
	}
}
