<?php
/**
 * Tests the Magic Link functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Magic_Link;
use Newspack\Reader_Activation;

/**
 * Tests the Magic Link functionality.
 */
class Newspack_Test_Magic_Link extends WP_UnitTestCase {
	/**
	 * Reader user id.
	 *
	 * @var int
	 */
	private static $user_id = null;

	/**
	 * Secondary reader user id.
	 *
	 * @var int
	 */
	private static $secondary_user_id = null;

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private static $admin_id = null;

	/**
	 * Setup for the tests.
	 */
	public function set_up() {
		// Enable reader activation.
		add_filter( 'newspack_reader_activation_enabled', '__return_true' );

		// Create sample reader.
		if ( empty( self::$user_id ) ) {
			self::$user_id = Reader_Activation::register_reader( 'reader@test.com', 'Test Reader' );
			// Ensure we're logged out before continuing.
			wp_logout();
		}

		// Create a secondary sample reader.
		if ( empty( self::$secondary_user_id ) ) {
			self::$secondary_user_id = wp_insert_user(
				[
					'user_login' => 'secondary-user',
					'user_pass'  => wp_generate_password(),
					'user_email' => 'secondary@test.com',
				]
			);
		}
		// Remove tokens.
		delete_user_meta( self::$user_id, Magic_Link::TOKENS_META );

		// Create sample admin.
		if ( empty( self::$admin_id ) ) {
			self::$admin_id = wp_insert_user(
				[
					'user_login' => 'sample-admin',
					'user_pass'  => wp_generate_password(),
					'user_email' => 'admin@test.com',
					'role'       => 'administrator',
				]
			);
		}
		// Remove tokens.
		delete_user_meta( self::$admin_id, Magic_Link::TOKENS_META );
	}

	/**
	 * Assert valid token.
	 *
	 * @param array $token_data Token data. {
	 *   The token data.
	 *
	 *   @type string $token  The token.
	 *   @type string $client Client hash.
	 *   @type string $time   Token creation time.
	 *   @type array  $otp    The OTP.
	 * }
	 */
	public function assertTokenIsValid( $token_data ) {
		$this->assertFalse( is_wp_error( $token_data ) );
		$this->assertIsString( $token_data['token'] );
		$this->assertIsString( $token_data['client'] );
		$this->assertIsInt( $token_data['time'] );
		$this->assertIsArray( $token_data['otp'] );
	}

	/**
	 * Test simple token generation.
	 */
	public function test_generate_token() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertTokenIsValid( $token_data );
	}

	/**
	 * Test simple secret generation.
	 */
	public function test_generate_secret() {
		$secret = Magic_Link::generate_secret( get_user_by( 'id', self::$user_id ) );
		$this->assertEquals( $secret, wp_hash( 'reader@test.com' ) );
	}

	/**
	 * Test rate limiting of token generation.
	 */
	public function test_rate_limit() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$new_token  = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertTrue( is_wp_error( $new_token ) );
		$this->assertEquals( 'rate_limit_exceeded', $new_token->get_error_code() );
	}

	/**
	 * Test simple token validation.
	 */
	public function test_validate_token() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertTokenIsValid( Magic_Link::validate_token( self::$user_id, $token_data['client'], $token_data['token'] ) );
	}

	/**
	 * Test whether up to five tokens can be generated and validated.
	 */
	public function test_multiple_tokens() {
		/**
		 * Filter the rate interval to 0 seconds.
		 *
		 * @param int $rate_interval The rate interval in seconds.
		 */
		function modify_magic_link_rate_interval( $rate_interval ) {
			return 0;
		}
		add_filter( 'newspack_magic_link_rate_interval', 'modify_magic_link_rate_interval', 10 );
		$tokens = [];
		for ( $i = 0; $i <= 5; $i++ ) {
			$tokens[] = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		}
		remove_filter( 'newspack_magic_link_rate_interval', 'modify_magic_link_rate_interval', 10 );
		foreach ( $tokens as $index => $token ) {
			if ( $index < 1 ) {
				$this->assertTrue( is_wp_error( Magic_Link::validate_token( self::$user_id, $token['client'], $token['token'] ) ) );
			} else {
				$this->assertTokenIsValid( Magic_Link::validate_token( self::$user_id, $token['client'], $token['token'] ) );
			}
		}
	}

	/**
	 * Test single-use quality of a token.
	 */
	public function test_single_use_token() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );

		// First use should be valid.
		$first_validation = Magic_Link::validate_token( self::$user_id, $token_data['client'], $token_data['token'] );
		$this->assertTokenIsValid( $first_validation );

		// Second use should error with "invalid_token", since it was deleted by previous use.
		$second_validation = Magic_Link::validate_token( self::$user_id, $token_data['client'], $token_data['token'] );
		$this->assertTrue( is_wp_error( $second_validation ) );
		$this->assertEquals( 'invalid_token', $second_validation->get_error_code() );
	}

	/**
	 * Test that generating a token for an admin returns an error.
	 */
	public function test_generate_token_for_admin() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$admin_id ) );
		$this->assertTrue( is_wp_error( $token_data ) );
		$this->assertEquals( 'newspack_magic_link_invalid_user', $token_data->get_error_code() );
	}

	/**
	 * Test that generating a token for a user with disabled magic links returns
	 * an error.
	 */
	public function test_generate_token_for_disabled_user() {
		update_user_meta( self::$user_id, Magic_Link::DISABLED_META, true );
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertTrue( is_wp_error( $token_data ) );
		$this->assertEquals( 'newspack_magic_link_invalid_user', $token_data->get_error_code() );
		delete_user_meta( self::$user_id, Magic_Link::DISABLED_META ); // Clean up.
	}

	/**
	 * Test that a self-served (unauthenticated) generated token contains a client
	 * hash for validation.
	 */
	public function test_generate_self_served_token() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertNotEmpty( $token_data['client'] );
	}

	/**
	 * Test that an admin generated token does not contain a client hash for
	 * validation.
	 */
	public function test_generate_admin_token() {
		wp_set_current_user( self::$admin_id );
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$this->assertEmpty( $token_data['client'] );
	}

	/**
	 * Test that valid token with different user ID.
	 */
	public function test_validate_token_with_different_user_id() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$validation = Magic_Link::validate_token( self::$secondary_user_id, $token_data['client'], $token_data['token'] );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_token', $validation->get_error_code() );
	}

	/**
	 * Test token OTP.
	 */
	public function test_token_otp() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$otp        = $token_data['otp'];
		$validation = Magic_Link::validate_otp( self::$user_id, $otp['hash'], $otp['code'] );
		$this->assertTokenIsValid( $validation );
	}

	/**
	 * Test invalid OTP code.
	 */
	public function test_invalid_otp_code() {
		$token_data  = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$otp         = $token_data['otp'];
		$random_code = 123456;
		$validation  = Magic_Link::validate_otp( self::$user_id, $otp['hash'], $random_code );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_otp', $validation->get_error_code() );
	}

	/**
	 * Test invalid OTP hash.
	 */
	public function test_invalid_otp_hash() {
		$token_data  = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$otp         = $token_data['otp'];
		$random_hash = wp_generate_password( 32, false );
		$validation  = Magic_Link::validate_otp( self::$user_id, $random_hash, $otp['code'] );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_hash', $validation->get_error_code() );
	}

	/**
	 * Test OTP hash expiration.
	 */
	public function test_otp_hash_expiration() {
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$otp        = $token_data['otp'];

		for ( $i = 0; $i < Magic_Link::OTP_MAX_ATTEMPTS; $i++ ) {
			$validation = Magic_Link::validate_otp( self::$user_id, $otp['hash'], 12345 );
			$this->assertTrue( is_wp_error( $validation ) );
			$this->assertEquals( 'invalid_otp', $validation->get_error_code() );
		}

		// On the max attempt threshold, hash will expire.
		$validation = Magic_Link::validate_otp( self::$user_id, $otp['hash'], 123456 );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'max_otp_attempts', $validation->get_error_code() );

		// Next attempt on the same hash should fail with `invalid_hash` because the hash was deleted.
		$validation = Magic_Link::validate_otp( self::$user_id, $otp['hash'], 123456 );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_hash', $validation->get_error_code() );
	}

	/**
	 * Test invalid token.
	 */
	public function test_invalid_token() {
		$token_data   = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$random_token = wp_generate_password( 32 );
		$validation   = Magic_Link::validate_token( self::$user_id, $token_data['client'], $random_token );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_token', $validation->get_error_code() );
	}

	/**
	 * Test invalid client hash.
	 */
	public function test_invalid_client_hash() {
		$token_data         = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		$random_client_hash = wp_generate_password( 32 );
		$validation         = Magic_Link::validate_token( self::$user_id, $random_client_hash, $token_data['token'] );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_client', $validation->get_error_code() );
	}

	/**
	 * Test that an expired token is invalid.
	 */
	public function test_expired_token() {
		// Filter the token expiration time to be 0 seconds.
		add_filter( 'newspack_magic_link_token_expiration', '__return_zero' );
		$token_data = Magic_Link::generate_token( get_user_by( 'id', self::$user_id ) );
		// Sleep for 1 second to ensure the token is expired.
		sleep( 1 );
		$validation = Magic_Link::validate_token( self::$user_id, $token_data['client'], $token_data['token'] );
		$this->assertTrue( is_wp_error( $validation ) );
		$this->assertEquals( 'invalid_token', $validation->get_error_code() );
	}

	/**
	 * Test that newspack_magic_link_email_config filter can override the email config.
	 */
	public function test_email_config_filter() {
		$filter_called   = false;
		$received_params = [];

		// Add a filter to capture the parameters and modify the config name.
		$filter_callback = function ( $email_config_name, $email_type, $user, $redirect_to, $token_data ) use ( &$filter_called, &$received_params ) {
			$filter_called   = true;
			$received_params = [
				'email_config_name' => $email_config_name,
				'email_type'        => $email_type,
				'user'              => $user,
				'redirect_to'       => $redirect_to,
				'token_data'        => $token_data,
			];
			// Return a custom config name.
			return 'custom-email-config';
		};

		add_filter( 'newspack_magic_link_email_config', $filter_callback, 10, 5 );

		// Trigger send_email (it will fail to send since we don't have email setup, but filter should still fire).
		$user = get_user_by( 'id', self::$user_id );
		Magic_Link::send_email( $user, 'https://example.com/redirect' );

		remove_filter( 'newspack_magic_link_email_config', $filter_callback, 10 );

		$this->assertTrue( $filter_called, 'The newspack_magic_link_email_config filter was called.' );
		$this->assertEquals( 'OTP_AUTH', $received_params['email_type'], 'Filter received the correct email type.' );
		$this->assertEquals( $user->ID, $received_params['user']->ID, 'Filter received the correct user.' );
		$this->assertEquals( 'https://example.com/redirect', $received_params['redirect_to'], 'Filter received the correct redirect_to.' );
		$this->assertIsArray( $received_params['token_data'], 'Filter received token data as array.' );
	}
}
