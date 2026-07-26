<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the Contextual Prompt render pipeline: CTA platform normalization and
 * the site-wide override ("fund-drive mode").
 *
 * Both operate on parsed block data via prepare_block_data(). Structure
 * assertions call the filter directly; markup assertions render through
 * do_blocks() so the real pipeline runs. The donate block is not registered in
 * this test env, so native-CTA assertions are structural only.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt render pipeline test.
 */
class ContextualPromptOverrideTest extends WP_UnitTestCase {
	/**
	 * Rendering requires the admin opt-in since the render-time strip keys on it.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
	}


	const PLAIN_BUTTON_PROMPT = '<!-- wp:newspack-popups/contextual-prompt -->
<div class="wp-block-newspack-popups-contextual-prompt"><!-- wp:paragraph -->
<p>Original story copy.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"url":"https://example.com/donate/"} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/donate/">Donate</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:newspack-popups/contextual-prompt -->';

	const DONATE_FORM_PROMPT = '<!-- wp:newspack-popups/contextual-prompt -->
<div class="wp-block-newspack-popups-contextual-prompt"><!-- wp:paragraph -->
<p>Original story copy.</p>
<!-- /wp:paragraph -->

<!-- wp:newspack-blocks/donate {"className":"is-style-modern"} /--></div>
<!-- /wp:newspack-popups/contextual-prompt -->';

	const URLLESS_BUTTON_PROMPT = '<!-- wp:newspack-popups/contextual-prompt -->
<div class="wp-block-newspack-popups-contextual-prompt"><!-- wp:paragraph -->
<p>Original story copy.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Donate</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:newspack-popups/contextual-prompt -->';

	/**
	 * Reset override options, donor landing page and platform filters.
	 */
	public function tear_down() {
		delete_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION );
		delete_option( 'newspack_contextual_prompts_override_body' );
		delete_option( 'newspack_contextual_prompts_override_label' );
		delete_option( 'newspack_contextual_prompts_override_url' );
		delete_option( 'newspack_popups_donor_landing_page' );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Parse a serialized prompt fixture into its parsed-block array.
	 *
	 * @param string $markup Serialized block markup.
	 * @return array
	 */
	private function parse_prompt( $markup ) {
		return parse_blocks( $markup )[0];
	}

	/**
	 * Create a published donor landing page and point Campaigns settings at it.
	 *
	 * @return string The page permalink.
	 */
	private function set_donor_landing_page() {
		$page_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Donate to us',
			]
		);
		update_option( 'newspack_popups_donor_landing_page', $page_id );
		return get_permalink( $page_id );
	}

	/**
	 * Native platform: a stale plain-button CTA is normalized to the donate form.
	 */
	public function test_native_platform_swaps_stale_button_for_donate_form() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		$result = Newspack_Popups_Contextual_Prompt_Block::prepare_block_data( $this->parse_prompt( self::PLAIN_BUTTON_PROMPT ) );

		$this->assertSame( 'newspack-blocks/donate', $result['innerBlocks'][1]['blockName'] );
		$this->assertSame( 'is-style-modern', $result['innerBlocks'][1]['attrs']['className'] );
	}

	/**
	 * Native platform: a matching donate-form CTA passes through untouched.
	 */
	public function test_native_platform_keeps_matching_form_untouched() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		$parsed = $this->parse_prompt( self::DONATE_FORM_PROMPT );
		$this->assertSame( $parsed, Newspack_Popups_Contextual_Prompt_Block::prepare_block_data( $parsed ) );
	}

	/**
	 * Off-site platform: a stale donate-form CTA becomes a button to the donor
	 * landing page.
	 */
	public function test_offsite_platform_swaps_form_for_donor_landing_button() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		$permalink = $this->set_donor_landing_page();

		$result = Newspack_Popups_Contextual_Prompt_Block::prepare_block_data( $this->parse_prompt( self::DONATE_FORM_PROMPT ) );

		$this->assertSame( 'core/buttons', $result['innerBlocks'][1]['blockName'] );
		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', $result['innerBlocks'][1]['innerBlocks'][0]['innerHTML'] );
	}

	/**
	 * Off-site platform with no donor landing page: the CTA is dropped entirely —
	 * copy only, no dead button, no form on a disabled platform.
	 */
	public function test_offsite_platform_without_landing_page_drops_cta() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$result = Newspack_Popups_Contextual_Prompt_Block::prepare_block_data( $this->parse_prompt( self::DONATE_FORM_PROMPT ) );

		$this->assertCount( 1, $result['innerBlocks'], 'Only the copy paragraph remains.' );
		$this->assertSame( 1, count( array_filter( $result['innerContent'], 'is_null' ) ), 'One placeholder per remaining child.' );
	}

	/**
	 * Off-site platform: a matching plain-button CTA passes through untouched —
	 * per-story customization is preserved.
	 */
	public function test_offsite_platform_keeps_matching_button_untouched() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$parsed = $this->parse_prompt( self::PLAIN_BUTTON_PROMPT );
		$this->assertSame( $parsed, Newspack_Popups_Contextual_Prompt_Block::prepare_block_data( $parsed ) );
	}

	/**
	 * Off-site platform: a button that never got a destination (fresh insert
	 * before any donor landing page existed) is repointed at the landing page.
	 */
	public function test_offsite_urlless_button_repointed_to_landing_page() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		$permalink = $this->set_donor_landing_page();

		$result = Newspack_Popups_Contextual_Prompt_Block::prepare_block_data( $this->parse_prompt( self::URLLESS_BUTTON_PROMPT ) );

		$this->assertSame( 'core/buttons', $result['innerBlocks'][1]['blockName'] );
		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', $result['innerBlocks'][1]['innerBlocks'][0]['innerHTML'] );
	}

	/**
	 * Off-site platform, no destination anywhere: a URL-less button is dropped —
	 * copy only, never a dead Donate button.
	 */
	public function test_offsite_urlless_button_without_landing_page_drops_cta() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$result = Newspack_Popups_Contextual_Prompt_Block::prepare_block_data( $this->parse_prompt( self::URLLESS_BUTTON_PROMPT ) );

		$this->assertCount( 1, $result['innerBlocks'], 'Only the copy paragraph remains.' );
		$this->assertStringNotContainsString( 'wp-block-button', do_blocks( self::URLLESS_BUTTON_PROMPT ) );
	}

	/**
	 * Override in form mode (native): copy swaps, the donate form stays.
	 */
	public function test_override_form_mode_swaps_copy_only() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'form' );
		update_option( 'newspack_contextual_prompts_override_body', 'Support our spring drive.' );

		$result = Newspack_Popups_Contextual_Prompt_Block::prepare_block_data( $this->parse_prompt( self::DONATE_FORM_PROMPT ) );

		$this->assertStringContainsString( 'Support our spring drive.', $result['innerBlocks'][0]['innerHTML'] );
		$this->assertStringNotContainsString( 'Original story copy.', $result['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( 'newspack-blocks/donate', $result['innerBlocks'][1]['blockName'], 'Form mode keeps the donate form.' );
	}

	/**
	 * Override in button mode on a native site: the donate form is replaced by
	 * the override button, rendered end-to-end.
	 */
	public function test_override_button_mode_replaces_form_with_button() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'button' );
		update_option( 'newspack_contextual_prompts_override_body', 'Support our spring drive.' );
		update_option( 'newspack_contextual_prompts_override_label', 'Give now' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );

		$html = do_blocks( self::DONATE_FORM_PROMPT );

		$this->assertStringContainsString( 'Support our spring drive.', $html );
		$this->assertStringContainsString( 'href="https://example.com/drive/"', $html );
		$this->assertStringContainsString( '>Give now</a>', $html );
	}

	/**
	 * Override on an off-site site (always button mode): the story's own button
	 * is replaced by the override button.
	 */
	public function test_override_replaces_button_on_offsite_site() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Support our spring drive.' );
		update_option( 'newspack_contextual_prompts_override_label', 'Give now' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );

		$html = do_blocks( self::PLAIN_BUTTON_PROMPT );

		$this->assertStringContainsString( 'href="https://example.com/drive/"', $html );
		$this->assertStringNotContainsString( 'https://example.com/donate/', $html );
		$this->assertStringContainsString( '>Give now</a>', $html );
	}

	/**
	 * Override in button mode when normalization dropped the CTA (no donor
	 * landing page): the override button is appended, so fund mode still has a
	 * working ask.
	 */
	public function test_override_button_mode_appends_cta_when_missing() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Support our spring drive.' );
		update_option( 'newspack_contextual_prompts_override_label', 'Give now' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );

		$html = do_blocks( self::DONATE_FORM_PROMPT );

		$this->assertStringContainsString( 'href="https://example.com/drive/"', $html );
		$this->assertStringContainsString( '>Give now</a>', $html );
	}

	/**
	 * The stamped CTA type follows what actually renders, not the configured
	 * platform: a button override on a native-donations site reports 'button'.
	 */
	public function test_cta_attribute_follows_button_override() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'button' );
		update_option( 'newspack_contextual_prompts_override_body', 'Support our spring drive.' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );

		$html = do_blocks( self::DONATE_FORM_PROMPT );

		$this->assertStringContainsString( 'data-newspack-cp-cta="button"', $html );
	}

	/**
	 * Off-site with no destination drops the CTA entirely, and the stamped type
	 * says so rather than claiming a button rendered.
	 */
	public function test_cta_attribute_reports_none_when_cta_dropped() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$html = do_blocks( self::DONATE_FORM_PROMPT );

		$this->assertStringContainsString( 'data-newspack-cp-cta="none"', $html );
	}

	/**
	 * A literal $-sequence in override copy or label survives verbatim — never
	 * expanded as a regex backreference.
	 */
	public function test_dollar_sequences_are_not_expanded() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Give $5 today — just ${1} a week.' );
		update_option( 'newspack_contextual_prompts_override_label', 'Give $5' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );

		$html = do_blocks( self::PLAIN_BUTTON_PROMPT );

		$this->assertStringContainsString( 'Give $5 today — just ${1} a week.', $html );
		$this->assertStringContainsString( '>Give $5</a>', $html );
	}

	/**
	 * An inactive override leaves the (normalized) block alone: enabled with no
	 * copy counts as inactive.
	 */
	public function test_inactive_override_is_a_noop() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );

		$parsed = $this->parse_prompt( self::PLAIN_BUTTON_PROMPT );
		$this->assertSame( $parsed, Newspack_Popups_Contextual_Prompt_Block::prepare_block_data( $parsed ) );
	}
}
