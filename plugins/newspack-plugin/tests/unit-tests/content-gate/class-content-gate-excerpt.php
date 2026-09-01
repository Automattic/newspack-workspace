<?php
/**
 * Tests for gated content in auto-generated excerpts.
 *
 * @package Newspack
 */

use Newspack\Block_Visibility;
use Newspack\Content_Gate_Excerpt;

/**
 * Excerpt gating tests.
 */
class Newspack_Test_Content_Gate_Excerpt extends WP_UnitTestCase {

	/**
	 * Build a post with one gated group and an ungated paragraph.
	 *
	 * @param string $attrs JSON attributes for the group block.
	 * @return int
	 */
	private function make_post( $attrs ) {
		$content = '<!-- wp:paragraph --><p>PUBLICMARK</p><!-- /wp:paragraph -->'
			. '<!-- wp:group ' . $attrs . ' --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>SECRETMARK</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
		return $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $content,
				'post_excerpt' => '',
			]
		);
	}

	/**
	 * The excerpt withholds exactly what the front end withholds from a logged-out
	 * reader, across every gate configuration. Asserting equivalence rather than
	 * absence is what catches over-stripping.
	 */
	public function test_excerpt_visibility_matches_front_end() {
		$published_gate = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$draft_gate     = $this->factory->post->create( [ 'post_status' => 'draft' ] );

		$cases = [
			'registration, visible' => '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}',
			'registration, hidden'  => '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"hidden"}',
			'gate published'        => '{"newspackAccessControlMode":"gate","newspackAccessControlGateIds":[' . $published_gate . '],"newspackAccessControlVisibility":"visible"}',
			'gate draft'            => '{"newspackAccessControlMode":"gate","newspackAccessControlGateIds":[' . $draft_gate . '],"newspackAccessControlVisibility":"visible"}',
			'gate deleted'          => '{"newspackAccessControlMode":"gate","newspackAccessControlGateIds":[99999999],"newspackAccessControlVisibility":"visible"}',
			'no active rules'       => '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{},"newspackAccessControlVisibility":"visible"}',
		];

		foreach ( $cases as $label => $attrs ) {
			$post_id         = $this->make_post( $attrs );
			$GLOBALS['post'] = get_post( $post_id );
			setup_postdata( $GLOBALS['post'] );
			wp_set_current_user( 0 );
			Block_Visibility::reset_cache_for_tests();

			$front_shows = false !== strpos( apply_filters( 'the_content', get_post( $post_id )->post_content ), 'SECRETMARK' );

			Block_Visibility::reset_cache_for_tests();
			$excerpt_shows = false !== strpos( get_the_excerpt( $post_id ), 'SECRETMARK' );

			$this->assertSame(
				$front_shows,
				$excerpt_shows,
				sprintf( 'Excerpt and front end must agree for: %s', $label )
			);

			unset( $GLOBALS['post'] );
		}
	}

	/**
	 * A post whose readable content is entirely gated gets a blank excerpt — the
	 * article page shows a non-member no more than that already.
	 */
	public function test_fully_gated_post_has_a_blank_excerpt() {
		$gate    = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$post_id = $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:group ' . $gate . ' --><div class="wp-block-group"><!-- wp:paragraph --><p>SECRETMARK</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			]
		);
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringNotContainsString( 'SECRETMARK', $excerpt );
		$this->assertSame( '', trim( wp_strip_all_tags( $excerpt ) ), 'No teaser when every readable block is gated.' );

		unset( $GLOBALS['post'] );
	}

	/**
	 * The REST `excerpt.rendered` field carries no gated text for a logged-out read.
	 *
	 * Unlike the render path, this needs no REST_REQUEST constant: the excerpt is
	 * built through get_the_excerpt(), which rest_do_request() does reach.
	 */
	public function test_rest_excerpt_rendered_omits_gated_text() {
		$gate    = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$post_id = $this->make_post( $gate );

		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$data     = $response->get_data();

		$this->assertStringNotContainsString( 'SECRETMARK', $data['excerpt']['rendered'] );
		$this->assertStringContainsString( 'PUBLICMARK', $data['excerpt']['rendered'] );
	}

	/**
	 * A value another filter already placed in $text must not bypass sanitization.
	 *
	 * Core's wp_trim_excerpt() returns $text unchanged when it is non-empty, so an
	 * excerpt produced earlier in the chain would be handed straight back if this
	 * filter forwarded it. Detection of a manual excerpt reads the post, not $text.
	 */
	public function test_prepopulated_text_does_not_bypass_sanitization() {
		$gate    = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$post_id = $this->make_post( $gate );

		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		// Stand in for any filter that runs before ours and produces an excerpt from
		// unsanitized content.
		$contaminate = function () {
			return 'PUBLICMARK and SECRETMARK from an earlier filter';
		};
		add_filter( 'get_the_excerpt', $contaminate, 9 );
		$excerpt = get_the_excerpt( $post_id );
		remove_filter( 'get_the_excerpt', $contaminate, 9 );

		$this->assertStringNotContainsString( 'SECRETMARK', $excerpt, 'An excerpt produced earlier in the chain is still rebuilt from sanitized content.' );
		$this->assertStringContainsString( 'PUBLICMARK', $excerpt, 'Ungated content still reaches the excerpt.' );

		unset( $GLOBALS['post'] );
	}

	/**
	 * A blanked $text on a post that has a manual excerpt must not leak.
	 *
	 * Core rebuilds from the post whenever $text is empty. If the manual-excerpt
	 * branch hands core the original post rather than the sanitized clone, that
	 * rebuild reaches post_content and puts gated blocks back in the excerpt.
	 */
	public function test_blanked_text_on_a_post_with_a_manual_excerpt_does_not_leak() {
		$gate    = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$post_id = $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_excerpt' => 'A hand-written excerpt.',
				'post_content' => '<!-- wp:paragraph --><p>PUBLICMARK</p><!-- /wp:paragraph -->'
					. '<!-- wp:group ' . $gate . ' --><div class="wp-block-group">'
					. '<!-- wp:paragraph --><p>SECRETMARK</p><!-- /wp:paragraph -->'
					. '</div><!-- /wp:group -->',
			]
		);
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$blank = function () {
			return '';
		};
		add_filter( 'get_the_excerpt', $blank, 9 );
		$excerpt = get_the_excerpt( $post_id );
		remove_filter( 'get_the_excerpt', $blank, 9 );

		$this->assertStringNotContainsString( 'SECRETMARK', $excerpt, 'A rebuild triggered by a blanked $text still uses sanitized content.' );

		unset( $GLOBALS['post'] );
	}

	/**
	 * A manually written excerpt is returned untouched.
	 */
	public function test_manual_excerpt_is_untouched() {
		$post_id = $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>PUBLICMARK</p><!-- /wp:paragraph -->',
				'post_excerpt' => 'Hand written.',
			]
		);
		$this->assertStringContainsString( 'Hand written.', get_the_excerpt( $post_id ) );
	}

	/**
	 * A post with no access-control attributes keeps core's contract: a non-empty
	 * $text from an earlier filter is returned untouched. This filter replaces
	 * core's on every site, including those with gates switched off, so a post
	 * that cannot have gated blocks must not notice the replacement.
	 */
	public function test_ungated_post_returns_upstream_text_untouched() {
		$post_id = $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>PUBLICMARK</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
			]
		);

		$supplied = function () {
			return 'THIRDPARTYEXCERPT';
		};
		add_filter( 'get_the_excerpt', $supplied, 6 );
		$excerpt = get_the_excerpt( $post_id );
		remove_filter( 'get_the_excerpt', $supplied, 6 );

		$this->assertSame( 'THIRDPARTYEXCERPT', $excerpt, 'An ungated post must not have an upstream excerpt discarded.' );
	}

	/**
	 * The sanitized clone survives wp_trim_excerpt()'s internal get_post().
	 *
	 * That call ends in WP_Post::filter( 'raw' ), which re-reads the row by ID for
	 * any post not already in raw form -- discarding the clone and its stripped
	 * content. Passing a display-form post is enough to reach it.
	 */
	public function test_display_form_post_still_has_gated_blocks_withheld() {
		$attrs   = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$post_id = $this->make_post( $attrs );

		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$display = get_post( $post_id, OBJECT, 'display' );
		$excerpt = apply_filters( 'get_the_excerpt', $display->post_excerpt, $display );

		$this->assertStringNotContainsString( 'SECRETMARK', $excerpt, 'A display-form post must not bypass sanitization.' );
	}

	/**
	 * An unresolvable post yields nothing rather than the loop's current post.
	 *
	 * Core would fall back to $GLOBALS['post'] via get_the_content( '', false, null ),
	 * so a gated post set up in the loop would supply the excerpt for a post this
	 * filter was never asked about.
	 */
	public function test_unresolvable_post_does_not_borrow_the_global_post() {
		$attrs   = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$post_id = $this->make_post( $attrs );

		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		// Call the filter method directly rather than through apply_filters(): other
		// callbacks on this hook assume core's contract of a WP_Post second argument
		// and fatal on a bare ID, which would mask the branch under test.
		$excerpt = Content_Gate_Excerpt::filter_get_the_excerpt( '', 99999999 );

		$this->assertStringNotContainsString( 'SECRETMARK', $excerpt, 'An unresolvable post must not borrow the global post.' );
		$this->assertStringNotContainsString( 'PUBLICMARK', $excerpt, 'An unresolvable post must not build an excerpt at all.' );

		unset( $GLOBALS['post'] );
	}

	/**
	 * A manual excerpt plus a non-empty upstream $text does not leak gated blocks.
	 *
	 * Core returns a non-empty $text verbatim, so a filter deriving $text from
	 * post_content would reach the teaser on a post that also has a hand-written
	 * excerpt -- the quadrant the auto-branch test does not cover.
	 */
	public function test_manual_excerpt_with_upstream_text_does_not_leak() {
		$attrs   = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$post_id = $this->make_post( $attrs );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_excerpt' => 'Hand written.',
			]
		);

		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$from_content = function () use ( $post_id ) {
			return get_post( $post_id )->post_content;
		};
		add_filter( 'get_the_excerpt', $from_content, 6 );
		$excerpt = get_the_excerpt( $post_id );
		remove_filter( 'get_the_excerpt', $from_content, 6 );

		$this->assertStringNotContainsString( 'SECRETMARK', $excerpt, 'A manual excerpt must not carry gated blocks from upstream $text.' );
	}
}
