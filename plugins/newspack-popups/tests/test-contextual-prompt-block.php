<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Contextual Prompt Block Test
 *
 * The block renders as body content, so it can't use the prompt-keyed GA event.
 * These tests cover the analytics hooks it stamps at render — the post id, the
 * CTA type, and the placement bucket that answers the grant's "which placement
 * converts best" question.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt block test case.
 */
class ContextualPromptBlockTest extends WP_UnitTestCase {

	/**
	 * A single top-level contextual-prompt block, wrapped in filler paragraphs.
	 *
	 * @param int $before Filler paragraphs before the prompt.
	 * @param int $after  Filler paragraphs after the prompt.
	 * @return string Post content.
	 */
	private function content_with_prompt( $before, $after ) {
		$para   = "<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n";
		$prompt = "<!-- wp:newspack-popups/contextual-prompt -->\n<div class=\"wp-block-newspack-popups-contextual-prompt\"><!-- wp:paragraph -->\n<p>Ask.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:newspack-popups/contextual-prompt -->\n";
		return str_repeat( $para, $before ) . $prompt . str_repeat( $para, $after );
	}

	/**
	 * Placement is bucketed from the block's actual position among top-level blocks.
	 */
	public function test_placement_buckets_by_position() {
		$cases = [
			'top' => [ 0, 5 ],
			'mid' => [ 3, 3 ],
			'end' => [ 5, 0 ],
		];
		foreach ( $cases as $expected => $split ) {
			$post_id = self::factory()->post->create(
				[
					'post_type'    => 'post',
					'post_content' => $this->content_with_prompt( $split[0], $split[1] ),
				]
			);
			$this->assertSame(
				$expected,
				Newspack_Popups_Contextual_Prompt_Block::get_placement( $post_id ),
				"A prompt with {$split[0]} blocks before and {$split[1]} after should be '{$expected}'."
			);
		}
	}

	/**
	 * A lone prompt, or one nested where it can't be positioned, degrades sanely.
	 */
	public function test_placement_edge_cases() {
		$only = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_content' => $this->content_with_prompt( 0, 0 ),
			]
		);
		$this->assertSame( 'top', Newspack_Popups_Contextual_Prompt_Block::get_placement( $only ) );

		$this->assertSame( 'unknown', Newspack_Popups_Contextual_Prompt_Block::get_placement( 0 ) );

		$no_prompt = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_content' => "<!-- wp:paragraph -->\n<p>No prompt here.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$this->assertSame( 'unknown', Newspack_Popups_Contextual_Prompt_Block::get_placement( $no_prompt ) );
	}

	/**
	 * The rendered wrapper carries the analytics hooks the view script reads:
	 * post id, CTA type, and placement — stamped at render, not saved.
	 */
	public function test_render_stamps_analytics_attributes() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_content' => $this->content_with_prompt( 0, 3 ),
			]
		);

		$rendered = '';
		$query    = new WP_Query( [ 'p' => $post_id ] );
		while ( $query->have_posts() ) {
			$query->the_post();
			$rendered = do_blocks( get_the_content() );
		}
		wp_reset_postdata();

		$this->assertStringContainsString( 'data-newspack-cp-post-id="' . $post_id . '"', $rendered );
		$this->assertStringContainsString( 'data-newspack-cp-cta="button"', $rendered );
		$this->assertStringContainsString( 'data-newspack-cp-placement="top"', $rendered );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * The attributes are added to the wrapper's opening tag only — a copy body
	 * containing a `<` must not gain an injected attribute, and the stamped values
	 * are escaped.
	 */
	public function test_render_stamps_only_the_wrapper() {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_content' => $this->content_with_prompt( 1, 1 ),
			]
		);

		$rendered = Newspack_Popups_Contextual_Prompt_Block::add_analytics_attributes(
			'<div class="wp-block-newspack-popups-contextual-prompt"><p>1 < 2 always.</p></div>',
			[]
		);

		$this->assertSame( 1, substr_count( $rendered, 'data-newspack-cp-post-id' ), 'Stamped exactly once.' );
		$this->assertStringContainsString( '<p>1 < 2 always.</p>', $rendered, 'Inner content is untouched.' );
	}

	/**
	 * The fund-drive override swaps the copy verbatim, including a dollar amount —
	 * the override is admin text fed to preg_replace, where `$` is a backreference.
	 */
	public function test_override_preserves_dollar_amounts() {
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Give $5 today — our drive is on.' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive' );

		$rendered = Newspack_Popups_Contextual_Prompt_Block::maybe_apply_override(
			"<div class=\"wp-block-newspack-popups-contextual-prompt\"><p>Original ask.</p></div>"
		);

		$this->assertStringContainsString( 'Give $5 today', $rendered, 'The dollar amount survives.' );
		$this->assertStringNotContainsString( 'Original ask.', $rendered, 'The original copy is replaced.' );

		delete_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION );
		delete_option( 'newspack_contextual_prompts_override_body' );
		delete_option( 'newspack_contextual_prompts_override_url' );
	}
}
