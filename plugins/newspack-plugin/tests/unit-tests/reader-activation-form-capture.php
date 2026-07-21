<?php
/**
 * Tests the Inbound Form Capture integration and its Reader Activation hooks.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Form_Capture;
use Newspack\Reader_Registration;

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
		remove_all_filters( 'newspack_magic_link_rate_interval' );
		remove_all_filters( 'newspack_reader_activation_send_magic_link_on_reregistration' );
		remove_all_filters( 'newspack_reader_activation_is_syncing_allowed' );
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
		$this->assertFalse( $result, 'Re-registration with suppression filter should still return false.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent, 'Filter should suppress the magic link email.' );
	}

	/**
	 * The integration is registered but disabled by default, and enabling it
	 * exposes it to the frontend registration endpoint.
	 */
	public function test_registered_disabled_by_default_and_enablement_gates_frontend_registration() {
		$integration = Integrations::get_integration( Form_Capture::ID );
		$this->assertInstanceOf( Form_Capture::class, $integration );
		$this->assertFalse( Integrations::is_enabled( Form_Capture::ID ), 'Must be disabled by default.' );
		$this->assertFalse( $integration->supports_frontend_registration() );
		$this->assertArrayNotHasKey( Form_Capture::ID, Reader_Registration::get_frontend_registration_integrations() );

		Integrations::enable( Form_Capture::ID );
		$this->assertTrue( $integration->supports_frontend_registration() );
		$this->assertArrayHasKey( Form_Capture::ID, Reader_Registration::get_frontend_registration_integrations() );
		Integrations::disable( Form_Capture::ID );
	}

	/**
	 * Selector and list settings parse into clean arrays.
	 */
	public function test_settings_parsing() {
		$integration = Integrations::get_integration( Form_Capture::ID );

		$this->assertSame( [ '.newspack-form-capture' ], $integration->get_selectors(), 'Marker class is always present.' );
		$integration->update_settings_field_value( 'selectors', "#signup-form\n .sidebar form \n#signup-form" );
		$this->assertSame( [ '.newspack-form-capture', '#signup-form', '.sidebar form' ], $integration->get_selectors() );

		$this->assertSame( [], $integration->get_lists() );
		$integration->update_settings_field_value( 'lists', ' list-1, list-2 ,, ' );
		$this->assertSame( [ 'list-1', 'list-2' ], $integration->get_lists() );
	}

	/**
	 * Verify can_sync() honors the base contract: WP_Error when $return_errors
	 * is true — has_one_syncable_integration() calls ->has_errors() on it unguarded.
	 */
	public function test_can_sync_honors_wp_error_contract() {
		Integrations::enable( Form_Capture::ID );
		$integration = Integrations::get_integration( Form_Capture::ID );

		$this->assertTrue( $integration->can_sync() );
		$errors = $integration->can_sync( true );
		$this->assertInstanceOf( \WP_Error::class, $errors );
		$this->assertFalse( $errors->has_errors(), 'Inbound-only integration has no sync prerequisites to fail.' );

		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		$this->assertTrue( Contact_Sync::has_one_syncable_integration() );

		Integrations::disable( Form_Capture::ID );
	}
}
