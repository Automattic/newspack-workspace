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
	 * Rendering requires the admin opt-in since the render-time strip keys on it.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
	}

	/**
	 * Put the block registration back. WP_UnitTestCase rolls back $wp_filter but
	 * not WP_Block_Type_Registry, so a test that fails mid-body would otherwise
	 * leave the block registered with native layout supports for the rest of the
	 * run.
	 */
	public function tear_down() {
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' ) ) {
			unregister_block_type( 'newspack-blocks/donate' );
		}
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ) ) {
			unregister_block_type( Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME );
		}
		Newspack_Popups_Contextual_Prompt_Block::register_block();
		parent::tear_down();
	}


	/**
	 * A single top-level contextual-prompt block carrying a plain-button CTA,
	 * wrapped in filler paragraphs.
	 *
	 * @param int $before Filler paragraphs before the prompt.
	 * @param int $after  Filler paragraphs after the prompt.
	 * @return string Post content.
	 */
	private function content_with_prompt( $before, $after ) {
		$para   = "<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n";
		$button = "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button {\"url\":\"https://example.com/donate/\"} -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"https://example.com/donate/\">Donate</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";
		$prompt = "<!-- wp:newspack-popups/contextual-prompt -->\n<div class=\"wp-block-newspack-popups-contextual-prompt\"><!-- wp:paragraph -->\n<p>Ask.</p>\n<!-- /wp:paragraph -->" . $button . "</div>\n<!-- /wp:newspack-popups/contextual-prompt -->\n";
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
	 * When the feature is off, the render_block callback strips Contextual Prompt
	 * blocks entirely and leaves every other block untouched. Tested directly since
	 * the test bootstrap keeps the flag on (so the filter itself isn't hooked here).
	 */
	public function test_strip_contextual_prompt_block_callback() {
		$this->assertSame(
			'',
			Newspack_Popups::strip_contextual_prompt_block(
				'<div class="wp-block-newspack-popups-contextual-prompt">Ask.</div>',
				[ 'blockName' => Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ]
			),
			'A Contextual Prompt block is stripped to nothing.'
		);

		$this->assertSame(
			'<p>Unrelated.</p>',
			Newspack_Popups::strip_contextual_prompt_block( '<p>Unrelated.</p>', [ 'blockName' => 'core/paragraph' ] ),
			'A different block passes through unchanged.'
		);

		$this->assertSame(
			'<p>No name.</p>',
			Newspack_Popups::strip_contextual_prompt_block( '<p>No name.</p>', [] ),
			'A block with no name passes through unchanged.'
		);
	}

	/**
	 * The render-time wrapper keys on the admin opt-in: with the rollout flag on
	 * (test bootstrap) but the opt-in withdrawn, stored Contextual Prompt markup
	 * is stripped; with the opt-in active it passes through. Guards against a
	 * disabled feature leaving prompts (or a live site-wide override) rendering.
	 */
	public function test_maybe_strip_follows_the_opt_in() {
		$content = '<div class="wp-block-newspack-popups-contextual-prompt">Ask.</div>';
		$block   = [ 'blockName' => Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ];

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		$this->assertSame(
			$content,
			Newspack_Popups::maybe_strip_contextual_prompt_block( $content, $block ),
			'With the opt-in active, the block renders.'
		);

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );
		$this->assertSame(
			'',
			Newspack_Popups::maybe_strip_contextual_prompt_block( $content, $block ),
			'With the opt-in withdrawn, the block is stripped.'
		);
		$this->assertSame(
			'<p>Unrelated.</p>',
			Newspack_Popups::maybe_strip_contextual_prompt_block( '<p>Unrelated.</p>', [ 'blockName' => 'core/paragraph' ] ),
			'Other blocks always pass through.'
		);
	}

	/**
	 * The registered layout supports follow the CTA the site renders, and must
	 * match what index.js registers on the client.
	 */
	public function test_registered_layout_supports_follow_the_cta() {
		$name          = Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME;
		$register_with = function ( $callback ) use ( $name ) {
			add_filter( 'newspack_contextual_prompts_use_donate_block', $callback );
			unregister_block_type( $name );
			Newspack_Popups_Contextual_Prompt_Block::register_block();
			remove_filter( 'newspack_contextual_prompts_use_donate_block', $callback );
			return WP_Block_Type_Registry::get_instance()->get_registered( $name )->supports['layout'];
		};

		$button = $register_with( '__return_false' );
		$this->assertSame(
			[
				'type'           => 'flex',
				'orientation'    => 'vertical',
				'justifyContent' => 'stretch',
			],
			$button['default']
		);
		$this->assertTrue( $button['allowOrientation'] );
		$this->assertTrue( $button['allowJustification'] );

		// use_donate_block() also requires the donate block itself, which this
		// test env does not load.
		register_block_type( 'newspack-blocks/donate' );
		$form = $register_with( '__return_true' );
		unregister_block_type( 'newspack-blocks/donate' );

		$this->assertSame( [ 'type' => 'default' ], $form['default'] );
		$this->assertFalse( $form['allowOrientation'] );
		$this->assertFalse( $form['allowJustification'] );
		$this->assertFalse( $form['allowEditing'] );
	}

	/**
	 * The layout attribute carries no default, so an untouched prompt follows
	 * whichever layout the supports declare.
	 */
	public function test_layout_attribute_has_no_default() {
		$attributes = WP_Block_Type_Registry::get_instance()->get_registered( Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME )->attributes;
		$this->assertArrayNotHasKey( 'default', $attributes['layout'] );
	}

	/**
	 * The color node the default design emits for the active theme.
	 *
	 * @return array
	 */
	private function default_color_node() {
		$filtered = Newspack_Popups_Contextual_Prompt_Block::default_design(
			new WP_Theme_JSON_Data( [ 'version' => 3 ], 'default' )
		);
		return $filtered->get_data()['styles']['blocks'][ Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ]['color'];
	}

	/**
	 * A block theme's style variations re-point the palette, so a literal
	 * background would survive a switch the page around it does not: the shipped
	 * Nocturne variation puts `contrast` at white, which the copy inherits.
	 * Naming slugs makes the card follow the active variation, and the pinned
	 * text color keeps the copy tied to the card rather than the canvas.
	 */
	public function test_block_theme_default_colors_name_palette_slugs() {
		$original = get_stylesheet();
		switch_theme( 'twentytwentyfive' );

		if ( ! wp_is_block_theme() ) {
			switch_theme( $original );
			$this->markTestSkipped( 'No block theme available in the test install.' );
		}

		$color = $this->default_color_node();
		switch_theme( $original );

		// Compiled to custom properties, which is what proves the references
		// resolve as presets rather than travelling through as literal strings.
		$this->assertSame( 'var(--wp--preset--color--base-2)', $color['background'] );
		$this->assertSame( 'var(--wp--preset--color--contrast)', $color['text'] );
	}

	/**
	 * Classic themes have no variations, so the designed value stands and the
	 * copy keeps inheriting the theme's body color, which the Customizer owns.
	 */
	public function test_classic_theme_default_colors_keep_the_designed_value() {
		$this->assertFalse( wp_is_block_theme(), 'The suite is expected to run on a classic theme.' );

		$color = $this->default_color_node();

		$this->assertSame( '#f7f7f7', $color['background'] );
		$this->assertArrayNotHasKey( 'text', $color );
	}
}
