<?php
/**
 * Tests the Reader Activation ESP data sync functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Sync;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;

require_once __DIR__ . '/../mocks/newsletters-mocks.php';
require_once __DIR__ . '/integrations/class-failing-sample-integration.php';

/**
 * Test the Esp_Metadata_Sync class.
 */
class Newspack_Test_Reader_Activation_Sync extends WP_UnitTestCase {
	/**
	 * Gets a sample contact for the tests
	 *
	 * @return array
	 */
	public function get_sample_contact() {
		$contact = [
			'email'    => 'test@example.com',
			'name'     => 'Test Contact',
			'metadata' => [],
		];
		foreach ( array_keys( Sync\Metadata::get_keys() ) as $key ) {
			$contact['metadata'][ Sync\Metadata::get_key( $key ) ] = 'value';
		}
		return $contact;
	}

	/**
	 * Sets the Metadata keys option to the given value
	 *
	 * @param array|string $value The value to set the option to.
	 */
	public function set_option( $value ) {
		Sync\Metadata::update_fields( $value );
	}

	/**
	 * Test whether reader data can be synced.
	 */
	public function test_can_esp_sync() {
		$this->assertFalse( Contact_Sync::can_sync(), 'Reader data should not be syncable by default' );

		$errors = Contact_Sync::can_sync( true );
		$this->assertInstanceOf( 'WP_Error', $errors );

		// Assert all errors.
		$this->assertTrue( $errors->has_errors() );
		$error_codes = $errors->get_error_codes();
		$this->assertNotContains( 'ras_not_enabled', $error_codes, 'Reader Activation is always enabled in test env' );
		$this->assertNotContains( 'ras_esp_sync_not_enabled', $error_codes, 'RAS ESP Sync is enabled by default' );
		$this->assertContains( 'esp_sync_not_allowed', $error_codes, 'RAS ESP Sync is not allowed on non-production site' );
	}

	/**
	 * Test specific ESP integration checks.
	 */
	public function test_esp_integration_checks() {
		$esp_integration = new Integrations\ESP();
		$errors = $esp_integration->can_sync( true );
		$this->assertInstanceOf( 'WP_Error', $errors );
		$this->assertTrue( $errors->has_errors() );
		$error_codes = $errors->get_error_codes();

		$this->assertContains( 'ras_esp_master_list_id_not_found', $error_codes, 'Missing master list ID' );

		// Disable ESP sync.
		Integrations::disable( 'esp' );
		$esp_integration->update_settings_field_value( 'sync_esp', false );
		$errors = $esp_integration->can_sync( true );
		$this->assertContains( 'ras_esp_sync_not_enabled', $errors->get_error_codes(), 'RAS ESP Sync is disabled' );

		// Reenable ESP sync.
		Integrations::enable( 'esp' );

		// Allow ESP sync via constant. We're not testing `Newspack_Manager::is_connected_to_production_manager()` here.
		if ( ! defined( 'NEWSPACK_ALLOW_READER_SYNC' ) ) {
			define( 'NEWSPACK_ALLOW_READER_SYNC', true );
		}
		$errors = $esp_integration->can_sync( true );
		$this->assertNotContains( 'esp_sync_not_allowed', $errors->get_error_codes(), 'RAS ESP Sync is allowed via constant' );

		// Set master list ID.
		$esp_integration->update_settings_field_value( 'mailchimp_audience_id', '123' );
		$errors = $esp_integration->can_sync( true );
		$this->assertNotContains( 'ras_esp_master_list_id_not_found', $errors->get_error_codes(), 'Master list ID is set' );

		$this->assertTrue( $esp_integration->can_sync(), 'Reader data should be syncable after conditions are met' );
		define( 'NEWSPACK_FORCE_ALLOW_ESP_SYNC', true );
		$this->assertTrue( $esp_integration->can_sync(), 'Reader data should be syncable with a force constant' );
		$errors = $esp_integration->can_sync( true );
		$this->assertFalse( $errors->has_errors(), 'No errors should be returned with a force constant' );
	}

	/**
	 * Test that ESP::is_set_up() reads stored configuration only — never makes
	 * a live provider call. Protects every gate that consults is_set_up()
	 * (Integrations::get_active_configured_integrations and the retry-time
	 * guards in Contact_Sync / Contact_Pull) from silently dropping traffic
	 * on transient ESP failures, which the AS retry system is meant to survive.
	 */
	public function test_esp_is_set_up_reads_stored_state() {
		$esp = new Integrations\ESP();

		// Master list ID not stored → setup is incomplete.
		$esp->update_settings_field_value( 'mailchimp_audience_id', '' );
		$this->assertFalse( $esp->is_set_up(), 'is_set_up() must be false when master list ID is not stored.' );

		// Admin selects a list → setup is complete.
		$esp->update_settings_field_value( 'mailchimp_audience_id', '123' );
		$this->assertTrue( $esp->is_set_up(), 'is_set_up() must be true when provider + master list are stored.' );
	}

	/**
	 * Test that ESP::is_set_up() returns false when no provider is selected —
	 * the exact default-state scenario the hotfix targets (ESP auto-enabled on
	 * fresh installs while `newspack_newsletters_service_provider` is unset).
	 */
	public function test_esp_is_set_up_returns_false_when_no_provider_selected() {
		$esp = new Integrations\ESP();
		$esp->update_settings_field_value( 'mailchimp_audience_id', '123' );

		// Even with a master list stored, an unconfigured provider must short-circuit.
		\Newspack_Newsletters::$is_service_provider_configured = false;
		try {
			$this->assertFalse(
				$esp->is_set_up(),
				'is_set_up() must be false when no provider is selected, even if a master list ID is stored.'
			);
		} finally {
			\Newspack_Newsletters::reset_calls();
		}
	}

	/**
	 * Test contact data sync to ESP.
	 */
	public function test_sync_contact_data() {
		// Set connected ESP to ActiveCampaign.
		\update_option( 'newspack_newsletters_service_provider', 'active_campaign' );
		$contact_data_with_raw_keys      = [
			'email'    => 'test@example.com',
			'name'     => 'Test Contact',
			'metadata' => [
				'account'           => 123,
				'registration_date' => '2023-12-11',
				'current_page_url'  => 'https://newspack.com/registration-page/',
			],
		];
		$contact_data_with_prefixed_keys = [
			'email'    => 'test@example.com',
			'name'     => 'Test Contact',
			'metadata' => [
				'NP_Account'           => 123,
				'NP_Registration Date' => '2023-12-11',
				'NP_Registration Page' => 'https://newspack.com/registration-page/',
			],
		];
		$contact_data_with_custom_prefix = [
			'email'    => 'test@example.com',
			'name'     => 'Test Contact',
			'metadata' => [
				'CU_Account'           => 123,
				'CU_Registration Date' => '2023-12-11',
				'CU_Registration Page' => 'https://newspack.com/registration-page/',
			],
		];

		$this->assertEquals(
			$contact_data_with_prefixed_keys,
			Sync\Metadata::normalize_contact_data( $contact_data_with_raw_keys ),
			'Raw metadata keys should be converted to prefixed keys.'
		);

		Sync\Metadata::update_prefix( 'CU_' );

		$this->assertEquals(
			$contact_data_with_custom_prefix,
			Sync\Metadata::normalize_contact_data( $contact_data_with_raw_keys ),
			'Metadata keys should be prefixed with the custom prefix, if set.'
		);

		// Clear from last test.
		Sync\Metadata::update_prefix( '' );

		$contact_data_with_prefixed_keys['metadata']['NP_Invalid_Key'] = 'Invalid data';
		$this->assertEquals(
			array_diff( $contact_data_with_prefixed_keys['metadata'], Sync\Metadata::normalize_contact_data( $contact_data_with_prefixed_keys )['metadata'] ),
			[ 'NP_Invalid_Key' => 'Invalid data' ],
			'Most keys should be exact.'
		);

		unset( $contact_data_with_prefixed_keys['metadata']['NP_Invalid_Key'] );
		$contact_data_with_prefixed_keys['metadata']['NP_Signup UTM: foo'] = 'bar';
		$this->assertArrayHasKey(
			'NP_Signup UTM: foo',
			Sync\Metadata::normalize_contact_data( $contact_data_with_prefixed_keys )['metadata'],
			'But UTM keys can have arbitrary suffixes.'
		);

		// And UTM keys MUST have a suffix.
		$contact_data_with_prefixed_keys['metadata']['NP_Signup UTM: '] = 'foo';
		$contact_data_with_prefixed_keys['metadata']['signup_page_utm'] = 'bar';
		$contact_data_with_prefixed_keys['metadata']['signup_page_utm_'] = 'baz';
		$this->assertArrayNotHasKey(
			'NP_Signup UTM: ',
			Sync\Metadata::normalize_contact_data( $contact_data_with_prefixed_keys )['metadata'],
			'Prefixed UTM keys must have a suffix.'
		);
		$this->assertArrayNotHasKey(
			'signup_page_utm',
			Sync\Metadata::normalize_contact_data( $contact_data_with_prefixed_keys )['metadata'],
			'Raw UTM keys must have a suffix.'
		);
		$this->assertArrayNotHasKey(
			'NP_Signup UTM: ',
			Sync\Metadata::normalize_contact_data( $contact_data_with_prefixed_keys )['metadata'],
			'Raw UTM keys must have a suffix.'
		);
	}

	/**
	 * Test the normalize_contact_data method with default fields
	 */
	public function test_with_default_option() {
		$contact = $this->get_sample_contact();
		$normalized = Sync\Metadata::normalize_contact_data( $contact );

		// Strip unsuffixed UTM keys.
		unset( $contact['metadata'][ Sync\Metadata::get_key( 'signup_page_utm' ) ] );
		unset( $contact['metadata'][ Sync\Metadata::get_key( 'payment_page_utm' ) ] );

		$this->assertSame( $contact, $normalized, 'All default keys pass normalization except for unsuffixed UTM keys.' );
	}

	/**
	 * Test the normalize_contact_data method with the fields option set to empty
	 */
	public function test_with_empty_selected() {
		$contact = $this->get_sample_contact();
		$this->set_option( [] );
		$normalized = Sync\Metadata::normalize_contact_data( $contact );
		$this->assertEmpty( $normalized['metadata'] );
	}

	/**
	 * Test the normalize_contact_data method with the fields option containing only invalid values
	 */
	public function test_with_all_invalid_selected() {
		$contact = $this->get_sample_contact();
		$this->set_option( [ 'invalid_1', 'invalid_2' ] );
		$normalized = Sync\Metadata::normalize_contact_data( $contact );
		$this->assertEmpty( $normalized['metadata'] );
	}

	/**
	 * Test the normalize_contact_data method with the fields option containing only valid values
	 */
	public function test_with_all_valid_selected() {
		$contact = $this->get_sample_contact();
		$defaults = array_keys( Sync\Metadata::get_keys() );
		$this->set_option( [ Sync\Metadata::get_keys()[ $defaults[0] ], Sync\Metadata::get_keys()[ $defaults[1] ] ] );
		$normalized = Sync\Metadata::normalize_contact_data( $contact );
		$this->assertArrayHasKey( Sync\Metadata::get_key( $defaults[0] ), $normalized['metadata'] );
		$this->assertArrayHasKey( Sync\Metadata::get_key( $defaults[1] ), $normalized['metadata'] );
		$this->assertArrayNotHasKey( Sync\Metadata::get_key( $defaults[2] ), $normalized['metadata'] );
		$this->assertArrayNotHasKey( Sync\Metadata::get_key( $defaults[3] ), $normalized['metadata'] );
	}

	/**
	 * Test the normalize_contact_data method with the option containing valid and invalid values
	 */
	public function test_with_valid_and_invalid_selected() {
		$contact  = $this->get_sample_contact();
		$defaults = array_keys( Sync\Metadata::get_keys() );
		$this->set_option( [ Sync\Metadata::get_keys()[ $defaults[0] ], Sync\Metadata::get_keys()[ $defaults[1] ], 'invalid' ] );
		$normalized = Sync\Metadata::normalize_contact_data( $contact );
		$this->assertArrayHasKey( Sync\Metadata::get_key( $defaults[0] ), $normalized['metadata'] );
		$this->assertArrayHasKey( Sync\Metadata::get_key( $defaults[1] ), $normalized['metadata'] );
		$this->assertArrayNotHasKey( Sync\Metadata::get_key( $defaults[2] ), $normalized['metadata'] );
		$this->assertArrayNotHasKey( Sync\Metadata::get_key( $defaults[3] ), $normalized['metadata'] );
		$this->assertCount( 2, $normalized['metadata'] );
	}

	/**
	 * Test the normalize_contact_data method with the option containing UTM values.
	 * UTM field keys can have arbitrary suffixes.
	 */
	public function test_with_utm_fields() {
		$contact  = $this->get_sample_contact();
		$defaults = array_keys( Sync\Metadata::get_keys() );
		$this->set_option( [ Sync\Metadata::get_keys()['signup_page_utm'], Sync\Metadata::get_keys()['payment_page_utm'] ] );
		$contact['metadata'][ Sync\Metadata::get_key( 'signup_page_utm' ) . 'foo' ] = 'bar';
		$contact['metadata'][ Sync\Metadata::get_key( 'payment_page_utm' ) . 'yyy' ] = 'zzz';
		$normalized = Sync\Metadata::normalize_contact_data( $contact );
		$this->assertArrayHasKey( Sync\Metadata::get_key( 'signup_page_utm' ) . 'foo', $normalized['metadata'] );
		$this->assertArrayHasKey( Sync\Metadata::get_key( 'payment_page_utm' ) . 'yyy', $normalized['metadata'] );
		$this->assertArrayNotHasKey( Sync\Metadata::get_key( $defaults[0] ), $normalized['metadata'] );
		$this->assertArrayNotHasKey( Sync\Metadata::get_key( $defaults[1] ), $normalized['metadata'] );
	}

	/**
	 * Test the normalize_contact_data method with the option containing raw UTM values.
	 */
	public function test_with_raw_utm_fields() {
		$contact  = $this->get_sample_contact();
		$defaults = array_keys( Sync\Metadata::get_keys() );
		$this->set_option( [ Sync\Metadata::get_keys()['signup_page_utm'], Sync\Metadata::get_keys()['payment_page_utm'] ] );
		$contact['metadata']['signup_page_utm_foo'] = 'bar';
		$contact['metadata']['payment_page_utm_yyy'] = 'zzz';
		$normalized = Sync\Metadata::normalize_contact_data( $contact );
		$this->assertArrayHasKey( Sync\Metadata::get_key( 'signup_page_utm' ) . 'foo', $normalized['metadata'] );
		$this->assertArrayHasKey( Sync\Metadata::get_key( 'payment_page_utm' ) . 'yyy', $normalized['metadata'] );
	}

	/**
	 * Register a Failing_Sample_Integration and enable it.
	 *
	 * @param string $id Integration ID.
	 * @return Failing_Sample_Integration
	 */
	private function register_failing_integration( $id = 'failing_mock' ) {
		$integration = new Failing_Sample_Integration( $id, 'Failing Mock' );
		Integrations::register( $integration );
		Integrations::enable( $id );
		return $integration;
	}

	/**
	 * Test that a failed integration push schedules an AS retry.
	 */
	public function test_integration_retry_scheduling() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail = true;
		$this->register_failing_integration();

		// Clear any pending retries.
		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'retry@example.com' ] );

		Contact_Sync::execute_integration_retry(
			[
				'integration_id' => 'failing_mock',
				'user_id'        => $user_id,
				'context'        => 'Test',
				'retry_count'    => 1,
			]
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'failing_mock' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertNotEmpty( $pending, 'A retry should be scheduled when an integration push fails.' );

		// Verify the retry data contains the reason key.
		$action_id = array_key_first( $pending );
		$action    = \ActionScheduler::store()->fetch_action( $action_id );
		$args      = $action->get_args();
		$this->assertArrayHasKey( 'reason', $args[0], 'Retry data should contain a reason key.' );
		$this->assertEquals( 'Mock push failed', $args[0]['reason'], 'Reason should match the error message.' );
	}

	/**
	 * Test that a successful retry does not schedule another retry.
	 */
	public function test_integration_retry_success() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		Failing_Sample_Integration::reset();
		$this->register_failing_integration( 'success_mock' );

		// Clear any pending retries.
		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'success@example.com' ] );

		Contact_Sync::execute_integration_retry(
			[
				'integration_id' => 'success_mock',
				'user_id'        => $user_id,
				'context'        => 'Test',
				'retry_count'    => 1,
			]
		);

		$this->assertEquals( 1, Failing_Sample_Integration::$push_count, 'Integration push should have been called once.' );

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'success_mock' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No retry should be scheduled on success.' );
	}

	/**
	 * Test that retries stop after MAX_RETRIES.
	 */
	public function test_integration_max_retries() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail = true;
		$this->register_failing_integration( 'max_mock' );

		// Clear any pending retries.
		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'max@example.com' ] );

		// Simulate a retry at the max count — should NOT schedule another and should throw.
		$threw = false;
		try {
			Contact_Sync::execute_integration_retry(
				[
					'integration_id' => 'max_mock',
					'user_id'        => $user_id,
					'context'        => 'Test',
					'retry_count'    => Contact_Sync::MAX_RETRIES,
				]
			);
		} catch ( \Exception $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'Should throw an exception on the last retry.' );

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'max_mock' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No retry should be scheduled after max retries.' );
	}

	/**
	 * Test that a failed integration retry logs the error to the current AS action.
	 */
	public function test_integration_retry_as_log_entry() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail = true;
		$this->register_failing_integration( 'log_mock' );

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'log@example.com' ] );

		// Schedule a dummy AS action to simulate the currently-executing action.
		$dummy_action_id = as_schedule_single_action( time() + 3600, 'newspack_dummy_log_action' );
		Contact_Sync::set_current_as_action_id( $dummy_action_id );

		Contact_Sync::execute_integration_retry(
			[
				'integration_id' => 'log_mock',
				'user_id'        => $user_id,
				'context'        => 'Test',
				'retry_count'    => 1,
			]
		);

		Contact_Sync::clear_current_as_action_id();

		// Intermediate retries log a formatted failure message to the current AS action.
		$logs     = \ActionScheduler_Logger::instance()->get_logs( $dummy_action_id );
		$messages = array_map(
			function ( $log ) {
				return $log->get_message();
			},
			$logs
		);
		$has_retry_log = false;
		foreach ( $messages as $message ) {
			if ( false !== strpos( $message, 'Retry 1/' . Contact_Sync::MAX_RETRIES . ' failed for integration "log_mock"' ) ) {
				$has_retry_log = true;
				break;
			}
		}
		$this->assertTrue(
			$has_retry_log,
			'Intermediate retries should log a formatted failure message to AS.'
		);

		// Clean up.
		as_unschedule_all_actions( 'newspack_dummy_log_action' );
	}

	/**
	 * Test that max retries exhausted creates an AS log entry on the current action.
	 */
	public function test_integration_max_retries_as_log_entry() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail = true;
		$this->register_failing_integration( 'deadletter_mock' );

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'deadletter@example.com' ] );

		// Schedule a dummy AS action to simulate the currently-executing action.
		$dummy_action_id = as_schedule_single_action( time() + 3600, 'newspack_dummy_sync_action' );

		// Set the current AS action ID.
		Contact_Sync::set_current_as_action_id( $dummy_action_id );

		// Execute at max retry count — push fails, triggers max-retries guard and throws.
		try {
			Contact_Sync::execute_integration_retry(
				[
					'integration_id' => 'deadletter_mock',
					'user_id'        => $user_id,
					'context'        => 'Test',
					'retry_count'    => Contact_Sync::MAX_RETRIES,
				]
			);
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — final retry throws so AS marks the action as failed.
		}

		Contact_Sync::clear_current_as_action_id();

		// Verify AS log entry on the dummy action.
		$logs     = \ActionScheduler_Logger::instance()->get_logs( $dummy_action_id );
		$messages = array_map(
			function ( $log ) {
				return $log->get_message();
			},
			$logs
		);
		$this->assertTrue(
			in_array( 'Max retries exhausted.', $messages, true ),
			'AS logs should contain the max retries exhausted message.'
		);

		// Clean up.
		as_unschedule_all_actions( 'newspack_dummy_sync_action' );
	}

	/**
	 * Test that sync retry exhaustion fires the alert hook.
	 */
	public function test_sync_retry_exhaustion_fires_hook() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$hook_fired = false;
		$hook_data  = null;
		add_action(
			'newspack_sync_retry_exhausted',
			function ( $data ) use ( &$hook_fired, &$hook_data ) {
				$hook_fired = true;
				$hook_data  = $data;
			}
		);

		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail = true;
		$this->register_failing_integration( 'exhaustion_mock' );

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'exhaustion@example.com' ] );

		// Execute at max retry count — triggers exhaustion and throws.
		try {
			Contact_Sync::execute_integration_retry(
				[
					'integration_id' => 'exhaustion_mock',
					'user_id'        => $user_id,
					'context'        => 'Test',
					'retry_count'    => Contact_Sync::MAX_RETRIES,
				]
			);
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — final retry throws so AS marks the action as failed.
		}

		$this->assertTrue( $hook_fired, 'newspack_sync_retry_exhausted should fire on max retries.' );
		$this->assertEquals( 'exhaustion_mock', $hook_data['integration_id'] );
		$this->assertEquals( $user_id, $hook_data['user_id'] );
		$this->assertEquals( Contact_Sync::MAX_RETRIES, $hook_data['retry_count'] );
		$this->assertArrayHasKey( 'reason', $hook_data );
	}

	/**
	 * Test that classify_error sorts ESP error messages into the right class.
	 */
	public function test_classify_error_signatures() {
		$reflection = new \ReflectionMethod( Contact_Sync::class, 'classify_error' );
		$reflection->setAccessible( true );

		$cases = [
			'neil@example.com was permanently deleted and cannot be re-imported.' => 'permanent_contact',
			'asdf@example.com looks fake or invalid, please enter a real email address.' => 'permanent_contact',
			'Please enter a number Your merge fields were invalid.' => 'permanent_contact',
			'Please provide a valid email address.' => 'permanent_contact',
			'Contact Email Address is not valid.'   => 'permanent_contact',
			'API Access has been disabled for this account.' => 'permanent_config',
			'Payment Required'                      => 'permanent_config',
			'cindy@example.com is already a list member. Use PUT to insert or update.' => 'benign',
			'Member Exists'                         => 'benign',
			'garcia@example.com has signed up to a lot of lists very recently' => 'transient',
			'Some unknown transient network error'  => 'transient',
		];

		foreach ( $cases as $message => $expected ) {
			$this->assertEquals(
				$expected,
				$reflection->invoke( null, new \WP_Error( 'esp_error', $message ) ),
				sprintf( 'Message "%s" should classify as %s.', $message, $expected )
			);
		}
	}

	/**
	 * Test that classify_error matches signatures in any message of an
	 * aggregate WP_Error, not just the first — the ESP layer prepends
	 * invalid-list and exception messages ahead of the provider's own error.
	 */
	public function test_classify_error_reads_all_messages() {
		$reflection = new \ReflectionMethod( Contact_Sync::class, 'classify_error' );
		$reflection->setAccessible( true );

		$error = new \WP_Error( 'newspack_newsletters_invalid_list', 'Invalid list: xyz123' );
		$error->add( 'esp_error', 'API Access has been disabled for this account.' );

		$this->assertEquals(
			'permanent_config',
			$reflection->invoke( null, $error ),
			'A signature in a later message of an aggregate WP_Error should still classify.'
		);
	}

	/**
	 * Test that the deletion direction uses its own signature map: "member
	 * exists" phrases must retry (the ESP contact still exists without the
	 * deletion flags) and "was permanently deleted" is the benign end-state.
	 */
	public function test_classify_error_deletion_direction() {
		$reflection = new \ReflectionMethod( Contact_Sync::class, 'classify_error' );
		$reflection->setAccessible( true );

		$cases = [
			'Member Exists' => 'transient',
			'cindy@example.com is already a list member. Use PUT to insert or update.' => 'transient',
			'neil@example.com was permanently deleted and cannot be re-imported.' => 'benign',
			'asdf@example.com looks fake or invalid, please enter a real email address.' => 'permanent_contact',
			'API Access has been disabled for this account.' => 'permanent_config',
		];

		foreach ( $cases as $message => $expected ) {
			$this->assertEquals(
				$expected,
				$reflection->invoke( null, new \WP_Error( 'esp_error', $message ), 'deletion' ),
				sprintf( 'Deletion-direction message "%s" should classify as %s.', $message, $expected )
			);
		}
	}

	/**
	 * Test that a permanent contact-data error skips the retry without firing
	 * the permanent-failure hook (only config failures are surfaced).
	 */
	public function test_permanent_contact_error_skips_retry_without_alert() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$fired = false;
		add_action(
			'newspack_sync_permanent_failure',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail  = true;
		Failing_Sample_Integration::$fail_message = 'saraeschwartz@example.com looks fake or invalid, please enter a real email address.';
		$this->register_failing_integration( 'permanent_mock' );

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'perm@example.com' ] );

		Contact_Sync::execute_integration_retry(
			[
				'integration_id' => 'permanent_mock',
				'user_id'        => $user_id,
				'context'        => 'Test',
				'retry_count'    => 1,
			]
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'permanent_mock' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);

		$this->assertEmpty( $pending, 'No retry should be scheduled for a permanent error.' );
		$this->assertFalse( $fired, 'Permanent contact-data failures should not fire newspack_sync_permanent_failure.' );
	}

	/**
	 * Test that a benign error (contact already synced) skips the retry without
	 * firing the permanent-failure hook.
	 */
	public function test_benign_error_skips_retry_without_alert() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$fired = false;
		add_action(
			'newspack_sync_permanent_failure',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail  = true;
		Failing_Sample_Integration::$fail_message = 'cindy@example.com is already a list member. Use PUT to insert or update.';
		$this->register_failing_integration( 'benign_mock' );

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'benign@example.com' ] );

		Contact_Sync::execute_integration_retry(
			[
				'integration_id' => 'benign_mock',
				'user_id'        => $user_id,
				'context'        => 'Test',
				'retry_count'    => 1,
			]
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'benign_mock' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);

		$this->assertEmpty( $pending, 'No retry should be scheduled for a benign result.' );
		$this->assertFalse( $fired, 'Benign results should not fire newspack_sync_permanent_failure.' );
	}

	/**
	 * Test that execute_integration_retry aborts the retry chain when the
	 * integration becomes unconfigured between schedule and execute — drains
	 * existing flood without scheduling further attempts.
	 *
	 * The initial-push gate at Contact_Sync::push_to_integrations is the
	 * structural twin of Integrations::run_health_checks's gate (tested in
	 * class-test-integrations.php) and Contact_Pull::pull_all's gate (also
	 * tested there); a direct reflection-based unit test on push_to_integrations
	 * is not feasible because the iteration also touches the live ESP
	 * integration whose push_contact_data hits Newspack_Newsletters_Contacts
	 * (not loaded in the unit-test env).
	 */
	public function test_execute_integration_retry_aborts_when_not_set_up() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail     = true;
		Failing_Sample_Integration::$is_set_up_value = false;
		$this->register_failing_integration( 'retry_abort_mock' );

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'retry-abort@example.com' ] );

		Contact_Sync::execute_integration_retry(
			[
				'integration_id' => 'retry_abort_mock',
				'user_id'        => $user_id,
				'context'        => 'Test',
				'retry_count'    => 1,
			]
		);

		$this->assertSame( 0, Failing_Sample_Integration::$push_count, 'push_contact_data must not be called when is_set_up() returns false at retry time.' );

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'retry_abort_mock' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No new retry should be scheduled when integration becomes unconfigured mid-chain.' );
	}

	/**
	 * Test that invalid retry data is handled gracefully.
	 */
	public function test_integration_retry_invalid_data() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		// Clear any pending retries.
		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		// Missing integration_id.
		Contact_Sync::execute_integration_retry(
			[
				'user_id'     => 1,
				'retry_count' => 1,
			]
		);

		// Missing user_id.
		Contact_Sync::execute_integration_retry(
			[
				'integration_id' => 'failing_mock',
				'retry_count'    => 1,
			]
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'failing_mock' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No retry should be scheduled for invalid data.' );
	}

	/**
	 * Test that a permanent deletion error skips the retry and fires the hook.
	 */
	public function test_permanent_deletion_error_skips_retry() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$fired = false;
		$data  = null;
		add_action(
			'newspack_sync_permanent_failure',
			function ( $d ) use ( &$fired, &$data ) {
				$fired = true;
				$data  = $d;
			}
		);

		as_unschedule_all_actions( Contact_Sync::RETRY_DELETION_HOOK );

		$reflection = new \ReflectionMethod( Contact_Sync::class, 'schedule_deletion_retry' );
		$reflection->setAccessible( true );
		$reflection->invoke(
			null,
			'esp',           // integration_id.
			'delete',        // mode.
			'gone@example.com', // email.
			[],              // contact.
			'Test',          // context.
			0,               // retry_count.
			new \WP_Error( 'esp_error', 'API Access has been disabled for this account.' )
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_DELETION_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);

		$this->assertEmpty( $pending, 'No deletion retry should be scheduled for a permanent error.' );
		$this->assertTrue( $fired, 'newspack_sync_permanent_failure should fire.' );
		$this->assertEquals( 'delete', $data['mode'] );
		$this->assertEquals( 'gone@example.com', $data['email'] );
		$this->assertEquals( 'esp', $data['integration_id'] );
		$this->assertEquals( 'Test', $data['context'] );
		$this->assertEquals( 'API Access has been disabled for this account.', $data['reason'] );
		$this->assertEquals( 'permanent_config', $data['error_class'] );
	}

	/**
	 * Test that a permanent config error fires the permanent-failure hook even
	 * when the sync has no resolvable WP user (guest checkouts, users deleted
	 * mid-flight) — classification runs before the user-existence bail.
	 */
	public function test_permanent_config_alert_fires_without_user() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$fired = false;
		$data  = null;
		add_action(
			'newspack_sync_permanent_failure',
			function ( $d ) use ( &$fired, &$data ) {
				$fired = true;
				$data  = $d;
			}
		);

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$reflection = new \ReflectionMethod( Contact_Sync::class, 'schedule_integration_retry' );
		$reflection->setAccessible( true );
		$reflection->invoke(
			null,
			'esp',  // integration_id.
			0,      // user_id — no resolvable WP user.
			'Test', // context.
			0,      // retry_count.
			new \WP_Error( 'esp_error', 'API Access has been disabled for this account.' )
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);

		$this->assertEmpty( $pending, 'No retry should be scheduled for a permanent error.' );
		$this->assertTrue( $fired, 'newspack_sync_permanent_failure should fire even without a WP user.' );
		$this->assertSame( '', $data['email'], 'Email should be empty when no WP user was resolved.' );
		$this->assertEquals( 'permanent_config', $data['error_class'] );
	}

	/**
	 * Test that a benign result on the final retry does not mark the AS action
	 * as failed — the chain is deliberately ended for an effectively-synced
	 * outcome, so the executor must not throw.
	 */
	public function test_benign_error_at_max_retries_does_not_throw() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail  = true;
		Failing_Sample_Integration::$fail_message = 'Member Exists';
		$this->register_failing_integration( 'benign_final_mock' );

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$user_id = $this->factory()->user->create( [ 'user_email' => 'benign-final@example.com' ] );

		// Would throw before the benign-aware guard; an exception here fails the test.
		Contact_Sync::execute_integration_retry(
			[
				'integration_id' => 'benign_final_mock',
				'user_id'        => $user_id,
				'context'        => 'Test',
				'retry_count'    => Contact_Sync::MAX_RETRIES,
			]
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'benign_final_mock' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No retry should be scheduled for a benign result.' );
	}

	/**
	 * Test that "member exists" responses on the deletion path are retried:
	 * they mean the ESP contact still exists WITHOUT the deletion flags, so
	 * skipping the retry would permanently drop the deletion signal.
	 */
	public function test_deletion_member_exists_is_retried() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		as_unschedule_all_actions( Contact_Sync::RETRY_DELETION_HOOK );

		$reflection = new \ReflectionMethod( Contact_Sync::class, 'schedule_deletion_retry' );
		$reflection->setAccessible( true );
		$reflection->invoke(
			null,
			'esp',                              // integration_id.
			'flag',                             // mode.
			'gone@example.com',                 // email.
			[ 'email' => 'gone@example.com' ],  // contact.
			'Test',                             // context.
			0,                                  // retry_count.
			new \WP_Error( 'esp_error', 'Member Exists' )
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_DELETION_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertCount( 1, $pending, '"Member Exists" on a deletion push must schedule a retry.' );
	}

	/**
	 * Test that "was permanently deleted" on the deletion path is the benign
	 * end-state: the contact is already gone, so no retry and no alert.
	 */
	public function test_deletion_already_deleted_is_benign() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$fired = false;
		add_action(
			'newspack_sync_permanent_failure',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		as_unschedule_all_actions( Contact_Sync::RETRY_DELETION_HOOK );

		$reflection = new \ReflectionMethod( Contact_Sync::class, 'schedule_deletion_retry' );
		$reflection->setAccessible( true );
		$reflection->invoke(
			null,
			'esp',              // integration_id.
			'delete',           // mode.
			'gone@example.com', // email.
			[],                 // contact.
			'Test',             // context.
			0,                  // retry_count.
			new \WP_Error( 'esp_error', 'neil@example.com was permanently deleted and cannot be re-imported.' )
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_DELETION_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No retry should be scheduled when the contact is already gone.' );
		$this->assertFalse( $fired, 'An already-deleted contact is the deletion end-state, not a failure.' );
	}

	/**
	 * Test that a permanent contact-data error on the deletion path fires the
	 * permanent-failure hook — a skipped deletion retry has no natural
	 * re-trigger, so the dropped deletion signal must stay observable.
	 */
	public function test_deletion_permanent_contact_error_fires_hook() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$fired = false;
		$data  = null;
		add_action(
			'newspack_sync_permanent_failure',
			function ( $d ) use ( &$fired, &$data ) {
				$fired = true;
				$data  = $d;
			}
		);

		as_unschedule_all_actions( Contact_Sync::RETRY_DELETION_HOOK );

		$reflection = new \ReflectionMethod( Contact_Sync::class, 'schedule_deletion_retry' );
		$reflection->setAccessible( true );
		$reflection->invoke(
			null,
			'esp',                              // integration_id.
			'flag',                             // mode.
			'gone@example.com',                 // email.
			[ 'email' => 'gone@example.com' ],  // contact.
			'Test',                             // context.
			0,                                  // retry_count.
			new \WP_Error( 'esp_error', 'asdf@example.com looks fake or invalid, please enter a real email address.' )
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_DELETION_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No retry should be scheduled for a permanent contact-data error.' );
		$this->assertTrue( $fired, 'Deletion-path permanent contact-data errors must fire the hook.' );
		$this->assertEquals( 'permanent_contact', $data['error_class'] );
		$this->assertEquals( 'flag', $data['mode'] );
	}
}
