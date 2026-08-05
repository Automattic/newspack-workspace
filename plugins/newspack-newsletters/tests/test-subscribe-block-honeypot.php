<?php
/**
 * Class Test Subscribe Block Honeypot
 *
 * @package Newspack_Newsletters
 */

/**
 * Subscribe block honeypot field.
 *
 * The honeypot is a decoy input named `email` (the real address field is `npe`).
 * A non-empty honeypot makes the form report success without subscribing anyone,
 * so a reader whose browser fills it is thanked for a subscription that was never
 * recorded — silently, on both sides.
 *
 * @group subscribe_honeypot
 */
class Subscribe_Block_Honeypot_Test extends WP_UnitTestCase {
	/**
	 * Capture the honeypot markup.
	 *
	 * @return string Rendered markup.
	 */
	private static function render() {
		ob_start();
		Newspack_Newsletters\Blocks\Subscribe\render_honeypot_field();
		return ob_get_clean();
	}

	/**
	 * Browsers fill a field that renders and skip one that doesn't.
	 *
	 * A clipped or 1px-square field still renders, so hiding the decoy that way
	 * leaves it an autofill target. `display:none` is what takes it off the table,
	 * and it belongs inline: a stylesheet rule is also stripped or deferred by
	 * optimization plugins, which would put the decoy back on the page in view of
	 * a reader.
	 */
	public function test_honeypot_is_hidden_from_autofill_and_from_readers() {
		$this->assertMatchesRegularExpression(
			'/style="[^"]*display\s*:\s*none/i',
			self::render(),
			'The honeypot must carry an inline display:none so browsers skip it and a missing stylesheet cannot expose it.'
		);
	}

	/**
	 * The honeypot must opt out of password-manager autofill.
	 *
	 * `autocomplete="off"` is advisory and password managers routinely ignore it on
	 * address fields. Each manager that publishes an opt-out honours its own attribute.
	 */
	public function test_honeypot_opts_out_of_password_manager_autofill() {
		$markup = self::render();
		$this->assertStringContainsString( 'data-1p-ignore', $markup, 'The honeypot must opt out of 1Password autofill.' );
		$this->assertStringContainsString( 'data-lpignore="true"', $markup, 'The honeypot must opt out of LastPass autofill.' );
		$this->assertStringContainsString( 'data-form-type="other"', $markup, 'The honeypot must opt out of Dashlane autofill.' );
	}

	/**
	 * The decoy must keep the shape bots look for.
	 *
	 * The trap only works because the field looks like the address field a bot
	 * expects to fill, so closing it to autofill must not change its name or type.
	 */
	public function test_honeypot_still_looks_like_an_email_field_to_bots() {
		$markup = self::render();
		$this->assertStringContainsString( 'name="email"', $markup, 'The honeypot must stay named `email`.' );
		$this->assertStringContainsString( 'type="email"', $markup, 'The honeypot must stay an email input.' );
	}
}
