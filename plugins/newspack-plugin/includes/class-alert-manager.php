<?php
/**
 * Alert Manager for data event handlers, integration health checks, and contact
 * syncs observability.
 *
 * Listens for data event handler and integration sync retry exhaustion and
 * fires a unified alert action for each.
 *
 * Also scans the failure log for recurring patterns and fires an alert when a
 * threshold is exceeded within the configured time window.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Alert Manager Class.
 */
class Alert_Manager {

	/**
	 * WP-Cron hook for the recurring pattern scan.
	 */
	const PATTERN_SCAN_HOOK = 'newspack_alert_pattern_scan';

	/**
	 * Option name for storing the failure log.
	 */
	const FAILURE_LOG_OPTION = 'newspack_alert_failure_log';

	/**
	 * Default pattern rules.
	 * Each rule defines a grouping dimension, threshold, and time interval.
	 */
	const DEFAULT_PATTERN_RULES = [
		[
			'id'        => 'same_user',
			'label'     => 'Same user',
			'group_by'  => 'contact_email',
			'threshold' => 5,
			'interval'  => 3600,
		],
		[
			'id'        => 'same_event',
			'label'     => 'Same event',
			'group_by'  => 'action_name',
			'threshold' => 5,
			'interval'  => 3600,
		],
		[
			'id'        => 'same_integration',
			'label'     => 'Same integration',
			'group_by'  => 'integration_id',
			'threshold' => 5,
			'interval'  => 3600,
		],
		[
			'id'        => 'same_message',
			'label'     => 'Same error message',
			'group_by'  => 'reason',
			'threshold' => 5,
			'interval'  => 3600,
		],
	];

	/**
	 * Get the pattern rules, passed through a filter for customization.
	 *
	 * @return array Pattern rules.
	 */
	public static function get_pattern_rules() {
		/**
		 * Filters the failure pattern detection rules.
		 *
		 * Each rule is an array with keys: id, label, group_by, threshold, interval.
		 * - id: Unique rule identifier.
		 * - label: Human-readable label.
		 * - group_by: Key in the failure record to group by.
		 * - threshold: Number of failures to trigger an alert.
		 * - interval: Time window in seconds.
		 *
		 * @param array $rules The pattern rules.
		 */
		return apply_filters( 'newspack_alert_pattern_rules', self::DEFAULT_PATTERN_RULES );
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'newspack_sync_contact_failed', [ __CLASS__, 'record_failure' ] );
		add_action( 'newspack_data_event_handler_failed', [ __CLASS__, 'record_failure' ] );
		add_action( 'newspack_sync_retry_exhausted', [ __CLASS__, 'handle_sync_retry_exhausted' ] );
		add_action( 'newspack_data_event_retry_exhausted', [ __CLASS__, 'handle_data_event_retry_exhausted' ] );
		add_action( 'newspack_integration_health_check_failed', [ __CLASS__, 'handle_health_check_failed' ] );
		add_action( 'newspack_alert', [ __CLASS__, 'forward_alert_to_log' ] );
		add_action( self::PATTERN_SCAN_HOOK, [ __CLASS__, 'scan_failure_patterns' ] );
		add_action( 'init', [ __CLASS__, 'schedule_pattern_scan' ] );
	}

	/**
	 * Forward a `newspack_alert` to the `newspack_log` action so Newspack
	 * Manager's Logger routes it. Severity drives the destination:
	 *
	 *   - severity = 'error' or 'critical' → type 'error', log_level 3
	 *     (Alert — Slack)
	 *   - anything else (incl. 'warning', unknown, or missing severity) →
	 *     type 'debug', log_level 2 (Watch — logstash only)
	 *
	 * Only known error severities escalate to Slack so an unanticipated
	 * alert shape (e.g. a third-party `newspack_alert` with no severity)
	 * lands in Watch rather than paging on-call.
	 *
	 * Only the human-readable `message` is forwarded as free text. Any
	 * contact email carried in the alert `context` is passed through
	 * Logger's first-class `user_email` param — a structured field that is
	 * not part of the Slack message body — instead of being interpolated
	 * into `message`. The rest of the `context` is intentionally dropped to
	 * avoid leaking source payloads into downstream logs.
	 *
	 * When Newspack Manager isn't active, `newspack_log` is a no-op.
	 *
	 * @param mixed $alert The alert payload fired by this class.
	 */
	public static function forward_alert_to_log( $alert ) {
		if ( ! is_array( $alert ) || ! isset( $alert['message'] ) || ! is_scalar( $alert['message'] ) || '' === (string) $alert['message'] ) {
			return;
		}

		$code = is_scalar( $alert['type'] ?? null ) && '' !== (string) $alert['type']
			? (string) $alert['type']
			: 'newspack_alert';

		$severity = is_scalar( $alert['severity'] ?? null ) ? (string) $alert['severity'] : '';
		$is_error = in_array( $severity, [ 'error', 'critical' ], true );

		$params = [
			'type'      => $is_error ? 'error' : 'debug',
			'log_level' => $is_error ? 3 : 2,
		];

		$user_email = self::get_alert_user_email( $alert );
		if ( '' !== $user_email ) {
			$params['user_email'] = $user_email;
		}

		do_action( 'newspack_log', $code, (string) $alert['message'], $params );
	}

	/**
	 * Extract the contact email (if any) carried in an alert's `context` so
	 * it can be forwarded via Logger's structured `user_email` param rather
	 * than interpolated into the human-readable message.
	 *
	 * @param array $alert The alert payload.
	 *
	 * @return string The contact email, or '' when none is present.
	 */
	private static function get_alert_user_email( $alert ) {
		$context = is_array( $alert['context'] ?? null ) ? $alert['context'] : [];

		// Failure-pattern alerts grouped by contact email carry it as the group value.
		if ( 'contact_email' === ( $context['group_by'] ?? '' ) && is_scalar( $context['group_value'] ?? null ) ) {
			return (string) $context['group_value'];
		}

		// Sync/handler exhaustion payloads carry the contact under `contact.email`.
		if ( is_array( $context['contact'] ?? null ) && is_scalar( $context['contact']['email'] ?? null ) ) {
			return (string) $context['contact']['email'];
		}

		return '';
	}

	/**
	 * Schedule the recurring pattern scan via WP-Cron.
	 */
	public static function schedule_pattern_scan() {
		register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'deactivate_pattern_scan' ] );

		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::PATTERN_SCAN_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			self::deactivate_pattern_scan();
		} elseif ( ! wp_next_scheduled( self::PATTERN_SCAN_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::PATTERN_SCAN_HOOK );
		}
	}

	/**
	 * Deactivate the pattern scan cron job.
	 */
	public static function deactivate_pattern_scan() {
		wp_clear_scheduled_hook( self::PATTERN_SCAN_HOOK );
	}

	/**
	 * Record a failure entry in the failure log option.
	 *
	 * Appends a lightweight, flattened record so the pattern scanner
	 * can later detect recurring failure patterns.
	 *
	 * @param array $payload Alert data from the exhaustion hook.
	 */
	public static function record_failure( $payload ) {
		$log = get_option( self::FAILURE_LOG_OPTION, [] );

		$record = [
			'timestamp'      => time(),
			'integration_id' => $payload['integration_id'] ?? null,
			'contact_email'  => is_array( $payload['contact'] ?? null ) ? ( $payload['contact']['email'] ?? null ) : null,
			'action_name'    => $payload['action_name'] ?? null,
			'reason'         => $payload['reason'] ?? null,
		];

		/**
		 * Filters the failure record before it is stored in the failure log.
		 *
		 * Useful for adding custom fields that a custom pattern rule can group by.
		 *
		 * @param array $record  The failure record to be stored.
		 * @param array $payload The full payload from the exhaustion hook.
		 */
		$record = apply_filters( 'newspack_alert_failure_record', $record, $payload );

		$log[] = $record;
		update_option( self::FAILURE_LOG_OPTION, $log, false );
	}

	/**
	 * Handle sync retry exhaustion.
	 *
	 * @param array $payload Alert data from Contact_Sync.
	 */
	public static function handle_sync_retry_exhausted( $payload ) {
		// The contact email is intentionally left out of the message; it is
		// forwarded to the log via Logger's structured `user_email` param
		// (see forward_alert_to_log) and remains available in `context`.
		$message = sprintf(
			'Max retries (%d) reached for integration "%s" contact sync. Last error: %s',
			$payload['retry_count'] ?? 0,
			$payload['integration_id'] ?? 'unknown',
			$payload['reason'] ?? 'unknown'
		);

		/**
		 * Fires when an alert condition is detected in the sync system.
		 *
		 * @param array $alert {
		 *     Structured alert data.
		 *
		 *     @type string $type          Alert type identifier.
		 *     @type string $severity      Alert severity ('error', 'warning').
		 *     @type string $message       Human-readable alert message.
		 *     @type array  $context       Full payload from the source hook.
		 *     @type int    $timestamp     Unix timestamp.
		 * }
		 */
		do_action(
			'newspack_alert',
			[
				'type'      => 'sync_retry_exhausted',
				'severity'  => 'error',
				'message'   => $message,
				'context'   => $payload,
				'timestamp' => time(),
			]
		);
	}

	/**
	 * Handle data event handler retry exhaustion.
	 *
	 * @param array $payload Alert data from Data_Events.
	 */
	public static function handle_data_event_retry_exhausted( $payload ) {
		$handler_name = is_array( $payload['handler'] ?? null )
			? implode( '::', $payload['handler'] )
			: (string) ( $payload['handler'] ?? 'unknown' );

		$message = sprintf(
			'Max retries (%d) reached for handler %s on "%s". Last error: %s',
			$payload['retry_count'] ?? 0,
			$handler_name,
			$payload['action_name'] ?? 'unknown',
			$payload['reason'] ?? 'unknown'
		);

		/** This action is documented in includes/class-alert-manager.php */
		do_action(
			'newspack_alert',
			[
				'type'      => 'data_event_retry_exhausted',
				'severity'  => 'error',
				'message'   => $message,
				'context'   => $payload,
				'timestamp' => time(),
			]
		);
	}

	/**
	 * Scan the failure log for recurring patterns and fire alerts.
	 *
	 * Reads the failure log, groups entries by each rule's dimension,
	 * and fires a `newspack_alert` action when a threshold is exceeded
	 * within the configured time window. Deduplicates alerts using
	 * transients so the same pattern is not re-alerted within the interval.
	 */
	public static function scan_failure_patterns() {
		$log = get_option( self::FAILURE_LOG_OPTION, [] );
		if ( empty( $log ) ) {
			return;
		}

		$rules        = self::get_pattern_rules();
		$now          = time();
		$max_interval = 0;
		foreach ( $rules as $rule ) {
			if ( $rule['interval'] > $max_interval ) {
				$max_interval = $rule['interval'];
			}
		}

		// Pre-filter once using the widest interval.
		$global_cutoff = $now - $max_interval;
		$recent_log    = array_filter(
			$log,
			function ( $entry ) use ( $global_cutoff ) {
				return $entry['timestamp'] >= $global_cutoff;
			}
		);

		foreach ( $rules as $rule ) {
			$cutoff = $now - $rule['interval'];

			// Group by the rule's dimension, skipping entries outside this rule's window.
			$groups = [];
			foreach ( $recent_log as $entry ) {
				if ( $entry['timestamp'] < $cutoff ) {
					continue;
				}
				$key = $entry[ $rule['group_by'] ] ?? null;
				if ( ! is_scalar( $key ) || null === $key || '' === $key ) {
					continue;
				}
				$key = (string) $key;
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = [];
				}
				$groups[ $key ][] = $entry;
			}

			// Check each group against the threshold.
			foreach ( $groups as $group_value => $entries ) {
				if ( count( $entries ) < $rule['threshold'] ) {
					continue;
				}

				// Deduplication: skip if already alerted within the interval.
				$dedup_key = self::get_dedup_key( $rule['id'], $group_value );
				if ( get_transient( $dedup_key ) ) {
					continue;
				}

				// When grouping by contact email, keep the email out of the
				// message; it is forwarded via Logger's `user_email` param
				// (see forward_alert_to_log) and stays in `context`.
				$is_email_group = 'contact_email' === $rule['group_by'];
				$message        = sprintf(
					'Pattern detected: %d failures with %s%s in the last %s.',
					count( $entries ),
					$rule['label'],
					$is_email_group ? '' : sprintf( ' "%s"', $group_value ),
					self::format_interval( $rule['interval'] )
				);

				/** This action is documented in includes/class-alert-manager.php */
				do_action(
					'newspack_alert',
					[
						'type'      => 'failure_pattern',
						'severity'  => 'error',
						'message'   => $message,
						'context'   => [
							'rule_id'     => $rule['id'],
							'group_by'    => $rule['group_by'],
							'group_value' => $group_value,
							'count'       => count( $entries ),
							'threshold'   => $rule['threshold'],
							'interval'    => $rule['interval'],
						],
						'timestamp' => time(),
					]
				);

				set_transient( $dedup_key, $now, $rule['interval'] );
			}
		}

		// Clean up entries older than the maximum interval.
		if ( $max_interval > 0 ) {
			$cleanup_cutoff = $now - $max_interval;
			$log            = array_filter(
				$log,
				function ( $entry ) use ( $cleanup_cutoff ) {
					return $entry['timestamp'] >= $cleanup_cutoff;
				}
			);
			update_option( self::FAILURE_LOG_OPTION, array_values( $log ), false );
		}
	}

	/**
	 * Get the deduplication transient key for a rule+group combination.
	 *
	 * @param string $rule_id     The rule identifier.
	 * @param string $group_value The grouped value.
	 *
	 * @return string Transient key.
	 */
	private static function get_dedup_key( $rule_id, $group_value ) {
		return 'newspack_alert_pat_' . md5( $rule_id . ':' . $group_value );
	}

	/**
	 * Format a time interval in seconds as a human-readable string.
	 *
	 * @param int $seconds The interval in seconds.
	 *
	 * @return string Formatted interval (e.g. '1h', '5m').
	 */
	private static function format_interval( $seconds ) {
		if ( $seconds >= 3600 ) {
			$hours   = (int) floor( $seconds / 3600 );
			$minutes = (int) floor( ( $seconds % 3600 ) / 60 );

			if ( $minutes > 0 ) {
				return $hours . 'h ' . $minutes . 'm';
			}

			return $hours . 'h';
		}

		if ( $seconds >= 60 ) {
			$minutes = (int) floor( $seconds / 60 );
			return $minutes . 'm';
		}

		return (int) $seconds . 's';
	}

	/**
	 * Handle integration health check failure.
	 *
	 * @param array $payload Health check failure data.
	 */
	public static function handle_health_check_failed( $payload ) {
		$error   = $payload['error'] ?? null;
		$message = sprintf(
			'Integration "%s" health check failed: %s',
			$payload['integration_name'] ?? 'unknown',
			is_wp_error( $error ) ? implode( '; ', $error->get_error_messages() ) : 'unknown error'
		);

		/** This action is documented in includes/class-alert-manager.php */
		do_action(
			'newspack_alert',
			[
				'type'      => 'integration_health_check_failed',
				'severity'  => 'error',
				'message'   => $message,
				'context'   => $payload,
				'timestamp' => time(),
			]
		);
	}
}
Alert_Manager::init();
