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
	 * A post whose readable content is entirely gated gets a blank excerpt when no
	 * whole-post gate is configured — nobody authorized a preview of that text.
	 */
	public function test_fully_gated_post_without_a_whole_post_gate_has_a_blank_excerpt() {
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
		$this->assertSame( '', trim( wp_strip_all_tags( $excerpt ) ), 'No teaser without a configured whole-post gate.' );

		unset( $GLOBALS['post'] );
	}

	/**
	 * The same post falls back to the gate's own teaser when the publisher has
	 * configured a whole-post gate, which is a preview they authorized.
	 */
	public function test_fully_gated_post_with_a_whole_post_gate_falls_back_to_the_teaser() {
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

		// newspack_is_post_restricted is the shared decision filter the content gate
		// itself reads, so forcing it avoids wiring a full gate layout in a unit test.
		add_filter( 'newspack_is_post_restricted', '__return_true' );
		$excerpt = get_the_excerpt( $post_id );
		remove_filter( 'newspack_is_post_restricted', '__return_true' );

		$this->assertNotSame( '', trim( wp_strip_all_tags( $excerpt ) ), 'A configured whole-post gate yields its teaser.' );

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
