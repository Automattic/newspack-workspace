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
	 * Alerts captured by capture_alerts(), keyed by type then severity.
	 *
	 * @var array
	 */
	private $captured = [];

	/**
	 * Clean up hooks between tests to prevent callback leaking.
	 */
	public function tear_down() {
		parent::tear_down();
		remove_all_actions( 'newspack_alert' );
		remove_all_actions( 'newspack_log' );
		remove_all_actions( 'newspack_integration_health_changed' );
		remove_all_filters( 'newspack_alert_pattern_rules' );
		remove_all_filters( 'newspack_alert_failure_record' );
		delete_option( Alert_Manager::FAILURE_LOG_OPTION );
		delete_option( Alert_Manager::HEALTH_STATE_OPTION );
		wp_clear_scheduled_hook( Alert_Manager::PATTERN_SCAN_HOOK );
		$this->captured = [];
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
				'contact'        => [ 'email' => 'test@example.com' ],
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
	 * Test that a permanent config-level failure forwards at warning severity
	 * (Watch). The hourly health check observes the same account state and
	 * owns the Slack escalation, so this path no longer pages.
	 */
	public function test_permanent_failure_alert() {
		$alerts = [];
		add_action(
			'newspack_alert',
			function ( $data ) use ( &$alerts ) {
				$alerts[] = $data;
			}
		);

		do_action(
			'newspack_sync_permanent_failure',
			[
				'integration_id' => 'esp',
				'user_id'        => 1,
				'context'        => 'Reader registered',
				'reason'         => 'Payment Required',
			]
		);

		$this->assertCount( 1, $alerts, 'The permanent failure should fire newspack_alert.' );
		$this->assertEquals( 'sync_permanent_failure', $alerts[0]['type'] );
		$this->assertEquals( 'warning', $alerts[0]['severity'], 'Permanent config failures reach the log, not Slack.' );
		$this->assertStringContainsString( 'Permanent config sync failure for integration "esp"', $alerts[0]['message'] );
	}

	/**
	 * Test that repeat permanent config failures are no longer deduped: at
	 * warning severity each one is a log entry, and per-contact history in
	 * the log is useful.
	 */
	public function test_permanent_config_failure_is_not_deduped() {
		$alerts = [];
		add_action(
			'newspack_alert',
			function ( $data ) use ( &$alerts ) {
				$alerts[] = $data;
			}
		);

		$payload = [
			'integration_id' => 'esp',
			'user_id'        => 1,
			'context'        => 'Reader registered',
			'reason'         => 'Payment Required',
			'error_class'    => 'permanent_config',
		];
		do_action( 'newspack_sync_permanent_failure', $payload );
		do_action( 'newspack_sync_permanent_failure', $payload );

		$this->assertCount( 2, $alerts );
		$this->assertEquals( 'warning', $alerts[1]['severity'] );
	}

	/**
	 * Test that both permanent-failure classes stay out of Slack, and that
	 * the deletion-path message still names the dropped deletion signal.
	 */
	public function test_permanent_failure_classes_both_stay_out_of_slack() {
		$alerts = [];
		add_action(
			'newspack_alert',
			function ( $data ) use ( &$alerts ) {
				$alerts[] = $data;
			}
		);

		do_action(
			'newspack_sync_permanent_failure',
			[
				'integration_id' => 'esp',
				'email'          => 'gone@example.com',
				'mode'           => 'flag',
				'context'        => 'Account deletion',
				'reason'         => 'Your merge fields were invalid.',
				'error_class'    => 'permanent_contact',
			]
		);
		do_action(
			'newspack_sync_permanent_failure',
			[
				'integration_id' => 'esp',
				'user_id'        => 1,
				'context'        => 'Reader registered',
				'reason'         => 'Payment Required',
				'error_class'    => 'permanent_config',
			]
		);

		$this->assertCount( 2, $alerts );
		$this->assertEquals( 'warning', $alerts[0]['severity'] );
		$this->assertStringContainsString( 'deletion signal was not propagated', $alerts[0]['message'] );
		$this->assertEquals( 'warning', $alerts[1]['severity'] );
	}

	/**
	 * Test that non-transient failure classes stay out of the failure log so
	 * never-fixable failures cannot trip the hourly pattern rules.
	 */
	public function test_record_failure_skips_non_transient_classes() {
		foreach ( [ 'benign', 'permanent_contact', 'permanent_config' ] as $error_class ) {
			Alert_Manager::record_failure(
				[
					'integration_id' => 'esp',
					'contact'        => [ 'email' => 'test@example.com' ],
					'reason'         => 'Some error',
					'error_class'    => $error_class,
				]
			);
		}

		$this->assertEmpty( get_option( Alert_Manager::FAILURE_LOG_OPTION, [] ), 'Non-transient classes must not be recorded.' );

		Alert_Manager::record_failure(
			[
				'integration_id' => 'esp',
				'contact'        => [ 'email' => 'test@example.com' ],
				'reason'         => 'Some error',
				'error_class'    => 'transient',
			]
		);
		Alert_Manager::record_failure(
			[
				'action_name' => 'reader_registered',
				'reason'      => 'Handler failed',
			]
		);

		$this->assertCount( 2, get_option( Alert_Manager::FAILURE_LOG_OPTION, [] ), 'Transient and unclassified failures must be recorded.' );
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
				'contact'        => [ 'email' => 'user@example.com' ],
				'context'        => 'Reader registered',
				'reason'         => 'Invalid API key',
			]
		);

		$log = get_option( Alert_Manager::FAILURE_LOG_OPTION, [] );
		$this->assertCount( 1, $log );
		$this->assertEquals( 'mailchimp', $log[0]['integration_id'] );
		$this->assertEquals( 'user@example.com', $log[0]['contact_email'] );
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
				'contact_email'  => "user{$i}@example.com",
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
				'contact_email'  => "user{$i}@example.com",
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
				'contact_email'  => "user{$i}@example.com",
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
				'contact_email'  => "user{$i}@example.com",
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
	 * Alerts from a staging host are capped at Watch (log_level 2), so a
	 * broken sandbox never pages the on-call channel but still reaches the
	 * log.
	 */
	public function test_staging_site_alerts_are_capped_at_watch() {
		add_action( 'newspack_alert', [ Alert_Manager::class, 'forward_alert_to_log' ] );
		$staging = function () {
			return 'https://sandbox.newspackstaging.com';
		};
		add_filter( 'home_url', $staging );

		$captured = null;
		add_action(
			'newspack_log',
			function ( $code, $message, $params ) use ( &$captured ) {
				$captured = compact( 'code', 'message', 'params' );
			},
			10,
			3
		);

		try {
			do_action(
				'newspack_alert',
				[
					'type'     => 'integration_health_check_failed',
					'severity' => 'error',
					'message'  => 'Boom',
				]
			);
		} finally {
			remove_filter( 'home_url', $staging );
		}

		$this->assertNotNull( $captured, 'The entry must still reach the log.' );
		$this->assertSame( 2, $captured['params']['log_level'] );
		$this->assertSame( 'debug', $captured['params']['type'] );
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
	 * Test that permanent-failure alerts forward the contact email via the
	 * structured `user_email` param for both the contact-sync payload (top-level
	 * `email`) and the deletion payload (keyed on `email`).
	 *
	 * @dataProvider permanent_failure_email_provider
	 *
	 * @param array $payload The newspack_sync_permanent_failure payload.
	 */
	public function test_permanent_failure_email_forwarded_via_user_email_param( $payload ) {
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

		do_action( 'newspack_sync_permanent_failure', $payload );

		$this->assertNotNull( $captured );
		$this->assertSame( 'reader@example.com', $captured['params']['user_email'] );
		$this->assertStringNotContainsString( 'reader@example.com', $captured['message'], 'Email must not leak into the message.' );
	}

	/**
	 * Provides the two permanent-failure payload shapes that carry an email.
	 *
	 * @return array
	 */
	public function permanent_failure_email_provider() {
		return [
			'contact-sync path' => [
				[
					'integration_id' => 'esp',
					'user_id'        => 1,
					'email'          => 'reader@example.com',
					'context'        => 'Reader registered',
					'reason'         => 'Payment Required',
				],
			],
			'deletion path'     => [
				[
					'integration_id' => 'esp',
					'email'          => 'reader@example.com',
					'mode'           => 'delete',
					'context'        => 'Reader deleted',
					'reason'         => 'Payment Required',
				],
			],
		];
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

	/**
	 * Helper: build a health-check failure payload with the given codes.
	 *
	 * @param string   $integration_id Integration ID.
	 * @param string[] $codes          WP_Error codes to attach.
	 * @return array
	 */
	private function make_health_check_payload( $integration_id, array $codes ) {
		$error = new \WP_Error();
		foreach ( $codes as $code ) {
			$error->add( $code, sprintf( 'Mock: %s', $code ) );
		}
		return [
			'integration_id'   => $integration_id,
			'integration_name' => 'Mock ' . $integration_id,
			'error'            => $error,
		];
	}

	/**
	 * Capture `newspack_alert` payloads of one type into $this->captured,
	 * split by severity: 'error' pages Slack, 'warning' reaches the log.
	 * Read $this->captured after firing the actions under test.
	 *
	 * @param string $type The alert type to capture.
	 */
	private function capture_alerts( $type ) {
		$this->captured[ $type ] = [
			'error'   => [],
			'warning' => [],
		];
		add_action(
			'newspack_alert',
			function ( $data ) use ( $type ) {
				if ( $type === ( $data['type'] ?? '' ) && isset( $this->captured[ $type ][ $data['severity'] ?? '' ] ) ) {
					$this->captured[ $type ][ $data['severity'] ][] = $data;
				}
			}
		);
	}

	/**
	 * Failures below the threshold reach the log at warning severity and
	 * never page.
	 */
	public function test_health_check_failed_below_threshold_is_watch_only() {
		$this->capture_alerts( 'integration_health_check_failed' );

		$payload = $this->make_health_check_payload( 'esp', [ 'connection_failed' ] );
		do_action( 'newspack_integration_health_check_failed', $payload );
		do_action( 'newspack_integration_health_check_failed', $payload );

		$this->assertCount( 0, $this->captured['integration_health_check_failed']['error'], 'Two failures must not page.' );
		$this->assertCount( 2, $this->captured['integration_health_check_failed']['warning'], 'Every failure reaches the log.' );

		$record = get_option( Alert_Manager::HEALTH_STATE_OPTION )['esp'];
		$this->assertSame( 'failing', $record['status'] );
		$this->assertSame( 2, $record['failures'] );
		$this->assertSame( 'Mock: connection_failed', $record['last_error'] );
	}

	/**
	 * The threshold-th consecutive failure pages once and fires the broken
	 * transition; later failures add nothing to Slack.
	 */
	public function test_health_check_failed_pages_once_at_threshold() {
		$this->capture_alerts( 'integration_health_check_failed' );
		$changed = [];
		add_action(
			'newspack_integration_health_changed',
			function ( $data ) use ( &$changed ) {
				$changed[] = $data;
			}
		);

		$payload = $this->make_health_check_payload( 'esp', [ 'connection_failed' ] );
		for ( $i = 0; $i < Alert_Manager::HEALTH_BROKEN_THRESHOLD + 2; $i++ ) {
			do_action( 'newspack_integration_health_check_failed', $payload );
		}

		$this->assertCount( 1, $this->captured['integration_health_check_failed']['error'], 'One outage pages exactly once.' );
		$this->assertCount( Alert_Manager::HEALTH_BROKEN_THRESHOLD + 1, $this->captured['integration_health_check_failed']['warning'], 'Every other failure reaches the log.' );
		$this->assertStringContainsString( 'failed 3 consecutive health checks', $this->captured['integration_health_check_failed']['error'][0]['message'] );

		$this->assertCount( 1, $changed, 'The broken transition fires once.' );
		$this->assertSame( 'broken', $changed[0]['state'] );
		$this->assertSame( 'esp', $changed[0]['integration_id'] );
		$this->assertSame( Alert_Manager::HEALTH_BROKEN_THRESHOLD, $changed[0]['failures'] );
		$this->assertSame( 'other', $changed[0]['error_class'] );

		$record = get_option( Alert_Manager::HEALTH_STATE_OPTION )['esp'];
		$this->assertSame( 'broken', $record['status'] );
		$this->assertSame( Alert_Manager::HEALTH_BROKEN_THRESHOLD + 2, $record['failures'] );
	}

	/**
	 * A publisher-side error is classified on the transition and named in
	 * the page, since that is the part the on-call can act on.
	 */
	public function test_health_check_failed_names_publisher_side_causes() {
		$this->capture_alerts( 'integration_health_check_failed' );
		$payload = [
			'integration_id'   => 'esp',
			'integration_name' => 'Newsletter ESP',
			'error'            => new \WP_Error( 'newspack_newsletters_connection_error', '403: API Access has been disabled for this account.' ),
		];
		for ( $i = 0; $i < Alert_Manager::HEALTH_BROKEN_THRESHOLD; $i++ ) {
			do_action( 'newspack_integration_health_check_failed', $payload );
		}

		$this->assertCount( 1, $this->captured['integration_health_check_failed']['error'] );
		$this->assertStringContainsString( 'on the publisher side', $this->captured['integration_health_check_failed']['error'][0]['message'] );
		$this->assertSame( 'publisher', $this->captured['integration_health_check_failed']['error'][0]['context']['health']['error_class'] );
	}

	/**
	 * Each integration keeps its own record, so two integrations failing
	 * with the same error page independently.
	 */
	public function test_health_check_failed_keeps_one_record_per_integration() {
		$this->capture_alerts( 'integration_health_check_failed' );

		foreach ( [ 'esp', 'crm' ] as $integration_id ) {
			$payload = $this->make_health_check_payload( $integration_id, [ 'connection_failed' ] );
			for ( $i = 0; $i < Alert_Manager::HEALTH_BROKEN_THRESHOLD; $i++ ) {
				do_action( 'newspack_integration_health_check_failed', $payload );
			}
		}

		$this->assertCount( 2, $this->captured['integration_health_check_failed']['error'], 'Distinct integrations each page once.' );
		$this->assertCount( 2, get_option( Alert_Manager::HEALTH_STATE_OPTION ) );
	}

	/**
	 * A changed error message on an already-broken integration updates the
	 * record and does not page again. The old dedup keyed on message text,
	 * which re-paged every hour for a timeout message carrying a float.
	 */
	public function test_health_check_failed_changed_message_does_not_repage() {
		$this->capture_alerts( 'integration_health_check_failed' );

		$first  = [
			'integration_id'   => 'esp',
			'integration_name' => 'Mock esp',
			'error'            => new \WP_Error( 'connection_failed', 'Request timed out after 20.001555 seconds.' ),
		];
		$second = [
			'integration_id'   => 'esp',
			'integration_name' => 'Mock esp',
			'error'            => new \WP_Error( 'connection_failed', 'Request timed out after 20.002996 seconds.' ),
		];
		for ( $i = 0; $i < Alert_Manager::HEALTH_BROKEN_THRESHOLD; $i++ ) {
			do_action( 'newspack_integration_health_check_failed', $first );
		}
		do_action( 'newspack_integration_health_check_failed', $second );
		do_action( 'newspack_integration_health_check_failed', $second );

		$this->assertCount( 1, $this->captured['integration_health_check_failed']['error'], 'A new message on a broken integration must not page again.' );
		$this->assertSame( 'Request timed out after 20.002996 seconds.', get_option( Alert_Manager::HEALTH_STATE_OPTION )['esp']['last_error'] );
	}

	/**
	 * The record is written BEFORE dispatch so a `newspack_alert` handler
	 * that throws cannot leave the transition unrecorded and page again on
	 * the next hourly cron.
	 */
	public function test_health_check_failed_records_state_before_dispatch() {
		$payload = $this->make_health_check_payload( 'esp', [ 'connection_failed' ] );
		for ( $i = 0; $i < Alert_Manager::HEALTH_BROKEN_THRESHOLD - 1; $i++ ) {
			do_action( 'newspack_integration_health_check_failed', $payload );
		}

		$listener = function () {
			throw new \RuntimeException( 'Simulated handler failure.' );
		};
		add_action( 'newspack_alert', $listener );
		try {
			do_action( 'newspack_integration_health_check_failed', $payload );
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected: the handler is intentionally throwing.
		} finally {
			remove_action( 'newspack_alert', $listener );
		}

		$this->assertSame( 'broken', get_option( Alert_Manager::HEALTH_STATE_OPTION )['esp']['status'], 'The transition must be recorded even when dispatch throws.' );

		$this->capture_alerts( 'integration_health_check_failed' );
		do_action( 'newspack_integration_health_check_failed', $payload );
		$this->assertCount( 0, $this->captured['integration_health_check_failed']['error'], 'The next failure must not page again.' );
	}

	/**
	 * A pass after broken fires the recovered transition, logs at warning
	 * severity, and clears the record so a fresh outage can page again.
	 */
	public function test_health_check_passed_after_broken_reports_recovery() {
		$this->capture_alerts( 'integration_health_check_failed' );
		$this->capture_alerts( 'integration_health_check_recovered' );
		$changed = [];
		add_action(
			'newspack_integration_health_changed',
			function ( $data ) use ( &$changed ) {
				$changed[] = $data;
			}
		);

		$failure = $this->make_health_check_payload( 'esp', [ 'connection_failed' ] );
		$pass    = [
			'integration_id'   => 'esp',
			'integration_name' => 'Mock esp',
		];
		for ( $i = 0; $i < Alert_Manager::HEALTH_BROKEN_THRESHOLD; $i++ ) {
			do_action( 'newspack_integration_health_check_failed', $failure );
		}
		do_action( 'newspack_integration_health_check_passed', $pass );

		$this->assertCount( 2, $changed );
		$this->assertSame( 'recovered', $changed[1]['state'] );
		$this->assertSame( 'esp', $changed[1]['integration_id'] );
		$this->assertSame( Alert_Manager::HEALTH_BROKEN_THRESHOLD, $changed[1]['failures'] );
		$this->assertSame( 'Mock: connection_failed', $changed[1]['error'] );

		$this->assertCount( 1, $this->captured['integration_health_check_recovered']['warning'], 'Recovery reaches the log without paging.' );
		$this->assertCount( 0, $this->captured['integration_health_check_recovered']['error'] );
		$this->assertStringContainsString( 'passing again', $this->captured['integration_health_check_recovered']['warning'][0]['message'] );

		$this->assertFalse( get_option( Alert_Manager::HEALTH_STATE_OPTION ), 'The last record clears the option.' );

		for ( $i = 0; $i < Alert_Manager::HEALTH_BROKEN_THRESHOLD; $i++ ) {
			do_action( 'newspack_integration_health_check_failed', $failure );
		}
		$this->assertCount( 2, $this->captured['integration_health_check_failed']['error'], 'A new outage after recovery pages again.' );
	}

	/**
	 * A pass while merely failing (below the threshold) resets the record
	 * silently: nothing was reported, so there is nothing to recover from.
	 */
	public function test_health_check_passed_while_failing_resets_silently() {
		$this->capture_alerts( 'integration_health_check_failed' );
		$this->capture_alerts( 'integration_health_check_recovered' );
		$changed = [];
		add_action(
			'newspack_integration_health_changed',
			function ( $data ) use ( &$changed ) {
				$changed[] = $data;
			}
		);

		$failure = $this->make_health_check_payload( 'esp', [ 'connection_failed' ] );
		$pass    = [
			'integration_id'   => 'esp',
			'integration_name' => 'Mock esp',
		];
		do_action( 'newspack_integration_health_check_failed', $failure );
		do_action( 'newspack_integration_health_check_failed', $failure );
		do_action( 'newspack_integration_health_check_passed', $pass );

		$this->assertCount( 0, $changed, 'No transition below the threshold.' );
		$this->assertCount( 0, $this->captured['integration_health_check_recovered']['warning'] );
		$this->assertFalse( get_option( Alert_Manager::HEALTH_STATE_OPTION ) );

		// The count restarted: two more failures do not reach the threshold.
		do_action( 'newspack_integration_health_check_failed', $failure );
		do_action( 'newspack_integration_health_check_failed', $failure );
		$this->assertCount( 0, $this->captured['integration_health_check_failed']['error'] );
	}

	/**
	 * A pass with no record is the hourly steady state and must not write
	 * the option.
	 */
	public function test_health_check_passed_when_healthy_writes_nothing() {
		$writes  = 0;
		$counter = function () use ( &$writes ) {
			$writes++;
		};
		add_action( 'add_option_' . Alert_Manager::HEALTH_STATE_OPTION, $counter );
		add_action( 'update_option_' . Alert_Manager::HEALTH_STATE_OPTION, $counter );
		$changed = [];
		add_action(
			'newspack_integration_health_changed',
			function ( $data ) use ( &$changed ) {
				$changed[] = $data;
			}
		);

		try {
			do_action(
				'newspack_integration_health_check_passed',
				[
					'integration_id'   => 'esp',
					'integration_name' => 'Mock esp',
				]
			);
		} finally {
			remove_action( 'add_option_' . Alert_Manager::HEALTH_STATE_OPTION, $counter );
			remove_action( 'update_option_' . Alert_Manager::HEALTH_STATE_OPTION, $counter );
		}

		$this->assertSame( 0, $writes );
		$this->assertCount( 0, $changed );
	}

	/**
	 * Health-check errors classify as publisher-side when the ESP account
	 * itself is the problem, and as other for anything else.
	 *
	 * @dataProvider data_health_error_classification
	 *
	 * @param string     $message  The WP_Error message.
	 * @param string     $expected 'publisher' or 'other'.
	 * @param string|int $code     The WP_Error code.
	 */
	public function test_classify_health_error( $message, $expected, $code = 'newspack_newsletters_connection_error' ) {
		$method = new \ReflectionMethod( Alert_Manager::class, 'classify_health_error' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( null, new \WP_Error( $code, $message ) ) );
	}

	/**
	 * Messages seen in the alerts channel during the week of 2026-09-16, plus
	 * the Newsletters ActiveCampaign provider's dead-key response. The rows
	 * with a numeric code have that provider's shape for a response without
	 * an error body: the HTTP status as the code and its reason phrase as the
	 * message.
	 */
	public function data_health_error_classification() {
		return [
			'mailchimp invalid key'         => [ "401: Your API key may be invalid, or you've attempted to access the wrong datacenter.", 'publisher' ],
			'mailchimp key disabled'        => [ '401: API key has been disabled', 'publisher' ],
			'mailchimp access disabled'     => [ '403: API Access has been disabled for this account. Please contact customer support.', 'publisher' ],
			'mailchimp unpaid'              => [ 'Payment Required', 'publisher' ],
			'account deactivated'           => [ 'User Disabled: This account has been deactivated.', 'publisher' ],
			'bare 401 from activecampaign'  => [ 'ActiveCampaign REST returned status 401 for /api/3/users/me.', 'publisher' ],
			'status only in the code'       => [ 'Forbidden', 'publisher', 403 ],
			'mailchimp timeout'             => [ 'Request timed out after 20.001555 seconds.', 'other' ],
			'timeout with a 401-like float' => [ 'Request timed out after 401.5 seconds.', 'other' ],
			'service unavailable'           => [ 'Service Unavailable', 'other', 503 ],
			'activecampaign 503'            => [ 'ActiveCampaign REST returned status 503 for /api/3/users/me.', 'other' ],
			'too many requests'             => [ 'Too Many Requests', 'other', 429 ],
		];
	}

	/**
	 * A non-WP_Error value classifies as other rather than throwing.
	 */
	public function test_classify_health_error_without_wp_error() {
		$method = new \ReflectionMethod( Alert_Manager::class, 'classify_health_error' );
		$method->setAccessible( true );

		$this->assertSame( 'other', $method->invoke( null, null ) );
	}
}
