<?php
/**
 * Tests for Complianz integration methods.
 *
 * @package Newspack\Tests
 */

use Newspack\Complianz;
use Newspack\Wizards\Newspack\Privacy_Section;

/**
 * Test Complianz script-blocking and pixel-handling methods.
 */
class Newspack_Test_Complianz extends WP_UnitTestCase {

	/**
	 * Mock controlling whether Complianz cookie blocker is active.
	 *
	 * @var bool
	 */
	public static $cookie_blocker_active = false;

	/**
	 * Reset privacy options before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', false );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', false );

		// Simulate Complianz cookie blocker settings.
		if ( ! function_exists( 'cmplz_can_run_cookie_blocker' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound 
			function cmplz_can_run_cookie_blocker() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
				return Newspack_Test_Complianz::$cookie_blocker_active; // phpcs:ignore Squiz.Classes.SelfMemberReference.NotUsed
			}
		}
	}

	// -------------------------------------------------------------------------
	// extra_third_party_script_blocking
	// -------------------------------------------------------------------------

	/**
	 * Output is returned unchanged when both settings are off.
	 */
	public function test_script_blocking_passthrough_when_both_settings_off() {
		$html = '<script src="https://www.googletagmanager.com/gtag/js?id=G-123"></script>';
		$this->assertSame( $html, Complianz::extra_third_party_script_blocking( $html ) );
	}

	/**
	 * Check googletagmanager.com is blocked when block_before_consent is on.
	 */
	public function test_blocks_gtm_when_block_before_consent_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$html   = '<script src="https://www.googletagmanager.com/gtag/js?id=G-123"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringContainsString( 'data-cmplz-src=', $result );
		$this->assertStringContainsString( 'data-category="statistics"', $result );
		$this->assertStringContainsString( 'type="text/plain"', $result );
		$this->assertStringNotContainsString( ' src=', $result );
	}

	/**
	 * Check parsely.com is blocked when block_before_consent is on.
	 */
	public function test_blocks_parsely_when_block_before_consent_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$html   = '<script src="https://cdn.parsely.com/keys/example.com/p.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringContainsString( 'data-cmplz-src=', $result );
		$this->assertStringContainsString( 'data-category="statistics"', $result );
		$this->assertStringNotContainsString( ' src=', $result );
	}

	/**
	 * Check doubleclick.net is NOT blocked when only block_before_consent is on
	 * (ads require block_ads_before_consent).
	 */
	public function test_does_not_block_ads_when_only_block_before_consent_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$html   = '<script src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringNotContainsString( 'data-cmplz-src=', $result );
		$this->assertSame( $html, $result );
	}

	/**
	 * Check doubleclick.net is blocked when block_ads_before_consent is on.
	 */
	public function test_blocks_doubleclick_when_block_ads_before_consent_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', true );

		$html   = '<script src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringContainsString( 'data-cmplz-src=', $result );
		$this->assertStringContainsString( 'data-category="marketing"', $result );
		$this->assertStringNotContainsString( ' src=', $result );
	}

	/**
	 * Check doubleclick.net is NOT blocked when only block_ads_before_consent is on
	 * but block_before_consent is off (both checks required, early return fires).
	 */
	public function test_does_not_block_ads_when_only_block_ads_setting_on() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', false );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', true );

		$html   = '<script src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertStringNotContainsString( 'data-cmplz-src=', $result );
	}

	/**
	 * Scripts already processed by Complianz (data-cmplz-src present) are skipped.
	 */
	public function test_already_blocked_scripts_are_skipped() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$html   = '<script type="text/plain" data-category="statistics" data-cmplz-src="https://www.googletagmanager.com/gtag/js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		// Should be unchanged — already has data-cmplz-src, no src= to replace.
		$this->assertSame( $html, $result );
	}

	/**
	 * Unrecognised domains are not touched.
	 */
	public function test_unrecognised_domain_is_not_blocked() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', true );

		$html   = '<script src="https://example.com/script.js"></script>';
		$result = Complianz::extra_third_party_script_blocking( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * Multiple scripts in the same output are each handled correctly.
	 */
	public function test_multiple_scripts_in_output() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );
		update_option( Privacy_Section::OPTION_PREFIX . 'block_ads_before_consent', true );

		$html = implode(
			"\n",
			[
				'<script src="https://www.googletagmanager.com/gtag/js"></script>',
				'<script src="https://example.com/safe.js"></script>',
				'<script src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>',
			]
		);

		$result = Complianz::extra_third_party_script_blocking( $html );

		// GTM blocked.
		$this->assertStringContainsString( 'data-category="statistics"', $result );
		// Doubleclick blocked.
		$this->assertStringContainsString( 'data-category="marketing"', $result );
		// Safe script untouched.
		$this->assertStringContainsString( 'src="https://example.com/safe.js"', $result );
	}

	// -------------------------------------------------------------------------
	// pixel_handling_for_complianz
	// -------------------------------------------------------------------------

	/**
	 * Pixel markup is unchanged when block_before_consent is off.
	 */
	public function test_pixel_unchanged_when_block_before_consent_off() {
		$markup = '<script>fbq("track","PageView");</script>';
		$result = Complianz::pixel_handling_for_complianz( $markup );
		$this->assertSame( $markup, $result );
	}

	/**
	 * Pixel markup is unchanged when block_before_consent is on but
	 * Complianz cookie blocker is not active (cmplz_can_run_cookie_blocker absent).
	 */
	public function test_pixel_unchanged_when_complianz_cookie_blocker_not_active() {
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		// cmplz_can_run_cookie_blocker() is not defined in tests, so
		// is_complianz_with_cookie_blocker_active() returns false.
		$markup = '<script>fbq("track","PageView");</script>';
		$result = Complianz::pixel_handling_for_complianz( $markup );
		$this->assertSame( $markup, $result );
	}

	/**
	 * When block_before_consent is on AND Complianz cookie blocker is active,
	 * pixel <script> tags get type="text/plain" data-category="marketing".
	 */
	public function test_pixel_blocked_when_settings_and_complianz_active() {
		self::$cookie_blocker_active = true;
		update_option( Privacy_Section::OPTION_PREFIX . 'block_before_consent', true );

		$markup = '<script>fbq("track","PageView");</script>';
		$result = Complianz::pixel_handling_for_complianz( $markup );

		$this->assertStringContainsString( 'type="text/plain"', $result );
		$this->assertStringContainsString( 'data-category="marketing"', $result );
		self::$cookie_blocker_active = false;
	}
}
