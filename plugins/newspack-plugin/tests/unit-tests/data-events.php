<?php
/**
 * Tests the Data Events functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * Tests the Data Events functionality.
 */
class Newspack_Test_Data_Events extends WP_UnitTestCase {
	/**
	 * Always reset the wc-mocks PHP globals mutated by tests in this class, even if
	 * an assertion fails partway through. The mocks back orders, subscriptions, and
	 * products with PHP globals (not DB), so they don't get rolled back by
	 * WP_UnitTestCase's transaction handling.
	 */
	public function tear_down() {
		global $orders_database, $subscriptions_database, $products_database;
		$orders_database        = [];
		$subscriptions_database = [];
		$products_database      = [];
		\delete_option( 'newspack_donation_product_id' );
		parent::tear_down();
	}

	/**
	 * Test registering an action.
	 */
	public function test_register_action() {
		$action_name = 'test_action';
		Data_Events::register_action( $action_name );
		$registered_actions = Data_Events::get_actions();
		$this->assertContains( $action_name, $registered_actions );
	}

	/**
	 * Test that registering an action handler without registering an action fails
	 * with WP_Error.
	 */
	public function test_register_missing_action_handler() {
		$handler = function() {};
		$result  = Data_Events::register_handler( $handler, 'missing_action' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test "is_action_registered" method.
	 */
	public function test_is_action_registered() {
		$action_name = 'test_action';
		Data_Events::register_action( $action_name );
		$this->assertTrue( Data_Events::is_action_registered( $action_name ) );
		$this->assertFalse( Data_Events::is_action_registered( 'missing_action' ) );
	}

	/**
	 * Test register action handler.
	 */
	public function test_register_action_handler() {
		$action_name = 'test_action';
		$handler     = function () {};
		Data_Events::register_action( $action_name );
		$result = Data_Events::register_handler( $handler, $action_name );
		$this->assertEquals( null, $result );
		$action_handlers = Data_Events::get_action_handlers( $action_name );
		$this->assertContains( $handler, $action_handlers );
	}

	/**
	 * Test that dispatching an action returns a WP_Http response and triggers a
	 * WP action.
	 */
	public function test_dispatch() {
		$action_name = 'test_action';
		$data        = [ 'test' => 'data' ];

		// Hook into dispatch.
		$call_count = 0;
		$hook       = function() use ( &$call_count ) {
			$call_count++;
		};
		add_action( 'newspack_data_event_dispatch', $hook, 10, 3 );

		Data_Events::register_action( $action_name );
		$result = Data_Events::dispatch( $action_name, $data );

		// Assert the hook was called once.
		$this->assertEquals( 1, $call_count );
	}

	/**
	 * Test that executing queued dispatches triggers the dispatched action hook.
	 */
	public function test_execute_queued_dispatches() {
		$action_name = 'test_action';
		$data        = [ 'test' => 'data' ];

		$hook_request = 'not_called';
		$hook_queued_dispatches = null;

		$hook = function( $request, $queued_dispatches ) use ( &$hook_request, &$hook_queued_dispatches ) {
			$hook_request = $request;
			$hook_queued_dispatches = $queued_dispatches;
		};
		add_action( 'newspack_data_events_dispatched', $hook, 10, 2 );

		Data_Events::register_action( $action_name );
		Data_Events::dispatch( $action_name, $data );
		Data_Events::execute_queued_dispatches();

		// Request is null when using AS, array when using wp_remote_post.
		$this->assertNotEquals( 'not_called', $hook_request, 'Dispatched hook should fire.' );
		$this->assertIsArray( $hook_queued_dispatches );

		// Find our test action (other events may be queued from other tests).
		$matching = array_filter(
			$hook_queued_dispatches,
			fn( $d ) => $d['action_name'] === $action_name
		);
		$this->assertNotEmpty( $matching, 'Test action should be in queued dispatches.' );
		$this->assertEquals( $data, array_values( $matching )[0]['data'] );
	}

	/**
	 * Test triggering the handler.
	 */
	public function test_handler() {
		$action_name = 'test_action';

		Data_Events::register_action( $action_name );

		$handler_data = [
			'called' => 0,
			'args'   => [],
		];
		$handler      = function( ...$handler_args ) use ( &$handler_data ) {
			$handler_data['called']++;
			$handler_data['args'] = $handler_args;
		};
		// Attach the handler through the Data_Events API.
		Data_Events::register_handler( $handler, $action_name );
		// Attach the handler through a WP action.
		add_action( 'newspack_data_event_test_action', $handler, 10, 3 );

		// Manual trigger.
		$timestamp = time();
		$data      = [ 'test' => 'data' ];
		$client_id = 'test-client-id';
		Data_Events::handle( $action_name, $timestamp, $data, $client_id );

		// Should have been called twice.
		$this->assertEquals( 2, $handler_data['called'] );

		// Assert args sent to handler.
		$this->assertEquals( $timestamp, $handler_data['args'][0] );
		$this->assertEquals( $data, $handler_data['args'][1] );
		$this->assertEquals( $client_id, $handler_data['args'][2] );
	}

	/**
	 * Test that a handler can throw an exception without disrupting other handler.
	 */
	public function test_handler_exception() {
		$action_name = 'test_action';

		Data_Events::register_action( $action_name );

		$handler_called = 0;

		$handler1 = function( ...$handler_args ) use ( &$handler_called ) {
			$handler_called++;
			throw new Exception( 'Test exception' );
		};
		$handler2 = function( ...$handler_args ) use ( &$handler_called ) {
			$handler_called++;
		};

		// Attach the handlers through the Data_Events API.
		Data_Events::register_handler( $handler1, $action_name );
		Data_Events::register_handler( $handler2, $action_name );

		// Manual trigger.
		$timestamp = time();
		$data      = [ 'test' => 'data' ];
		$client_id = 'test-client-id';
		Data_Events::handle( $action_name, $timestamp, $data, $client_id );

		// Should have been called twice.
		$this->assertEquals( 2, $handler_called );
	}

	/**
	 * Test global handler execution.
	 */
	public function test_global_handler() {
		$action_name = 'test_action';

		Data_Events::register_action( $action_name );

		$handler_data = [
			'called' => 0,
			'args'   => [],
		];
		$handler      = function( ...$handler_args ) use ( &$handler_data ) {
			$handler_data['called']++;
			$handler_data['args'] = $handler_args;
		};
		Data_Events::register_handler( $handler );

		$timestamp = time();
		$data      = [ 'test' => 'data' ];
		$client_id = 'test-client-id';
		Data_Events::handle( $action_name, $timestamp, $data, $client_id );

		$this->assertEquals( 1, $handler_data['called'] );
		$this->assertEquals( $action_name, $handler_data['args'][0] );
		$this->assertEquals( $timestamp, $handler_data['args'][1] );
		$this->assertEquals( $data, $handler_data['args'][2] );
		$this->assertEquals( $client_id, $handler_data['args'][3] );
	}

	/**
	 * Test registering a listener.
	 */
	public function test_register_listener() {
		$action_name = 'test_action';
		Data_Events::register_listener( 'some_actionable_thing', $action_name );
		do_action( 'some_actionable_thing', 'data' );
		$this->assertEquals( 1, did_action( "newspack_data_event_dispatch_$action_name" ) );
	}

	/**
	 * Test registering a listener with a callable.
	 */
	public function test_register_listener_with_callable() {
		$action_name = 'test_action';
		Data_Events::register_listener(
			'some_actionable_thing',
			$action_name,
			function( $data ) {
				return $data . ' was parsed';
			}
		);

		$parsed_data = '';
		add_action(
			"newspack_data_event_dispatch_$action_name",
			function( $timestamp, $data, $client_id ) use ( &$parsed_data ) {
				$parsed_data = $data;
			},
			10,
			3
		);

		do_action( 'some_actionable_thing', 'data' );

		$this->assertEquals( 'data was parsed', $parsed_data );
	}

	/**
	 * Test registering a listener with an argument map.
	 */
	public function test_register_listener_with_map() {
		$action_name = 'test_action';
		Data_Events::register_listener(
			'some_actionable_thing',
			$action_name,
			[ 'key1', 'key2' ]
		);

		$parsed_data = [];
		add_action(
			"newspack_data_event_dispatch_$action_name",
			function( $timestamp, $data, $client_id ) use ( &$parsed_data ) {
				$parsed_data = $data;
			},
			10,
			3
		);

		do_action( 'some_actionable_thing', 'value1', 'value2' );

		$this->assertEquals(
			[
				'key1' => 'value1',
				'key2' => 'value2',
			],
			$parsed_data
		);
	}

	/**
	 * Test the current event is set and available during handler execution.
	 */
	public function test_current_event() {
		Data_Events::register_action( 'test_action' );
		Data_Events::register_action( 'test_action2' );

		$handler = function() {
			$this->assertEquals( 'test_action', Data_Events::current_event(), 'Current event should be set and equal to the action name' );
		};
		Data_Events::register_handler( $handler, 'test_action' );
		Data_Events::handle( 'test_action', time(), [], 'test-client-id' );

		$this->assertNull( Data_Events::current_event(), 'Current event should be null after handling' );

		$handler2 = function() {
			$this->assertEquals( 'test_action2', Data_Events::current_event(), 'Current event should be set and equal to the action name' );
		};
		Data_Events::register_handler( $handler2, 'test_action2' );
		Data_Events::handle( 'test_action2', time(), [], 'test-client-id' );

		$this->assertNull( Data_Events::current_event(), 'Current event should be null after handling' );
	}

	/**
	 * Test that the current event is set to null even if a handler throws an exception.
	 */
	public function test_current_event_exception() {
		Data_Events::register_action( 'test_action' );

		$handler = function() {
			$this->assertEquals( 'test_action', Data_Events::current_event(), 'Current event should be set and equal to the action name' );
			throw new Exception( 'Test exception' );
		};
		Data_Events::register_handler( $handler, 'test_action' );

		try {
			Data_Events::handle( 'test_action', time(), [], 'test-client-id' );
		} catch ( Exception $e ) {
			$this->assertNull( Data_Events::current_event(), 'Current event should be null after handling' );
		}
	}

	/**
	 * Test the custom nonce generation and verification.
	 */
	public function test_nonce_generation_and_verification() {
		// Get a nonce.
		$nonce = Data_Events::get_nonce();

		// Verify the nonce is not empty.
		$this->assertNotEmpty( $nonce );

		// Verify the nonce passes verification.
		$this->assertTrue( Data_Events::verify_nonce( $nonce ) );

		// Verify an invalid nonce fails verification.
		$this->assertFalse( Data_Events::verify_nonce( 'invalid_nonce' ) );
	}

	/**
	 * Test that the nonce is URL-safe.
	 */
	public function test_nonce_is_url_safe() {
		$nonce = Data_Events::get_nonce();

		// Verify the nonce only contains alphanumeric characters.
		$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9]+$/', $nonce );

		// Verify the nonce doesn't change when requested multiple times.
		$nonce2 = Data_Events::get_nonce();
		$this->assertEquals( $nonce, $nonce2 );
	}

	/**
	 * Test nonce expiration and rotation.
	 */
	public function test_nonce_expiration() {
		// Get initial nonce.
		$initial_nonce = Data_Events::get_nonce();

		// Manually expire the nonce by setting expiration to past time.
		update_option( Data_Events::NONCE_EXPIRATION_OPTION, time() - 1 );

		// Get a new nonce - should be different.
		$new_nonce = Data_Events::get_nonce();

		// Verify the new nonce is different from the initial one.
		$this->assertNotEquals( $initial_nonce, $new_nonce );

		// Verify the new nonce passes verification.
		$this->assertTrue( Data_Events::verify_nonce( $new_nonce ) );

		// Verify the old nonce passes verification during grace period.
		$this->assertTrue( Data_Events::verify_nonce( $initial_nonce ) );

		// Expire the grace period.
		update_option( Data_Events::PREVIOUS_NONCE_EXPIRATION_OPTION, time() - 1 );

		// Now the old nonce should fail verification.
		$this->assertFalse( Data_Events::verify_nonce( $initial_nonce ) );
	}

	/**
	 * Test that the nonce is used in dispatches.
	 */
	public function test_nonce_in_dispatches() {
		$action_name = 'test_nonce_action';
		Data_Events::register_action( $action_name );

		// Hook into the dispatched action to capture the URL.
		$captured_url = '';
		add_filter(
			'pre_http_request',
			function( $preempt, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return true; // Short-circuit the request.
			},
			10,
			3
		);

		// Dispatch an action.
		Data_Events::dispatch( $action_name, [ 'test' => 'data' ] );
		Data_Events::execute_queued_dispatches();

		// Verify the URL contains our custom nonce.
		$nonce = Data_Events::get_nonce();
		$this->assertStringContainsString( 'nonce=' . $nonce, $captured_url );
	}

	/**
	 * Test the nonce grace period functionality.
	 */
	public function test_nonce_grace_period() {
		// Get initial nonce.
		$initial_nonce = Data_Events::get_nonce();

		// Store the initial nonce and expiration values.
		$initial_expiration = get_option( Data_Events::NONCE_EXPIRATION_OPTION );

		// Force nonce rotation by setting expiration to past time.
		update_option( Data_Events::NONCE_EXPIRATION_OPTION, time() - 1 );

		// Get a new nonce - this should trigger rotation and store the old nonce.
		$new_nonce = Data_Events::get_nonce();

		// Verify we have different nonces.
		$this->assertNotEquals( $initial_nonce, $new_nonce );

		// Verify the previous nonce was stored.
		$previous_nonce = get_option( Data_Events::PREVIOUS_NONCE_OPTION );
		$this->assertEquals( $initial_nonce, $previous_nonce );

		// Verify the previous nonce expiration was set to a future time.
		$previous_expiration = get_option( Data_Events::PREVIOUS_NONCE_EXPIRATION_OPTION );
		$this->assertGreaterThan( time(), $previous_expiration, 'Previous nonce expiration should be in the future' );

		// Verify both nonces are valid during the grace period.
		$this->assertTrue( Data_Events::verify_nonce( $new_nonce ), 'New nonce should be valid' );
		$this->assertTrue( Data_Events::verify_nonce( $initial_nonce ), 'Old nonce should be valid during grace period' );

		// Expire the grace period.
		update_option( Data_Events::PREVIOUS_NONCE_EXPIRATION_OPTION, time() - 1 );

		// Verify only the new nonce is valid after grace period.
		$this->assertTrue( Data_Events::verify_nonce( $new_nonce ), 'New nonce should still be valid' );
		$this->assertFalse( Data_Events::verify_nonce( $initial_nonce ), 'Old nonce should be invalid after grace period' );
	}

	/**
	 * Test that the nonce lifetime is correctly set to 1 hour.
	 */
	public function test_nonce_lifetime() {
		// Get a nonce and check its expiration time.
		Data_Events::get_nonce();
		$expiration = get_option( Data_Events::NONCE_EXPIRATION_OPTION );

		// Verify the expiration is set to approximately 1 hour from now.
		$expected_expiration = time() + Data_Events::NONCE_LIFETIME;
		$this->assertEqualsWithDelta( $expected_expiration, $expiration, 2, 'Nonce expiration should be set to 1 hour' );
	}

	/**
	 * Test that the grace period is correctly set to 10 seconds.
	 */
	public function test_grace_period_duration() {
		// Get initial nonce.
		$initial_nonce = Data_Events::get_nonce();

		// Force nonce rotation.
		update_option( Data_Events::NONCE_EXPIRATION_OPTION, time() - 1 );
		Data_Events::get_nonce();

		// Get the previous nonce expiration.
		$previous_expiration = get_option( Data_Events::PREVIOUS_NONCE_EXPIRATION_OPTION );

		// Verify the grace period is approximately 10 seconds.
		$grace_period = $previous_expiration - time();
		$this->assertEqualsWithDelta( Data_Events::NONCE_GRACE_PERIOD, $grace_period, 2, 'Grace period should be approximately 10 seconds' );
	}

	/**
	 * Test that dispatches work with both current and previous nonces during grace period.
	 */
	public function test_dispatch_with_grace_period() {
		$action_name = 'test_grace_period_action';
		Data_Events::register_action( $action_name );

		// Get initial nonce.
		$initial_nonce = Data_Events::get_nonce();

		// Force nonce rotation.
		update_option( Data_Events::NONCE_EXPIRATION_OPTION, time() - 1 );
		$new_nonce = Data_Events::get_nonce();

		// Hook into the dispatched action to capture the URL.
		$captured_url = '';
		add_filter(
			'pre_http_request',
			function( $preempt, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return true; // Short-circuit the request.
			},
			10,
			3
		);

		// Dispatch an action with the new nonce.
		Data_Events::dispatch( $action_name, [ 'test' => 'data' ] );
		Data_Events::execute_queued_dispatches();

		// Verify the URL contains the new nonce.
		$this->assertStringContainsString( 'nonce=' . $new_nonce, $captured_url );

		// Manually verify a request with the old nonce would be accepted.
		$_REQUEST['nonce'] = $initial_nonce;
		$this->assertTrue( Data_Events::verify_nonce( $initial_nonce ), 'Old nonce should be valid during grace period' );
	}

	/**
	 * Test that AS dispatch schedules actions instead of making HTTP requests.
	 */
	public function test_dispatch_via_action_scheduler() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		// Clear any pending dispatch actions from previous tests.
		as_unschedule_all_actions( Data_Events::DISPATCH_AS_HOOK );

		// Enable AS dispatch via filter.
		add_filter( 'newspack_data_events_use_action_scheduler_dispatch', '__return_true' );

		$action_name = 'test_as_dispatch';
		Data_Events::register_action( $action_name );

		$hook_request = 'not_called';
		$hook = function( $request ) use ( &$hook_request ) {
			$hook_request = $request;
		};
		add_action( 'newspack_data_events_dispatched', $hook, 10, 1 );

		Data_Events::dispatch( $action_name, [ 'test' => 'as' ] );
		Data_Events::execute_queued_dispatches();

		remove_action( 'newspack_data_events_dispatched', $hook );

		// AS path passes null as request.
		$this->assertNull( $hook_request, 'Request should be null when dispatching via AS.' );

		// Verify an action was scheduled.
		$pending = as_get_scheduled_actions(
			[
				'hook'   => Data_Events::DISPATCH_AS_HOOK,
				'group'  => 'newspack',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertCount( 1, $pending, 'AS dispatch should schedule exactly one batched action.' );

		remove_filter( 'newspack_data_events_use_action_scheduler_dispatch', '__return_true' );
	}

	/**
	 * Test handle_from_scheduler calls handle() with correct args.
	 */
	public function test_handle_from_scheduler() {
		$action_name = 'test_as_handle';
		Data_Events::register_action( $action_name );

		$handler_args = null;
		$handler = function( ...$args ) use ( &$handler_args ) {
			$handler_args = $args;
		};
		Data_Events::register_handler( $handler, $action_name );

		$timestamp = time();
		$data      = [ 'from' => 'scheduler' ];
		$client_id = 'as-client';

		Data_Events::handle_from_scheduler(
			[
				[
					'action_name' => $action_name,
					'timestamp'   => $timestamp,
					'data'        => $data,
					'client_id'   => $client_id,
				],
			]
		);

		$this->assertNotNull( $handler_args, 'Handler should be called from scheduler.' );
		$this->assertEquals( $timestamp, $handler_args[0] );
		$this->assertEquals( $data, $handler_args[1] );
		$this->assertEquals( $client_id, $handler_args[2] );
	}

	/**
	 * Test handle_from_scheduler processes a batch of dispatches.
	 */
	public function test_handle_from_scheduler_batch() {
		$action_a = 'test_as_batch_a';
		$action_b = 'test_as_batch_b';
		Data_Events::register_action( $action_a );
		Data_Events::register_action( $action_b );

		$called_actions = [];
		$handler = function( ...$args ) use ( &$called_actions ) {
			$called_actions[] = $args;
		};
		Data_Events::register_handler( $handler, $action_a );
		Data_Events::register_handler( $handler, $action_b );

		$timestamp = time();

		Data_Events::handle_from_scheduler(
			[
				[
					'action_name' => $action_a,
					'timestamp'   => $timestamp,
					'data'        => [ 'order' => 1 ],
					'client_id'   => 'batch-client',
				],
				[
					'action_name' => $action_b,
					'timestamp'   => $timestamp,
					'data'        => [ 'order' => 2 ],
					'client_id'   => 'batch-client',
				],
			]
		);

		$this->assertCount( 2, $called_actions, 'Both dispatches in the batch should be handled.' );
		$this->assertEquals( [ 'order' => 1 ], $called_actions[0][1] );
		$this->assertEquals( [ 'order' => 2 ], $called_actions[1][1] );
	}

	/**
	 * Test handle_from_scheduler rejects invalid data.
	 */
	public function test_handle_from_scheduler_invalid_data() {
		$action_name = 'test_as_invalid';
		Data_Events::register_action( $action_name );

		$called = 0;
		Data_Events::register_handler(
			function() use ( &$called ) {
				$called++;
			},
			$action_name
		);

		// Non-array should be rejected.
		Data_Events::handle_from_scheduler( 'not_an_array' );
		$this->assertEquals( 0, $called );

		// Unregistered action should be skipped.
		Data_Events::handle_from_scheduler( [ [ 'action_name' => 'nonexistent' ] ] );
		$this->assertEquals( 0, $called );
	}

	/**
	 * Test that a throwing handler schedules an AS retry when AS is available.
	 */
	public function test_handler_retry_scheduling() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		// Clear any pending retry actions from previous tests.
		as_unschedule_all_actions( Data_Events::HANDLER_RETRY_HOOK );

		$action_name = 'test_retry_action';
		Data_Events::register_action( $action_name );

		// Use a serializable (static method array) handler that throws.
		$handler = [ self::class, 'throwing_handler' ];
		Data_Events::register_handler( $handler, $action_name );

		Data_Events::handle( $action_name, time(), [ 'test' => 'data' ], 'client-1' );

		// Verify a retry was scheduled.
		$pending = as_get_scheduled_actions(
			[
				'hook'   => Data_Events::HANDLER_RETRY_HOOK,
				'group'  => 'newspack',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertNotEmpty( $pending, 'A handler retry should be scheduled when a serializable handler throws.' );

		// Verify the retry data contains the reason key.
		$action_id = array_key_first( $pending );
		$action    = \ActionScheduler::store()->fetch_action( $action_id );
		$args      = $action->get_args();
		$this->assertArrayHasKey( 'reason', $args[0], 'Retry data should contain a reason key.' );
		$this->assertEquals( 'Test handler failure', $args[0]['reason'], 'Reason should match the error message.' );
	}

	/**
	 * Test that a non-serializable (closure) handler does NOT schedule a retry.
	 */
	public function test_closure_handler_no_retry() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		// Clear any pending retries from previous tests.
		as_unschedule_all_actions( Data_Events::HANDLER_RETRY_HOOK );

		$action_name = 'test_closure_retry_action';
		Data_Events::register_action( $action_name );

		$handler = function() {
			throw new \RuntimeException( 'Closure failure' );
		};
		Data_Events::register_handler( $handler, $action_name );

		Data_Events::handle( $action_name, time(), [], 'client-1' );

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Data_Events::HANDLER_RETRY_HOOK,
				'group'  => 'newspack',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'Closure handlers should not schedule retries.' );
	}

	/**
	 * Test that execute_handler_retry calls the handler with correct arguments.
	 */
	public function test_execute_handler_retry_action_handler() {
		$action_name = 'test_retry_exec_action';
		Data_Events::register_action( $action_name );

		$captured_args = null;
		$handler = [ self::class, 'capturing_handler' ];
		Data_Events::register_handler( $handler, $action_name );

		self::$captured_handler_args = null;

		$timestamp = time();
		$data      = [ 'retry' => 'test' ];
		$client_id = 'retry-client';

		Data_Events::execute_handler_retry(
			[
				'handler'     => $handler,
				'action_name' => $action_name,
				'timestamp'   => $timestamp,
				'data'        => $data,
				'client_id'   => $client_id,
				'is_global'   => false,
				'retry_count' => 1,
			]
		);

		$this->assertNotNull( self::$captured_handler_args, 'Handler should have been called during retry.' );
		$this->assertEquals( $timestamp, self::$captured_handler_args[0] );
		$this->assertEquals( $data, self::$captured_handler_args[1] );
		$this->assertEquals( $client_id, self::$captured_handler_args[2] );
	}

	/**
	 * Test that execute_handler_retry passes action_name for global handlers.
	 */
	public function test_execute_handler_retry_global_handler() {
		$action_name = 'test_retry_global';
		Data_Events::register_action( $action_name );

		self::$captured_handler_args = null;

		$handler = [ self::class, 'capturing_handler' ];

		Data_Events::execute_handler_retry(
			[
				'handler'     => $handler,
				'action_name' => $action_name,
				'timestamp'   => time(),
				'data'        => [ 'global' => true ],
				'client_id'   => 'client-1',
				'is_global'   => true,
				'retry_count' => 1,
			]
		);

		$this->assertNotNull( self::$captured_handler_args );
		// Global handlers receive action_name as first arg.
		$this->assertEquals( $action_name, self::$captured_handler_args[0] );
	}

	/**
	 * Test that max retries are respected.
	 */
	public function test_max_retries_respected() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		as_unschedule_all_actions( Data_Events::HANDLER_RETRY_HOOK );

		$action_name = 'test_max_retries';
		Data_Events::register_action( $action_name );

		$handler = [ self::class, 'throwing_handler' ];
		Data_Events::register_handler( $handler, $action_name );

		// Simulate a retry at max count — should NOT schedule another.
		Data_Events::execute_handler_retry(
			[
				'handler'     => $handler,
				'action_name' => $action_name,
				'timestamp'   => time(),
				'data'        => [],
				'client_id'   => null,
				'is_global'   => false,
				'retry_count' => Data_Events::MAX_HANDLER_RETRIES,
			]
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Data_Events::HANDLER_RETRY_HOOK,
				'group'  => 'newspack',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No retry should be scheduled after max retries.' );
	}

	/**
	 * Test that current_event is set during handler retry execution.
	 */
	public function test_current_event_set_during_retry() {
		$action_name = 'test_retry_current_event';
		Data_Events::register_action( $action_name );

		self::$captured_current_event = null;

		Data_Events::execute_handler_retry(
			[
				'handler'     => [ self::class, 'current_event_capturing_handler' ],
				'action_name' => $action_name,
				'timestamp'   => time(),
				'data'        => [],
				'client_id'   => null,
				'is_global'   => false,
				'retry_count' => 1,
			]
		);

		$this->assertEquals( $action_name, self::$captured_current_event, 'current_event should be set during retry.' );
		$this->assertNull( Data_Events::current_event(), 'current_event should be null after retry completes.' );
	}

	/**
	 * Test that scheduling a handler retry creates an AS log entry with the failure reason.
	 */
	public function test_handler_retry_as_log_entry() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		as_unschedule_all_actions( Data_Events::HANDLER_RETRY_HOOK );

		$action_name = 'test_retry_as_log';
		Data_Events::register_action( $action_name );

		$handler = [ self::class, 'throwing_handler' ];
		Data_Events::register_handler( $handler, $action_name );

		Data_Events::handle( $action_name, time(), [ 'test' => 'data' ], 'client-1' );

		// Get the scheduled retry action.
		$pending = as_get_scheduled_actions(
			[
				'hook'   => Data_Events::HANDLER_RETRY_HOOK,
				'group'  => 'newspack',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertNotEmpty( $pending );

		$action_id = array_key_first( $pending );

		// Verify AS log entry.
		$logs     = \ActionScheduler_Logger::instance()->get_logs( $action_id );
		$messages = array_map(
			function ( $log ) {
				return $log->get_message();
			},
			$logs
		);
		$this->assertTrue(
			in_array( 'Failure reason: Test handler failure', $messages, true ),
			'AS logs should contain the failure reason.'
		);
	}

	/**
	 * Test that max retries exhausted creates an AS log entry on the current action.
	 */
	public function test_max_retries_as_log_entry() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		as_unschedule_all_actions( Data_Events::HANDLER_RETRY_HOOK );

		$action_name = 'test_max_retries_log';
		Data_Events::register_action( $action_name );

		$handler = [ self::class, 'throwing_handler' ];
		Data_Events::register_handler( $handler, $action_name );

		// Schedule a dummy AS action to simulate the currently-executing action.
		$dummy_action_id = as_schedule_single_action( time() + 3600, 'newspack_dummy_action' );

		// Set the current AS action ID to simulate being inside an AS execution.
		Data_Events::set_current_as_action_id( $dummy_action_id );

		// Execute at max retry count — handler throws, triggers max-retries guard.
		Data_Events::execute_handler_retry(
			[
				'handler'     => $handler,
				'action_name' => $action_name,
				'timestamp'   => time(),
				'data'        => [],
				'client_id'   => null,
				'is_global'   => false,
				'retry_count' => Data_Events::MAX_HANDLER_RETRIES,
			]
		);

		Data_Events::clear_current_as_action_id();

		// Verify AS log entry on the dummy action.
		$logs     = \ActionScheduler_Logger::instance()->get_logs( $dummy_action_id );
		$messages = array_map(
			function ( $log ) {
				return $log->get_message();
			},
			$logs
		);
		$this->assertTrue(
			in_array( 'Max retries exhausted. Final error: Test handler failure', $messages, true ),
			'AS logs should contain the max retries exhausted message.'
		);

		// Clean up.
		as_unschedule_all_actions( 'newspack_dummy_action' );
	}

	/**
	 * Test that retry exhaustion fires the alert hook.
	 */
	public function test_handler_retry_exhaustion_fires_hook() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}

		$hook_fired = false;
		$hook_data  = null;
		add_action(
			'newspack_data_event_retry_exhausted',
			function ( $data ) use ( &$hook_fired, &$hook_data ) {
				$hook_fired = true;
				$hook_data  = $data;
			}
		);

		as_unschedule_all_actions( Data_Events::HANDLER_RETRY_HOOK );

		$action_name = 'test_exhaustion_hook';
		Data_Events::register_action( $action_name );

		$handler = [ self::class, 'throwing_handler' ];
		Data_Events::register_handler( $handler, $action_name );

		// Execute at max retry count — triggers exhaustion.
		Data_Events::execute_handler_retry(
			[
				'handler'     => $handler,
				'action_name' => $action_name,
				'timestamp'   => time(),
				'data'        => [ 'test' => true ],
				'client_id'   => 'test-client',
				'is_global'   => false,
				'retry_count' => Data_Events::MAX_HANDLER_RETRIES,
			]
		);

		$this->assertTrue( $hook_fired, 'newspack_data_event_retry_exhausted should fire on max retries.' );
		$this->assertEquals( $action_name, $hook_data['action_name'] );
		$this->assertEquals( Data_Events::MAX_HANDLER_RETRIES, $hook_data['retry_count'] );
		$this->assertArrayHasKey( 'reason', $hook_data );
	}

	// --- Helper methods for tests ---

	/**
	 * Captured handler arguments.
	 *
	 * @var array|null
	 */
	public static $captured_handler_args = null;

	/**
	 * Captured current event during handler execution.
	 *
	 * @var string|null
	 */
	public static $captured_current_event = null;

	/**
	 * A serializable handler that throws.
	 *
	 * @throws \RuntimeException Always.
	 */
	public static function throwing_handler() {
		throw new \RuntimeException( 'Test handler failure' );
	}

	/**
	 * A serializable handler that captures its arguments.
	 */
	public static function capturing_handler() {
		self::$captured_handler_args = func_get_args();
	}

	/**
	 * A serializable handler that captures the current event.
	 */
	public static function current_event_capturing_handler() {
		self::$captured_current_event = Data_Events::current_event();
	}

	/**
	 * The new transactional Woo events should be registered as actions.
	 */
	public function test_woo_transactional_actions_registered() {
		$registered = Data_Events::get_actions();
		$this->assertContains( 'woo_order_updated', $registered );
		$this->assertContains( 'woo_subscription_updated', $registered );
	}

	/**
	 * Build a WC_Order_Item_Product mock with inline product info.
	 *
	 * @param array $args { product_id, name, subtotal }.
	 * @return \WC_Order_Item_Product
	 */
	private function build_order_item( $args ) {
		$defaults = [
			'product_id' => 0,
			'name'       => 'Test Product',
			'subtotal'   => 0,
		];
		return new \WC_Order_Item_Product( array_merge( $defaults, $args ) );
	}

	/**
	 * Create a WC order via the wc-mocks data-array shape.
	 *
	 * @param array $line_items Each item: [ 'product_id' => int, 'name' => string, 'subtotal' => float ].
	 * @param array $order_args Override: status, billing_email, currency, customer_id, meta, total.
	 * @return \WC_Order
	 */
	private function create_order_with_items( $line_items, $order_args = [] ) {
		$defaults = [
			'status'        => 'pending',
			'billing_email' => 'reader@example.com',
			'currency'      => 'USD',
			'customer_id'   => 0,
			'meta'          => [],
			'total'         => array_sum( array_map( fn( $li ) => (float) ( $li['subtotal'] ?? 0 ), $line_items ) ),
		];
		$data = array_merge( $defaults, $order_args );
		$data['items'] = array_map( [ $this, 'build_order_item' ], $line_items );
		return \wc_create_order( $data );
	}

	/**
	 * Single-product order produces one payload with the basic fields.
	 */
	public function test_woo_order_updated_payload_basic_fields() {
		$order = $this->create_order_with_items(
			[
				[
					'product_id' => 42,
					'name'       => 'Mug',
					'subtotal'   => 12.50,
				],
			],
			[
				'billing_email' => 'a@b.com',
				'currency'      => 'USD',
			]
		);

		$payloads = \Newspack\Data_Events\Utils::get_woo_order_updated_payloads( $order, 'completed' );

		$this->assertCount( 1, $payloads );
		$payload = $payloads[0];
		$this->assertSame( $order->get_id(), $payload['order_id'] );
		$this->assertSame( 'completed', $payload['status'] );
		$this->assertSame( 'a@b.com', $payload['email'] );
		$this->assertSame( 'USD', $payload['currency'] );
		$this->assertSame( 12.50, $payload['amount'] );
		$this->assertSame( 42, $payload['product_id'] );
		$this->assertSame( 'Mug', $payload['product_name'] );
	}

	/**
	 * Register mock donation products and configure the donation product option.
	 *
	 * Returns an associative array of product IDs: parent (grouped), once (simple),
	 * month/year (subscription children with the appropriate `_subscription_period` meta).
	 *
	 * @return array{parent: int, once: int, month: int, year: int}
	 */
	private function setup_donation_products() {
		$ids = [
			'parent' => 999,
			'once'   => 1001,
			'month'  => 1002,
			'year'   => 1003,
		];

		\wc_create_mock_product(
			[
				'id'   => $ids['once'],
				'type' => 'simple',
			]
		);
		\wc_create_mock_product(
			[
				'id'   => $ids['month'],
				'type' => 'subscription',
				'meta' => [ '_subscription_period' => 'month' ],
			]
		);
		\wc_create_mock_product(
			[
				'id'   => $ids['year'],
				'type' => 'subscription',
				'meta' => [ '_subscription_period' => 'year' ],
			]
		);
		\wc_create_mock_product(
			[
				'id'       => $ids['parent'],
				'type'     => 'grouped',
				'children' => [ $ids['once'], $ids['month'], $ids['year'] ],
			]
		);
		\update_option( 'newspack_donation_product_id', $ids['parent'] );

		return $ids;
	}

	/**
	 * Donation product is flagged via is_donation; recurrence comes from product meta.
	 */
	public function test_woo_order_updated_payload_recurrence_and_is_donation() {
		$donation_ids = $this->setup_donation_products();

		$order = $this->create_order_with_items(
			[
				[
					'product_id' => $donation_ids['month'],
					'name'       => 'Monthly Donation',
					'subtotal'   => 10.00,
				],
				[
					'product_id' => 5000,
					'name'       => 'T-shirt',
					'subtotal'   => 20.00,
				],
			]
		);

		$payloads = \Newspack\Data_Events\Utils::get_woo_order_updated_payloads( $order, 'completed' );

		$by_product = [];
		foreach ( $payloads as $payload ) {
			$by_product[ $payload['product_name'] ] = $payload;
		}
		$this->assertTrue( $by_product['Monthly Donation']['is_donation'] );
		$this->assertSame( 'month', $by_product['Monthly Donation']['recurrence'] );

		$this->assertFalse( $by_product['T-shirt']['is_donation'] );
		$this->assertSame( 'once', $by_product['T-shirt']['recurrence'] );
	}

	/**
	 * A multi-product order produces one payload per product line item.
	 */
	public function test_woo_order_updated_payload_multi_product() {
		$order = $this->create_order_with_items(
			[
				[
					'product_id' => 100,
					'name'       => 'Donation',
					'subtotal'   => 50.00,
				],
				[
					'product_id' => 200,
					'name'       => 'T-shirt',
					'subtotal'   => 20.00,
				],
			]
		);

		$payloads = \Newspack\Data_Events\Utils::get_woo_order_updated_payloads( $order, 'completed' );

		$this->assertCount( 2, $payloads );

		$by_product = [];
		foreach ( $payloads as $payload ) {
			$by_product[ $payload['product_name'] ] = $payload;
		}
		$this->assertSame( 50.00, $by_product['Donation']['amount'] );
		$this->assertSame( 20.00, $by_product['T-shirt']['amount'] );
		$this->assertSame( 100, $by_product['Donation']['product_id'] );
		$this->assertSame( 200, $by_product['T-shirt']['product_id'] );
	}

	/**
	 * Build a subscription via the wc-mocks shape.
	 *
	 * @param array $args id, customer_id, status, billing_period, total.
	 * @return \WC_Subscription
	 */
	private function create_test_subscription( $args = [] ) {
		$defaults = [
			'customer_id'    => 0,
			'status'         => 'active',
			'billing_period' => 'month',
			'billing_email'  => 'sub@example.com',
			'currency'       => 'USD',
			'total'          => 30.00,
			'items'          => [],
		];
		return \wcs_create_subscription( array_merge( $defaults, $args ) );
	}

	/**
	 * Create a renewal order linked to the given subscription via the
	 * `_subscription_renewal` meta the mocks recognise.
	 *
	 * @param \WC_Subscription $subscription Subscription to renew.
	 * @param array            $line_items   Line items array (same shape as create_order_with_items).
	 * @param array            $order_args   Optional order overrides.
	 * @return \WC_Order
	 */
	private function create_renewal_order( $subscription, $line_items, $order_args = [] ) {
		$order_args['meta']                          = $order_args['meta'] ?? [];
		$order_args['meta']['_subscription_renewal'] = (int) $subscription->get_id();
		return $this->create_order_with_items( $line_items, $order_args );
	}

	/**
	 * A renewal order has is_renewal=true and subscription_id resolved.
	 */
	public function test_woo_order_updated_payload_renewal() {
		$subscription = $this->create_test_subscription();
		$renewal      = $this->create_renewal_order(
			$subscription,
			[
				[
					'product_id' => 7000,
					'name'       => 'Monthly Plan',
					'subtotal'   => 30.00,
				],
			]
		);

		$payloads = \Newspack\Data_Events\Utils::get_woo_order_updated_payloads( $renewal, 'completed' );

		$this->assertCount( 1, $payloads );
		$this->assertTrue( $payloads[0]['is_renewal'] );
		$this->assertSame( (int) $subscription->get_id(), $payloads[0]['subscription_id'] );
	}

	/**
	 * A non-renewal order has is_renewal=false and subscription_id=null.
	 */
	public function test_woo_order_updated_payload_non_renewal() {
		$order = $this->create_order_with_items(
			[
				[
					'product_id' => 8000,
					'name'       => 'Standalone',
					'subtotal'   => 5.00,
				],
			]
		);

		$payloads = \Newspack\Data_Events\Utils::get_woo_order_updated_payloads( $order, 'completed' );

		$this->assertFalse( $payloads[0]['is_renewal'] );
		$this->assertNull( $payloads[0]['subscription_id'] );
	}

	/**
	 * Newspack referer/popup_id meta on the order is forwarded to the payload.
	 */
	public function test_woo_order_updated_payload_referer_and_popup() {
		$order = $this->create_order_with_items(
			[
				[
					'product_id' => 6000,
					'name'       => 'Generic',
					'subtotal'   => 5.00,
				],
			],
			[
				'meta' => [
					'_newspack_referer'  => 'https://example.com/landing',
					'_newspack_popup_id' => '12345',
				],
			]
		);

		$payloads = \Newspack\Data_Events\Utils::get_woo_order_updated_payloads( $order, 'completed' );

		$this->assertSame( 'https://example.com/landing', $payloads[0]['referer'] );
		$this->assertSame( '12345', $payloads[0]['popup_id'] );
	}

	/**
	 * Single-product subscription emits one payload with the expected fields.
	 */
	public function test_woo_subscription_updated_payload_basic() {
		$donation_ids = $this->setup_donation_products();

		$subscription = $this->create_test_subscription(
			[
				'customer_id'   => 42,
				'billing_email' => 'sub@example.com',
				'currency'      => 'USD',
				'total'         => 30.00,
				'items'         => [
					$this->build_order_item(
						[
							'product_id' => $donation_ids['month'],
							'name'       => 'Monthly Donation',
							'subtotal'   => 30.00,
						]
					),
				],
			]
		);

		$payloads = \Newspack\Data_Events\Utils::get_woo_subscription_updated_payloads( $subscription, 'active' );

		$this->assertCount( 1, $payloads );
		$payload = $payloads[0];
		$this->assertSame( (int) $subscription->get_id(), $payload['subscription_id'] );
		$this->assertSame( 'active', $payload['status'] );
		$this->assertSame( 42, $payload['user_id'] );
		$this->assertSame( 'sub@example.com', $payload['email'] );
		$this->assertSame( 'USD', $payload['currency'] );
		$this->assertSame( 'month', $payload['recurrence'] );
		$this->assertSame( $donation_ids['month'], $payload['product_id'] );
		$this->assertSame( 'Monthly Donation', $payload['product_name'] );
		$this->assertTrue( $payload['is_donation'] );
		$this->assertSame( 30.00, $payload['amount'] );
		$this->assertFalse( $payload['is_switch'] );
	}

	/**
	 * Multi-product subscription produces one payload per line item.
	 */
	public function test_woo_subscription_updated_payload_multi_product() {
		$subscription = $this->create_test_subscription(
			[
				'items' => [
					$this->build_order_item(
						[
							'product_id' => 9001,
							'name'       => 'Monthly Plan',
							'subtotal'   => 30.00,
						]
					),
					$this->build_order_item(
						[
							'product_id' => 9002,
							'name'       => 'Second Tier',
							'subtotal'   => 5.00,
						]
					),
				],
			]
		);

		$payloads = \Newspack\Data_Events\Utils::get_woo_subscription_updated_payloads( $subscription, 'active' );

		$this->assertCount( 2, $payloads );
		$by_product = [];
		foreach ( $payloads as $payload ) {
			$by_product[ $payload['product_name'] ] = $payload;
		}
		$this->assertSame( 30.00, $by_product['Monthly Plan']['amount'] );
		$this->assertSame( 5.00, $by_product['Second Tier']['amount'] );
		$this->assertSame( 9001, $by_product['Monthly Plan']['product_id'] );
		$this->assertSame( 9002, $by_product['Second Tier']['product_id'] );
	}

	/**
	 * `woocommerce_order_status_changed` triggers one woo_order_updated dispatch per line item.
	 */
	public function test_woo_order_updated_listener_dispatches_per_line_item() {
		$captured = [];
		add_action(
			'newspack_data_event_dispatch_woo_order_updated',
			function ( $timestamp, $data, $client_id ) use ( &$captured ) {
				$captured[] = $data;
			},
			10,
			3
		);

		$order = $this->create_order_with_items(
			[
				[
					'product_id' => 11000,
					'name'       => 'Donation',
					'subtotal'   => 50.00,
				],
				[
					'product_id' => 11001,
					'name'       => 'T-shirt',
					'subtotal'   => 20.00,
				],
			]
		);

		// Trigger the listener directly by firing the hook.
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'completed', $order );

		$this->assertCount( 2, $captured );
		$names = array_map(
			static function ( $d ) {
				return $d['product_name'];
			},
			$captured
		);
		$this->assertContains( 'Donation', $names );
		$this->assertContains( 'T-shirt', $names );
		foreach ( $captured as $payload ) {
			$this->assertSame( 'pending', $payload['status_from'] );
			$this->assertSame( 'completed', $payload['status'] );
			$this->assertSame( $order->get_id(), $payload['order_id'] );
		}
	}

	/**
	 * `woocommerce_subscription_status_updated` triggers one woo_subscription_updated dispatch per line item.
	 */
	public function test_woo_subscription_updated_listener_dispatches_per_line_item() {
		$captured = [];
		add_action(
			'newspack_data_event_dispatch_woo_subscription_updated',
			function ( $timestamp, $data, $client_id ) use ( &$captured ) {
				$captured[] = $data;
			},
			10,
			3
		);

		$subscription = $this->create_test_subscription(
			[
				'items' => [
					$this->build_order_item(
						[
							'product_id' => 12000,
							'name'       => 'Monthly Plan',
							'subtotal'   => 30.00,
						]
					),
				],
			]
		);

		// Trigger transition: active -> on-hold.
		do_action( 'woocommerce_subscription_status_updated', $subscription, 'on-hold', 'active' );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'active', $captured[0]['status_from'] );
		$this->assertSame( 'on-hold', $captured[0]['status'] );
		$this->assertSame( (int) $subscription->get_id(), $captured[0]['subscription_id'] );
		$this->assertFalse( $captured[0]['is_switch'] );
	}

	/**
	 * `woocommerce_subscriptions_switch_completed` triggers woo_subscription_updated with the post-switch status.
	 */
	public function test_woo_subscription_updated_listener_dispatches_on_switch() {
		$captured = [];
		add_action(
			'newspack_data_event_dispatch_woo_subscription_updated',
			function ( $timestamp, $data, $client_id ) use ( &$captured ) {
				$captured[] = $data;
			},
			10,
			3
		);

		$subscription = $this->create_test_subscription(
			[
				'status' => 'active',
				'items'  => [
					$this->build_order_item(
						[
							'product_id' => 13000,
							'name'       => 'Switched Plan',
							'subtotal'   => 30.00,
						]
					),
				],
			]
		);

		// Build a switch order whose `_subscription_switch_data` meta references the subscription.
		$switch_order = \wc_create_order(
			[
				'status'      => 'completed',
				'customer_id' => 0,
				'total'       => 30.00,
				'meta'        => [
					'_subscription_switch_data' => [
						(int) $subscription->get_id() => [ 'switches' => [] ],
					],
				],
			]
		);

		do_action( 'woocommerce_subscriptions_switch_completed', $switch_order );

		$this->assertCount( 1, $captured );
		$this->assertSame( (int) $subscription->get_id(), $captured[0]['subscription_id'] );
		// For switches, status_from equals status (no status transition occurred).
		$this->assertSame( 'active', $captured[0]['status_from'] );
		$this->assertSame( 'active', $captured[0]['status'] );
		$this->assertTrue( $captured[0]['is_switch'] );
	}
}
