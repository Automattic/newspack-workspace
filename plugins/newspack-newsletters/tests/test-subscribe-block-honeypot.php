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

	/**
	 * Call the submit-side predicate.
	 *
	 * @param mixed $honeypot Decoy value.
	 * @param mixed $email    Real address value.
	 *
	 * @return bool
	 */
	private static function tripped( $honeypot, $email ) {
		return Newspack_Newsletters\Blocks\Subscribe\is_honeypot_tripped( $honeypot, $email );
	}

	/**
	 * An empty decoy is the normal case and never a bot.
	 */
	public function test_empty_honeypot_is_not_tripped() {
		$this->assertFalse( self::tripped( '', 'reader@example.test' ) );
		$this->assertFalse( self::tripped( null, 'reader@example.test' ) );
	}

	/**
	 * A decoy matching the real field is autofill, not a bot.
	 */
	public function test_honeypot_matching_the_real_email_is_not_tripped() {
		$this->assertFalse( self::tripped( 'reader@example.test', 'reader@example.test' ) );
		$this->assertFalse( self::tripped( ' Reader@Example.test ', 'reader@example.test' ) );
	}

	/**
	 * A decoy that differs from the real field is still a bot.
	 */
	public function test_honeypot_differing_from_the_real_email_is_tripped() {
		$this->assertTrue( self::tripped( 'bot@spam.test', 'reader@example.test' ) );
		$this->assertTrue( self::tripped( 'bot@spam.test', '' ) );
	}

	/**
	 * A decoy filled with something that isn't an address is still a bot.
	 *
	 * This is the case that regressed once already: email-sanitizing the decoy before
	 * testing whether it was filled blanks every non-address value, which is most of
	 * what bots put in it.
	 */
	public function test_non_email_honeypot_is_tripped() {
		$this->assertTrue( self::tripped( 'buy-cheap-pills', 'reader@example.test' ) );
		$this->assertTrue( self::tripped( 'John Smith', 'reader@example.test' ) );
		$this->assertTrue( self::tripped( 'https://spam.test', 'reader@example.test' ) );
		$this->assertTrue( self::tripped( '0', 'reader@example.test' ) );
	}

	/**
	 * Non-scalar input is not a filled decoy.
	 */
	public function test_non_scalar_input_is_handled() {
		$this->assertFalse( self::tripped( [ 'bot@spam.test' ], 'reader@example.test' ) );
		$this->assertTrue( self::tripped( 'bot@spam.test', [ 'reader@example.test' ] ) );
	}
}
