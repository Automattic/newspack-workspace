<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the output-side filters a Contextual Prompt goes through: the analytics
 * hooks stamped on the rendered card, the placement bucket that answers the
 * grant's "which placement converts best" question, the empty-copy suppression,
 * and the strip that hides every instance while the feature is off.
 *
 * A prompt renders as body content, so it can't use the prompt-keyed GA event —
 * the stamped attributes are what the view script reads instead.
 *
 * Newspack Blocks is not loaded in this test env, so set_up() registers a stub
 * donate block whose markup carries the class the CTA type is read from.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt analytics test case.
 */
class ContextualPromptAnalyticsTest extends WP_UnitTestCase {
	/**
	 * Copy an instance carries as its own pattern override.
	 */
	const INSTANCE_COPY = 'Ask.';

	/**
	 * Register the stub donate block, clear the per-request render state the
	 * previous test left behind, and take the admin opt-in the strip keys on.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_request_state();
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' ) ) {
			register_block_type(
				'newspack-blocks/donate',
				[
					'render_callback' => function () {
						ob_start();
						do_action( 'newspack_blocks_donate_before_form_fields' );
						return '<div class="wpbnbd"><form>' . ob_get_clean() . '<button type="submit">Donate</button></form></div>';
					},
				]
			);
		}
	}

	/**
	 * Reset the pattern record, the donation settings and the stub block type.
	 */
	public function tear_down() {
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID );
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT );
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION );
		delete_option( 'newspack_contextual_prompts_override_body' );
		delete_option( 'newspack_contextual_prompts_override_label' );
		delete_option( 'newspack_contextual_prompts_override_url' );
		delete_option( 'newspack_popups_donor_landing_page' );
		delete_option( Newspack_Popups_Settings::CONTROL_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::CONTROL_BODY_OPTION );
		delete_option( Newspack_Popups_Settings::CONTROL_INTERVAL_OPTION );
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' ) ) {
			unregister_block_type( 'newspack-blocks/donate' );
		}
		parent::tear_down();
	}

	/**
	 * The render class carries per-request state (the instance window and the
	 * once-a-request repair guard); a test run is one process, so it is reset
	 * between tests the way a new request would.
	 */
	private function reset_request_state() {
		$state = [
			'in_instance'        => false,
			'repaired'           => false,
			'card_open'          => false,
			'rendered_condition' => null,
		];
		foreach ( $state as $name => $value ) {
			$property = new ReflectionProperty( 'Newspack_Popups_Contextual_Prompt_Render', $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/**
	 * Point the donation platform at the native donate block, or off site.
	 *
	 * @param bool $native Whether the site uses native (WooCommerce) donations.
	 */
	private function set_platform( $native ) {
		remove_all_filters( 'newspack_contextual_prompts_use_donate_block' );
		add_filter( 'newspack_contextual_prompts_use_donate_block', $native ? '__return_true' : '__return_false' );
	}

	/**
	 * Create a published donor landing page and point Campaigns settings at it.
	 *
	 * @return string The page permalink.
	 */
	private function set_donor_landing_page() {
		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Donate to us',
				'post_status' => 'publish',
			]
		);
		update_option( 'newspack_popups_donor_landing_page', $page_id );
		return get_permalink( $page_id );
	}

	/**
	 * An instance of the pattern, as a post carries it.
	 *
	 * @param string|null $copy The instance's own pattern override copy, or null
	 *                          for an instance carrying none.
	 * @return string Serialized block markup.
	 */
	private function instance_markup( $copy = self::INSTANCE_COPY ) {
		$attrs = [ 'ref' => Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id() ];
		if ( null !== $copy ) {
			$attrs['content'] = [ Newspack_Popups_Contextual_Prompt_Pattern::BOUND_NAME => [ 'content' => $copy ] ];
		}
		return '<!-- wp:block ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/**
	 * The same card detached from the pattern and pasted into a post as ordinary
	 * content: the marker Group, with copy written straight into it.
	 *
	 * @param string $copy The card's copy.
	 * @return string Serialized block markup.
	 */
	private function detached_markup( $copy = 'Detached copy.' ) {
		$content = get_post( Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id() )->post_content;
		return preg_replace_callback(
			'#<p\b[^>]*>.*?</p>#s',
			function () use ( $copy ) {
				return '<p>' . $copy . '</p>';
			},
			$content,
			1
		);
	}

	/**
	 * A post body with the given card among filler paragraphs.
	 *
	 * @param int    $before Filler paragraphs before the card.
	 * @param int    $after  Filler paragraphs after the card.
	 * @param string $card   Serialized card markup.
	 * @return string Post content.
	 */
	private function content_with_prompt( $before, $after, $card ) {
		$para = "<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n";
		return str_repeat( $para, $before ) . $card . "\n" . str_repeat( $para, $after );
	}

	/**
	 * Create a post carrying the markup, then render it in the loop — the stamped
	 * post id comes from the post being rendered.
	 *
	 * @param string $content Post content.
	 * @return string Rendered markup.
	 */
	private function render_post( $content ) {
		return $this->render_in_loop(
			self::factory()->post->create(
				[
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_content' => $content,
				]
			)
		);
	}

	/**
	 * A published post carrying the markup whose id is, or is not, a multiple of
	 * the control interval — the id is what decides the condition.
	 *
	 * @param string $content  Post content.
	 * @param int    $interval Control interval.
	 * @param bool   $selected Whether the id should be a multiple of it.
	 * @return int Post id.
	 */
	private function post_for_interval( $content, $interval, $selected ) {
		do {
			$post_id = self::factory()->post->create(
				[
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_content' => $content,
				]
			);
		} while ( ( 0 === $post_id % $interval ) !== $selected );
		return $post_id;
	}

	/**
	 * Render a post in the loop, the way a reader gets it — the stamped post id
	 * comes from the post being rendered.
	 *
	 * @param int $post_id Post to render.
	 * @return string Rendered markup.
	 */
	private function render_in_loop( $post_id ) {
		$rendered = '';
		$query    = new WP_Query( [ 'p' => $post_id ] );
		while ( $query->have_posts() ) {
			$query->the_post();
			$rendered = do_blocks( get_the_content() );
		}
		wp_reset_postdata();

		return $rendered;
	}

	/**
	 * The rendered card carries the analytics hooks the view script reads: post
	 * id, CTA type and placement — stamped at render, never saved.
	 */
	public function test_render_stamps_analytics_attributes() {
		$this->set_platform( false );
		$this->set_donor_landing_page();

		$post_id  = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => $this->content_with_prompt( 0, 3, $this->instance_markup() ),
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
	}

	/**
	 * The attributes land on the card's opening tag only — copy containing a `<`
	 * must not gain an injected attribute.
	 */
	public function test_render_stamps_only_the_wrapper() {
		$rendered = Newspack_Popups_Contextual_Prompt_Render::add_analytics_attributes(
			'<div class="wp-block-group ' . Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS . '"><p>1 < 2 always.</p></div>',
			[]
		);

		$this->assertSame( 1, substr_count( $rendered, 'data-newspack-cp-post-id' ), 'Stamped exactly once.' );
		$this->assertStringContainsString( '<p>1 < 2 always.</p>', $rendered, 'Inner content is untouched.' );
	}

	/**
	 * A group that merely contains a prompt is not a prompt: stamping it too would
	 * hand the view script a second element reporting the same prompt, doubling
	 * every seen and every click.
	 */
	public function test_a_wrapping_group_is_not_stamped() {
		$this->set_platform( false );
		$this->set_donor_landing_page();

		$rendered = $this->render_post(
			"<!-- wp:group -->\n<div class=\"wp-block-group\">" . $this->instance_markup() . "</div>\n<!-- /wp:group -->"
		);

		$this->assertSame( 1, substr_count( $rendered, 'data-newspack-cp-post-id' ) );
	}

	/**
	 * Placement is bucketed from the instance's actual position among the post's
	 * top-level blocks.
	 */
	public function test_placement_buckets_by_position() {
		$this->set_platform( false );
		$this->set_donor_landing_page();

		$cases = [
			'top' => [ 0, 5 ],
			'mid' => [ 3, 3 ],
			'end' => [ 5, 0 ],
		];
		foreach ( $cases as $expected => $split ) {
			$post_id = self::factory()->post->create(
				[
					'post_type'    => 'post',
					'post_content' => $this->content_with_prompt( $split[0], $split[1], $this->instance_markup() ),
				]
			);
			$this->assertSame(
				$expected,
				Newspack_Popups_Contextual_Prompt_Render::get_placement( $post_id ),
				"A prompt with {$split[0]} blocks before and {$split[1]} after should be '{$expected}'."
			);
		}
	}

	/**
	 * A lone prompt, or one that can't be positioned, degrades sanely.
	 */
	public function test_placement_edge_cases() {
		$this->set_platform( false );
		$this->set_donor_landing_page();

		$only = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_content' => $this->content_with_prompt( 0, 0, $this->instance_markup() ),
			]
		);
		$this->assertSame( 'top', Newspack_Popups_Contextual_Prompt_Render::get_placement( $only ) );

		$this->assertSame( 'unknown', Newspack_Popups_Contextual_Prompt_Render::get_placement( 0 ) );

		$no_prompt = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_content' => "<!-- wp:paragraph -->\n<p>No prompt here.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$this->assertSame( 'unknown', Newspack_Popups_Contextual_Prompt_Render::get_placement( $no_prompt ) );
	}

	/**
	 * A card the publisher detached from the pattern is still a prompt as far as
	 * analytics go: it is stamped. It is never suppressed either — its copy is
	 * the publisher's, written into the post.
	 */
	public function test_detached_group_is_still_stamped() {
		$this->set_platform( false );
		$this->set_donor_landing_page();

		$rendered = $this->render_post( $this->detached_markup() );

		$this->assertStringContainsString( 'data-newspack-cp-cta="button"', $rendered );
		$this->assertSame( 1, substr_count( $rendered, 'data-newspack-cp-post-id' ) );

		$this->assertStringContainsString(
			Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS,
			$this->render_post( $this->detached_markup( '' ) ),
			'Empty-copy suppression is scoped to instances.'
		);
	}

	/**
	 * A detached card counts for placement too: the publisher moved the card, not
	 * the measurement.
	 */
	public function test_placement_counts_a_detached_group() {
		$this->set_platform( false );
		$this->set_donor_landing_page();

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_content' => $this->content_with_prompt( 5, 0, $this->detached_markup() ),
			]
		);

		$this->assertSame( 'end', Newspack_Popups_Contextual_Prompt_Render::get_placement( $post_id ) );
	}

	/**
	 * An instance whose copy resolves to nothing — the story's own copy deleted in
	 * the editor and the post published anyway — renders nothing rather than a
	 * CTA-only card.
	 */
	public function test_empty_copy_instance_renders_nothing() {
		$this->set_platform( false );
		$this->set_donor_landing_page();

		$this->assertSame( '', trim( do_blocks( $this->instance_markup( '' ) ) ) );
	}

	/**
	 * What a prompt displays is its instance override; an instance carrying none
	 * falls back to the pattern's own general ask, and one the active site-wide
	 * override reaches displays that.
	 */
	public function test_copy_falls_back_to_the_pattern_then_to_the_override() {
		$this->set_platform( false );
		$this->set_donor_landing_page();

		$this->assertStringContainsString( self::INSTANCE_COPY, do_blocks( $this->instance_markup() ) );
		$this->assertStringContainsString( 'Reporting like this takes time', do_blocks( $this->instance_markup( null ) ) );

		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Support our spring drive.' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );

		$this->assertStringContainsString( 'Support our spring drive.', do_blocks( $this->instance_markup( null ) ) );
	}

	/**
	 * With the feature off — rollout flag absent or the admin opt-in withdrawn —
	 * every instance is stripped, so a disabled feature can't leave prompts (or a
	 * live site-wide override) rendering with no UI to stop them.
	 */
	public function test_disabled_feature_strips_instances() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		$markup = $this->instance_markup();

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		$this->assertSame( '', trim( do_blocks( $markup ) ) );
	}

	/**
	 * The strip runs on every synced pattern on the site, so it has to key on the
	 * pattern ref: another pattern renders as its author wrote it, feature on or
	 * off.
	 */
	public function test_the_strip_leaves_another_pattern_alone() {
		$this->set_platform( false );
		Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$other = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => "<!-- wp:paragraph -->\n<p>Another pattern.</p>\n<!-- /wp:paragraph -->",
			]
		);

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		$this->assertStringContainsString( 'Another pattern.', do_blocks( '<!-- wp:block {"ref":' . $other . '} /-->' ) );
	}

	/**
	 * Posts saved while a prompt was its own block carry markup nothing registers
	 * any more; it renders empty rather than as a card nothing manages.
	 */
	public function test_legacy_beta_block_renders_empty() {
		$legacy = "<!-- wp:newspack-popups/contextual-prompt {\"body\":\"Support us.\"} -->\n"
			. '<div class="wp-block-newspack-popups-contextual-prompt"><p>Support us.</p></div>'
			. "\n<!-- /wp:newspack-popups/contextual-prompt -->";

		$this->assertSame( '', trim( do_blocks( $legacy ) ) );
	}

	/**
	 * A detached card is the publisher's own content, so the feature strip leaves
	 * it alone: turning Contextual Prompts off must not empty a story.
	 */
	public function test_detached_group_survives_the_feature_strip() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		$markup = $this->detached_markup();

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		$this->assertStringContainsString( 'Detached copy.', do_blocks( $markup ) );
	}

	/**
	 * The native platform's card reports the donate form.
	 */
	public function test_cta_type_reports_the_donate_form() {
		$this->set_platform( true );

		$this->assertStringContainsString( 'data-newspack-cp-cta="donate_block"', do_blocks( $this->instance_markup() ) );
	}

	/**
	 * Off site with no donor landing page drops the CTA entirely, and the stamped
	 * type says so rather than claiming a button rendered.
	 */
	public function test_cta_type_reports_none_when_the_cta_is_dropped() {
		$this->set_platform( false );

		$this->assertStringContainsString( 'data-newspack-cp-cta="none"', do_blocks( $this->instance_markup() ) );
	}

	/**
	 * The stamped type follows what actually rendered, not the configured
	 * platform: a button override on a native site reports 'button'.
	 */
	public function test_cta_type_follows_a_button_override() {
		$this->set_platform( true );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'button' );
		update_option( 'newspack_contextual_prompts_override_body', 'Support our spring drive.' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );

		$this->assertStringContainsString( 'data-newspack-cp-cta="button"', do_blocks( $this->instance_markup() ) );
	}

	/**
	 * The condition is stamped while the control is on, and absent when it's off.
	 */
	public function test_render_stamps_the_condition() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		$content = $this->content_with_prompt( 0, 3, $this->instance_markup() );

		$this->assertStringNotContainsString( 'data-newspack-cp-condition', $this->render_post( $content ) );

		update_option( Newspack_Popups_Settings::CONTROL_ENABLED_OPTION, '1' );
		update_option( Newspack_Popups_Settings::CONTROL_BODY_OPTION, 'Support local news.' );
		update_option( Newspack_Popups_Settings::CONTROL_INTERVAL_OPTION, '3' );
		$rendered = $this->render_post( $content );
		$this->assertMatchesRegularExpression( '/data-newspack-cp-condition="(story_aware|generic_control)"/', $rendered );

		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'button' );
		update_option( 'newspack_contextual_prompts_override_body', 'Fund drive' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );
		$this->assertStringContainsString( 'data-newspack-cp-condition="override"', $this->render_post( $content ) );
	}

	/**
	 * Native mode: the donate form inside the card carries the source triple,
	 * so the donation can be attributed to this story, placement and condition.
	 */
	public function test_donate_form_inside_the_card_carries_the_source() {
		$this->set_platform( true );
		update_option( Newspack_Popups_Settings::CONTROL_ENABLED_OPTION, '1' );
		update_option( Newspack_Popups_Settings::CONTROL_BODY_OPTION, 'Support local news.' );
		$rendered = $this->render_post( $this->content_with_prompt( 0, 3, $this->instance_markup() ) );
		$this->assertMatchesRegularExpression( '/name="contextual_prompt_post_id"[^>]*value="\d+"/', $rendered );
		$this->assertMatchesRegularExpression( '/name="contextual_prompt_placement"[^>]*value="top"/', $rendered );
		$this->assertMatchesRegularExpression( '/name="contextual_prompt_condition"[^>]*value="(story_aware|generic_control)"/', $rendered );
	}

	/**
	 * A donate form outside any card gets nothing, unless the request arrived
	 * from a plain-button card (landing page), in which case the URL's triple is
	 * forwarded so the same attribution path applies.
	 */
	public function test_donate_form_outside_the_card_forwards_a_request_source_only() {
		$this->set_platform( true );
		$this->assertStringNotContainsString( 'contextual_prompt_post_id', do_blocks( '<!-- wp:newspack-blocks/donate /-->' ) );

		$post_id                             = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$_GET['contextual_prompt_post_id']   = (string) $post_id;
		$_GET['contextual_prompt_placement'] = 'end';
		$_GET['contextual_prompt_condition'] = 'generic_control';
		$rendered                            = do_blocks( '<!-- wp:newspack-blocks/donate /-->' );
		unset( $_GET['contextual_prompt_post_id'], $_GET['contextual_prompt_placement'], $_GET['contextual_prompt_condition'] );
		$this->assertMatchesRegularExpression( '/name="contextual_prompt_post_id"[^>]*value="' . $post_id . '"/', $rendered );
		$this->assertMatchesRegularExpression( '/name="contextual_prompt_condition"[^>]*value="generic_control"/', $rendered );
	}

	/**
	 * Junk in the URL is not forwarded.
	 */
	public function test_request_source_is_validated_before_forwarding() {
		$this->set_platform( true );
		$_GET['contextual_prompt_post_id']   = 'abc';
		$_GET['contextual_prompt_placement'] = 'sideways';
		$_GET['contextual_prompt_condition'] = '<script>';
		$rendered                            = do_blocks( '<!-- wp:newspack-blocks/donate /-->' );
		unset( $_GET['contextual_prompt_post_id'], $_GET['contextual_prompt_placement'], $_GET['contextual_prompt_condition'] );
		$this->assertStringNotContainsString( 'contextual_prompt_', $rendered );
	}

	/**
	 * Plain-button mode: the button destination carries the triple at render.
	 * The stored pattern's button does not, so nothing is baked into content.
	 *
	 * A post whose only top-level block is the card buckets as 'top'
	 * (bucket_placement() special-cases a single block), not 'end'.
	 */
	public function test_button_href_carries_the_source_at_render_only() {
		$this->set_platform( false );
		$landing = $this->set_donor_landing_page();
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->content_with_prompt( 0, 0, $this->instance_markup() ),
			]
		);
		$query = new WP_Query( [ 'p' => $post_id ] );
		$query->the_post();
		$rendered = do_blocks( get_the_content() );
		wp_reset_postdata();

		$this->assertStringContainsString( 'contextual_prompt_post_id=' . $post_id, $rendered );
		$this->assertStringContainsString( 'contextual_prompt_placement=top', $rendered );
		$this->assertStringNotContainsString( 'contextual_prompt_post_id', get_post( Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id() )->post_content );
		$this->assertStringContainsString( esc_url( $landing ), html_entity_decode( $rendered ) );
	}

	/**
	 * Turn the control test on.
	 *
	 * @param string $body     Control copy.
	 * @param int    $interval Every Nth story.
	 */
	private function set_control( $body = 'Support local news.', $interval = 3 ) {
		update_option( Newspack_Popups_Settings::CONTROL_ENABLED_OPTION, '1' );
		update_option( Newspack_Popups_Settings::CONTROL_BODY_OPTION, $body );
		update_option( Newspack_Popups_Settings::CONTROL_INTERVAL_OPTION, (string) $interval );
	}

	/**
	 * A detached card is still a prompt, so a selected story swaps its copy for
	 * the control copy, reports the swap as `generic_control`, and hands the
	 * donate form this story's source triple.
	 */
	public function test_detached_card_in_a_selected_story_renders_the_control() {
		$this->set_platform( true );
		$this->set_control( 'Support local news.', 3 );

		$rendered = $this->render_in_loop( $this->post_for_interval( $this->detached_markup(), 3, true ) );

		$this->assertStringContainsString( 'Support local news.', $rendered );
		$this->assertStringNotContainsString( 'Detached copy.', $rendered );
		$this->assertStringContainsString( 'data-newspack-cp-condition="generic_control"', $rendered );
		$this->assertMatchesRegularExpression( '/name="contextual_prompt_post_id"[^>]*value="\d+"/', $rendered );
		$this->assertMatchesRegularExpression( '/name="contextual_prompt_placement"[^>]*value="top"/', $rendered );
		$this->assertMatchesRegularExpression( '/name="contextual_prompt_condition"[^>]*value="generic_control"/', $rendered );
	}

	/**
	 * An unselected story keeps the publisher's own copy, and the stamp says so:
	 * the label follows what actually rendered.
	 */
	public function test_detached_card_in_an_unselected_story_keeps_its_copy() {
		$this->set_platform( true );
		$this->set_control( 'Support local news.', 3 );

		$rendered = $this->render_in_loop( $this->post_for_interval( $this->detached_markup(), 3, false ) );

		$this->assertStringContainsString( 'Detached copy.', $rendered );
		$this->assertStringNotContainsString( 'Support local news.', $rendered );
		$this->assertStringContainsString( 'data-newspack-cp-condition="story_aware"', $rendered );
	}

	/**
	 * A fund drive replaces every card, detached ones included, and the stamp
	 * reports the override rather than the control the drive paused.
	 */
	public function test_detached_card_takes_the_fund_drive_override() {
		$this->set_platform( true );
		$this->set_control( 'Support local news.', 3 );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'form' );
		update_option( 'newspack_contextual_prompts_override_body', 'Fund drive copy' );

		$rendered = $this->render_in_loop( $this->post_for_interval( $this->detached_markup(), 3, true ) );

		$this->assertStringContainsString( 'Fund drive copy', $rendered );
		$this->assertStringNotContainsString( 'Support local news.', $rendered );
		$this->assertStringContainsString( 'data-newspack-cp-condition="override"', $rendered );
	}

	/**
	 * The same card with its copy paragraph deleted — a publisher can remove it
	 * from a detached card — so there is nothing for a swap to replace.
	 *
	 * @return string Serialized block markup.
	 */
	private function detached_markup_without_copy() {
		return preg_replace( '#<!-- wp:paragraph.*?<!-- /wp:paragraph -->#s', '', $this->detached_markup(), 1 );
	}

	/**
	 * Withdrawing the admin opt-in stops the fund drive and the control test at
	 * every card, detached ones included: a detached card renders the copy its
	 * post carries, reports no condition, and hands a donate form nothing.
	 */
	public function test_the_feature_switch_reaches_detached_cards() {
		$this->set_platform( true );
		$this->set_control( 'Support local news.', 3 );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'form' );
		update_option( 'newspack_contextual_prompts_override_body', 'Fund drive copy' );
		$content = $this->detached_markup();

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );
		$rendered = $this->render_in_loop( $this->post_for_interval( $content, 3, true ) );

		$this->assertStringContainsString( 'Detached copy.', $rendered );
		$this->assertStringNotContainsString( 'Fund drive copy', $rendered );
		$this->assertStringNotContainsString( 'Support local news.', $rendered );
		$this->assertStringNotContainsString( 'data-newspack-cp-condition', $rendered );
		$this->assertStringNotContainsString( 'contextual_prompt_post_id', $rendered );
	}

	/**
	 * Attribution reports the condition the card rendered under, not the one the
	 * story is assigned. A selected story whose card has no copy paragraph swaps
	 * nothing, so the impression, the hidden inputs and the button destination all
	 * say `story_aware` — otherwise the same donation lands on both sides of the
	 * comparison.
	 */
	public function test_attribution_uses_the_condition_that_rendered() {
		$this->set_platform( true );
		$this->set_control( 'Support local news.', 3 );

		$rendered = $this->render_in_loop( $this->post_for_interval( $this->detached_markup_without_copy(), 3, true ) );

		$this->assertStringContainsString( 'data-newspack-cp-condition="story_aware"', $rendered );
		$this->assertMatchesRegularExpression( '/name="contextual_prompt_condition"[^>]*value="story_aware"/', $rendered );
	}

	/**
	 * The marker is a whole class, not a prefix: a Group the publisher gave a
	 * class of their own that happens to start with it is their content, so it is
	 * not swapped, not stamped, and does not leave a card open for the next
	 * donate form on the page to attribute itself to.
	 */
	public function test_a_class_merely_prefixed_with_the_marker_is_not_a_card() {
		$this->set_platform( true );
		$this->set_control( 'Support local news.', 3 );

		$content = '<!-- wp:group {"className":"' . Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS . "-custom\"} -->\n"
			. '<div class="wp-block-group ' . Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS . '-custom">'
			. "<!-- wp:paragraph -->\n<p>Custom copy.</p>\n<!-- /wp:paragraph -->"
			. "</div>\n<!-- /wp:group -->\n"
			. '<!-- wp:newspack-blocks/donate /-->';

		$rendered = $this->render_in_loop( $this->post_for_interval( $content, 3, true ) );

		$this->assertStringContainsString( 'Custom copy.', $rendered );
		$this->assertStringNotContainsString( 'Support local news.', $rendered );
		$this->assertStringNotContainsString( 'data-newspack-cp-condition', $rendered );
		$this->assertStringNotContainsString( 'contextual_prompt_post_id', $rendered );
	}

	/**
	 * A fund drive in button mode replaces a detached card's CTA too: during a
	 * drive every card carries the drive's ask and the drive's button.
	 */
	public function test_fund_drive_button_mode_replaces_a_detached_cta() {
		$this->set_platform( true );
		$this->set_control( 'Support local news.', 3 );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'button' );
		update_option( 'newspack_contextual_prompts_override_body', 'Fund drive copy' );
		update_option( 'newspack_contextual_prompts_override_label', 'Give now' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );

		$rendered = $this->render_in_loop( $this->post_for_interval( $this->detached_markup(), 3, true ) );

		$this->assertStringContainsString( 'Fund drive copy', $rendered );
		$this->assertStringNotContainsString( 'Detached copy.', $rendered );
		$this->assertStringContainsString( 'Give now', $rendered );
		$this->assertStringContainsString( 'https://example.com/drive/', html_entity_decode( $rendered ) );
		$this->assertStringContainsString( 'data-newspack-cp-condition="override"', $rendered );
	}
}
