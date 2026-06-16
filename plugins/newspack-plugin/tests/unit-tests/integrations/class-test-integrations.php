<?php
/**
 * Tests for the Integrations class.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Data_Events;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Contact_Cron;
use Newspack\Reader_Activation\Integrations\Contact_Pull;
use Sample_Integration;

/**
 * Tests for the Integrations class.
 */
class Test_Integrations extends \WP_UnitTestCase {

	/**
	 * Stored pre_http_request callback for removal in tear_down.
	 *
	 * @var callable|null
	 */
	private $loopback_filter = null;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Integrations::OPTION_NAME );
		delete_metadata( 'user', 0, Contact_Cron::PULL_PENDING_META, '', true );
		delete_metadata( 'user', 0, Contact_Cron::PUSH_PENDING_META, '', true );
		$this->reset_integrations();
		$this->reset_handler_map();
		Sample_Integration::reset();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		if ( $this->loopback_filter ) {
			remove_filter( 'pre_http_request', $this->loopback_filter );
			$this->loopback_filter = null;
		}
		Integrations::register_integrations(); // recover core integrations for future tests.
		parent::tear_down();
	}

	/**
	 * Reset integrations registry via reflection.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Reset handler_map via reflection.
	 */
	private function reset_handler_map() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'handler_map' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Mock the loopback HTTP request so pull_sync calls pull_single_integration directly.
	 *
	 * Intercepts wp_remote_post calls to the AJAX pull endpoint, extracts the
	 * integration_id from the body, and calls pull_single_integration directly.
	 *
	 * @param int $user_id The user ID to pull data for.
	 */
	private function mock_pull_loopback( $user_id ) {
		$this->loopback_filter = function ( $preempt, $parsed_args, $url ) use ( $user_id ) {
			if ( false === strpos( $url, 'action=' . Contact_Pull::AJAX_ACTION ) ) {
				return $preempt;
			}

			$integration_id = $parsed_args['body']['integration_id'] ?? '';
			if ( empty( $integration_id ) ) {
				return $preempt;
			}

			$integration = Integrations::get_integration( $integration_id );
			if ( $integration ) {
				Contact_Pull::pull_single_integration( $user_id, $integration );
			}

			return [
				'response' => [ 'code' => 200 ],
				'body'     => '{"success":true}',
			];
		};

		add_filter( 'pre_http_request', $this->loopback_filter, 10, 3 );
	}

	/**
	 * Test registering an integration.
	 */
	public function test_register_integration() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );

		$this->assertTrue( Integrations::register( $integration ) );
		$this->assertNotNull( Integrations::get_integration( 'test-id' ) );
	}

	/**
	 * Test registering duplicate integration returns false.
	 */
	public function test_register_duplicate_returns_false() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );

		Integrations::register( $integration );
		$this->assertFalse( Integrations::register( $integration ) );
	}

	/**
	 * Test registering invalid object returns false.
	 */
	public function test_register_invalid_returns_false() {
		$this->assertFalse( Integrations::register( new \stdClass() ) );
	}

	/**
	 * Test enabling an integration.
	 */
	public function test_enable_integration() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );
		Integrations::register( $integration );

		$this->assertTrue( Integrations::enable( 'test-id' ) );
		$this->assertTrue( Integrations::is_enabled( 'test-id' ) );
	}

	/**
	 * Test enabling unregistered integration returns false.
	 */
	public function test_enable_unregistered_returns_false() {
		$this->assertFalse( Integrations::enable( 'nonexistent' ) );
	}

	/**
	 * Test disabling an integration.
	 */
	public function test_disable_integration() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );
		Integrations::register( $integration );
		Integrations::enable( 'test-id' );

		$this->assertTrue( Integrations::disable( 'test-id' ) );
		$this->assertFalse( Integrations::is_enabled( 'test-id' ) );
	}

	/**
	 * Test get_active_integrations returns only enabled ones.
	 */
	public function test_get_active_integrations() {
		$integration1 = new Sample_Integration( 'enabled', 'Enabled' );
		$integration2 = new Sample_Integration( 'disabled', 'Disabled' );

		Integrations::register( $integration1 );
		Integrations::register( $integration2 );
		Integrations::enable( 'enabled' );

		$active = Integrations::get_active_integrations();

		$this->assertArrayHasKey( 'enabled', $active );
		$this->assertArrayNotHasKey( 'disabled', $active );
	}

	/**
	 * Test get_active_configured_integrations filters by is_set_up too.
	 *
	 * This is the central skip site for the integration-walking paths
	 * (health checks, sync push, pull). A regression here silently
	 * re-introduces the alert/retry flood on unconfigured integrations.
	 */
	public function test_get_active_configured_integrations_filters_by_is_set_up() {
		$configured   = new Sample_Integration( 'configured', 'Configured' );
		$unconfigured = new class( 'unconfigured', 'Unconfigured' ) extends Sample_Integration {
			/**
			 * Force this mock to report itself as not yet set up.
			 *
			 * @return bool
			 */
			public function is_set_up() {
				return false;
			}
		};

		Integrations::register( $configured );
		Integrations::register( $unconfigured );
		Integrations::enable( 'configured' );
		Integrations::enable( 'unconfigured' );

		$result = Integrations::get_active_configured_integrations();

		$this->assertArrayHasKey( 'configured', $result, 'A set-up integration must be included.' );
		$this->assertArrayNotHasKey( 'unconfigured', $result, 'An unconfigured integration must be excluded.' );
	}

	/**
	 * Test get_available_integrations returns all registered.
	 */
	public function test_get_available_integrations() {
		$integration1 = new Sample_Integration( 'one', 'One' );
		$integration2 = new Sample_Integration( 'two', 'Two' );

		Integrations::register( $integration1 );
		Integrations::register( $integration2 );

		$available = Integrations::get_available_integrations();

		$this->assertCount( 2, $available );
		$this->assertArrayHasKey( 'one', $available );
		$this->assertArrayHasKey( 'two', $available );
	}

	/**
	 * Test that registering a data event handler results in a serializable
	 * static callable being registered with Data Events.
	 */
	public function test_register_handler_is_serializable() {
		$action_name = 'test_integration_event';
		Data_Events::register_action( $action_name );

		$integration = new Sample_Integration( 'test-id', 'Test' );
		Integrations::register( $integration );

		$integration->test_register_handler( $action_name, 'handle_test_event' );

		$handlers = Data_Events::get_action_handlers( $action_name );
		$this->assertCount( 1, $handlers );

		// The handler should be a static callable array (two strings).
		$handler = $handlers[0];
		$this->assertIsArray( $handler );
		$this->assertCount( 2, $handler );
		$this->assertIsString( $handler[0] );
		$this->assertIsString( $handler[1] );
		$this->assertEquals( 'dispatch_data_event_handler', $handler[1] );
	}

	/**
	 * Test that dispatching a data event through Data_Events::handle() calls
	 * the registered instance method on the integration.
	 */
	public function test_dispatch_data_event_handler_calls_instance_method() {
		$action_name = 'test_dispatch_event';
		Data_Events::register_action( $action_name );

		$integration = new Sample_Integration( 'test-id', 'Test' );
		Integrations::register( $integration );
		$integration->test_register_handler( $action_name, 'handle_test_event' );

		$timestamp = time();
		$data      = [ 'key' => 'value' ];
		$client_id = 'test-client';

		Data_Events::handle( $action_name, $timestamp, $data, $client_id );

		$this->assertNotNull( Sample_Integration::$handler_args, 'Instance method should have been called.' );
		$this->assertEquals( $timestamp, Sample_Integration::$handler_args['timestamp'] );
		$this->assertEquals( $data, Sample_Integration::$handler_args['data'] );
		$this->assertEquals( $client_id, Sample_Integration::$handler_args['client_id'] );
	}

	/**
	 * Test that registering an uncallable method is rejected.
	 */
	public function test_register_uncallable_method_is_rejected() {
		$action_name = 'test_uncallable_event';
		Data_Events::register_action( $action_name );

		$integration = new Sample_Integration( 'test-id', 'Test' );
		Integrations::register( $integration );

		$integration->test_register_handler( $action_name, 'nonexistent_method' );

		$handlers = Data_Events::get_action_handlers( $action_name );
		$this->assertEmpty( $handlers, 'Uncallable method should not be registered.' );
	}

	/**
	 * Test that dispatch throws when integration is not found, allowing
	 * Data Events to catch the error and schedule a retry.
	 */
	public function test_dispatch_throws_when_integration_missing() {
		$action_name = 'test_missing_integration_event';
		Data_Events::register_action( $action_name );

		$integration = new Sample_Integration( 'test-id', 'Test' );
		// Register the integration and its handler, then later clear the registry to simulate a missing integration.
		Integrations::register( $integration );
		$integration->test_register_handler( $action_name, 'handle_test_event' );

		// Now remove the integration from the registry.
		$this->reset_integrations();

		// Data_Events::handle() catches \Throwable and schedules a retry,
		// so this should not propagate, but the handler should not be called.
		Data_Events::handle( $action_name, time(), [], 'client' );

		$this->assertNull( Sample_Integration::$handler_args, 'Handler should not be called when integration is missing.' );
	}

	/**
	 * Test get_available_incoming_fields returns empty array when no fields available.
	 */
	public function test_get_available_incoming_fields_empty() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );
		Integrations::register( $integration );

		$fields = $integration->get_available_incoming_fields();

		$this->assertIsArray( $fields );
		$this->assertEmpty( $fields );
	}

	/**
	 * Test get_available_incoming_fields propagates WP_Error from get_available_incoming_fields.
	 */
	public function test_get_available_incoming_fields_propagates_error() {
		$integration = new class( 'error-test', 'Error Test' ) extends Sample_Integration {
			/**
			 * Get incoming available contact fields (returns error for test).
			 *
			 * @return \WP_Error
			 */
			public function get_available_incoming_fields() {
				return new \WP_Error( 'test_error', 'Test error message' );
			}
		};

		Integrations::register( $integration );

		$result = $integration->get_available_incoming_fields();

		$this->assertWPError( $result );
		$this->assertEquals( 'test_error', $result->get_error_code() );
		$this->assertEquals( 'Test error message', $result->get_error_message() );
	}

	/**
	 * Test get_incoming_fields returns empty array by default.
	 */
	public function test_get_incoming_fields_default_empty() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );

		$this->assertSame( [], $integration->get_enabled_incoming_fields() );
	}

	/**
	 * Test update_incoming_fields and get_incoming_fields round-trip.
	 */
	public function test_set_and_get_enabled_incoming_fields() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );
		$keys        = [ 'first_name', 'last_name', 'phone' ];

		$integration->update_enabled_incoming_fields( $keys );

		$result     = $integration->get_enabled_incoming_fields();
		$result_keys = array_map( fn( $f ) => $f->get_key(), $result );
		$this->assertSame( $keys, $result_keys );
	}

	/**
	 * Test update_incoming_fields stores any keys without validation.
	 */
	public function test_update_incoming_fields_stores_any_keys() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );
		$keys        = [ 'nonexistent_field', 'another_unknown' ];

		$integration->update_enabled_incoming_fields( $keys );

		$result     = $integration->get_enabled_incoming_fields();
		$result_keys = array_map( fn( $f ) => $f->get_key(), $result );
		$this->assertSame( $keys, $result_keys );
	}

	/**
	 * Test enqueue is skipped when no user is logged in.
	 */
	public function test_enqueue_skipped_when_not_logged_in() {
		wp_set_current_user( 0 );

		Contact_Cron::maybe_enqueue_contact();

		// No users should be staged since no one is logged in.
		$this->assertEmpty(
			get_users(
				[
					'meta_key' => Contact_Cron::PULL_PENDING_META,
					'fields'   => 'ID',
				]
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$this->assertEmpty(
			get_users(
				[
					'meta_key' => Contact_Cron::PUSH_PENDING_META,
					'fields'   => 'ID',
				]
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	/**
	 * Test enqueue is throttled by the cron interval.
	 */
	public function test_enqueue_throttled_by_interval() {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		// Simulate a recent enqueue for this user.
		update_user_meta( $user_id, Contact_Cron::LAST_ENQUEUE_META, time() );

		Contact_Cron::maybe_enqueue_contact();

		// User should not be staged because the interval hasn't elapsed.
		$this->assertEmpty( get_user_meta( $user_id, Contact_Cron::PULL_PENDING_META, true ) );
		$this->assertEmpty( get_user_meta( $user_id, Contact_Cron::PUSH_PENDING_META, true ) );
	}

	/**
	 * Test sync pull runs when last cron run is older than 24 hours.
	 */
	public function test_sync_pull_when_data_stale() {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		// Set last enqueue to beyond the 24h threshold.
		update_user_meta( $user_id, Contact_Cron::LAST_ENQUEUE_META, time() - Contact_Pull::PULL_SYNC_THRESHOLD - 1 );

		// Create an integration that returns data from pull.
		$integration = new class( 'pull-test', 'Pull Test' ) extends Sample_Integration {
			/**
			 * Pull contact data returning test data.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [ 'favorite_color' => 'blue' ];
			}
		};

		$integration->update_enabled_incoming_fields( [ 'favorite_color' ] );
		Integrations::register( $integration );
		Integrations::enable( 'pull-test' );

		$this->mock_pull_loopback( $user_id );
		Contact_Cron::maybe_enqueue_contact();

		// Verify the data was stored synchronously.
		$stored = get_user_meta( $user_id, 'newspack_reader_data_item_favorite_color', true );
		$this->assertSame( wp_json_encode( 'blue' ), $stored );

		// Verify enqueue timestamp was updated.
		$last_enqueue = (int) get_user_meta( $user_id, Contact_Cron::LAST_ENQUEUE_META, true );
		$this->assertGreaterThanOrEqual( time() - 2, $last_enqueue );
	}

	/**
	 * Test sync pull filters returned data by selected fields only.
	 */
	public function test_sync_pull_filters_by_incoming_fields() {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		update_user_meta( $user_id, Contact_Cron::LAST_ENQUEUE_META, time() - Contact_Pull::PULL_SYNC_THRESHOLD - 1 );

		$integration = new class( 'filter-test', 'Filter Test' ) extends Sample_Integration {
			/**
			 * Pull contact data returning multiple fields.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [
					'field_a' => 'value_a',
					'field_b' => 'value_b',
					'field_c' => 'value_c',
				];
			}
		};

		// Only select fields a and c.
		$integration->update_enabled_incoming_fields( [ 'field_a', 'field_c' ] );
		Integrations::register( $integration );
		Integrations::enable( 'filter-test' );

		$this->mock_pull_loopback( $user_id );
		Contact_Cron::maybe_enqueue_contact();

		// a and c should be stored.
		$this->assertSame( wp_json_encode( 'value_a' ), get_user_meta( $user_id, 'newspack_reader_data_item_field_a', true ) );
		$this->assertSame( wp_json_encode( 'value_c' ), get_user_meta( $user_id, 'newspack_reader_data_item_field_c', true ) );

		// b should NOT be stored.
		$this->assertEmpty( get_user_meta( $user_id, 'newspack_reader_data_item_field_b', true ) );
	}

	/**
	 * Test sync pull catches throwable from integration without fatal.
	 */
	public function test_sync_pull_catches_throwable() {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		update_user_meta( $user_id, Contact_Cron::LAST_ENQUEUE_META, time() - Contact_Pull::PULL_SYNC_THRESHOLD - 1 );

		$integration = new class( 'throw-test', 'Throw Test' ) extends Sample_Integration {
			/**
			 * Pull contact data that throws an exception.
			 *
			 * @param int $user_id WordPress user ID.
			 * @throws \RuntimeException Always.
			 */
			public function pull_contact_data( $user_id ) {
				throw new \RuntimeException( 'Something went wrong' );
			}
		};

		$integration->update_enabled_incoming_fields( [ 'some_field' ] );
		Integrations::register( $integration );
		Integrations::enable( 'throw-test' );

		// Should not throw — the routine catches Throwable.
		$this->mock_pull_loopback( $user_id );
		Contact_Cron::maybe_enqueue_contact();

		// Enqueue meta should still have been set.
		$last_enqueue = (int) get_user_meta( $user_id, Contact_Cron::LAST_ENQUEUE_META, true );
		$this->assertGreaterThanOrEqual( time() - 2, $last_enqueue );
	}

	/**
	 * Test async pull is scheduled when data is fresh (< 24h but past interval).
	 */
	public function test_async_pull_scheduled_when_fresh() {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		// Last enqueue 10 minutes ago — past interval but within 24h.
		update_user_meta( $user_id, Contact_Cron::LAST_ENQUEUE_META, time() - 600 );

		$integration = new class( 'async-test', 'Async Test' ) extends Sample_Integration {
			/**
			 * Pull contact data (should NOT be called synchronously).
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [ 'city' => 'Portland' ];
			}
		};

		$integration->update_enabled_incoming_fields( [ 'city' ] );
		Integrations::register( $integration );
		Integrations::enable( 'async-test' );

		Contact_Cron::maybe_enqueue_contact();

		// Data should NOT have been stored synchronously.
		$stored = get_user_meta( $user_id, 'newspack_reader_data_item_city', true );
		$this->assertEmpty( $stored );

		// Verify user was staged for pull.
		$this->assertNotEmpty( get_user_meta( $user_id, Contact_Cron::PULL_PENDING_META, true ) );
	}

	/**
	 * Test handle_batch_pull processes data for queued users.
	 */
	public function test_handle_batch_pull() {
		$user_id = $this->factory()->user->create();

		$integration = new class( 'handle-test', 'Handle Test' ) extends Sample_Integration {
			/**
			 * Pull contact data returning test data.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [ 'language' => 'PHP' ];
			}
		};

		$integration->update_enabled_incoming_fields( [ 'language' ] );
		Integrations::register( $integration );
		Integrations::enable( 'handle-test' );

		// Stage the user for pull.
		Contact_Cron::enqueue_for_pull( $user_id );

		Contact_Cron::handle_batch();

		$stored = get_user_meta( $user_id, 'newspack_reader_data_item_language', true );
		$this->assertSame( wp_json_encode( 'PHP' ), $stored );

		// User meta flag should be cleared after processing.
		$this->assertEmpty( get_user_meta( $user_id, Contact_Cron::PULL_PENDING_META, true ) );
	}

	/**
	 * Test handle_batch_pull skips disabled integration.
	 */
	public function test_handle_batch_pull_skips_disabled() {
		$user_id = $this->factory()->user->create();

		$integration = new class( 'disabled-test', 'Disabled Test' ) extends Sample_Integration {
			/**
			 * Pull contact data returning test data.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [ 'pet' => 'cat' ];
			}
		};

		$integration->update_enabled_incoming_fields( [ 'pet' ] );
		Integrations::register( $integration );
		// Not enabled.

		Contact_Cron::enqueue_for_pull( $user_id );

		Contact_Cron::handle_batch();

		$stored = get_user_meta( $user_id, 'newspack_reader_data_item_pet', true );
		$this->assertEmpty( $stored );
	}

	/**
	 * Test that first-ever pull (no meta) runs synchronously.
	 */
	public function test_first_pull_runs_sync() {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		// No LAST_ENQUEUE_META set — age will be time() - 0, which is > 24h.

		$integration = new class( 'first-test', 'First Test' ) extends Sample_Integration {
			/**
			 * Pull contact data returning test data.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [ 'first_field' => 'hello' ];
			}
		};

		$integration->update_enabled_incoming_fields( [ 'first_field' ] );
		Integrations::register( $integration );
		Integrations::enable( 'first-test' );

		$this->mock_pull_loopback( $user_id );
		Contact_Cron::maybe_enqueue_contact();

		// Should have run synchronously.
		$stored = get_user_meta( $user_id, 'newspack_reader_data_item_first_field', true );
		$this->assertSame( wp_json_encode( 'hello' ), $stored );
	}

	/**
	 * Test stale sync pull failure enqueues user for batch pull.
	 */
	public function test_stale_sync_pull_failure_enqueues_for_batch() {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		update_user_meta( $user_id, Contact_Cron::LAST_ENQUEUE_META, time() - Contact_Pull::PULL_SYNC_THRESHOLD - 1 );

		$integration = new class( 'timeout-test', 'Timeout Test' ) extends Sample_Integration {
			/**
			 * Pull contact data returning test data.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [ 'timeout_field' => 'should_not_appear' ];
			}
		};

		$integration->update_enabled_incoming_fields( [ 'timeout_field' ] );
		Integrations::register( $integration );
		Integrations::enable( 'timeout-test' );

		// Simulate a timeout by returning WP_Error from the loopback request.
		$this->loopback_filter = function ( $preempt, $parsed_args, $url ) {
			if ( false === strpos( $url, 'action=' . Contact_Pull::AJAX_ACTION ) ) {
				return $preempt;
			}
			return new \WP_Error( 'http_request_failed', 'Connection timed out' );
		};
		add_filter( 'pre_http_request', $this->loopback_filter, 10, 3 );

		Contact_Cron::maybe_enqueue_contact();

		// Stale sync pull failed, user should be staged for batch pull.
		$this->assertNotEmpty( get_user_meta( $user_id, Contact_Cron::PULL_PENDING_META, true ) );

		// User should still be staged for push.
		$this->assertNotEmpty( get_user_meta( $user_id, Contact_Cron::PUSH_PENDING_META, true ) );
	}

	/**
	 * Test health_check returns true when can_sync passes and test_connection succeeds.
	 */
	public function test_health_check_returns_true_when_healthy() {
		$integration = new Sample_Integration( 'healthy', 'Healthy' );
		Integrations::register( $integration );

		$result = $integration->health_check();

		$this->assertTrue( $result );
	}

	/**
	 * Test health_check returns WP_Error from can_sync when validation fails.
	 */
	public function test_health_check_returns_can_sync_error() {
		$integration = new class( 'sync-fail', 'Sync Fail' ) extends Sample_Integration {
			/**
			 * Simulate can_sync validation failure.
			 *
			 * @param bool $return_errors Whether to return WP_Error.
			 * @return bool|\WP_Error
			 */
			public function can_sync( $return_errors = false ) {
				if ( $return_errors ) {
					$errors = new \WP_Error();
					$errors->add( 'missing_key', 'API key is missing.' );
					$errors->add( 'missing_list', 'List ID is not set.' );
					return $errors;
				}
				return false;
			}
		};
		Integrations::register( $integration );

		$result = $integration->health_check();

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_key', $result->get_error_code() );
		$this->assertCount( 2, $result->get_error_messages() );
	}

	/**
	 * Test health_check returns WP_Error from test_connection when live check fails.
	 */
	public function test_health_check_returns_test_connection_error() {
		$integration = new class( 'conn-fail', 'Conn Fail' ) extends Sample_Integration {
			/**
			 * Simulate a connection failure.
			 *
			 * @return \WP_Error
			 */
			public function test_connection() {
				return new \WP_Error( 'connection_failed', 'Could not reach the API.' );
			}
		};
		Integrations::register( $integration );

		$result = $integration->health_check();

		$this->assertWPError( $result );
		$this->assertEquals( 'connection_failed', $result->get_error_code() );
		$this->assertEquals( 'Could not reach the API.', $result->get_error_message() );
	}

	/**
	 * Test health_check short-circuits on can_sync failure without calling test_connection.
	 */
	public function test_health_check_skips_test_connection_on_can_sync_failure() {
		$integration = new class( 'short-circuit', 'Short Circuit' ) extends Sample_Integration {
			/**
			 * Whether test_connection was called.
			 *
			 * @var bool
			 */
			public static $connection_called = false;

			/**
			 * Simulate can_sync validation failure.
			 *
			 * @param bool $return_errors Whether to return WP_Error.
			 * @return bool|\WP_Error
			 */
			public function can_sync( $return_errors = false ) {
				if ( $return_errors ) {
					$errors = new \WP_Error();
					$errors->add( 'not_configured', 'Not configured.' );
					return $errors;
				}
				return false;
			}

			/**
			 * Track whether this method is called.
			 *
			 * @return true
			 */
			public function test_connection() {
				self::$connection_called = true;
				return true;
			}
		};
		Integrations::register( $integration );

		$integration->health_check();

		$this->assertFalse( $integration::$connection_called, 'test_connection should not be called when can_sync fails.' );
	}

	/**
	 * Test health_check catches Throwable from test_connection and returns WP_Error.
	 */
	public function test_health_check_catches_throwable_from_test_connection() {
		$integration = new class( 'throw-conn', 'Throw Conn' ) extends Sample_Integration {
			/**
			 * Simulate a fatal error during connection test.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function test_connection() {
				throw new \RuntimeException( 'Fatal: something exploded' );
			}
		};
		Integrations::register( $integration );

		$result = $integration->health_check();

		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_integration_connection_error', $result->get_error_code() );
		$this->assertEquals( 'Fatal: something exploded', $result->get_error_message() );
	}

	/**
	 * Test run_health_checks skips integrations that are not yet set up.
	 *
	 * An enabled-but-unconfigured integration (e.g. ESP enabled by default
	 * but provider/master list never selected) is a setup-incomplete state,
	 * not a runtime incident — the hourly cron must not generate an alert.
	 */
	public function test_run_health_checks_skips_unconfigured_integrations() {
		$integration = new Sample_Integration( 'unconfigured', 'Unconfigured' );
		Integrations::register( $integration );
		Integrations::enable( 'unconfigured' );

		Sample_Integration::$is_set_up_value      = false;
		Sample_Integration::$can_sync_error_codes = [ 'ras_esp_master_list_id_not_found' ];

		$fired    = false;
		$listener = function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'newspack_integration_health_check_failed', $listener );

		try {
			Integrations::run_health_checks();
			$this->assertFalse( $fired, 'health_check_failed action must not fire when is_set_up() is false.' );
		} finally {
			remove_action( 'newspack_integration_health_check_failed', $listener );
		}
	}

	/**
	 * Test run_health_checks fires the action for set-up integrations whose
	 * health check fails — the control case for the skip behavior above.
	 */
	public function test_run_health_checks_fires_when_set_up_and_failing() {
		$integration = new Sample_Integration( 'failing', 'Failing' );
		Integrations::register( $integration );
		Integrations::enable( 'failing' );

		Sample_Integration::$is_set_up_value      = true;
		Sample_Integration::$can_sync_error_codes = [ 'ras_esp_master_list_id_not_found' ];

		$payload  = null;
		$listener = function ( $data ) use ( &$payload ) {
			$payload = $data;
		};
		add_action( 'newspack_integration_health_check_failed', $listener );

		try {
			Integrations::run_health_checks();
			$this->assertNotNull( $payload, 'health_check_failed action must fire when is_set_up() is true and health_check fails.' );
			$this->assertSame( 'failing', $payload['integration_id'] );
			$this->assertInstanceOf( \WP_Error::class, $payload['error'] );
			$this->assertContains( 'ras_esp_master_list_id_not_found', $payload['error']->get_error_codes() );
		} finally {
			remove_action( 'newspack_integration_health_check_failed', $listener );
		}
	}

	/**
	 * Test Contact_Pull::pull_sync defaults to set-up integrations only.
	 *
	 * The synchronous loopback path (run from Contact_Cron when the user's
	 * last pull is stale) must NOT fire a loopback for an integration whose
	 * `is_set_up()` is false — otherwise it both wastes a blocking remote
	 * call and logs a spurious failure.
	 */
	public function test_pull_sync_skips_unconfigured_integrations() {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$configured = new class( 'sync-configured', 'Sync Configured' ) extends Sample_Integration {
			/**
			 * Pull mock returning data so the loopback would succeed if reached.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [ 'favorite_color' => 'green' ];
			}
		};
		$configured->update_enabled_incoming_fields( [ 'favorite_color' ] );
		Integrations::register( $configured );
		Integrations::enable( 'sync-configured' );

		$unconfigured = new class( 'sync-unconfigured', 'Sync Unconfigured' ) extends Sample_Integration {
			/**
			 * Force this mock to report itself as not yet set up.
			 *
			 * @return bool
			 */
			public function is_set_up() {
				return false;
			}
			/**
			 * Pull mock that would return data if reached — must not be called.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [ 'favorite_color' => 'red' ];
			}
		};
		$unconfigured->update_enabled_incoming_fields( [ 'favorite_color' ] );
		Integrations::register( $unconfigured );
		Integrations::enable( 'sync-unconfigured' );

		// Capture which integration_ids the loopback receives.
		$loopback_hits = [];
		$this->loopback_filter = function ( $preempt, $parsed_args, $url ) use ( &$loopback_hits ) {
			if ( false === strpos( $url, 'action=' . Contact_Pull::AJAX_ACTION ) ) {
				return $preempt;
			}
			$loopback_hits[] = $parsed_args['body']['integration_id'] ?? '';
			return [
				'response' => [ 'code' => 200 ],
				'body'     => '{"success":true}',
			];
		};
		add_filter( 'pre_http_request', $this->loopback_filter, 10, 3 );

		Contact_Pull::pull_sync();

		$this->assertContains( 'sync-configured', $loopback_hits, 'Configured integration must receive a loopback.' );
		$this->assertNotContains( 'sync-unconfigured', $loopback_hits, 'Unconfigured integration must NOT receive a loopback.' );
	}

	/**
	 * Test Contact_Pull::pull_all skips integrations whose is_set_up() is false.
	 *
	 * Mirrors the run_health_checks skip: an unconfigured integration must not
	 * be invoked, must not generate retry rows in ActionScheduler.
	 */
	public function test_pull_all_skips_unconfigured_integrations() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$integration = new class( 'pull-skip', 'Pull Skip' ) extends Sample_Integration {
			/**
			 * Count of pull_contact_data calls.
			 *
			 * @var int
			 */
			public static $pull_count = 0;

			/**
			 * Pull mock that counts invocations.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				self::$pull_count++;
				return [ 'favorite_color' => 'blue' ];
			}
		};
		$integration::$pull_count = 0;
		$integration->update_enabled_incoming_fields( [ 'favorite_color' ] );

		Sample_Integration::$is_set_up_value = false;

		Integrations::register( $integration );
		Integrations::enable( 'pull-skip' );

		\as_unschedule_all_actions( Contact_Pull::RETRY_HOOK );

		$user_id = $this->factory()->user->create();

		Contact_Pull::pull_all( $user_id );

		$this->assertSame( 0, $integration::$pull_count, 'pull_contact_data must not be called when is_set_up() is false.' );

		$pending = \as_get_scheduled_actions(
			[
				'hook'   => Contact_Pull::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'pull-skip' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No pull retry should be scheduled for an unconfigured integration.' );
	}

	/**
	 * Test Contact_Pull::execute_integration_retry aborts when the integration
	 * is no longer set up — drains existing retry rows without scheduling more.
	 */
	public function test_pull_execute_integration_retry_aborts_when_not_set_up() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$integration = new class( 'pull-retry-abort', 'Pull Retry Abort' ) extends Sample_Integration {
			/**
			 * Count of pull_contact_data calls.
			 *
			 * @var int
			 */
			public static $pull_count = 0;

			/**
			 * Pull mock returning WP_Error so retry would be scheduled if reached.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return \WP_Error
			 */
			public function pull_contact_data( $user_id ) {
				self::$pull_count++;
				return new \WP_Error( 'mock_error', 'Mock pull failed' );
			}
		};
		$integration::$pull_count = 0;
		$integration->update_enabled_incoming_fields( [ 'favorite_color' ] );

		Sample_Integration::$is_set_up_value = false;

		Integrations::register( $integration );
		Integrations::enable( 'pull-retry-abort' );

		\as_unschedule_all_actions( Contact_Pull::RETRY_HOOK );

		$user_id = $this->factory()->user->create();

		Contact_Pull::execute_integration_retry(
			[
				'integration_id' => 'pull-retry-abort',
				'user_id'        => $user_id,
				'retry_count'    => 1,
			]
		);

		$this->assertSame( 0, $integration::$pull_count, 'pull_contact_data must not be called when is_set_up() returns false at retry time.' );

		$pending = \as_get_scheduled_actions(
			[
				'hook'   => Contact_Pull::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'pull-retry-abort' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No new pull retry should be scheduled when integration becomes unconfigured mid-chain.' );
	}

	/**
	 * Test get_metadata_prefix returns default 'NP_' when no custom prefix is set.
	 */
	public function test_get_metadata_prefix_default() {
		$integration = new Sample_Integration( 'prefix-test', 'Prefix Test' );

		$this->assertSame( 'NP_', $integration->get_metadata_prefix() );
	}

	/**
	 * Test update_metadata_prefix stores and retrieves a custom prefix.
	 */
	public function test_update_and_get_metadata_prefix() {
		$integration = new Sample_Integration( 'prefix-test', 'Prefix Test' );

		$integration->update_metadata_prefix( 'CUSTOM_' );

		$this->assertSame( 'CUSTOM_', $integration->get_metadata_prefix() );
		$this->assertSame( 'CUSTOM_', get_option( 'newspack_integration_metadata_prefix_prefix-test' ) );
	}

	/**
	 * Test update_metadata_prefix with empty string falls back to 'NP_'.
	 */
	public function test_update_metadata_prefix_empty_falls_back() {
		$integration = new Sample_Integration( 'prefix-test', 'Prefix Test' );

		$integration->update_metadata_prefix( 'CUSTOM_' );
		$integration->update_metadata_prefix( '' );

		$this->assertSame( 'NP_', $integration->get_metadata_prefix() );
	}

	/**
	 * Test metadata prefix is isolated per integration.
	 */
	public function test_metadata_prefix_per_integration_isolation() {
		$integration_a = new Sample_Integration( 'iso-a', 'Integration A' );
		$integration_b = new Sample_Integration( 'iso-b', 'Integration B' );

		$integration_a->update_metadata_prefix( 'AAA_' );
		$integration_b->update_metadata_prefix( 'BBB_' );

		$this->assertSame( 'AAA_', $integration_a->get_metadata_prefix() );
		$this->assertSame( 'BBB_', $integration_b->get_metadata_prefix() );
	}

	/**
	 * Test settings field value routing for metadata_prefix.
	 */
	public function test_settings_field_value_routes_metadata_prefix() {
		$integration = new Sample_Integration( 'route-test', 'Route Test' );

		$this->assertTrue( $integration->update_settings_field_value( 'metadata_prefix', 'API_' ) );
		$this->assertSame( 'API_', $integration->get_settings_field_value( 'metadata_prefix' ) );

		// Verify it wrote to the dedicated option, not the generic settings option.
		$this->assertSame( 'API_', get_option( 'newspack_integration_metadata_prefix_route-test' ) );
		$this->assertFalse( get_option( 'newspack_integration_settings_route-test_metadata_prefix' ) );
	}

	/**
	 * Test get_enabled_outgoing_fields_keys uses integration prefix when prefixed flag is true.
	 */
	public function test_get_enabled_outgoing_fields_keys_uses_integration_prefix() {
		$integration = new Sample_Integration( 'keys-test', 'Keys Test' );
		$integration->update_metadata_prefix( 'TEST_' );
		$integration->update_enabled_outgoing_fields( [ 'Account' ] );

		$keys = $integration->get_enabled_outgoing_fields_keys( true );

		$this->assertNotEmpty( $keys );
		foreach ( $keys as $key ) {
			$this->assertStringStartsWith( 'TEST_', $key, "Key '$key' should start with 'TEST_'" );
		}
	}

	/**
	 * Test get_settings_config includes metadata_prefix field with correct value.
	 */
	public function test_get_settings_config_includes_metadata_prefix() {
		$integration = new Sample_Integration( 'config-test', 'Config Test' );
		$integration->update_metadata_prefix( 'CFG_' );

		$config = $integration->get_settings_config();

		$prefix_field = null;
		foreach ( $config as $field ) {
			if ( 'metadata_prefix' === $field['key'] ) {
				$prefix_field = $field;
				break;
			}
		}

		$this->assertNotNull( $prefix_field, 'Settings config should contain a metadata_prefix field.' );
		$this->assertSame( 'CFG_', $prefix_field['value'] );
	}

	/**
	 * Test handle_ajax_pull processes data when called directly.
	 */
	public function test_handle_ajax_pull() {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$integration = new class( 'ajax-test', 'Ajax Test' ) extends Sample_Integration {
			/**
			 * Pull contact data returning test data.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				return [ 'ajax_field' => 'ajax_value' ];
			}
		};

		$integration->update_enabled_incoming_fields( [ 'ajax_field' ] );
		Integrations::register( $integration );
		Integrations::enable( 'ajax-test' );

		// Call pull_single_integration directly — the AJAX handler is thin glue
		// (nonce + lookup + this call + wp_send_json) and calling it in tests
		// produces unavoidable output from wp_send_json.
		$result = Contact_Pull::pull_single_integration( $user_id, $integration );

		$this->assertTrue( $result );
		$stored = get_user_meta( $user_id, 'newspack_reader_data_item_ajax_field', true );
		$this->assertSame( wp_json_encode( 'ajax_value' ), $stored );
	}

	/**
	 * Test get_action_group returns prefixed integration ID.
	 */
	public function test_get_action_group() {
		$this->assertSame( 'newspack-integration-esp', Integrations::get_action_group( 'esp' ) );
		$this->assertSame( 'newspack-integration-my-crm', Integrations::get_action_group( 'my-crm' ) );
	}

	/**
	 * Test get_action_group_for_handler returns group for registered handler.
	 */
	public function test_get_action_group_for_handler_returns_group() {
		$action_name = 'test_group_event';
		Data_Events::register_action( $action_name );

		$integration = new Sample_Integration( 'test-id', 'Test' );
		Integrations::register( $integration );
		$integration->test_register_handler( $action_name, 'handle_test_event' );

		$group = Integrations::get_action_group_for_handler( Sample_Integration::class, $action_name );
		$this->assertSame( 'newspack-integration-test-id', $group );
	}

	/**
	 * Test get_action_group_for_handler returns null for unknown handler.
	 */
	public function test_get_action_group_for_handler_fallback() {
		$group = Integrations::get_action_group_for_handler( 'NonExistent', 'unknown_action' );
		$this->assertNull( $group );
	}

	/**
	 * Test Data_Events::get_handler_action_group returns 'newspack' by default.
	 */
	public function test_data_events_get_handler_action_group_default() {
		$group = Data_Events::get_handler_action_group( 'SomeClass', 'some_action' );
		$this->assertSame( 'newspack', $group );
	}

	/**
	 * Test Data_Events::get_handler_action_group is filtered by Integrations.
	 */
	public function test_data_events_get_handler_action_group_filtered() {
		$action_name = 'test_filtered_group_event';
		Data_Events::register_action( $action_name );

		$integration = new Sample_Integration( 'filtered-id', 'Filtered' );
		Integrations::register( $integration );
		$integration->test_register_handler( $action_name, 'handle_test_event' );

		$group = Data_Events::get_handler_action_group( Sample_Integration::class, $action_name );
		$this->assertSame( 'newspack-integration-filtered-id', $group );
	}

	/**
	 * Register an active Sample_Integration with the given ID and menu item.
	 *
	 * @param string     $id   Integration ID.
	 * @param array|null $item Menu item declaration or null.
	 * @return Sample_Integration
	 */
	private function register_active_integration_with_menu( $id, $item ) {
		$integration                       = new Sample_Integration( $id, ucfirst( $id ) );
		$integration->my_account_menu_item = $item;
		Integrations::register( $integration );
		Integrations::enable( $id );
		return $integration;
	}

	/**
	 * Reset the private $my_account_endpoints map between tests.
	 */
	private function reset_my_account_endpoints() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'my_account_endpoints' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Test that register_my_account_endpoints() collects declared menu items
	 * only from integrations that opt in, ignoring invalid and opted-out ones.
	 */
	public function test_my_account_collects_declared_menu_items() {
		delete_option( Integrations::MY_ACCOUNT_ENDPOINTS_OPTION );
		$this->reset_my_account_endpoints();

		$this->register_active_integration_with_menu(
			'alpha',
			[
				'slug'  => 'alpha-page',
				'label' => 'Alpha',
			]
		);
		$this->register_active_integration_with_menu( 'beta', null ); // opted out.
		$this->register_active_integration_with_menu(
			'gamma',
			[
				'slug'  => '',
				'label' => 'Gamma',
			] // invalid slug.
		);
		$this->register_active_integration_with_menu(
			'delta',
			[
				'slug'  => 'delta-page',
				'label' => '',
			] // invalid label.
		);

		Integrations::register_my_account_endpoints();

		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'my_account_endpoints' );
		$property->setAccessible( true );
		$map = $property->getValue();

		$this->assertSame( [ 'alpha-page' => 'alpha' ], $map );
	}

	/**
	 * Test that duplicate slugs across integrations keep the first registration.
	 */
	public function test_my_account_collision_first_registration_wins() {
		delete_option( Integrations::MY_ACCOUNT_ENDPOINTS_OPTION );
		$this->reset_my_account_endpoints();

		$this->register_active_integration_with_menu(
			'first',
			[
				'slug'  => 'shared',
				'label' => 'First',
			]
		);
		$this->register_active_integration_with_menu(
			'second',
			[
				'slug'  => 'shared',
				'label' => 'Second',
			]
		);

		Integrations::register_my_account_endpoints();

		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'my_account_endpoints' );
		$property->setAccessible( true );
		$map = $property->getValue();

		$this->assertSame( [ 'shared' => 'first' ], $map );
	}

	/**
	 * Test menu insertion: positioned items sort by position, unpositioned
	 * items append above customer-logout, and existing slugs are not overwritten.
	 */
	public function test_my_account_menu_insertion_ordering_and_logout_handling() {
		delete_option( Integrations::MY_ACCOUNT_ENDPOINTS_OPTION );
		$this->reset_my_account_endpoints();

		$this->register_active_integration_with_menu(
			'positioned',
			[
				'slug'     => 'newsletters',
				'label'    => 'Newsletters',
				'position' => 1,
			]
		);
		$this->register_active_integration_with_menu(
			'appended',
			[
				'slug'  => 'preferences',
				'label' => 'Preferences',
			]
		);
		$this->register_active_integration_with_menu(
			'collides',
			[
				'slug'  => 'orders',
				'label' => 'Should Not Overwrite',
			]
		);

		Integrations::register_my_account_endpoints();

		$initial = [
			'dashboard'       => 'Dashboard',
			'orders'          => 'Orders',
			'customer-logout' => 'Logout',
		];

		$result = Integrations::filter_my_account_menu_items( $initial );
		$keys   = array_keys( $result );

		// "orders" must keep its original label (collision skipped).
		$this->assertSame( 'Orders', $result['orders'] );
		// Positioned "newsletters" inserted at index 1.
		$this->assertSame( 'newsletters', $keys[1] );
		// "preferences" appended above logout.
		$this->assertSame( 'customer-logout', end( $keys ) );
		$this->assertContains( 'preferences', $keys );
		$logout_index      = array_search( 'customer-logout', $keys, true );
		$preferences_index = array_search( 'preferences', $keys, true );
		$this->assertLessThan( $logout_index, $preferences_index );
	}

	/**
	 * Test that the flush-change-detection option is updated only when the
	 * set of endpoint slugs actually changes.
	 */
	public function test_my_account_endpoints_option_tracks_changes() {
		delete_option( Integrations::MY_ACCOUNT_ENDPOINTS_OPTION );
		$this->reset_my_account_endpoints();

		$this->register_active_integration_with_menu(
			'one',
			[
				'slug'  => 'one-page',
				'label' => 'One',
			]
		);

		Integrations::register_my_account_endpoints();
		$this->assertSame( [ 'one-page' ], get_option( Integrations::MY_ACCOUNT_ENDPOINTS_OPTION ) );

		// No change: running again must keep the option stable.
		Integrations::register_my_account_endpoints();
		$this->assertSame( [ 'one-page' ], get_option( Integrations::MY_ACCOUNT_ENDPOINTS_OPTION ) );

		// Add a second integration: option must now include both, sorted.
		$this->register_active_integration_with_menu(
			'two',
			[
				'slug'  => 'two-page',
				'label' => 'Two',
			]
		);
		Integrations::register_my_account_endpoints();
		$this->assertSame( [ 'one-page', 'two-page' ], get_option( Integrations::MY_ACCOUNT_ENDPOINTS_OPTION ) );

		// Disable 'one': option must shrink back to just 'two-page'.
		Integrations::disable( 'one' );
		Integrations::register_my_account_endpoints();
		$this->assertSame( [ 'two-page' ], get_option( Integrations::MY_ACCOUNT_ENDPOINTS_OPTION ) );
	}

	/**
	 * OAuth settings field value: scalar strings are sanitized through sanitize_text_field.
	 */
	public function test_sanitize_settings_field_value_oauth_scalar() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );
		$field       = [
			'key'  => 'token',
			'type' => 'oauth',
		];

		$this->assertSame(
			'abc123',
			$integration->test_sanitize_settings_field_value( $field, 'abc123' )
		);
		// Tags stripped by sanitize_text_field.
		$this->assertSame(
			'token',
			$integration->test_sanitize_settings_field_value( $field, '<b>token</b>' )
		);
		// Non-string scalars are coerced to a string then sanitized.
		$this->assertSame(
			'42',
			$integration->test_sanitize_settings_field_value( $field, 42 )
		);
	}

	/**
	 * OAuth settings field value: non-scalar payloads are rejected and the default is returned.
	 */
	public function test_sanitize_settings_field_value_oauth_non_scalar_returns_default() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );

		// No default declared: falls back to empty string.
		$this->assertSame(
			'',
			$integration->test_sanitize_settings_field_value(
				[
					'key'  => 'token',
					'type' => 'oauth',
				],
				[ 'unexpected' => 'array' ]
			)
		);
		// Explicit default is honored.
		$this->assertSame(
			'fallback',
			$integration->test_sanitize_settings_field_value(
				[
					'key'     => 'token',
					'type'    => 'oauth',
					'default' => 'fallback',
				],
				(object) [ 'unexpected' => 'object' ]
			)
		);
	}

	/**
	 * Hidden settings field value: scalar strings are sanitized, non-scalars return the default.
	 */
	public function test_sanitize_settings_field_value_hidden() {
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );
		$field       = [
			'key'  => 'secret',
			'type' => 'hidden',
		];

		$this->assertSame(
			'opaque-id',
			$integration->test_sanitize_settings_field_value( $field, 'opaque-id' )
		);
		// Non-scalar rejected.
		$this->assertSame(
			'',
			$integration->test_sanitize_settings_field_value( $field, [ 'nope' ] )
		);
		// Explicit default honored on non-scalar payload.
		$this->assertSame(
			'kept',
			$integration->test_sanitize_settings_field_value(
				[
					'key'     => 'secret',
					'type'    => 'hidden',
					'default' => 'kept',
				],
				[ 'nope' ]
			)
		);
	}

	/**
	 * The REST settings save entry point (update_integration_settings) drops
	 * oauth/hidden keys so admin clients can't overwrite server-managed values
	 * such as OAuth tokens. Other field types pass through.
	 */
	public function test_update_integration_settings_skips_managed_field_types() {
		Sample_Integration::$declared_settings_fields = [
			[
				'key'     => 'api_label',
				'type'    => 'text',
				'default' => '',
			],
			[
				'key'     => 'access_token',
				'type'    => 'hidden',
				'default' => '',
			],
			[
				'key'     => 'connection',
				'type'    => 'oauth',
				'default' => '',
			],
		];
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );
		Integrations::register( $integration );

		// Pre-seed the managed fields as if a server-side OAuth callback had
		// written them. The REST endpoint must not overwrite these.
		\update_option( Integration::SETTINGS_OPTION_PREFIX . 'test-id_access_token', 'server-managed-token' );
		\update_option( Integration::SETTINGS_OPTION_PREFIX . 'test-id_connection', 'connected' );

		$result = Integrations::update_integration_settings(
			'test-id',
			[
				'api_label'    => 'My Label',
				'access_token' => 'attempted-override',
				'connection'   => 'attempted-override',
			]
		);

		$this->assertTrue( $result );

		// The non-managed field was written through.
		$this->assertSame(
			'My Label',
			\get_option( Integration::SETTINGS_OPTION_PREFIX . 'test-id_api_label' )
		);

		// The managed fields kept their server-managed values.
		$this->assertSame(
			'server-managed-token',
			\get_option( Integration::SETTINGS_OPTION_PREFIX . 'test-id_access_token' )
		);
		$this->assertSame(
			'connected',
			\get_option( Integration::SETTINGS_OPTION_PREFIX . 'test-id_connection' )
		);
	}

	/**
	 * Server-side writers (e.g., an OAuth callback) calling
	 * update_settings_field_value() directly bypass the REST-path filter and
	 * can still write oauth/hidden values.
	 */
	public function test_update_settings_field_value_writes_managed_field_types_directly() {
		Sample_Integration::$declared_settings_fields = [
			[
				'key'     => 'access_token',
				'type'    => 'hidden',
				'default' => '',
			],
			[
				'key'     => 'connection',
				'type'    => 'oauth',
				'default' => '',
			],
		];
		$integration = new Sample_Integration( 'test-id', 'Test Integration' );

		$integration->update_settings_field_value( 'access_token', 'fresh-token' );
		$integration->update_settings_field_value( 'connection', 'connected' );

		$this->assertSame(
			'fresh-token',
			\get_option( Integration::SETTINGS_OPTION_PREFIX . 'test-id_access_token' )
		);
		$this->assertSame(
			'connected',
			\get_option( Integration::SETTINGS_OPTION_PREFIX . 'test-id_connection' )
		);
	}

	/**
	 * Integrations that don't override get_required_plugins() report an empty
	 * required_plugins array in the settings payload, so the audience UI renders
	 * them without a requirements badge.
	 */
	public function test_get_all_integration_settings_defaults_required_plugins_to_empty_array() {
		$integration = new Sample_Integration( 'no-overrides', 'No Overrides' );
		Integrations::register( $integration );

		$settings = Integrations::get_all_integration_settings();

		$this->assertArrayHasKey( 'no-overrides', $settings );
		$this->assertArrayHasKey( 'required_plugins', $settings['no-overrides'] );
		$this->assertSame( [], $settings['no-overrides']['required_plugins'] );
	}

	/**
	 * A child integration overriding get_required_plugins() has its declaration
	 * surfaced verbatim in the settings payload that drives the audience UI card.
	 */
	public function test_get_all_integration_settings_surfaces_required_plugins_override() {
		$declared    = [
			[
				'slug'         => 'some-dependency',
				'name'         => 'Some Dependency',
				'is_active'    => false,
				'is_installed' => true,
			],
		];
		$integration = new class( 'with-deps', 'With Deps', $declared ) extends Sample_Integration {
			/**
			 * Declared required-plugins payload returned by the override.
			 *
			 * @var array
			 */
			private $declared;

			/**
			 * Capture the declaration the test wants returned, then defer
			 * construction to the parent.
			 *
			 * @param string $id       Integration id.
			 * @param string $name     Integration name.
			 * @param array  $declared Required-plugins payload to return.
			 */
			public function __construct( $id, $name, $declared ) {
				$this->declared = $declared;
				parent::__construct( $id, $name );
			}

			/**
			 * Return the test-supplied required-plugins payload.
			 *
			 * @return array
			 */
			public function get_required_plugins() {
				return $this->declared;
			}
		};

		Integrations::register( $integration );

		$settings = Integrations::get_all_integration_settings();

		$this->assertArrayHasKey( 'with-deps', $settings );
		$this->assertSame( $declared, $settings['with-deps']['required_plugins'] );
	}
}
