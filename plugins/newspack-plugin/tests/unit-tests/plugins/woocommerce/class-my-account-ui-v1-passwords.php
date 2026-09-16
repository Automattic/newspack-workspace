<?php
/**
 * Tests for Newspack\My_Account_UI_V1_Passwords.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account_UI_V1_Passwords;
use Newspack\Reader_Activation;

// Only loaded on `init` when WooCommerce is active, which it isn't in the test env.
require_once dirname( __DIR__, 4 ) . '/includes/plugins/woocommerce/my-account/class-my-account-ui-v1-passwords.php';

/**
 * Tests for Newspack\My_Account_UI_V1_Passwords.
 *
 * @group My_Account
 */
class Newspack_Test_My_Account_UI_V1_Passwords extends WP_UnitTestCase {
	/**
	 * Tear down the test: clear the simulated form submission and current user.
	 */
	public function tear_down() {
		$_POST = [];
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a logged-in reader whose password is stored the way WooCommerce's
	 * reset flow stores it: a hash of the slashed $_POST value.
	 *
	 * @param string $password Plain-text password.
	 * @return WP_User
	 */
	private function create_reader( $password ) {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, Reader_Activation::READER, true );
		wp_set_password( wp_slash( $password ), $user_id );
		wp_set_current_user( $user_id );
		return get_userdata( $user_id );
	}

	/**
	 * Simulate submitting the Edit Account password form. WordPress slashes
	 * $_POST on every request (wp_magic_quotes()), so the values are slashed here too.
	 *
	 * @param mixed $current_password Submitted current password.
	 */
	private function submit_current_password( $current_password ) {
		$_POST = wp_slash(
			[
				'action'           => My_Account_UI_V1_Passwords::RESET_PASSWORD_ACTION,
				'current_password' => $current_password,
			]
		);
	}

	/**
	 * Valid passwords containing characters that HTML-escaping or slashing would alter.
	 *
	 * @return array
	 */
	public function special_character_passwords() {
		return [
			'ampersand'       => [ 'Example&Password1' ],
			'angle brackets'  => [ 'Ex<am>ple1' ],
			'lone less-than'  => [ 'Ex<ample1' ],
			'single quote'    => [ "O'Brien-Pass1" ],
			'double quote'    => [ 'Say"Cheese1' ],
			'backslash'       => [ 'Back\\slash1' ],
			'percent-encoded' => [ 'Pct%41word1' ],
			'double space'    => [ 'Two  spaces1' ],
		];
	}

	/**
	 * The correct current password is accepted regardless of special characters.
	 *
	 * @dataProvider special_character_passwords
	 *
	 * @param string $password Plain-text password.
	 */
	public function test_accepts_correct_password_with_special_characters( $password ) {
		$user = $this->create_reader( $password );
		$this->submit_current_password( $password );

		$errors = My_Account_UI_V1_Passwords::validate_password_reset( new WP_Error(), $user );

		$this->assertFalse( $errors->has_errors(), 'Unexpected errors: ' . implode( ', ', $errors->get_error_codes() ) );
	}

	/**
	 * An incorrect current password is still rejected, including the HTML-escaped
	 * form of the real password.
	 */
	public function test_rejects_incorrect_password() {
		$user = $this->create_reader( 'Example&Password1' );
		$this->submit_current_password( 'Example&amp;Password1' );

		$errors = My_Account_UI_V1_Passwords::validate_password_reset( new WP_Error(), $user );

		$this->assertSame( [ 'invalid_current_password' ], $errors->get_error_codes() );
	}

	/**
	 * A missing or non-string current password is reported as missing and never
	 * reaches wp_check_password().
	 */
	public function test_requires_current_password() {
		$user = $this->create_reader( 'Example&Password1' );

		$this->submit_current_password( '' );
		$errors = My_Account_UI_V1_Passwords::validate_password_reset( new WP_Error(), $user );
		$this->assertSame( [ 'missing_current_password' ], $errors->get_error_codes() );

		$this->submit_current_password( [ 'Example&Password1' ] );
		$errors = My_Account_UI_V1_Passwords::validate_password_reset( new WP_Error(), $user );
		$this->assertSame( [ 'missing_current_password' ], $errors->get_error_codes() );
	}
}
