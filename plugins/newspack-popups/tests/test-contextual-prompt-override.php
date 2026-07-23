<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the Contextual Prompt site-wide override ("fund-drive mode") render path.
 *
 * The override swaps the copy of every prompt at render time, and on plain-button
 * sites also repoints the CTA at the override destination and label. The native
 * donate block owns its own URL, so it is left untouched.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt override render test.
 */
class ContextualPromptOverrideTest extends WP_UnitTestCase {

	const PLAIN_BUTTON_BLOCK = '<div class="wp-block-newspack-popups-contextual-prompt">' . "\n"
		. '<p>Original story copy.</p>' . "\n"
		. '<div class="wp-block-buttons"><div class="wp-block-button">'
		. '<a class="wp-block-button__link wp-element-button" href="https://example.com/donate/">Donate</a>'
		. '</div></div></div>';

	const NATIVE_DONATE_BLOCK = '<div class="wp-block-newspack-popups-contextual-prompt">' . "\n"
		. '<p>Original story copy.</p>' . "\n"
		. '<div class="wpbnbd wpbnbd--platform-wc"><form>donate form markup</form></div></div>';

	/**
	 * Reset override options and the donate-block filter between tests.
	 */
	public function tear_down() {
		delete_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION );
		delete_option( 'newspack_contextual_prompts_override_body' );
		delete_option( 'newspack_contextual_prompts_override_label' );
		delete_option( 'newspack_contextual_prompts_override_url' );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Put the site into plain-button (off-site donation) mode with an active override.
	 *
	 * @param string $body  Override copy.
	 * @param string $url   Override button URL.
	 * @param string $label Override button label.
	 */
	private function activate_plain_button_override( $body, $url, $label ) {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', $body );
		update_option( 'newspack_contextual_prompts_override_url', $url );
		update_option( 'newspack_contextual_prompts_override_label', $label );
	}

	/**
	 * On a plain-button site, an active override swaps the copy AND repoints the
	 * button's href and label at the override destination.
	 */
	public function test_plain_button_override_repoints_cta() {
		$this->activate_plain_button_override(
			'Fund the newsroom during our spring drive.',
			'https://example.com/fund-drive/',
			'Give now'
		);

		$result = Newspack_Popups_Contextual_Prompt_Block::maybe_apply_override( self::PLAIN_BUTTON_BLOCK );

		$this->assertStringContainsString( 'Fund the newsroom during our spring drive.', $result, 'Copy is swapped.' );
		$this->assertStringNotContainsString( 'Original story copy.', $result );
		$this->assertStringContainsString( 'href="https://example.com/fund-drive/"', $result, 'Button repointed.' );
		$this->assertStringNotContainsString( 'https://example.com/donate/', $result, 'Original destination gone.' );
		$this->assertStringContainsString( '>Give now</a>', $result, 'Button label swapped.' );
		$this->assertStringNotContainsString( '>Donate</a>', $result );
	}

	/**
	 * With an override URL but no label, the button is repointed while keeping its
	 * original label.
	 */
	public function test_plain_button_override_without_label_keeps_label() {
		$this->activate_plain_button_override( 'Fund the newsroom.', 'https://example.com/fund-drive/', '' );

		$result = Newspack_Popups_Contextual_Prompt_Block::maybe_apply_override( self::PLAIN_BUTTON_BLOCK );

		$this->assertStringContainsString( 'href="https://example.com/fund-drive/"', $result );
		$this->assertStringContainsString( '>Donate</a>', $result, 'Original label kept when no override label is set.' );
	}

	/**
	 * A block's CTA type is fixed at insertion, so the repoint follows the block's
	 * actual markup, not the site's current donation platform: a plain-button prompt
	 * that predates a switch to native donations is still repointed.
	 */
	public function test_repoint_follows_block_not_current_platform() {
		// Site is now on native donations, but this block was inserted as a plain button.
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Fund the newsroom.' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/fund-drive/' );
		update_option( 'newspack_contextual_prompts_override_label', 'Give now' );

		$result = Newspack_Popups_Contextual_Prompt_Block::maybe_apply_override( self::PLAIN_BUTTON_BLOCK );

		$this->assertStringContainsString( 'href="https://example.com/fund-drive/"', $result, 'Stale plain button is still repointed.' );
		$this->assertStringContainsString( '>Give now</a>', $result );
	}

	/**
	 * The native donate block owns its own URL, so the override only swaps the copy
	 * and leaves the donation markup untouched.
	 */
	public function test_native_donate_override_leaves_form_untouched() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Support our reporting.' );

		$result = Newspack_Popups_Contextual_Prompt_Block::maybe_apply_override( self::NATIVE_DONATE_BLOCK );

		$this->assertStringContainsString( 'Support our reporting.', $result, 'Copy is swapped.' );
		$this->assertStringContainsString( '<form>donate form markup</form>', $result, 'Donate form is untouched.' );
	}

	/**
	 * With the override inactive, the block renders unchanged.
	 */
	public function test_inactive_override_is_a_noop() {
		// Enabled but no copy — is_override_active() treats this as inactive.
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );

		$result = Newspack_Popups_Contextual_Prompt_Block::maybe_apply_override( self::PLAIN_BUTTON_BLOCK );

		$this->assertSame( self::PLAIN_BUTTON_BLOCK, $result, 'An inactive override changes nothing.' );
	}

	/**
	 * A literal $-sequence in override copy or label must survive verbatim — the
	 * render must not expand it as a regex backreference.
	 */
	public function test_dollar_sequences_are_not_expanded() {
		$this->activate_plain_button_override( 'Give $5 today — just ${1} a week.', 'https://example.com/fund-drive/', 'Give $5' );

		$result = Newspack_Popups_Contextual_Prompt_Block::maybe_apply_override( self::PLAIN_BUTTON_BLOCK );

		$this->assertStringContainsString( 'Give $5 today — just ${1} a week.', $result, 'Copy $-sequences are literal.' );
		$this->assertStringContainsString( '>Give $5</a>', $result, 'Label $-sequences are literal.' );
	}
}
