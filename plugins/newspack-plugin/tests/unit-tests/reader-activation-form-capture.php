<?php
/**
 * Tests the Inbound Form Capture integration and its Reader Activation hooks.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;

/**
 * Test the Form Capture integration.
 *
 * @group form-capture
 */
class Test_Form_Capture extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Reader_Activation::OPTIONS_PREFIX . 'enabled', true );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		delete_option( Reader_Activation::OPTIONS_PREFIX . 'enabled' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Re-registering an existing password-less reader sends a magic link by
	 * default, and the new filter can suppress it.
	 */
	public function test_magic_link_on_reregistration_is_filterable() {
		// Neutralize the magic link rate limiter so back-to-back reregistrations
		// within this test aren't rate-limited into a false "email suppressed"
		// result (mirrors the pattern in tests/unit-tests/magic-link.php).
		add_filter( 'newspack_magic_link_rate_interval', '__return_zero' );

		$email = 'magic-link-filter@example.com';
		Reader_Activation::register_reader( $email, '', false, [ 'registration_method' => 'test-first' ] );

		// Default: second registration sends the magic link email.
		reset_phpmailer_instance();
		$result = Reader_Activation::register_reader( $email, '', false, [ 'registration_method' => 'test-second' ] );
		$this->assertFalse( $result, 'Re-registration of an existing reader should return false.' );
		$this->assertNotEmpty( tests_retrieve_phpmailer_instance()->mock_sent, 'Magic link email should be sent by default.' );

		// Filter returning false suppresses the email.
		$filter = function() {
			return false;
		};
		add_filter( 'newspack_reader_activation_send_magic_link_on_reregistration', $filter );
		reset_phpmailer_instance();
		$result = Reader_Activation::register_reader( $email, '', false, [ 'registration_method' => 'test-third' ] );
		$this->assertFalse( $result );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent, 'Filter should suppress the magic link email.' );
		remove_filter( 'newspack_reader_activation_send_magic_link_on_reregistration', $filter );

		remove_filter( 'newspack_magic_link_rate_interval', '__return_zero' );
	}
}
