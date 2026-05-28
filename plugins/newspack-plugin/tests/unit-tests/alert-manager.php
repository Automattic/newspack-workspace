<?php
/**
 * Tests the Alert_Manager functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Alert_Manager;

/**
 * Test the Alert_Manager class.
 */
class Newspack_Test_Alert_Manager extends WP_UnitTestCase {

	/**
	 * Clean up hooks between tests to prevent callback leaking.
	 */
	public function tear_down() {
		parent::tear_down();
		remove_all_actions( 'newspack_alert' );
		remove_all_actions( 'newspack_log' );
		remove_all_filters( 'newspack_alert_pattern_rules' );
		remove_all_filters( 'newspack_alert_failure_record' );
		delete_option( Alert_Manager::FAILURE_LOG_OPTION );
		wp_clear_scheduled_hook( Alert_Manager::PATTERN_SCAN_HOOK );
	}

	/**
	 * Test that sync retry exhaustion triggers unified newspack_alert.
	 */
	public function test_sync_exhaustion_triggers_unified_alert() {
		$alert_fired = false;
		$alert_data  = null;
		add_action(
			'newspack_alert',
			function ( $data ) use ( &$alert_fired, &$alert_data ) {
				$alert_fired = true;
				$alert_data  = $data;
			}
		);

		do_action(
			'newspack_sync_retry_exhausted',
			[
				'integration_id' => 'esp',
				'contact'        => [ 'email' => 'test@test.com' ],
				'context'        => 'Reader registered',
				'retry_count'    => 5,
				'reason'         => 'Invalid API key',
			]
		);

		$this->assertTrue( $alert_fired, 'newspack_alert should fire.' );
		$this->assertEquals( 'sync_retry_exhausted', $alert_data['type'] );
		$this->assertEquals( 'error', $alert_data['severity'] );
		$this->assertArrayHasKey( 'message', $alert_data );
		$this->assertArrayHasKey( 'context', $alert_data );
		$this->assertArrayHasKey( 'timestamp', $alert_data );
	}

	/**
	 * Test that data event retry exhaustion triggers unified newspack_alert.
	 */
	public function test_data_event_exhaustion_triggers_unified_alert() {
		$alert_fired = false;
		$alert_data  = null;
		add_action(
			'newspack_alert',
			function ( $data ) use ( &$alert_fired, &$alert_data ) {
				$alert_fired = true;
				$alert_data  = $data;
			}
		);

		do_action(
			'newspack_data_event_retry_exhausted',
			[
				'handler'     => [ 'SomeClass', 'some_method' ],
				'action_name' => 'reader_registered',
				'data'        => [],
				'retry_count' => 5,
				'reason'      => 'Handler threw exception',
			]
		);

		$this->assertTrue( $alert_fired, 'newspack_alert should fire.' );
		$this->assertEquals( 'data_event_retry_exhausted', $alert_data['type'] );
	}

	/**
	 * Test that get_pattern_rules returns default rules.
	 */
	public function test_get_pattern_rules_returns_defaults() {
		$rules = Alert_Manager::get_pattern_rules();
		$this->assertIsArray( $rules );
		$this->assertCount( 4, $rules );

		$ids = array_column( $rules, 'id' );
		$this->assertContains( 'same_user', $ids );
		$this->assertContains( 'same_event', $ids );
		$this->assertContains( 'same_integration', $ids );
		$this->assertContains( 'same_message', $ids );

		// Each rule has required keys.
		foreach ( $rules as $rule ) {
			$this->assertArrayHasKey( 'id', $rule );
			$this->assertArrayHasKey( 'label', $rule );
			$this->assertArrayHasKey( 'group_by', $rule );
			$this->assertArrayHasKey( 'threshold', $rule );
			$this->assertArrayHasKey( 'interval', $rule );
		}
	}

	/**
	 * Test that pattern rules are filterable.
	 */
	public function test_pattern_rules_are_filterable() {
		$custom_rule = [
			'id'        => 'custom_rule',
			'label'     => 'Custom',
			'group_by'  => 'custom_field',
			'threshold' => 10,
			'interval'  => 7200,
		];
		add_filter(
			'newspack_alert_pattern_rules',
			function ( $rules ) use ( $custom_rule ) {
				$rules[] = $custom_rule;
				return $rules;
			}
		);
		$rules = Alert_Manager::get_pattern_rules();
		$ids   = array_column( $rules, 'id' );
		$this->assertContains( 'custom_rule', $ids );
	}

	/**
	 * Test that each sync failure records a failure entry (not just exhaustion).
	 */
	public function test_sync_failure_records_entry() {

		do_action(
			'newspack_sync_contact_failed',
			[
				'integration_id' => 'mailchimp',
				'contact'        => [ 'email' => 'user@test.com' ],
				'context'        => 'Reader registered',
				'reason'         => 'Invalid API key',
			]
		);

		$log = get_option( Alert_Manager::FAILURE_LOG_OPTION, [] );
		$this->assertCount( 1, $log );
		$this->assertEquals( 'mailchimp', $log[0]['integration_id'] );
		$this->assertEquals( 'user@test.com', $log[0]['contact_email'] );
		$this->assertEquals( 'Invalid API key', $log[0]['reason'] );
		$this->assertNull( $log[0]['action_name'] );
	}

	/**
	 * Test that the failure record is filterable.
	 */
	public function test_failure_record_is_filterable() {

		add_filter(
			'newspack_alert_failure_record',
			function ( $record, $payload ) {
				$record['handler_name'] = is_array( $payload['handler'] ?? null )
					? implode( '::', $payload['handler'] )
					: ( $payload['handler'] ?? null );
				return $record;
			},
			10,
			2
		);

		do_action(
			'newspack_data_event_handler_failed',
			[
				'handler'     => [ 'SomeClass', 'some_method' ],
				'action_name' => 'reader_registered',
				'data'        => [],
				'reason'      => 'Handler threw exception',
			]
		);

		$log = get_option( Alert_Manager::FAILURE_LOG_OPTION, [] );
		$this->assertCount( 1, $log );
		$this->assertArrayHasKey( 'handler_name', $log[0] );
		$this->assertEquals( 'SomeClass::some_method', $log[0]['handler_name'] );
	}

	/**
	 * Test that each data event handler failure records a failure entry.
	 */
	public function test_data_event_failure_records_entry() {

		do_action(
			'newspack_data_event_handler_failed',
			[
				'handler'     => [ 'SomeClass', 'some_method' ],
				'action_name' => 'reader_registered',
				'data'        => [],
				'reason'      => 'Handler threw exception',
			]
		);

		$log = get_option( Alert_Manager::FAILURE_LOG_OPTION, [] );
		$this->assertCount( 1, $log );
		$this->assertEquals( 'reader_registered', $log[0]['action_name'] );
		$this->assertEquals( 'Handler threw exception', $log[0]['reason'] );
		$this->assertNull( $log[0]['integration_id'] );
		$this->assertNull( $log[0]['contact_email'] );
	}

	/**
	 * Test that the scanner fires a pattern alert when threshold is exceeded.
	 */
	public function test_scanner_fires_pattern_alert_above_threshold() {

		// Record 5 failures for the same integration (threshold is 5).
		$log = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$log[] = [
				'timestamp'      => time() - 60,
				'integration_id' => 'mailchimp',
				'contact_email'  => "user{$i}@test.com",
				'action_name'    => null,
				'reason'         => "API timeout {$i}",
			];
		}
		update_option( Alert_Manager::FAILURE_LOG_OPTION, $log, false );

		$alert_fired = false;
		$alert_data  = null;
		add_action(
			'newspack_alert',
			function ( $data ) use ( &$alert_fired, &$alert_data ) {
				if ( 'failure_pattern' === $data['type'] ) {
					$alert_fired = true;
					$alert_data  = $data;
				}
			}
		);

		Alert_Manager::scan_failure_patterns();

		$this->assertTrue( $alert_fired, 'Pattern alert should fire when threshold is met.' );
		$this->assertEquals( 'failure_pattern', $alert_data['type'] );
		$this->assertEquals( 'error', $alert_data['severity'] );
		$this->assertEquals( 'same_integration', $alert_data['context']['rule_id'] );
		$this->assertEquals( 'mailchimp', $alert_data['context']['group_value'] );
		$this->assertEquals( 5, $alert_data['context']['count'] );
	}

	/**
	 * Test that the scanner does NOT fire when below threshold.
	 */
	public function test_scanner_does_not_fire_below_threshold() {

		// Record 4 failures (below threshold of 5).
		$log = [];
		for ( $i = 0; $i < 4; $i++ ) {
			$log[] = [
				'timestamp'      => time() - 60,
				'integration_id' => 'mailchimp',
				'contact_email'  => "user{$i}@test.com",
				'action_name'    => null,
				'reason'         => "API timeout {$i}",
			];
		}
		update_option( Alert_Manager::FAILURE_LOG_OPTION, $log, false );

		$alert_fired = false;
		add_action(
			'newspack_alert',
			function ( $data ) use ( &$alert_fired ) {
				if ( 'failure_pattern' === $data['type'] ) {
					$alert_fired = true;
				}
			}
		);

		Alert_Manager::scan_failure_patterns();

		$this->assertFalse( $alert_fired, 'Pattern alert should NOT fire below threshold.' );
	}

	/**
	 * Test that the scanner ignores failures outside the interval window.
	 */
	public function test_scanner_ignores_old_failures() {

		// Record 5 failures, but all older than the 1-hour interval.
		$log = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$log[] = [
				'timestamp'      => time() - 7200,
				'integration_id' => 'mailchimp',
				'contact_email'  => "user{$i}@test.com",
				'action_name'    => null,
				'reason'         => "API timeout {$i}",
			];
		}
		update_option( Alert_Manager::FAILURE_LOG_OPTION, $log, false );

		$alert_fired = false;
		add_action(
			'newspack_alert',
			function ( $data ) use ( &$alert_fired ) {
				if ( 'failure_pattern' === $data['type'] ) {
					$alert_fired = true;
				}
			}
		);

		Alert_Manager::scan_failure_patterns();

		$this->assertFalse( $alert_fired, 'Pattern alert should NOT fire for old failures.' );
	}

	/**
	 * Test that the scanner does not re-alert the same pattern within the interval.
	 */
	public function test_scanner_deduplicates_alerts() {

		$log = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$log[] = [
				'timestamp'      => time() - 60,
				'integration_id' => 'mailchimp',
				'contact_email'  => "user{$i}@test.com",
				'action_name'    => null,
				'reason'         => "API timeout {$i}",
			];
		}
		update_option( Alert_Manager::FAILURE_LOG_OPTION, $log, false );

		$fire_count = 0;
		add_action(
			'newspack_alert',
			function ( $data ) use ( &$fire_count ) {
				if ( 'failure_pattern' === $data['type'] ) {
					$fire_count++;
				}
			}
		);

		// First scan should fire.
		Alert_Manager::scan_failure_patterns();
		$this->assertEquals( 1, $fire_count, 'First scan should fire the pattern alert.' );

		// Re-add log entries (scanner cleans up, so repopulate).
		update_option( Alert_Manager::FAILURE_LOG_OPTION, $log, false );

		// Second scan should NOT fire (dedup transient active).
		Alert_Manager::scan_failure_patterns();
		$this->assertEquals( 1, $fire_count, 'Second scan should be deduplicated.' );
	}

	/**
	 * Test that firing `newspack_alert` reaches `newspack_log` via the
	 * registered listener with log_level 3 (Alert → Slack) for error severity.
	 */
	public function test_newspack_alert_emits_newspack_log_via_listener() {
		add_action( 'newspack_alert', [ Alert_Manager::class, 'forward_alert_to_log' ] );

		$captured = null;
		add_action(
			'newspack_log',
			function ( $code, $message, $params ) use ( &$captured ) {
				$captured = compact( 'code', 'message', 'params' );
			},
			10,
			3
		);

		do_action(
			'newspack_alert',
			[
				'type'      => 'sync_retry_exhausted',
				'severity'  => 'error',
				'message'   => 'Boom',
				'context'   => [ 'integration_id' => 'mailchimp' ],
				'timestamp' => time(),
			]
		);

		$this->assertNotNull( $captured, 'newspack_log should fire via the newspack_alert listener.' );
		$this->assertSame( 'sync_retry_exhausted', $captured['code'] );
		$this->assertSame( 'Boom', $captured['message'] );
		$this->assertSame( 'error', $captured['params']['type'] );
		$this->assertSame( 3, $captured['params']['log_level'] );
		$this->assertArrayNotHasKey( 'data', $captured['params'], 'Context should not be forwarded as data.' );
	}

	/**
	 * Test severity-to-destination routing. Only known error severities
	 * escalate to Slack (log_level 3); everything else — including
	 * 'warning', unknown values, and a missing severity — lands in Watch
	 * (log_level 2) so unanticipated alert shapes do not page on-call.
	 *
	 * @dataProvider data_severity_routing
	 *
	 * @param array  $alert         Alert payload to forward.
	 * @param string $expected_type Expected forwarded log `type`.
	 * @param int    $expected_lvl  Expected forwarded `log_level`.
	 */
	public function test_severity_routing( $alert, $expected_type, $expected_lvl ) {
		add_action( 'newspack_alert', [ Alert_Manager::class, 'forward_alert_to_log' ] );

		$captured = null;
		add_action(
			'newspack_log',
			function ( $code, $message, $params ) use ( &$captured ) {
				$captured = compact( 'code', 'message', 'params' );
			},
			10,
			3
		);

		do_action( 'newspack_alert', $alert );

		$this->assertNotNull( $captured );
		$this->assertSame( $expected_type, $captured['params']['type'] );
		$this->assertSame( $expected_lvl, $captured['params']['log_level'] );
	}

	/**
	 * Severity routing scenarios.
	 */
	public function data_severity_routing() {
		return [
			'error → Alert/Slack'      => [
				[
					'severity' => 'error',
					'message'  => 'x',
				],
				'error',
				3,
			],
			'critical → Alert/Slack'   => [
				[
					'severity' => 'critical',
					'message'  => 'x',
				],
				'error',
				3,
			],
			'warning → Watch'          => [
				[
					'severity' => 'warning',
					'message'  => 'x',
				],
				'debug',
				2,
			],
			'info → Watch'             => [
				[
					'severity' => 'info',
					'message'  => 'x',
				],
				'debug',
				2,
			],
			'empty severity → Watch'   => [
				[
					'severity' => '',
					'message'  => 'x',
				],
				'debug',
				2,
			],
			'missing severity → Watch' => [ [ 'message' => 'x' ], 'debug', 2 ],
		];
	}

	/**
	 * Test that an alert without a `type` falls back to the default
	 * `newspack_alert` log code.
	 */
	public function test_alert_without_type_uses_default_code() {
		add_action( 'newspack_alert', [ Alert_Manager::class, 'forward_alert_to_log' ] );

		$captured = null;
		add_action(
			'newspack_log',
			function ( $code, $message, $params ) use ( &$captured ) {
				$captured = compact( 'code', 'message', 'params' );
			},
			10,
			3
		);

		do_action(
			'newspack_alert',
			[
				'severity' => 'error',
				'message'  => 'No type here',
			] 
		);

		$this->assertNotNull( $captured );
		$this->assertSame( 'newspack_alert', $captured['code'] );
	}

	/**
	 * Test that a numeric-zero message is still forwarded (it casts to the
	 * non-empty string '0'), unlike (bool) false which casts to ''.
	 */
	public function test_numeric_zero_message_is_forwarded() {
		add_action( 'newspack_alert', [ Alert_Manager::class, 'forward_alert_to_log' ] );

		$captured = null;
		add_action(
			'newspack_log',
			function ( $code, $message, $params ) use ( &$captured ) {
				$captured = compact( 'code', 'message', 'params' );
			},
			10,
			3
		);

		do_action(
			'newspack_alert',
			[
				'severity' => 'error',
				'message'  => 0,
			] 
		);

		$this->assertNotNull( $captured, 'A numeric 0 message should still be forwarded.' );
		$this->assertSame( '0', $captured['message'] );
	}

	/**
	 * Test that a contact email in the alert context is forwarded via
	 * Logger's structured `user_email` param and is NOT interpolated into
	 * the human-readable message that reaches Slack.
	 */
	public function test_contact_email_forwarded_via_user_email_param() {
		add_action( 'newspack_alert', [ Alert_Manager::class, 'forward_alert_to_log' ] );

		$captured = null;
		add_action(
			'newspack_log',
			function ( $code, $message, $params ) use ( &$captured ) {
				$captured = compact( 'code', 'message', 'params' );
			},
			10,
			3
		);

		// Sync/handler exhaustion payload: contact under context.contact.email.
		do_action(
			'newspack_sync_retry_exhausted',
			[
				'integration_id' => 'mailchimp',
				'contact'        => [ 'email' => 'reader@example.com' ],
				'retry_count'    => 5,
				'reason'         => 'Invalid API key',
			]
		);

		$this->assertNotNull( $captured );
		$this->assertSame( 'reader@example.com', $captured['params']['user_email'] );
		$this->assertStringNotContainsString( 'reader@example.com', $captured['message'], 'Email must not leak into the message.' );
	}

	/**
	 * Test that a `same_user` failure pattern (grouped by contact email)
	 * forwards the email via `user_email` and keeps it out of the message.
	 */
	public function test_same_user_pattern_email_forwarded_via_user_email_param() {
		add_action( 'newspack_alert', [ Alert_Manager::class, 'forward_alert_to_log' ] );

		$captured = null;
		add_action(
			'newspack_log',
			function ( $code, $message, $params ) use ( &$captured ) {
				if ( 'failure_pattern' === $code || str_contains( (string) $message, 'Pattern detected' ) ) {
					$captured = compact( 'code', 'message', 'params' );
				}
			},
			10,
			3
		);

		// Five failures for the same contact email (same_user threshold is 5).
		$log = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$log[] = [
				'timestamp'      => time() - 60,
				'integration_id' => "esp{$i}",
				'contact_email'  => 'reader@example.com',
				'action_name'    => "action_{$i}",
				'reason'         => "reason {$i}",
			];
		}
		update_option( Alert_Manager::FAILURE_LOG_OPTION, $log, false );

		Alert_Manager::scan_failure_patterns();

		$this->assertNotNull( $captured, 'A same_user pattern alert should be forwarded.' );
		$this->assertSame( 'reader@example.com', $captured['params']['user_email'] );
		$this->assertStringNotContainsString( 'reader@example.com', $captured['message'], 'Email must not leak into the message.' );
	}

	/**
	 * Test that malformed alerts (non-array, missing or non-scalar message)
	 * do not fire `newspack_log`.
	 *
	 * @dataProvider data_malformed_alerts
	 *
	 * @param mixed $alert The alert payload to forward.
	 */
	public function test_malformed_alert_does_not_emit_log( $alert ) {
		add_action( 'newspack_alert', [ Alert_Manager::class, 'forward_alert_to_log' ] );

		$fired = false;
		add_action(
			'newspack_log',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		do_action( 'newspack_alert', $alert );

		$this->assertFalse( $fired, 'newspack_log should not fire for malformed alerts.' );
	}

	/**
	 * Malformed alert payloads.
	 */
	public function data_malformed_alerts() {
		return [
			'non-array'            => [ 'string' ],
			'missing message'      => [ [ 'type' => 'x' ] ],
			'non-scalar message'   => [ [ 'message' => [ 'not', 'a', 'string' ] ] ],
			'empty string message' => [ [ 'message' => '' ] ],
			// (bool) false casts to '' so it is skipped like an empty string.
			'false message'        => [ [ 'message' => false ] ],
		];
	}

	/**
	 * Test that the pattern scan cron event is scheduled.
	 */
	public function test_pattern_scan_is_scheduled() {
		wp_clear_scheduled_hook( Alert_Manager::PATTERN_SCAN_HOOK );

		Alert_Manager::schedule_pattern_scan();

		$this->assertNotFalse(
			wp_next_scheduled( Alert_Manager::PATTERN_SCAN_HOOK ),
			'Pattern scan cron event should be scheduled.'
		);
	}
}
