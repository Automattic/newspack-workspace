<?php
/**
 * Tests for reCAPTCHA on WordPress's own registration form (wp-login.php?action=register).
 *
 * @package Newspack\Tests
 */

use Newspack\Recaptcha;

/**
 * A refused registration must stop inside `registration_errors`, before
 * register_new_user() creates the account or sends its email.
 *
 * @group recaptcha
 */
class Test_Recaptcha_WP_Registration extends WP_UnitTestCase {
	const LOGIN = 'np_register_test';
	const EMAIL = 'np-register-test@example.test';
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
	 * The script registry from before the test, restored afterwards.
	 *
	 * @var WP_Scripts|null
	 */
	private $original_wp_scripts;

	/**
	 * The style registry from before the test, restored afterwards.
	 *
	 * @var WP_Styles|null
	 */
	private $original_wp_styles;

	/**
	 * Start each test with reCAPTCHA off, no token, an empty mailbox, empty script and style queues and a mocked siteverify endpoint.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( [ 'use_captcha', 'version', 'credentials', 'threshold', 'site_key', 'site_secret' ] as $key ) {
			delete_option( 'newspack_recaptcha_' . $key );
		}
		unset( $_POST['g-recaptcha-response'] );
		reset_phpmailer_instance();
		// Other tests leave scripts and styles queued, and wp_script_is() counts their dependencies (jQuery among them).
		// Fresh registries hold only core's defaults, so the queues reflect this test alone.
		$this->original_wp_scripts = $GLOBALS['wp_scripts'] ?? null;
		$GLOBALS['wp_scripts']     = new WP_Scripts();
		$this->original_wp_styles  = $GLOBALS['wp_styles'] ?? null;
		$GLOBALS['wp_styles']      = new WP_Styles();
		// Read by the TEC Community Events mock when another test file has loaded it.
		$GLOBALS['newspack_test_is_tec_community_page'] = false;
		$this->siteverify       = [ 'success' => true ];
		$this->siteverify_calls = 0;
		add_filter( 'pre_http_request', [ $this, 'mock_siteverify' ], 10, 3 );
	}

	/**
	 * Clear the submitted token and put the original script and style registries back.
	 */
	public function tear_down() {
		unset( $_POST['g-recaptcha-response'] );
		$GLOBALS['wp_scripts'] = $this->original_wp_scripts;
		$GLOBALS['wp_styles']  = $this->original_wp_styles;
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
	 * Submit the registration form the way wp-login.php?action=register does.
	 *
	 * @param string $login Username.
	 * @param string $email Email address.
	 * @return int|WP_Error New user ID, or the errors that refused it.
	 */
	private function register( $login = self::LOGIN, $email = self::EMAIL ) {
		do_action( 'login_form_register' );
		return register_new_user( $login, $email );
	}

	/**
	 * Number of emails sent since set_up().
	 *
	 * @return int
	 */
	private function mails() {
		return count( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * Assert the registration was refused with the given error code, and left no account or email behind.
	 *
	 * @param mixed  $result Return value of register_new_user().
	 * @param string $code   Expected error code.
	 */
	private function assert_refused( $result, $code ) {
		$this->assertWPError( $result, 'Registration should be refused.' );
		$this->assertContains( $code, $result->get_error_codes() );
		$this->assertFalse( email_exists( self::EMAIL ), 'No account should be created.' );
		$this->assertSame( 0, $this->mails(), 'No email should be sent.' );
	}

	/**
	 * Assert the registration went through: an account exists and its email went out.
	 *
	 * @param mixed $result Return value of register_new_user().
	 */
	private function assert_allowed( $result ) {
		$this->assertIsInt( $result, 'Registration should succeed.' );
		$this->assertSame( $result, email_exists( self::EMAIL ) );
		$this->assertGreaterThan( 0, $this->mails(), 'The new-user email should be sent.' );
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
	 * Passing siteverify responses, by version.
	 *
	 * @return array
	 */
	public function data_passing_responses() {
		return [
			'v3 score above the threshold' => [
				'v3',
				[
					'success' => true,
					'score'   => 0.9,
				],
			],
			'v2_invisible solved'          => [ 'v2_invisible', [ 'success' => true ] ],
		];
	}

	/**
	 * A person whose token Google accepts registers as before.
	 *
	 * @dataProvider data_passing_responses
	 *
	 * @param string $version    reCAPTCHA version.
	 * @param array  $siteverify Google's response.
	 */
	public function test_allows_registration_with_valid_token( $version, $siteverify ) {
		$this->enable( $version );
		$_POST['g-recaptcha-response'] = self::TOKEN;
		$this->siteverify              = $siteverify;

		$this->assert_allowed( $this->register() );
		$this->assertSame( 1, $this->siteverify_calls );
	}

	/**
	 * Failed verifications, with the error code each refusal carries.
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
			// Fails closed: an outage refuses sign-ups rather than letting them through.
			'Google cannot be reached'  => [
				new WP_Error( 'http_request_failed', 'Could not connect.' ),
				'http_request_failed',
			],
		];
	}

	/**
	 * Any failed verification refuses the registration, with no account and no email.
	 *
	 * @dataProvider data_failed_verifications
	 *
	 * @param array|WP_Error $siteverify Google's response.
	 * @param string         $code       Expected error code.
	 */
	public function test_refuses_registration_when_verification_fails( $siteverify, $code ) {
		$this->enable( 'v3' );
		update_option( 'newspack_recaptcha_threshold', 0.5 );
		$_POST['g-recaptcha-response'] = self::TOKEN;
		$this->siteverify              = $siteverify;

		$this->assert_refused( $this->register(), $code );
		$this->assertSame( 1, $this->siteverify_calls );
	}

	/**
	 * The reCAPTCHA error is added to WordPress's own errors, not swapped in for them.
	 */
	public function test_keeps_core_errors_when_refusing() {
		$this->enable( 'v3' );

		$result = $this->register( '' );

		$this->assertWPError( $result );
		$this->assertContains( 'empty_username', $result->get_error_codes() );
		$this->assertContains( 'newspack_recaptcha_invalid_token', $result->get_error_codes() );
	}

	/**
	 * With reCAPTCHA off, the form works as it does without this plugin.
	 */
	public function test_registration_unchanged_when_recaptcha_off() {
		$this->assert_allowed( $this->register() );
		$this->assertSame( 0, $this->siteverify_calls );
	}

	/**
	 * Code that calls register_new_user() outside the registration screen has no form to solve, so it is not checked.
	 */
	public function test_does_not_verify_outside_registration_screen() {
		$this->enable( 'v3' );

		$this->assert_allowed( register_new_user( self::LOGIN, self::EMAIL ) );
		$this->assertSame( 0, $this->siteverify_calls );
	}

	/**
	 * Sites can skip the check for this form through the shared filter, which names the form's context.
	 */
	public function test_verify_filter_receives_wp_register_context() {
		$this->enable( 'v3' );
		$seen = [];
		add_filter(
			'newspack_recaptcha_verify_captcha',
			function ( $should_verify, $url, $context ) use ( &$seen ) {
				$seen = [ $url, $context ];
				return false;
			},
			10,
			3
		);

		$this->assert_allowed( $this->register() );
		$this->assertSame( [ wp_registration_url(), 'wp_register' ], $seen );
	}

	/**
	 * Output of the `register_form` action, which core fires inside #registerform.
	 *
	 * @return string
	 */
	private function register_form_output() {
		ob_start();
		do_action( 'register_form' );
		return ob_get_clean();
	}

	/**
	 * The form is marked for the reCAPTCHA client, and carries the notice Google requires when its badge is hidden.
	 */
	public function test_register_form_marks_form_and_prints_terms() {
		$this->enable( 'v3' );
		do_action( 'login_form_register' );

		$output = $this->register_form_output();

		$this->assertMatchesRegularExpression( "/<script\b[^>]*>[^<]*setAttribute\(\s*'data-newspack-recaptcha',\s*'wp_register'\s*\)/", $output );
		$this->assertStringContainsString( 'This site is protected by reCAPTCHA', $output );
	}

	/**
	 * The registration screen loads the client and what it needs that the login screens don't load:
	 * Google's API, jQuery, and the plugin's shared webpack chunk, which the client's bundle waits on whenever it
	 * shares a module with another script. The client keeps its own stylesheet; the chunk's stylesheets style
	 * nothing on this screen, so they stay off it.
	 */
	public function test_register_screen_enqueues_client() {
		$this->enable( 'v2_invisible' );
		do_action( 'login_form_register' );
		do_action( 'login_enqueue_scripts' );

		$this->assertTrue( wp_script_is( 'newspack-recaptcha', 'enqueued' ), 'The reCAPTCHA client should load.' );
		$this->assertTrue( wp_script_is( 'newspack-recaptcha-api', 'enqueued' ), "Google's API should load." );
		$this->assertTrue( wp_script_is( 'jquery', 'enqueued' ), 'jQuery should load for the client.' );
		$this->assertTrue( wp_script_is( 'newspack_commons', 'enqueued' ), 'The shared chunk should load for the client.' );
		$this->assertTrue( wp_style_is( 'newspack-recaptcha', 'enqueued' ), "The client's stylesheet should still load." );
		$this->assertFalse( wp_style_is( 'newspack-commons', 'enqueued' ), "The shared chunk's stylesheet should not load." );
		$this->assertFalse( wp_style_is( 'wp-components', 'enqueued' ), "The shared chunk's stylesheet dependency should not load." );
	}

	/**
	 * Settings under which reCAPTCHA can't be used.
	 *
	 * @return array
	 */
	public function data_unusable_settings() {
		return [
			'off'                      => [ false ],
			// Switched on but missing its keys counts as off.
			'switched on without keys' => [ true ],
		];
	}

	/**
	 * With reCAPTCHA off, the registration screen gets no extra markup or scripts.
	 *
	 * @dataProvider data_unusable_settings
	 *
	 * @param bool $switched_on Whether the reCAPTCHA setting is on.
	 */
	public function test_register_screen_unchanged_when_recaptcha_off( $switched_on ) {
		if ( $switched_on ) {
			update_option( 'newspack_recaptcha_use_captcha', true );
			update_option( 'newspack_recaptcha_version', 'v3' );
		}
		do_action( 'login_form_register' );
		do_action( 'login_enqueue_scripts' );

		$this->assertSame( '', $this->register_form_output() );
		$this->assertFalse( wp_script_is( 'newspack-recaptcha', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'jquery', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'newspack_commons', 'enqueued' ) );
	}

	/**
	 * The login screen doesn't load Google's script, since nothing there is checked.
	 */
	public function test_login_screen_gets_no_client() {
		$this->enable( 'v3' );
		do_action( 'login_form_login' );
		do_action( 'login_enqueue_scripts' );

		$this->assertFalse( wp_script_is( 'newspack-recaptcha', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'newspack-recaptcha-api', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'newspack_commons', 'enqueued' ) );
	}
}
