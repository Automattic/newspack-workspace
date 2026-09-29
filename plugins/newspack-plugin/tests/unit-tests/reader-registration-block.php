<?php
/**
 * Tests the Reader Registration Block server-side form handler (process_form).
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;

require_once __DIR__ . '/../mocks/filter-input-post-mock.php';

/**
 * Test the Reader Registration Block's process_form() branching.
 *
 * The process_form() handler reads request data via filter_input( INPUT_POST, ... )
 * and responds via wp_send_json(); it is exercised through WP_Ajax_UnitTestCase so
 * the die/JSON handlers are captured rather than terminating the process.
 *
 * @group reader-registration-block
 */
class Newspack_Test_Reader_Registration_Block extends WP_Ajax_UnitTestCase {

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();
		// register_reader() refuses to run while logged in with $authenticate = true,
		// which process_form() uses; ensure a logged-out visitor.
		wp_set_current_user( 0 );
		// send_form_response() only emits JSON when the request looks like a JSON request.
		$_SERVER['HTTP_ACCEPT'] = 'application/json';
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		unset( $_SERVER['HTTP_ACCEPT'] );
		unset( $_POST['newspack_reader_registration'], $_POST['npe'], $_POST['email'] );
		parent::tear_down();
	}

	/**
	 * Invoke process_form() for the given email and capture the JSON response.
	 *
	 * The handler ends in wp_send_json(), which echoes JSON then calls wp_die();
	 * WP_Ajax_UnitTestCase's die handler throws and drains the output buffer into
	 * $this->_last_response.
	 *
	 * @param string $email Email to submit via the `npe` field.
	 * @return array Decoded JSON response body, or [] if none.
	 */
	private function submit_registration( $email ) {
		$_POST['newspack_reader_registration'] = 'newspack_reader_registration';
		$_POST['npe']                          = $email;

		$this->_last_response = '';
		try {
			\Newspack\Blocks\ReaderRegistration\process_form();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		// WP_Ajax_UnitTestCase's die handler closed the buffer it opened in set_up;
		// reopen one so tear_down's buffer level matches (PHPUnit flags mismatches as risky).
		ob_start();

		$decoded = json_decode( $this->_last_response, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * A non-reader account email returns the auth-modal "Account not found." error,
	 * instead of a silent success response.
	 */
	public function test_non_reader_email_returns_account_not_found() {
		$admin_email = 'admin-nonreader@example.com';
		$admin_id    = $this->factory()->user->create(
			[
				'role'       => 'administrator',
				'user_email' => $admin_email,
			]
		);

		$response = $this->submit_registration( $admin_email );

		$this->assertSame( 'Account not found.', $response['message'] );

		wp_delete_user( $admin_id );
	}

	/**
	 * An existing reader account is not treated as "Account not found"; it hands off to
	 * the auth flow (existing_user with an action), so no error message is surfaced.
	 */
	public function test_existing_reader_email_is_not_account_not_found() {
		$reader_email = 'existing-reader@example.com';
		$reader_id    = Reader_Activation::register_reader( $reader_email, 'Existing Reader', false );

		$response = $this->submit_registration( $reader_email );

		$this->assertNotSame( 'Account not found.', $response['message'] ?? '' );
		$this->assertNotEmpty( $response['data']['existing_user'] );
		$this->assertArrayHasKey( 'action', $response['data'] );

		wp_delete_user( $reader_id );
	}

	/**
	 * A brand-new email creates a reader and returns an authenticated success response
	 * (no "Account not found." error).
	 */
	public function test_new_email_registers_successfully() {
		$new_email = 'brand-new-reader@example.com';

		$response = $this->submit_registration( $new_email );

		$this->assertNotSame( 'Account not found.', $response['message'] ?? '' );
		$this->assertNotEmpty( $response['data']['authenticated'] );
		$this->assertEmpty( $response['data']['existing_user'] );

		$user = get_user_by( 'email', $new_email );
		$this->assertInstanceOf( \WP_User::class, $user );
		wp_delete_user( $user->ID );
	}
}
