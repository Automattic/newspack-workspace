<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class RevisionRenderSkipTest
 *
 * The Content Loop (homepage-articles) and Carousel blocks each build a full
 * WP_Query when they render. WordPress renders a revision's or autosave's
 * `content.rendered` through `the_content` on the revisions/autosaves REST
 * endpoints, so on busy sites every editor autosave fires those queries for
 * output nobody ever sees. These tests lock in that both blocks skip rendering
 * in that context, and that the skip never leaks past the request that caused it.
 *
 * @package Newspack_Blocks
 */

/**
 * Revision/autosave render-skip test case.
 */
class RevisionRenderSkipTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		unset( $GLOBALS['newspack_blocks_post_id'], $GLOBALS['newspack_blocks_all_specific_posts_ids'], $GLOBALS['newspack_blocks_hpb_all_blocks'] );
		wp_reset_postdata();
		parent::tear_down();
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
	 * Render block markup while a REST request for the given route is in flight,
	 * the way the REST server brackets an endpoint callback. The route is pushed
	 * before the render and popped after, even if the render throws, so the suite
	 * stays order-independent.
	 *
	 * @param string $markup Block markup to render.
	 * @param string $route  The REST route being served during the render.
	 * @return string The block output.
	 */
	private function render_during_rest_route( $markup, $route ) {
		$request = new WP_REST_Request( 'GET', $route );
		Newspack_Blocks::push_rest_route( null, [], $request );
		try {
			return do_blocks( $markup );
		} finally {
			Newspack_Blocks::pop_rest_route( null, [], $request );
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
	 * Route × block cases: for each block, the revisions and autosaves routes
	 * (collection and single) skip the render, while an ordinary post route still
	 * renders it. The marker is the block's front-end wrapper class.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
	 */
	public function rest_route_render_cases() {
		$content_loop = '<!-- wp:newspack-blocks/homepage-articles {"postsToShow":3} /-->';
		$carousel     = '<!-- wp:newspack-blocks/carousel {"slidesPerView":1,"postsToShow":3} /-->';

		return [
			// Case => [ markup, marker, route, expect_rendered ].
			'content loop, single revision'  => [ $content_loop, 'wpnbha', '/wp/v2/posts/123/revisions/456', false ],
			'content loop, autosaves list'   => [ $content_loop, 'wpnbha', '/wp/v2/posts/123/autosaves', false ],
			'content loop, single autosave'  => [ $content_loop, 'wpnbha', '/wp/v2/posts/123/autosaves/456', false ],
			'content loop, ordinary post'    => [ $content_loop, 'wpnbha', '/wp/v2/posts/123', true ],
			'carousel, single revision'      => [ $carousel, 'wpnbpc', '/wp/v2/posts/123/revisions/456', false ],
			'carousel, autosaves list'       => [ $carousel, 'wpnbpc', '/wp/v2/posts/123/autosaves', false ],
			'carousel, single autosave'      => [ $carousel, 'wpnbpc', '/wp/v2/posts/123/autosaves/456', false ],
			'carousel, ordinary post'        => [ $carousel, 'wpnbpc', '/wp/v2/posts/123', true ],
		];
	}

	/**
	 * A block renders during an ordinary REST request but skips its query on the
	 * revisions and autosaves routes, whose output is never displayed.
	 *
	 * @dataProvider rest_route_render_cases
	 *
	 * @param string $markup          Block markup to render.
	 * @param string $marker          The block's front-end wrapper class.
	 * @param string $route           The REST route in flight during the render.
	 * @param bool   $expect_rendered Whether the block should render on that route.
	 */
	public function test_block_render_respects_rest_route( $markup, $marker, $route, $expect_rendered ) {
		$this->create_published_posts();
		$output = $this->render_during_rest_route( $markup, $route );

		if ( $expect_rendered ) {
			self::assertStringContainsString(
				$marker,
				$output,
				"The block must render on route $route."
			);
		} else {
			self::assertStringNotContainsString(
				$marker,
				$output,
				"The block must render nothing on route $route."
			);
		}
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
	 * The captured route must not outlive the request that set it. An in-process
	 * `rest_do_request()` on a revisions route dispatches without ever firing
	 * `rest_post_dispatch`, so a route tracked on that hook would stay set and
	 * blank every later render in the process (and cache the empty page). Bracketing
	 * on the before/after-callback hooks clears it when the request returns.
	 */
	public function test_route_does_not_leak_after_in_process_revisions_request() {
		$this->create_published_posts();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_save_post_revision( $post_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id . '/revisions' );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		self::assertSame( 200, $response->get_status(), 'The in-process revisions request succeeded.' );

		self::assertStringContainsString(
			'wpnbha',
			$this->render_content_loop(),
			'The Content Loop must still render after an in-process revisions REST request returns.'
		);
	}
}
