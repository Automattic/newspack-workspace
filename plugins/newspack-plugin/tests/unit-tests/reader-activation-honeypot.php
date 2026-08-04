<?php
/**
 * Tests the reader auth honeypot field.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;

/**
 * Tests the reader auth honeypot field.
 *
 * The honeypot is a decoy input named `email` (the real address field is `npe`).
 * A non-empty honeypot makes the server return a fake "you're signed in" response
 * without creating a session, so anything that fills it on a reader's behalf leaves
 * that reader looking signed in while every subsequent page load says otherwise.
 * That half of the contract — a filled honeypot returns a fake success — is covered
 * by `Newspack_Test_Frontend_Registration_Endpoint::test_honeypot_returns_fake_success`;
 * loosening either half needs a look at the other.
 *
 * @group auth-honeypot
 */
class Newspack_Test_Reader_Activation_Honeypot extends WP_UnitTestCase {
	/**
	 * Restore reCAPTCHA settings touched by individual tests.
	 */
	public function tear_down() {
		delete_option( 'newspack_recaptcha_use_captcha' );
		delete_option( 'newspack_recaptcha_credentials' );
		delete_option( 'newspack_recaptcha_version' );
		parent::tear_down();
	}

	/**
	 * Capture the honeypot markup.
	 *
	 * @return string Rendered markup.
	 */
	private static function render() {
		ob_start();
		Reader_Activation::render_honeypot_field();
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
	 * An empty decoy is the normal case and never a bot.
	 */
	public function test_empty_honeypot_is_not_tripped() {
		$this->assertFalse( Reader_Activation::is_honeypot_tripped( '', 'reader@example.test' ) );
		$this->assertFalse( Reader_Activation::is_honeypot_tripped( null, 'reader@example.test' ) );
	}

	/**
	 * A decoy matching the real field is autofill, not a bot.
	 *
	 * This is the second line of defence behind the markup: a browser fills both
	 * fields from one profile entry, so identical values are the autofill signature.
	 */
	public function test_honeypot_matching_the_real_email_is_not_tripped() {
		$this->assertFalse( Reader_Activation::is_honeypot_tripped( 'reader@example.test', 'reader@example.test' ) );
	}

	/**
	 * Matching is insensitive to case and surrounding whitespace.
	 */
	public function test_honeypot_match_ignores_case_and_whitespace() {
		$this->assertFalse( Reader_Activation::is_honeypot_tripped( ' Reader@Example.test ', 'reader@example.test' ) );
	}

	/**
	 * A decoy that differs from the real field is still a bot.
	 */
	public function test_honeypot_differing_from_the_real_email_is_tripped() {
		$this->assertTrue( Reader_Activation::is_honeypot_tripped( 'bot@spam.test', 'reader@example.test' ) );
	}

	/**
	 * A decoy filled with no real address is still a bot.
	 *
	 * Filling only the decoy is the behaviour the trap was built for, and nothing
	 * about autofill produces it.
	 */
	public function test_honeypot_without_a_real_email_is_tripped() {
		$this->assertTrue( Reader_Activation::is_honeypot_tripped( 'bot@spam.test', '' ) );
	}

	/**
	 * The honeypot gives way to reCAPTCHA, which supersedes it.
	 */
	public function test_honeypot_is_not_rendered_when_recaptcha_is_active() {
		update_option( 'newspack_recaptcha_use_captcha', true );
		update_option(
			'newspack_recaptcha_credentials',
			[
				'v3' => [
					'site_key'    => 'site-key',
					'site_secret' => 'site-secret',
				],
			]
		);
		update_option( 'newspack_recaptcha_version', 'v3' );

		$this->assertSame( '', self::render(), 'The honeypot must not render while reCAPTCHA is active.' );
	}
}
