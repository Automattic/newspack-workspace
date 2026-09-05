<?php
/**
 * Tests the reader auth form's `signin` routing precedence (NPPM-3054).
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;
use Newspack\Magic_Link;

/**
 * The `signin` router must offer the password step to a reader who has a password,
 * even while an OTP token is still active, so they aren't locked into OTP.
 */
class Newspack_Test_RA_Auth_Signin_Routing extends WP_Ajax_UnitTestCase {

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();
		// process_auth_form() bails when logged in; ensure a logged-out visitor.
		wp_set_current_user( 0 );
		// Make magic-link email dispatch deterministic (no real mailer needed).
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', '__return_true' );
		unset(
			$_POST['reader-activation-auth-form'],
			$_POST['action'],
			$_POST['npe'],
			$_COOKIE[ Magic_Link::OTP_HASH_COOKIE ] // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		);
		parent::tear_down();
	}

	/**
	 * Create a reader and set its password state explicitly.
	 *
	 * @param string $email        Reader email.
	 * @param bool   $has_password Whether the reader has a password on file.
	 * @return int Reader user ID.
	 */
	private function make_reader( $email, $has_password ) {
		$user_id = Reader_Activation::register_reader( $email, 'Test Reader', false );
		if ( $has_password ) {
			delete_user_meta( $user_id, Reader_Activation::WITHOUT_PASSWORD );
		} else {
			update_user_meta( $user_id, Reader_Activation::WITHOUT_PASSWORD, true );
		}
		return $user_id;
	}

	/**
	 * Mark a reader as having an active OTP token: a token in meta plus the hash cookie.
	 *
	 * @param int $user_id Reader user ID.
	 */
	private function activate_token( $user_id ) {
		Magic_Link::generate_token( get_user_by( 'id', $user_id ) );
		$_COOKIE[ Magic_Link::OTP_HASH_COOKIE ] = 'test-hash'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Count the reader's stored OTP tokens.
	 *
	 * @param int $user_id Reader user ID.
	 * @return int Number of tokens on file.
	 */
	private function token_count( $user_id ) {
		$tokens = get_user_meta( $user_id, Magic_Link::TOKENS_META, true );
		return is_array( $tokens ) ? count( $tokens ) : 0;
	}

	/**
	 * Submit the `signin` action for an email and capture the JSON response.
	 *
	 * Process_auth_form() ends in wp_send_json(), which echoes JSON then wp_die();
	 * WP_Ajax_UnitTestCase's die handler throws and drains output into $this->_last_response.
	 *
	 * @param string $email Email to submit via the `npe` field.
	 * @return array Decoded response body (top-level `message` + `data`), or [] if none.
	 */
	private function submit_signin( $email ) {
		$_POST['reader-activation-auth-form'] = '1';
		$_POST['action']                      = 'signin';
		$_POST['npe']                         = $email;

		$this->_last_response = '';
		try {
			Reader_Activation::process_auth_form();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		// Reopen a buffer so tear_down's buffer level matches (PHPUnit flags mismatches).
		ob_start();

		$decoded = json_decode( $this->_last_response, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * A reader with a password gets the password step even when an OTP token is active,
	 * and no new token is generated.
	 */
	public function test_password_reader_with_active_token_routes_to_pwd() {
		$user_id = $this->make_reader( 'pwd-reader@test.com', true );
		$this->activate_token( $user_id );
		$before = $this->token_count( $user_id );

		$response = $this->submit_signin( 'pwd-reader@test.com' );

		$this->assertSame( 'pwd', $response['data']['action'] ?? null );
		$after = $this->token_count( $user_id );
		$this->assertSame( $before, $after, 'No new token should be generated.' );
	}

	/**
	 * A reader without a password and an active token stays on OTP and reuses the token.
	 */
	public function test_passwordless_reader_with_active_token_reuses_token() {
		$user_id = $this->make_reader( 'otp-reader@test.com', false );
		$this->activate_token( $user_id );
		$before = $this->token_count( $user_id );

		$response = $this->submit_signin( 'otp-reader@test.com' );

		$this->assertSame( 'otp', $response['data']['action'] ?? null );
		$after = $this->token_count( $user_id );
		$this->assertSame( $before, $after, 'Active token should be reused, not resent.' );
	}

	/**
	 * A reader without a password and no active token is sent a code and routed to OTP.
	 */
	public function test_passwordless_reader_without_token_sends_code() {
		$user_id = $this->make_reader( 'fresh-otp@test.com', false );
		$this->assertSame( 0, $this->token_count( $user_id ), 'Reader should start with no tokens.' );

		$response = $this->submit_signin( 'fresh-otp@test.com' );

		$this->assertSame( 'otp', $response['data']['action'] ?? null );
		$this->assertSame( 1, $this->token_count( $user_id ), 'A fresh code should be generated and sent.' );
	}

	/**
	 * Regression: a reader with a password and no active token still routes to pwd.
	 */
	public function test_password_reader_without_token_routes_to_pwd() {
		$this->make_reader( 'plain-pwd@test.com', true );

		$response = $this->submit_signin( 'plain-pwd@test.com' );

		$this->assertSame( 'pwd', $response['data']['action'] ?? null );
	}
}
