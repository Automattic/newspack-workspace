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

require_once __DIR__ . '/integrations/class-inherited-validator-integration.php';

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
		delete_option( 'newspack_recaptcha_use_captcha' );
		delete_option( 'newspack_recaptcha_version' );
		delete_option( 'newspack_recaptcha_credentials' );
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

		// Not @example.com: that is the Guest Contributor placeholder domain,
		// and the outbound-mail guard (#572) suppresses wp_mail() to it, which
		// would fail the delivery assertion below. Mirrors magic-link.php's
		// use of @test.com for mail-asserting tests.
		$email = 'magic-link-filter@test.com';
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
	 * Selector and list settings parse into clean arrays, and bare
	 * element/universal selectors — which would opt in every form on the
	 * site — are rejected.
	 */
	public function test_settings_parsing() {
		$integration = Integrations::get_integration( Form_Capture::ID );

		$this->assertSame( [ '.newspack-form-capture' ], $integration->get_selectors(), 'Marker class is always present.' );
		$integration->update_settings_field_value( 'selectors', "#signup-form\n .sidebar form \n#signup-form" );
		$this->assertSame( [ '.newspack-form-capture', '#signup-form', '.sidebar form' ], $integration->get_selectors() );

		$integration->update_settings_field_value( 'selectors', "form\n*\nbody\nDIV\n#signup-form\nform.signup" );
		$this->assertSame(
			[ '.newspack-form-capture', '#signup-form', 'form.signup' ],
			$integration->get_selectors(),
			'Bare element/universal selectors must be rejected; qualified ones kept.'
		);

		// An over-broad selector is over-broad wherever it sits: inside a
		// comma-separated list, or behind ancestors that name only elements.
		$integration->update_settings_field_value( 'selectors', "form, #signup\nbody , .thing\nbody form\ndiv > form\n#a, .b\nfooter form.signup" );
		$this->assertSame(
			[ '.newspack-form-capture', '#a, .b', 'footer form.signup' ],
			$integration->get_selectors(),
			'A line is dropped whole when any of its selectors matches every form.'
		);

		// A trailing comma is a plausible copy-paste from a CSS rule. The empty
		// slot has to be removed, not merely tolerated: it makes the whole line
		// invalid CSS, and querySelectorAll() would throw on it client-side.
		$integration->update_settings_field_value( 'selectors', "#signup,\n#a, #b,\n ,#c" );
		$this->assertSame(
			[ '.newspack-form-capture', '#signup', '#a, #b', '#c' ],
			$integration->get_selectors(),
			'Lines are rebuilt from their non-empty parts, so what ships is valid CSS.'
		);
		$integration->update_settings_field_value( 'selectors', '' );
	}

	/**
	 * The unsupported check must be consulted at runtime, not only when
	 * enabling: a site that switches to reCAPTCHA v2 afterwards would
	 * otherwise keep emitting a key capture can never use.
	 */
	public function test_switching_to_recaptcha_v2_after_enabling_stops_capture() {
		Integrations::enable( Form_Capture::ID );
		$integration = Integrations::get_integration( Form_Capture::ID );
		$this->assertTrue( $integration->supports_frontend_registration(), 'Enabled with no captcha configured.' );

		update_option( 'newspack_recaptcha_use_captcha', true );
		update_option( 'newspack_recaptcha_version', 'v2_invisible' );
		update_option(
			'newspack_recaptcha_credentials',
			[
				'v2_invisible' => [
					'site_key'    => 'test-key',
					'site_secret' => 'test-secret',
				],
			]
		);

		$this->assertTrue( Integrations::is_enabled( Form_Capture::ID ), 'The integration stays enabled — only its support changes.' );
		$this->assertFalse( $integration->supports_frontend_registration(), 'A v2 switch must withdraw frontend registration.' );
		$this->assertArrayNotHasKey(
			Form_Capture::ID,
			Reader_Registration::get_frontend_registration_integrations(),
			'No key may be emitted for a configuration capture cannot use.'
		);

		$integration->enqueue_scripts();
		$this->assertFalse( wp_script_is( Form_Capture::SCRIPT_HANDLE, 'enqueued' ), 'The capture script must not load either.' );

		Integrations::disable( Form_Capture::ID );
	}

	/**
	 * The reCAPTCHA v2 flow renders an interactive widget capture cannot warm,
	 * so the integration must report itself unsupported (the REST layer then
	 * refuses to enable it) instead of silently capturing nothing.
	 */
	public function test_unsupported_on_recaptcha_v2() {
		$integration = Integrations::get_integration( Form_Capture::ID );

		$this->assertNull( $integration->get_unsupported_reason(), 'Supported when no captcha is configured.' );

		update_option( 'newspack_recaptcha_use_captcha', true );
		update_option( 'newspack_recaptcha_version', 'v2_invisible' );
		update_option(
			'newspack_recaptcha_credentials',
			[
				'v2_invisible' => [
					'site_key'    => 'test-key',
					'site_secret' => 'test-secret',
				],
				'v3'           => [
					'site_key'    => 'test-key',
					'site_secret' => 'test-secret',
				],
			]
		);
		$this->assertNotNull( $integration->get_unsupported_reason(), 'Unsupported while reCAPTCHA v2 is active.' );
		$this->assertNotEmpty( $integration->get_setup_url(), 'Unsupported state must point at the reCAPTCHA settings.' );

		update_option( 'newspack_recaptcha_version', 'v3' );
		$this->assertNull( $integration->get_unsupported_reason(), 'Supported on reCAPTCHA v3.' );
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
		// A push-less integration can never satisfy has_one_syncable_integration():
		// its predicate is is_push_enabled() — capability AND toggle — and
		// supports_push() declares the capability off, always-passing can_sync()
		// notwithstanding.
		$this->assertFalse( Contact_Sync::has_one_syncable_integration() );

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
	 * The magic link is suppressed for this integration's registrations only,
	 * and only while the integration is enabled — the off switch must mean off
	 * even if something else stamps the method string (a replayed job, a CLI
	 * backfill).
	 */
	public function test_magic_link_suppressed_for_form_capture_method() {
		$user   = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$method = [ 'registration_method' => Form_Capture::get_registration_method() ];

		$this->assertTrue(
			apply_filters( 'newspack_reader_activation_send_magic_link_on_reregistration', true, $user, $method ),
			'Suppression must not apply while the integration is disabled.'
		);

		Integrations::enable( Form_Capture::ID );
		$this->assertFalse(
			apply_filters( 'newspack_reader_activation_send_magic_link_on_reregistration', true, $user, $method )
		);
		$this->assertTrue(
			apply_filters( 'newspack_reader_activation_send_magic_link_on_reregistration', true, $user, [ 'registration_method' => 'auth-form' ] )
		);
		Integrations::disable( Form_Capture::ID );
	}

	/**
	 * Existing readers get an explicit contact sync — the reader_registered
	 * data event covers new users only. The decision is gated on the enabled
	 * state alongside the method check.
	 */
	public function test_should_sync_existing_reader_decision() {
		$integration = Integrations::get_integration( Form_Capture::ID );
		$user        = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$method      = [ 'registration_method' => Form_Capture::get_registration_method() ];

		$this->assertFalse( $integration->should_sync_existing_reader( $user, $method ), 'No sync decision while the integration is disabled.' );

		Integrations::enable( Form_Capture::ID );
		$this->assertTrue( $integration->should_sync_existing_reader( $user, $method ) );
		$this->assertFalse( $integration->should_sync_existing_reader( false, $method ), 'New users are covered by the reader_registered data event.' );
		$this->assertFalse( $integration->should_sync_existing_reader( $user, [ 'registration_method' => 'auth-form' ] ), 'Other methods are not ours to sync.' );
		Integrations::disable( Form_Capture::ID );
	}

	/**
	 * An existing-reader capture registration schedules the contact sync via
	 * Action Scheduler in the integration's group — off the request thread,
	 * retryable, inspectable — and repeat captures reuse the pending action
	 * rather than stacking a second one.
	 */
	public function test_existing_reader_sync_is_scheduled_not_synchronous() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		Integrations::enable( Form_Capture::ID );
		$integration = Integrations::get_integration( Form_Capture::ID );
		$user        = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$method      = [ 'registration_method' => Form_Capture::get_registration_method() ];
		$context     = 'Form Capture registration (existing reader)';
		$hook        = 'newspack_scheduled_esp_sync';
		$args        = [ $user->ID, $context ];
		$group       = $integration->get_action_group();

		$integration->handle_registered_reader( $user->user_email, true, false, $user, $method );
		$this->assertNotFalse(
			as_next_scheduled_action( $hook, $args, $group ),
			'An async ESP sync must be scheduled for an existing reader capture.'
		);

		// A repeat capture before the sync runs must not stack a second action.
		$integration->handle_registered_reader( $user->user_email, true, false, $user, $method );
		$pending = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 10,
			],
			'ids'
		);
		$this->assertCount( 1, $pending, 'Repeat captures must reuse the pending sync action.' );

		as_unschedule_all_actions( $hook, $args, $group );
		Integrations::disable( Form_Capture::ID );
	}

	/**
	 * The scheduled-sync payload must carry the reader's name for readers
	 * without a WooCommerce billing record — an empty name pushed to the ESP
	 * clears the contact's stored name (the repeat-capture FNAME regression).
	 */
	public function test_scheduled_sync_payload_preserves_user_name() {
		$user = self::factory()->user->create_and_get(
			[
				'role'       => 'subscriber',
				'first_name' => 'Grace',
				'last_name'  => 'Hopper',
			]
		);

		$contact = \Newspack\Reader_Activation\Sync\Metadata::get_contact_with_metadata( $user->ID );
		$this->assertSame( 'Grace Hopper', $contact['name'], 'The sync payload must fall back to the WP user name, not push an empty name.' );
	}

	/**
	 * A capture with no name field registers a reader whose display name is
	 * generated from the email. That is a placeholder, not a name: syncing it
	 * would write the email's local part into the ESP's name field, overwriting
	 * a name that may have arrived from a list import.
	 */
	public function test_scheduled_sync_payload_omits_generated_display_name() {
		$user_id = Reader_Activation::register_reader( 'jane.doe@test.com' );
		$this->assertIsInt( $user_id );
		$user = get_userdata( $user_id );

		// Precondition: this is the population the guard is for.
		$this->assertSame( 'jane-doe', $user->display_name, 'register_reader() names the account after the email when none is given.' );
		$this->assertSame( '', get_user_meta( $user_id, 'first_name', true ) );

		// The key must be absent, not empty: connectors branch on isset(), so an
		// empty string overwrites the name the contact already has at the provider.
		$contact = \Newspack\Reader_Activation\Sync\Metadata::get_contact_with_metadata( $user_id );
		$this->assertArrayNotHasKey( 'name', $contact, 'A nameless reader must sync no name at all, not an empty one.' );

		// Same guarantee on the path that runs when WooCommerce is inactive.
		$contact = Contact_Sync::get_contact_data( $user_id );
		$this->assertArrayNotHasKey( 'name', $contact, 'The non-WooCommerce path must not send a name either.' );

		// A display name the reader actually has still syncs.
		wp_update_user(
			[
				'ID'           => $user_id,
				'display_name' => 'Jane Doe',
			]
		);
		$contact = \Newspack\Reader_Activation\Sync\Metadata::get_contact_with_metadata( $user_id );
		$this->assertSame( 'Jane Doe', $contact['name'], 'A real display name is still worth syncing.' );
	}

	/**
	 * A reader who deliberately saved a display name that looks generated has
	 * chosen it — My Account records that in meta, and it is their name.
	 */
	public function test_deliberately_saved_generic_display_name_still_syncs() {
		$user_id = Reader_Activation::register_reader( 'chosen.name@test.com' );
		$this->assertIsInt( $user_id );

		$contact = \Newspack\Reader_Activation\Sync\Metadata::get_contact_with_metadata( $user_id );
		$this->assertArrayNotHasKey( 'name', $contact, 'Generated until the reader says otherwise.' );

		update_user_meta( $user_id, Reader_Activation::READER_SAVED_GENERIC_DISPLAY_NAME, 1 );
		$contact = \Newspack\Reader_Activation\Sync\Metadata::get_contact_with_metadata( $user_id );
		$this->assertSame( 'chosen-name', $contact['name'], 'A name the reader saved deliberately must sync.' );
	}

	/**
	 * Adding the seed to the HMAC input changes every integration's key once on
	 * upgrade. Pages already in a CDN or page cache carry the old key, and the
	 * capture client treats an invalid key as permanent — so the legacy key must
	 * keep validating until those caches cycle.
	 */
	public function test_legacy_registration_key_is_accepted() {
		$integration = Integrations::get_integration( Form_Capture::ID );
		$legacy_key  = hash_hmac( 'sha256', Form_Capture::ID, wp_salt( 'auth' ) );
		$request     = new WP_REST_Request( 'POST', '/newspack/v1/reader-activation/register' );

		$this->assertNotSame( $legacy_key, $integration->get_registration_key(), 'The seeded key is a new value.' );
		$this->assertTrue( $integration->validate_registration_request( $integration->get_registration_key(), $request ) );
		$this->assertTrue( $integration->validate_registration_request( $legacy_key, $request ), 'A key from a cached page must still validate.' );
		$this->assertFalse( $integration->validate_registration_request( 'not-a-key', $request ) );
	}

	/**
	 * The transition allowance belongs to the framework's own key scheme. An
	 * integration with a custom scheme that inherits this validator must not
	 * find the framework's static HMAC accepted alongside its own — that would
	 * permanently bypass whatever bound the custom scheme enforces.
	 */
	public function test_legacy_key_is_refused_for_custom_key_schemes() {
		$integration = new Inherited_Validator_Integration();
		$legacy_key  = hash_hmac( 'sha256', 'custom-key-integration', wp_salt( 'auth' ) );
		$request     = new WP_REST_Request( 'POST', '/newspack/v1/reader-activation/register' );

		$this->assertTrue( $integration->validate_registration_request( 'custom-scheme-key', $request ), 'The custom key still validates.' );
		$this->assertFalse( $integration->validate_registration_request( $legacy_key, $request ), 'The framework legacy key must not bypass a custom scheme.' );
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
		// A locally constructed instance, with register_handlers() run inside
		// this test's hook-backup scope, pins the wiring order-independently
		// (registry instances may have registered their hooks before this
		// test's backup was taken).
		$integration = new Form_Capture();
		$integration->register_handlers();

		$this->assertSame( 20, has_action( 'wp_enqueue_scripts', [ $integration, 'enqueue_scripts' ] ), 'Enqueue must be hooked at priority 20.' );

		$integration->enqueue_scripts();
		$this->assertFalse( wp_script_is( Form_Capture::SCRIPT_HANDLE, 'enqueued' ) );

		Integrations::enable( Form_Capture::ID );
		$integration->enqueue_scripts();
		$this->assertTrue( wp_script_is( Form_Capture::SCRIPT_HANDLE, 'enqueued' ) );
		Integrations::disable( Form_Capture::ID );
	}

	/**
	 * Capture counts in its own per-IP rate-limit bucket, sized for form
	 * traffic — it must neither starve nor be starved by the shared
	 * registration bucket.
	 */
	public function test_rate_limit_bucket_and_sizing() {
		$bucket = Reader_Registration::get_rate_limit_bucket_for( Form_Capture::ID );
		$this->assertSame( 'registration_form-capture', $bucket );
		// Separators are preserved, so ids differing only by one can't silently
		// share a counter.
		$this->assertNotSame( $bucket, Reader_Registration::get_rate_limit_bucket_for( 'form_capture' ) );

		// The integration sizes its own bucket via the existing filter.
		$this->assertSame(
			Form_Capture::RATE_LIMIT_DEFAULT,
			apply_filters( 'newspack_frontend_registration_rate_limit', 10, '203.0.113.9', $bucket )
		);
		// Every other bucket is left alone.
		$this->assertSame( 10, apply_filters( 'newspack_frontend_registration_rate_limit', 10, '203.0.113.9', 'registration' ) );
	}

	/**
	 * The registration key derives from a stored per-integration seed, so it
	 * is stable across requests but revocable on its own — without rotating
	 * AUTH_SALT and logging out every user.
	 */
	public function test_registration_key_is_stable_and_rotatable() {
		$integration = Integrations::get_integration( Form_Capture::ID );

		$key = $integration->get_registration_key();
		$this->assertNotEmpty( $key );
		$this->assertSame( $key, $integration->get_registration_key(), 'The key must be deterministic (pages are cached).' );

		$new_key = $integration->rotate_registration_key();
		$this->assertNotSame( $key, $new_key, 'Rotation must invalidate the previous key.' );
		$this->assertSame( $new_key, $integration->get_registration_key(), 'The rotated key must be stable again.' );
	}
}
