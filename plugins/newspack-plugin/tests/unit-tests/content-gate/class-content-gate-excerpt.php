<?php
/**
 * Tests for gated content in auto-generated excerpts.
 *
 * @package Newspack
 */

use Newspack\Block_Visibility;

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
}
