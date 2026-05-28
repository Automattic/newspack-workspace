<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test file intentionally defines helper Integration subclasses alongside the main test class.
/**
 * Tests the Frontend Reader Registration REST endpoint.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;
use Newspack\Reader_Registration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integration;

/**
 * Test integration that supports frontend registration with default HMAC key.
 */
class Test_Frontend_Integration extends Integration {
	/**
	 * Whether this integration supports frontend registration.
	 *
	 * @return bool
	 */
	public function supports_frontend_registration(): bool {
		return true;
	}

	/**
	 * Register settings fields.
	 *
	 * @return array
	 */
	public function register_settings_fields() {
		return [];
	}

	/**
	 * Whether this integration can sync.
	 *
	 * @param bool $return_errors Whether to return errors.
	 * @return bool
	 */
	public function can_sync( $return_errors = false ) {
		return false;
	}

	/**
	 * Push contact data.
	 *
	 * @param array  $contact         Contact data.
	 * @param string $context         Context.
	 * @param array  $existing_contact Existing contact data.
	 * @return bool
	 */
	public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
		return true;
	}
}

/**
 * Test integration with custom key validation.
 * Demonstrates asymmetric key pattern: public key is output to page,
 * but validation uses a different secret key.
 */
class Test_Custom_Key_Integration extends Integration {
	/**
	 * Whether this integration supports frontend registration.
	 *
	 * @return bool
	 */
	public function supports_frontend_registration(): bool {
		return true;
	}

	/**
	 * Get the registration key (public key output to the page).
	 *
	 * @return string
	 */
	public function get_registration_key(): string {
		return 'custom-public-key';
	}

	/**
	 * Validate the registration request using a different secret key.
	 *
	 * @param string           $key     Key to validate.
	 * @param \WP_REST_Request $request The registration request.
	 * @return bool
	 */
	public function validate_registration_request( string $key, $request ): bool {
		return $key === 'custom-secret-key';
	}

	/**
	 * Register settings fields.
	 *
	 * @return array
	 */
	public function register_settings_fields() {
		return [];
	}

	/**
	 * Whether this integration can sync.
	 *
	 * @param bool $return_errors Whether to return errors.
	 * @return bool
	 */
	public function can_sync( $return_errors = false ) {
		return false;
	}

	/**
	 * Push contact data.
	 *
	 * @param array  $contact         Contact data.
	 * @param string $context         Context.
	 * @param array  $existing_contact Existing contact data.
	 * @return bool
	 */
	public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
		return true;
	}
}

/**
 * Tests the Frontend Reader Registration REST endpoint.
 *
 * @group frontend-registration
 */
class Newspack_Test_Frontend_Registration_Endpoint extends WP_UnitTestCase {
	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Route for the registration endpoint.
	 *
	 * @var string
	 */
	private static $route = '/newspack/v1/reader-activation/register';

	/**
	 * Test reader email.
	 *
	 * @var string
	 */
	private static $reader_email = 'integration-reader@test.com';

	/**
	 * Test integration ID.
	 *
	 * @var string
	 */
	private static $integration_id = 'test-integration';

	/**
	 * Register the test integration via filter.
	 *
	 * @param array $integrations Registered integrations.
	 * @return array
	 */
	public static function register_test_integration( $integrations ) {
		$integrations[ self::$integration_id ] = 'Test Integration';
		return $integrations;
	}

	/**
	 * Generate the expected HMAC key for a given integration ID.
	 *
	 * @param string $id Integration ID.
	 * @return string
	 */
	private static function generate_key( $id ) {
		return hash_hmac( 'sha256', $id, wp_salt( 'auth' ) );
	}

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		add_filter( 'newspack_frontend_registration_integrations', [ __CLASS__, 'register_test_integration' ] );
		// Ensure routes are registered — Reader_Activation::init() may have run
		// before IS_TEST_ENV was defined, skipping the rest_api_init hook.
		add_action( 'rest_api_init', [ Reader_Registration::class, 'register_routes' ] );
		do_action( 'rest_api_init' );
		wp_set_current_user( 0 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_filter( 'newspack_frontend_registration_integrations', [ __CLASS__, 'register_test_integration' ] );
		remove_action( 'rest_api_init', [ Reader_Registration::class, 'register_routes' ] );
		$user = get_user_by( 'email', self::$reader_email );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
		// Reset rate limit state.
		delete_transient( 'newspack_reg_ip_' . md5( '127.0.0.1' ) );
		wp_cache_delete( 'newspack_reg_ip_' . md5( '127.0.0.1' ), 'newspack_rate_limit' );
		// Clean up any $_POST pollution.
		unset( $_POST['g-recaptcha-response'] );
		parent::tear_down();
	}

	/**
	 * Helper to make a registration request.
	 *
	 * @param array $body Request body.
	 * @return WP_REST_Response
	 */
	private function do_register_request( $body = [] ) {
		$request = new WP_REST_Request( 'POST', self::$route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * Test successful reader registration.
	 */
	public function test_register_new_reader() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'created', $data['status'] );
		$this->assertEquals( self::$reader_email, $data['email'] );
		$this->assertInstanceOf( 'WP_User', get_user_by( 'email', self::$reader_email ) );
	}

	/**
	 * Test duplicate email returns 409.
	 */
	public function test_register_duplicate_email() {
		// Create the user directly to avoid register_reader()'s RAS-enabled check.
		self::factory()->user->create(
			[
				'user_email' => self::$reader_email,
				'role'       => 'subscriber',
			]
		);
		wp_set_current_user( 0 );

		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'reader_already_exists', $data['code'] );
	}

	/**
	 * Test missing email returns 400.
	 */
	public function test_register_missing_email() {
		$response = $this->do_register_request(
			[
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_email', $data['code'] );
	}

	/**
	 * Test invalid email returns 400.
	 */
	public function test_register_invalid_email() {
		$response = $this->do_register_request(
			[
				'npe'             => 'not-an-email',
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_email', $data['code'] );
	}

	/**
	 * Test missing integration ID returns 400.
	 */
	public function test_register_missing_integration_id() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_key' => 'anything',
			]
		);
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_integration', $data['code'] );
	}

	/**
	 * Test unregistered integration ID returns 400.
	 */
	public function test_register_unknown_integration_id() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => 'unknown-tool',
				'integration_key' => self::generate_key( 'unknown-tool' ),
			]
		);
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_integration', $data['code'] );
	}

	/**
	 * Test wrong integration key returns 403.
	 */
	public function test_register_wrong_integration_key() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => 'wrong-key',
			]
		);
		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_integration_key', $data['code'] );
	}

	/**
	 * Test honeypot field triggers fake success.
	 */
	public function test_honeypot_returns_fake_success() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'email'           => 'bot-filled@spam.com',
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		// Verify user was NOT actually created.
		$this->assertFalse( get_user_by( 'email', self::$reader_email ) );
	}

	/**
	 * Test logged-in user returns current reader data.
	 */
	public function test_register_while_logged_in() {
		$admin_id = self::factory()->user->create(
			[
				'role'       => 'administrator',
				'user_email' => 'admin@test.com',
			]
		);
		wp_set_current_user( $admin_id );

		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'existing', $data['status'] );
		$this->assertEquals( 'admin@test.com', $data['email'] );

		wp_delete_user( $admin_id );
	}

	/**
	 * Test registration with profile fields.
	 */
	public function test_register_with_profile_fields() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
				'first_name'      => 'Jane',
				'last_name'       => 'Doe',
			]
		);
		$this->assertEquals( 201, $response->get_status() );
		$user = get_user_by( 'email', self::$reader_email );
		$this->assertInstanceOf( 'WP_User', $user );
		$this->assertEquals( 'Jane', $user->first_name );
		$this->assertEquals( 'Doe', $user->last_name );
		$this->assertStringContainsString( 'Jane', $user->display_name );
	}

	/**
	 * Test registration stores the integration-based registration method.
	 */
	public function test_register_stores_registration_method() {
		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 201, $response->get_status() );
		$user = get_user_by( 'email', self::$reader_email );
		$this->assertEquals(
			'integration-registration-' . self::$integration_id,
			get_user_meta( $user->ID, Reader_Activation::REGISTRATION_METHOD, true )
		);
	}

	/**
	 * Test RAS disabled returns 403.
	 *
	 * Skipped in the test environment because Reader_Activation::is_enabled()
	 * short-circuits to true when IS_TEST_ENV is defined, bypassing the filter.
	 */
	public function test_register_when_ras_disabled() {
		if ( defined( 'IS_TEST_ENV' ) && IS_TEST_ENV ) {
			$this->markTestSkipped( 'is_enabled() always returns true when IS_TEST_ENV is defined.' );
		}

		add_filter( 'newspack_reader_activation_enabled', '__return_false' );

		$response = $this->do_register_request(
			[
				'npe'             => self::$reader_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'reader_activation_disabled', $data['code'] );

		remove_filter( 'newspack_reader_activation_enabled', '__return_false' );
	}

	/**
	 * Test per-IP rate limiting returns 429.
	 */
	public function test_rate_limit_exceeded() {
		// Lower limit to 2 for testing.
		$set_limit = function() {
			return 2;
		};
		add_filter( 'newspack_frontend_registration_rate_limit', $set_limit );

		$base_body = [
			'integration_id'  => self::$integration_id,
			'integration_key' => self::generate_key( self::$integration_id ),
		];

		// First two requests should succeed or return non-429 errors.
		// Reset current user between requests since successful registration authenticates the reader.
		$this->do_register_request( array_merge( $base_body, [ 'npe' => 'rate1@test.com' ] ) );
		wp_set_current_user( 0 );
		$this->do_register_request( array_merge( $base_body, [ 'npe' => 'rate2@test.com' ] ) );
		wp_set_current_user( 0 );

		// Third request should be rate-limited.
		$response = $this->do_register_request( array_merge( $base_body, [ 'npe' => 'rate3@test.com' ] ) );
		$this->assertEquals( 429, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'rate_limit_exceeded', $data['code'] );

		remove_filter( 'newspack_frontend_registration_rate_limit', $set_limit );

		// Clean up created users.
		foreach ( [ 'rate1@test.com', 'rate2@test.com' ] as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				wp_delete_user( $user->ID );
			}
		}
	}

	/**
	 * Test that a race condition WP_Error with "existing user" code maps to 409.
	 *
	 * Simulates a race where another request creates the user between
	 * register_reader()'s exists check and its wp_insert_user() call.
	 */
	public function test_race_condition_existing_user_returns_409() {
		$race_email = 'race-test@test.com';

		// This filter fires inside canonize_user_data(), after the exists check
		// but before wp_insert_user(). Creating the user here simulates a race.
		$create_user_during_insert = function( $user_data ) use ( $race_email ) {
			if ( ! empty( $user_data['user_email'] ) && $user_data['user_email'] === $race_email ) {
				wp_insert_user(
					[
						'user_login' => 'race-user',
						'user_email' => $race_email,
						'user_pass'  => wp_generate_password(),
						'role'       => 'subscriber',
					]
				);
			}
			return $user_data;
		};
		add_filter( 'newspack_register_reader_user_data', $create_user_during_insert );

		$response = $this->do_register_request(
			[
				'npe'             => $race_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'reader_already_exists', $data['code'] );

		remove_filter( 'newspack_register_reader_user_data', $create_user_during_insert );

		$user = get_user_by( 'email', $race_email );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
	}

	/**
	 * Test that current_page_url is normalized from the HTTP referer.
	 *
	 * The endpoint should parse the referer, extract the path, and rebuild
	 * it with home_url() — matching the process_auth_form() convention.
	 */
	public function test_current_page_url_normalization() {
		$test_email = 'referer-test@test.com';

		// Set a referer with query params and fragment that should be stripped.
		$_SERVER['HTTP_REFERER'] = home_url( '/sample-page/?foo=bar&baz=1#section' );

		$response = $this->do_register_request(
			[
				'npe'             => $test_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 201, $response->get_status() );

		$user = get_user_by( 'email', $test_email );
		$this->assertInstanceOf( 'WP_User', $user );

		$registration_page = get_user_meta( $user->ID, Reader_Activation::REGISTRATION_PAGE, true );
		// Should be normalized to just the path on the home URL, no query params.
		$this->assertEquals( home_url( '/sample-page/' ), $registration_page );

		unset( $_SERVER['HTTP_REFERER'] );
		wp_delete_user( $user->ID );
	}

	/**
	 * Test that the reCAPTCHA verify filter controls the verification attempt.
	 *
	 * When the filter returns true, the endpoint enters the verification block
	 * and calls verify_captcha(). In the test environment reCAPTCHA is not
	 * configured, so verify_captcha() short-circuits to true (passes).
	 * This test confirms the filter is respected and the $_POST bridge
	 * sets and cleans up the token correctly.
	 */
	public function test_recaptcha_filter_forces_verification() {
		$captcha_email = 'captcha-test@test.com';
		$token_value   = 'test-recaptcha-token';

		// Force reCAPTCHA verification on, regardless of configuration.
		$force_verify = function() {
			return true;
		};
		add_filter( 'newspack_recaptcha_verify_captcha', $force_verify );

		$response = $this->do_register_request(
			[
				'npe'                  => $captcha_email,
				'integration_id'       => self::$integration_id,
				'integration_key'      => self::generate_key( self::$integration_id ),
				'g-recaptcha-response' => $token_value,
			]
		);
		// verify_captcha() returns true when not configured, so registration succeeds.
		$this->assertEquals( 201, $response->get_status() );
		// Verify $_POST was cleaned up after the bridge.
		$this->assertArrayNotHasKey( 'g-recaptcha-response', $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		remove_filter( 'newspack_recaptcha_verify_captcha', $force_verify );

		$user = get_user_by( 'email', $captcha_email );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
	}

	/**
	 * Test that the reCAPTCHA verify filter can disable verification.
	 *
	 * When forced off, registration should succeed even if reCAPTCHA
	 * would otherwise be required.
	 */
	public function test_recaptcha_filter_disables_verification() {
		$disable_verify = function() {
			return false;
		};
		add_filter( 'newspack_recaptcha_verify_captcha', $disable_verify );

		$recaptcha_email = 'captcha-disabled@test.com';
		$response        = $this->do_register_request(
			[
				'npe'             => $recaptcha_email,
				'integration_id'  => self::$integration_id,
				'integration_key' => self::generate_key( self::$integration_id ),
			]
		);
		$this->assertEquals( 201, $response->get_status() );

		remove_filter( 'newspack_recaptcha_verify_captcha', $disable_verify );

		$user = get_user_by( 'email', $recaptcha_email );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
	}

	/**
	 * Test that the integration registry returns filtered integrations.
	 */
	public function test_get_frontend_registration_integrations() {
		// The test integration is registered via the filter in set_up().
		$integrations = Reader_Registration::get_frontend_registration_integrations();
		$this->assertArrayHasKey( self::$integration_id, $integrations );
		$this->assertEquals( 'Test Integration', $integrations[ self::$integration_id ] );
	}

	/**
	 * Test that the integration registry is empty without the filter.
	 */
	public function test_get_frontend_registration_integrations_empty_without_filter() {
		remove_filter( 'newspack_frontend_registration_integrations', [ __CLASS__, 'register_test_integration' ] );

		$integrations = Reader_Registration::get_frontend_registration_integrations();
		$this->assertEmpty( $integrations );

		// Re-add for tear_down consistency.
		add_filter( 'newspack_frontend_registration_integrations', [ __CLASS__, 'register_test_integration' ] );
	}

	/**
	 * Test that integration key generation is deterministic and unique per ID.
	 */
	public function test_integration_key_determinism_and_uniqueness() {
		$key_a_first  = Reader_Registration::get_frontend_registration_key( 'integration-a' );
		$key_a_second = Reader_Registration::get_frontend_registration_key( 'integration-a' );
		$key_b        = Reader_Registration::get_frontend_registration_key( 'integration-b' );

		// Same ID produces the same key.
		$this->assertEquals( $key_a_first, $key_a_second );
		// Different IDs produce different keys.
		$this->assertNotEquals( $key_a_first, $key_b );
		// Keys are 64-character hex strings (SHA-256).
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $key_a_first );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $key_b );
	}

	/**
	 * Test registration via an Integration subclass with default HMAC key.
	 */
	public function test_register_via_integration_subclass() {
		$integration = new Test_Frontend_Integration( 'subclass-test', 'Subclass Test' );
		Integrations::register( $integration );

		$response = $this->do_register_request(
			[
				'npe'             => 'subclass@test.com',
				'integration_id'  => 'subclass-test',
				'integration_key' => $integration->get_registration_key(),
			]
		);
		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$user = get_user_by( 'email', 'subclass@test.com' );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
	}

	/**
	 * Test that a custom key validation override is used.
	 */
	public function test_custom_key_validation() {
		$integration = new Test_Custom_Key_Integration( 'custom-key-test', 'Custom Key Test' );
		Integrations::register( $integration );

		// The public key (from get_registration_key) should NOT validate.
		$response = $this->do_register_request(
			[
				'npe'             => 'custom@test.com',
				'integration_id'  => 'custom-key-test',
				'integration_key' => 'custom-public-key',
			]
		);
		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_integration_key', $data['code'] );

		// The secret key should validate via the custom validate_registration_request().
		$response = $this->do_register_request(
			[
				'npe'             => 'custom@test.com',
				'integration_id'  => 'custom-key-test',
				'integration_key' => 'custom-secret-key',
			]
		);
		$this->assertEquals( 201, $response->get_status() );

		$user = get_user_by( 'email', 'custom@test.com' );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
	}

	/**
	 * Test that Integration subclass is included in get_frontend_registration_integrations().
	 */
	public function test_integration_subclass_in_registry() {
		$integration = new Test_Frontend_Integration( 'registry-test', 'Registry Test' );
		Integrations::register( $integration );

		$integrations = Reader_Registration::get_frontend_registration_integrations();
		$this->assertArrayHasKey( 'registry-test', $integrations );
		$this->assertEquals( 'Registry Test', $integrations['registry-test'] );
	}
}
