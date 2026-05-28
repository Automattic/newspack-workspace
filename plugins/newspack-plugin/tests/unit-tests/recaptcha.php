<?php
/**
 * Tests for the Recaptcha class.
 *
 * @package Newspack\Tests
 */

use Newspack\Recaptcha;

require_once NEWSPACK_ABSPATH . 'tests/mocks/tec-community-events-mocks.php';

/**
 * Class Test_Recaptcha
 */
class Test_Recaptcha extends WP_UnitTestCase {
	/**
	 * Reset options, enqueued scripts, and the TEC page global before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'newspack_recaptcha_use_captcha' );
		delete_option( 'newspack_recaptcha_version' );
		delete_option( 'newspack_recaptcha_credentials' );
		wp_dequeue_script( 'newspack-recaptcha' );
		wp_dequeue_script( 'newspack-recaptcha-api' );
		wp_deregister_script( 'newspack-recaptcha' );
		wp_deregister_script( 'newspack-recaptcha-api' );
		wp_dequeue_style( 'newspack-recaptcha' );
		wp_deregister_style( 'newspack-recaptcha' );
		$GLOBALS['newspack_test_is_tec_community_page'] = false;
	}

	/**
	 * Configure valid reCAPTCHA v3 credentials so can_use_captcha() returns true.
	 */
	private function enable_recaptcha() {
		update_option( 'newspack_recaptcha_use_captcha', true );
		update_option( 'newspack_recaptcha_version', 'v3' );
		update_option(
			'newspack_recaptcha_credentials',
			[
				'v3'           => [
					'site_key'    => 'test_key',
					'site_secret' => 'test_secret',
				],
				'v2_invisible' => [
					'site_key'    => '',
					'site_secret' => '',
				],
			]
		);
	}

	/**
	 * Normal front-end pages should enqueue Google's reCAPTCHA api.js.
	 */
	public function test_enqueues_api_script_on_normal_page() {
		$this->enable_recaptcha();
		$GLOBALS['newspack_test_is_tec_community_page'] = false;

		Recaptcha::register_scripts();

		$this->assertTrue(
			wp_script_is( 'newspack-recaptcha-api', 'enqueued' ),
			'reCAPTCHA api.js should be enqueued on a normal page when reCAPTCHA is enabled.'
		);
	}

	/**
	 * The TEC Community Events submission page should bail out and not enqueue api.js.
	 */
	public function test_skips_api_script_on_tec_community_page() {
		$this->enable_recaptcha();
		$GLOBALS['newspack_test_is_tec_community_page'] = true;

		Recaptcha::register_scripts();

		$this->assertFalse(
			wp_script_is( 'newspack-recaptcha-api', 'enqueued' ),
			'reCAPTCHA api.js should NOT be enqueued on TEC Community Events submission pages.'
		);
	}

	/**
	 * When reCAPTCHA is disabled, api.js should never be enqueued regardless of page.
	 */
	public function test_does_not_enqueue_when_recaptcha_disabled() {
		delete_option( 'newspack_recaptcha_use_captcha' );
		$GLOBALS['newspack_test_is_tec_community_page'] = false;

		Recaptcha::register_scripts();

		$this->assertFalse(
			wp_script_is( 'newspack-recaptcha-api', 'enqueued' ),
			'reCAPTCHA api.js should not be enqueued when reCAPTCHA is disabled.'
		);
	}
}
