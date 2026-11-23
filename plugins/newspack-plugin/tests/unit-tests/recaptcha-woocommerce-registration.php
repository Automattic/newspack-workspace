<?php
/**
 * Tests for reCAPTCHA on WooCommerce's My Account registration form.
 *
 * @package Newspack\Tests
 */

use Newspack\Recaptcha;

/**
 * WooCommerce's registration handler creates the account unless
 * `woocommerce_process_registration_errors` returns an error, so a refused
 * registration must add its error there.
 *
 * @group recaptcha
 */
class Test_Recaptcha_WooCommerce_Registration extends WP_UnitTestCase {
	const LOGIN = 'np_wc_register_test';
	const EMAIL = 'np-wc-register-test@example.test';
	const TOKEN = 'test-recaptcha-token';

	/**
	 * Response the mocked siteverify endpoint returns: an array (sent as the JSON body) or a WP_Error.
	 *
	 * @var array|WP_Error
	 */
	private $siteverify = [ 'success' => true ];

	/**
	 * Number of requests made to the siteverify endpoint.
	 *
	 * @var int
	 */
	private $siteverify_calls = 0;

	/**
	 * Start each test with reCAPTCHA off, no token and a mocked siteverify endpoint.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( [ 'use_captcha', 'version', 'credentials', 'threshold', 'site_key', 'site_secret' ] as $key ) {
			delete_option( 'newspack_recaptcha_' . $key );
		}
		unset( $_POST['g-recaptcha-response'] );
		$this->siteverify       = [ 'success' => true ];
		$this->siteverify_calls = 0;
		add_filter( 'pre_http_request', [ $this, 'mock_siteverify' ], 10, 3 );
	}

	/**
	 * Clear the submitted token.
	 */
	public function tear_down() {
		unset( $_POST['g-recaptcha-response'] );
		parent::tear_down();
	}

	/**
	 * Answer requests to Google's siteverify endpoint without leaving the test.
	 *
	 * @param false|array|WP_Error $pre  Preempted response.
	 * @param array                $args Request arguments.
	 * @param string               $url  Request URL.
	 * @return false|array|WP_Error
	 */
	public function mock_siteverify( $pre, $args, $url ) {
		if ( false === strpos( $url, 'recaptcha/api/siteverify' ) ) {
			return $pre;
		}
		++$this->siteverify_calls;
		if ( is_wp_error( $this->siteverify ) ) {
			return $this->siteverify;
		}
		return [
			'headers'  => [],
			'body'     => wp_json_encode( $this->siteverify ),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * Turn reCAPTCHA on with keys for the given version only.
	 *
	 * @param string $version 'v3' or 'v2_invisible'.
	 */
	private function enable( $version ) {
		$credentials = [
			'v3'           => [
				'site_key'    => '',
				'site_secret' => '',
			],
			'v2_invisible' => [
				'site_key'    => '',
				'site_secret' => '',
			],
		];
		$credentials[ $version ] = [
			'site_key'    => 'test_site_key',
			'site_secret' => 'test_site_secret',
		];
		update_option( 'newspack_recaptcha_use_captcha', true );
		update_option( 'newspack_recaptcha_version', $version );
		update_option( 'newspack_recaptcha_credentials', $credentials );
	}

	/**
	 * Submit a registration the way WooCommerce's My Account handler does.
	 *
	 * WC_Form_Handler::process_registration() applies
	 * `woocommerce_process_registration_errors` to a fresh WP_Error and creates
	 * the customer only when no error comes back. WooCommerce is not loaded in
	 * this suite, so wp_create_user() stands in for wc_create_new_customer().
	 *
	 * @param WP_Error|null $errors Errors other validators found before reCAPTCHA's check.
	 * @return int|WP_Error New user ID, or the errors that refused it.
	 */
	private function register( $errors = null ) {
		$errors = apply_filters( 'woocommerce_process_registration_errors', $errors ?? new WP_Error(), '', '', self::EMAIL );
		if ( $errors->has_errors() ) {
			return $errors;
		}
		return wp_create_user( self::LOGIN, wp_generate_password(), self::EMAIL );
	}

	/**
	 * Assert the registration was refused with the given error code.
	 *
	 * @param mixed  $result Return value of register().
	 * @param string $code   Expected error code.
	 */
	private function assert_refused( $result, $code ) {
		$this->assertWPError( $result, 'Registration should be refused.' );
		$this->assertContains( $code, $result->get_error_codes() );
	}

	/**
	 * Assert the registration went through.
	 *
	 * @param mixed $result Return value of register().
	 */
	private function assert_allowed( $result ) {
		$this->assertIsInt( $result, 'Registration should succeed.' );
		$this->assertSame( $result, email_exists( self::EMAIL ) );
	}

	/**
	 * The reCAPTCHA versions the form supports.
	 *
	 * @return array
	 */
	public function data_versions() {
		return [
			'v3'           => [ 'v3' ],
			'v2_invisible' => [ 'v2_invisible' ],
		];
	}

	/**
	 * A bot posting the form without a token is refused before Google is asked.
	 *
	 * @dataProvider data_versions
	 *
	 * @param string $version reCAPTCHA version.
	 */
	public function test_refuses_registration_without_token( $version ) {
		$this->enable( $version );

		$this->assert_refused( $this->register(), 'newspack_recaptcha_invalid_token' );
		$this->assertSame( 0, $this->siteverify_calls );
	}

	/**
	 * Tokens Google accepts, per version.
	 *
	 * @return array
	 */
	public function data_valid_tokens() {
		return [
			'v3 score above the threshold' => [
				'v3',
				[
					'success' => true,
					'score'   => 0.9,
				],
			],
			'v2_invisible'                 => [ 'v2_invisible', [ 'success' => true ] ],
		];
	}

	/**
	 * A person whose token Google accepts is registered.
	 *
	 * @dataProvider data_valid_tokens
	 *
	 * @param string $version    reCAPTCHA version.
	 * @param array  $siteverify Google's response.
	 */
	public function test_allows_registration_with_valid_token( $version, $siteverify ) {
		$this->enable( $version );
		update_option( 'newspack_recaptcha_threshold', 0.5 );
		$this->siteverify              = $siteverify;
		$_POST['g-recaptcha-response'] = self::TOKEN;

		$this->assert_allowed( $this->register() );
		$this->assertSame( 1, $this->siteverify_calls );
	}

	/**
	 * Ways verification fails after a token is sent, and the error each one leaves.
	 *
	 * @return array
	 */
	public function data_failed_verifications() {
		return [
			'Google rejects the token'  => [
				[
					'success'     => false,
					'error-codes' => [ 'invalid-input-response' ],
				],
				'newspack_recaptcha_error',
			],
			'score below the threshold' => [
				[
					'success' => true,
					'score'   => 0.3,
				],
				'newspack_recaptcha_failure',
			],
			'Google unreachable'        => [
				new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
				'http_request_failed',
			],
		];
	}

	/**
	 * A token Google rejects, a low v3 score, or no answer from Google all refuse the registration.
	 *
	 * @dataProvider data_failed_verifications
	 *
	 * @param array|WP_Error $siteverify Google's response.
	 * @param string         $code       Expected error code.
	 */
	public function test_refuses_registration_when_verification_fails( $siteverify, $code ) {
		$this->enable( 'v3' );
		update_option( 'newspack_recaptcha_threshold', 0.5 );
		$this->siteverify              = $siteverify;
		$_POST['g-recaptcha-response'] = self::TOKEN;

		$this->assert_refused( $this->register(), $code );
	}

	/**
	 * Errors other validators already found stay alongside reCAPTCHA's.
	 */
	public function test_keeps_other_errors_when_refusing() {
		$this->enable( 'v3' );
		$errors = new WP_Error( 'other_validator_error', 'Please fill in the required field.' );

		$result = $this->register( $errors );

		$this->assert_refused( $result, 'newspack_recaptcha_invalid_token' );
		$this->assertContains( 'other_validator_error', $result->get_error_codes() );
	}

	/**
	 * Settings under which reCAPTCHA can't run: switched off, or switched on with no keys stored.
	 *
	 * @return array
	 */
	public function data_recaptcha_unavailable() {
		return [
			'reCAPTCHA off'         => [ false ],
			'reCAPTCHA on, no keys' => [ true ],
		];
	}

	/**
	 * While reCAPTCHA can't run, registration is not checked and nothing is sent to Google.
	 *
	 * @dataProvider data_recaptcha_unavailable
	 *
	 * @param bool $use_captcha Whether reCAPTCHA is switched on.
	 */
	public function test_registration_not_checked_while_recaptcha_unavailable( $use_captcha ) {
		if ( $use_captcha ) {
			update_option( 'newspack_recaptcha_use_captcha', true );
		}

		$this->assert_allowed( $this->register() );
		$this->assertSame( 0, $this->siteverify_calls );
	}

	/**
	 * The shared verify filter sees this form's context, and can turn the check off.
	 */
	public function test_verify_filter_receives_woocommerce_register_context() {
		$this->enable( 'v3' );
		$seen     = [];
		$callback = function ( $should_verify, $url, $context ) use ( &$seen ) {
			$seen[] = $context;
			return false;
		};
		add_filter( 'newspack_recaptcha_verify_captcha', $callback, 10, 3 );

		$result = $this->register();

		remove_filter( 'newspack_recaptcha_verify_captcha', $callback, 10 );
		$this->assertSame( [ 'woocommerce_register' ], $seen );
		$this->assert_allowed( $result );
	}

	/**
	 * Newspack's own sign-up and checkout create customers through wc_create_new_customer(),
	 * which applies `woocommerce_registration_errors`. They have checks of their own and must
	 * not be refused here.
	 */
	public function test_does_not_check_other_customer_creation() {
		$this->enable( 'v3' );

		$errors = apply_filters( 'woocommerce_registration_errors', new WP_Error(), self::LOGIN, self::EMAIL );

		$this->assertFalse( $errors->has_errors() );
		$this->assertSame( 0, $this->siteverify_calls );
	}

	/**
	 * The form is marked for the reCAPTCHA client from inside it, and shows Google's notice.
	 *
	 * @dataProvider data_versions
	 *
	 * @param string $version reCAPTCHA version.
	 */
	public function test_register_form_marks_form_and_prints_terms( $version ) {
		$this->enable( $version );

		ob_start();
		do_action( 'woocommerce_register_form' );
		$html = ob_get_clean();

		$this->assertStringContainsString( '<script', $html );
		$this->assertStringContainsString( "closest( 'form' ).setAttribute( 'data-newspack-recaptcha', 'woocommerce_register' )", $html );
		$this->assertStringContainsString( 'https://policies.google.com/privacy', $html );
	}

	/**
	 * On v3, a submission that arrives before the form has a token waits while Google is asked for one.
	 */
	public function test_register_form_holds_early_submit_for_v3_token() {
		$this->enable( 'v3' );

		ob_start();
		do_action( 'woocommerce_register_form' );
		$html = ob_get_clean();

		$this->assertStringContainsString( "grecaptcha.execute( siteKey, { action: 'woocommerce_register' } )", $html );
		$this->assertStringContainsString( "'test_site_key'", $html );
		$this->assertStringContainsString( 'form.requestSubmit( submitter )', $html );
	}

	/**
	 * On v2 invisible, the shared client already holds the submission until the challenge passes.
	 */
	public function test_register_form_leaves_v2_submit_to_the_shared_client() {
		$this->enable( 'v2_invisible' );

		ob_start();
		do_action( 'woocommerce_register_form' );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'grecaptcha.execute', $html );
	}

	/**
	 * With reCAPTCHA off, nothing is added to the form.
	 */
	public function test_register_form_adds_nothing_when_recaptcha_off() {
		ob_start();
		do_action( 'woocommerce_register_form' );
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
}
