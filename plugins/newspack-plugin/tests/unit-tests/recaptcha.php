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

	/**
	 * Mock Google's response, passing only the token "good".
	 */
	private function mock_siteverify() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				return [
					'body'     => wp_json_encode(
						[
							'success' => false !== strpos( $url, 'response=good' ),
							'score'   => 0.9,
						]
					),
					'response' => [ 'code' => 200 ],
				];
			},
			10,
			3
		);
	}

	/**
	 * With no argument the token comes from the form, as every existing caller expects.
	 */
	public function test_verify_captcha_defaults_to_the_form_token() {
		$this->enable_recaptcha();
		$this->mock_siteverify();
		$_POST['g-recaptcha-response'] = 'good';

		$result = Recaptcha::verify_captcha();
		unset( $_POST['g-recaptcha-response'] );

		$this->assertTrue( $result );
	}

	/**
	 * The token travels in the siteverify query string, so it must be URL-encoded there.
	 */
	public function test_verify_captcha_encodes_the_token_for_siteverify() {
		$this->enable_recaptcha();
		$seen = '';
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$seen ) {
				$seen = $url;
				return [
					'body'     => wp_json_encode(
						[
							'success' => true,
							'score'   => 0.9,
						]
					),
					'response' => [ 'code' => 200 ],
				];
			},
			10,
			3
		);

		Recaptcha::verify_captcha( 'a b&response=good' );

		$this->assertStringContainsString( 'response=a%20b%26response%3Dgood', $seen );
	}

	/**
	 * A token passed in is the only one judged, even when empty.
	 */
	public function test_verify_captcha_prefers_the_given_token() {
		$this->enable_recaptcha();
		$this->mock_siteverify();
		$_POST['g-recaptcha-response'] = 'good';

		$given_bad   = Recaptcha::verify_captcha( 'bad' );
		$given_empty = Recaptcha::verify_captcha( '' );
		unset( $_POST['g-recaptcha-response'] );

		$this->assertWPError( $given_bad );
		$this->assertWPError( $given_empty );
		$this->assertTrue( Recaptcha::verify_captcha( 'good' ) );
	}

	/**
	 * The Checkout block script loads only where the server will ask for its token.
	 */
	public function test_checkout_block_script_follows_the_server_decision() {
		$this->enable_recaptcha();
		do_action( 'wp_enqueue_scripts' );

		add_filter( 'newspack_recaptcha_verify_captcha', '__return_false' );
		Recaptcha::enqueue_checkout_block_script();
		remove_filter( 'newspack_recaptcha_verify_captcha', '__return_false' );
		$this->assertFalse( wp_script_is( Recaptcha::CHECKOUT_BLOCK_SCRIPT_HANDLE, 'enqueued' ) );

		Recaptcha::enqueue_checkout_block_script();
		$this->assertTrue( wp_script_is( Recaptcha::CHECKOUT_BLOCK_SCRIPT_HANDLE, 'enqueued' ) );
	}
}
