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
	}

	/**
	 * The registration method format is a public contract shared with the
	 * frontend registration endpoint (and stored in user meta on live
	 * sites) — pin the literal so accidental drift on either side of the
	 * shared helper fails loudly.
	 */
	public function test_registration_method_format_is_pinned() {
		$this->assertSame( 'integration-registration-form-capture', Form_Capture::get_registration_method() );
		$this->assertSame(
			Reader_Registration::get_registration_method_for( Form_Capture::ID ),
			Form_Capture::get_registration_method(),
			'Integration and endpoint must derive the method string from the same helper.'
		);
	}

	/**
	 * Verify can_sync() honors the base contract: WP_Error when $return_errors
	 * is true — callers like health_check() invoke ->has_errors() on it unguarded.
	 */
	public function test_can_sync_honors_wp_error_contract() {
		Integrations::enable( Form_Capture::ID );
		$integration = Integrations::get_integration( Form_Capture::ID );

		$this->assertTrue( $integration->can_sync() );
		$errors = $integration->can_sync( true );
		$this->assertInstanceOf( \WP_Error::class, $errors );
		$this->assertFalse( $errors->has_errors(), 'Capture-only integration has no sync prerequisites to fail.' );

		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		// Exercised through the real predicate either way: a pre-capability
		// framework counts the always-passing can_sync() (true); with
		// per-direction capabilities a push-less integration never satisfies
		// this push-path predicate (false).
		$expected = ! method_exists( $integration, 'is_push_enabled' );
		$this->assertSame( $expected, Contact_Sync::has_one_syncable_integration() );

		Integrations::disable( Form_Capture::ID );
	}

	/**
	 * The capability declarations are part of the sync framework contract:
	 * capture-only means no push and no pull.
	 */
	public function test_declares_no_sync_capabilities() {
		$integration = Integrations::get_integration( Form_Capture::ID );
		$this->assertFalse( $integration->supports_push(), 'Capture-only integration must declare no push capability.' );
		$this->assertFalse( $integration->supports_pull(), 'Capture-only integration must declare no pull capability.' );
	}

	/**
	 * The magic link is suppressed for this integration's registrations only.
	 */
	public function test_magic_link_suppressed_for_form_capture_method() {
		$user = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$this->assertFalse(
			apply_filters( 'newspack_reader_activation_send_magic_link_on_reregistration', true, $user, [ 'registration_method' => Form_Capture::get_registration_method() ] )
		);
		$this->assertTrue(
			apply_filters( 'newspack_reader_activation_send_magic_link_on_reregistration', true, $user, [ 'registration_method' => 'auth-form' ] )
		);
	}

	/**
	 * Existing readers get an explicit contact sync — the reader_registered
	 * data event covers new users only.
	 */
	public function test_should_sync_existing_reader_decision() {
		$integration = Integrations::get_integration( Form_Capture::ID );
		$user        = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$method      = [ 'registration_method' => Form_Capture::get_registration_method() ];

		$this->assertTrue( $integration->should_sync_existing_reader( $user, $method ) );
		$this->assertFalse( $integration->should_sync_existing_reader( false, $method ), 'New users are covered by the reader_registered data event.' );
		$this->assertFalse( $integration->should_sync_existing_reader( $user, [ 'registration_method' => 'auth-form' ] ), 'Other methods are not ours to sync.' );
	}

	/**
	 * An existing-reader capture registration schedules the contact sync
	 * asynchronously (via Contact_Sync::schedule_sync()'s wp_schedule_single_event())
	 * rather than syncing synchronously on the request thread.
	 */
	public function test_existing_reader_sync_is_scheduled_not_synchronous() {
		$integration = Integrations::get_integration( Form_Capture::ID );
		$user        = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$method      = [ 'registration_method' => Form_Capture::get_registration_method() ];
		$context     = 'Form Capture registration (existing reader)';

		$integration->handle_registered_reader( $user->user_email, true, false, $user, $method );

		$this->assertNotFalse(
			wp_next_scheduled( 'newspack_scheduled_esp_sync', [ $user->ID, $context ] ),
			'An async ESP sync must be scheduled for an existing reader capture with no lists.'
		);

		wp_clear_scheduled_hook( 'newspack_scheduled_esp_sync', [ $user->ID, $context ] );
	}

	/**
	 * End-to-end: the registration endpoint accepts this integration's key and
	 * produces a reader stamped with this integration's registration method.
	 *
	 * Mirrors the logged-out precondition from
	 * Newspack_Test_Frontend_Registration_Endpoint::set_up() in
	 * reader-registration-endpoint.php (the frontend endpoint's step 2
	 * short-circuits to a 200 "existing" response for a logged-in caller). That
	 * suite always dispatches through a real WP_REST_Server; this test calls the
	 * handler directly, which is equivalent here since the handler only reads
	 * params via WP_REST_Request::get_param() and doesn't depend on the REST
	 * server's schema-driven sanitization/defaults for the fields exercised below.
	 */
	public function test_endpoint_registers_reader() {
		wp_set_current_user( 0 );

		Integrations::enable( Form_Capture::ID );
		$integration = Integrations::get_integration( Form_Capture::ID );

		$captured_metadata = null;
		$capture           = function( $email, $authenticate, $user_id, $existing_user, $metadata ) use ( &$captured_metadata ) {
			$captured_metadata = $metadata;
		};
		add_action( 'newspack_registered_reader', $capture, 10, 5 );

		$request = new WP_REST_Request( 'POST', '/newspack/v1/reader-activation/register' );
		$request->set_param( 'npe', 'capture-endpoint@example.com' );
		$request->set_param( 'integration_id', Form_Capture::ID );
		$request->set_param( 'integration_key', $integration->get_registration_key() );
		$response = Reader_Registration::api_frontend_register_reader( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( Form_Capture::get_registration_method(), $captured_metadata['registration_method'] );
		$user = get_user_by( 'email', 'capture-endpoint@example.com' );
		$this->assertNotFalse( $user );

		remove_action( 'newspack_registered_reader', $capture );
		Integrations::disable( Form_Capture::ID );
	}

	/**
	 * The capture script is enqueued only when the integration is enabled.
	 */
	public function test_capture_script_enqueued_only_when_enabled() {
		$integration = Integrations::get_integration( Form_Capture::ID );

		$this->assertSame( 20, has_action( 'wp_enqueue_scripts', [ $integration, 'enqueue_scripts' ] ), 'Enqueue must be hooked at priority 20.' );

		$integration->enqueue_scripts();
		$this->assertFalse( wp_script_is( Form_Capture::SCRIPT_HANDLE, 'enqueued' ) );

		Integrations::enable( Form_Capture::ID );
		$integration->enqueue_scripts();
		$this->assertTrue( wp_script_is( Form_Capture::SCRIPT_HANDLE, 'enqueued' ) );
		Integrations::disable( Form_Capture::ID );
	}
}
