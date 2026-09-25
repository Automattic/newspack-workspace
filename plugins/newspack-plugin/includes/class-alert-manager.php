<?php
/**
 * Alert Manager for data event handlers, integration health checks, and contact
 * syncs observability.
 *
 * Listens for data event handler and integration sync retry exhaustion and
 * fires a unified alert action for each.
 *
 * Keeps one health record per integration so a broken integration pages
 * once, on the transition, and reports its recovery.
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
	 * Option holding one health record per integration ID.
	 *
	 * An option rather than a transient: the previous dedup lived in
	 * transients, and a site's object cache evicting one re-paged the same
	 * failure hours later. A record exists only while an integration is
	 * failing or broken, so a healthy integration's hourly pass costs no
	 * write.
	 */
	const HEALTH_STATE_OPTION = 'newspack_integration_health_state';

	/**
	 * Consecutive failed hourly checks before an integration counts as
	 * broken and pages once. Three outlasts the provider blips seen in the
	 * alerts channel, which cleared within a single check.
	 */
	const HEALTH_BROKEN_THRESHOLD = 3;

	/**
	 * Longest gap between two failed checks that still extends a failing
	 * streak. The check runs hourly, so this allows for one skipped run. A
	 * longer silence (a cron stall, or checks switched off for a while) says
	 * nothing about the hours in between, and a streak carried across it
	 * would page on the next single failure, dated from before the gap.
	 */
	const HEALTH_STREAK_MAX_GAP = 3 * HOUR_IN_SECONDS;

	/**
	 * Substring signatures (lowercase) that mark a health-check failure as
	 * publisher-side: the ESP account is disabled, unpaid, or holding a dead
	 * key, so the fix belongs to the publisher and retrying on our side
	 * changes nothing. Matched against the joined, lowercased WP_Error
	 * messages, as Contact_Sync::ERROR_SIGNATURES does for push errors. A
	 * bare 401, 402 or 403 status marks it publisher-side too, whether the
	 * provider printed it in the message ("401: …" or "status 401") or kept
	 * it as the error code.
	 *
	 * Anything unmatched is 'other': a provider outage, a timeout, or an
	 * error not seen before, which stays an engineering signal.
	 */
	private const PUBLISHER_ERROR_SIGNATURES = [
		'api access has been disabled',
		'payment required',
		'api key',
		'account has been deactivated',
		'user disabled',
	];

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
		add_action( 'newspack_sync_permanent_failure', [ __CLASS__, 'handle_sync_permanent_failure' ] );
		add_action( 'newspack_data_event_retry_exhausted', [ __CLASS__, 'handle_data_event_retry_exhausted' ] );
		add_action( 'newspack_integration_health_check_failed', [ __CLASS__, 'handle_health_check_failed' ] );
		add_action( 'newspack_integration_health_check_passed', [ __CLASS__, 'handle_health_check_passed' ] );
		add_action( 'newspack_integration_health_checks_completed', [ __CLASS__, 'prune_health_state' ] );
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
	 *   - a Newspack staging host (`*.newspackstaging.com`) → always Watch
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

		// Staging sites report to the same on-call channel as production,
		// and a broken sandbox is never an incident. Watch keeps the entry
		// in the log.
		if ( $is_error && self::is_staging_site() ) {
			$is_error = false;
		}

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

		// Permanent-failure payloads carry the contact email at the top level
		// (contact-sync path passes the user's email, deletion path is keyed on it).
		if ( is_scalar( $context['email'] ?? null ) && '' !== (string) $context['email'] ) {
			return (string) $context['email'];
		}

		return '';
	}

	/**
	 * Whether this site is a Newspack staging site, judged by its host.
	 *
	 * @return bool
	 */
	private static function is_staging_site() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return str_ends_with( $host, '.newspackstaging.com' );
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
		// Keep never-fixable failures out of the failure log: pattern alerts
		// exist to surface conditions someone can fix, and benign / permanent
		// classes would otherwise keep tripping the hourly same_integration /
		// same_message rules for exactly the noise the retry classification
		// de-noises (permanent_config failures fire their own immediate alert
		// instead). Payloads without a classification — e.g. data-event handler
		// failures — are always recorded.
		if ( 'transient' !== ( $payload['error_class'] ?? 'transient' ) ) {
			return;
		}
		// A broken integration's failures share its outage's cause, which has
		// already paged once; logged here, they would page it again every hour.
		if ( self::is_integration_broken( $payload['integration_id'] ?? '' ) ) {
			return;
		}

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
	 * While the integration's health record is broken, the alert reaches the
	 * log only: the outage has already paged once, and every contact whose
	 * retries run out during it would page again for the same cause.
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
				'severity'  => self::is_integration_broken( $payload['integration_id'] ?? '' ) ? 'warning' : 'error',
				'message'   => $message,
				'context'   => $payload,
				'timestamp' => time(),
			]
		);
	}

	/**
	 * Handle a permanent (non-retryable) contact-sync failure.
	 *
	 * Both classes forward at 'warning' severity, which reaches the log but
	 * not Slack, apart from the fallback described for `permanent_config`:
	 *
	 * - `permanent_config` (disabled or unpaid ESP account) is site-level.
	 *   The hourly health check observes the same account state within the
	 *   hour and owns the escalation through handle_health_check_failed(),
	 *   so a per-contact repeat here would only duplicate it. Where the check
	 *   isn't running, because it is switched off or its cron has stalled,
	 *   this path is the only one that sees the account state, so it pages
	 *   instead, at most once an hour per integration.
	 * - `permanent_contact` (fired by the deletion path only, where a skipped
	 *   retry has no natural re-trigger and the dropped deletion signal is
	 *   GDPR-relevant) concerns one contact each and stays observable.
	 *
	 * Contact_Sync skips permanent contact-data failures silently on the
	 * regular sync path (the contact re-syncs on the reader's next event), so
	 * those never reach here.
	 *
	 * @param array $payload Alert data from Contact_Sync.
	 */
	public static function handle_sync_permanent_failure( $payload ) {
		$integration_id = $payload['integration_id'] ?? 'unknown';
		$is_config      = 'permanent_contact' !== ( $payload['error_class'] ?? 'permanent_config' );

		$message = $is_config
			? sprintf(
				'Permanent config sync failure for integration "%s" (no retry). Last error: %s',
				$integration_id,
				$payload['reason'] ?? 'unknown'
			)
			: sprintf(
				'Permanent contact-data failure for integration "%s" account-deletion sync; the deletion signal was not propagated (no retry). Last error: %s',
				$integration_id,
				$payload['reason'] ?? 'unknown'
			);

		/** This action is documented in includes/class-alert-manager.php */
		do_action(
			'newspack_alert',
			[
				'type'      => 'sync_permanent_failure',
				'severity'  => $is_config && self::claim_config_failure_page( $integration_id ) ? 'error' : 'warning',
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
	 * Handle an integration health check failure.
	 *
	 * Keeps a per-integration record and pages only on the transition to
	 * broken, the HEALTH_BROKEN_THRESHOLD-th consecutive failure. Every other
	 * failure is forwarded at warning severity so the log keeps the hourly
	 * history; those after the transition add nothing to Slack, since the
	 * condition is already reported and the record carries it until a
	 * passing check, or a run that no longer checks the integration, clears
	 * it.
	 *
	 * The record is written before dispatch so a `newspack_alert` handler
	 * that throws cannot leave the transition unrecorded and page again on
	 * the next hourly cron.
	 *
	 * @param array $payload Health check failure data.
	 */
	public static function handle_health_check_failed( $payload ) {
		$integration_id   = (string) ( $payload['integration_id'] ?? 'unknown' );
		$integration_name = (string) ( $payload['integration_name'] ?? 'unknown' );
		$error            = $payload['error'] ?? null;
		$message          = is_wp_error( $error ) ? implode( '; ', $error->get_error_messages() ) : 'unknown error';

		$state = get_option( self::HEALTH_STATE_OPTION, [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		$now    = time();
		$record = is_array( $state[ $integration_id ] ?? null ) ? $state[ $integration_id ] : null;
		// A failing streak interrupted by a long gap starts over. A broken
		// record is kept: its outage is already reported, and no passing check
		// observed it end.
		if ( null !== $record && 'broken' !== ( $record['status'] ?? '' ) && $now - (int) ( $record['last_failed_at'] ?? 0 ) > self::HEALTH_STREAK_MAX_GAP ) {
			$record = null;
		}
		$record = $record ?? [
			'status'          => 'failing',
			'failures'        => 0,
			'first_failed_at' => $now,
		];

		$record['failures']         = (int) ( $record['failures'] ?? 0 ) + 1;
		$record['last_error']       = $message;
		$record['last_failed_at']   = $now;
		$record['integration_name'] = $integration_name;

		$is_transition = 'broken' !== ( $record['status'] ?? 'failing' ) && $record['failures'] >= self::HEALTH_BROKEN_THRESHOLD;
		if ( $is_transition ) {
			$record['status']      = 'broken';
			$record['error_class'] = self::classify_health_error( $error );
		}

		$state[ $integration_id ] = $record;
		update_option( self::HEALTH_STATE_OPTION, $state, false );

		$context = array_merge( $payload, [ 'health' => $record ] );

		if ( ! $is_transition ) {
			/** This action is documented in includes/class-alert-manager.php */
			do_action(
				'newspack_alert',
				[
					'type'      => 'integration_health_check_failed',
					'severity'  => 'warning',
					'message'   => sprintf( 'Integration "%s" health check failed: %s', $integration_name, $message ),
					'context'   => $context,
					'timestamp' => time(),
				]
			);
			return;
		}

		self::fire_health_changed(
			[
				'integration_id'   => $integration_id,
				'integration_name' => $integration_name,
				'state'            => 'broken',
				'error_class'      => $record['error_class'],
				'error'            => $message,
				'first_failed_at'  => (int) $record['first_failed_at'],
				'failures'         => $record['failures'],
			]
		);

		$alert_message = sprintf(
			'Integration "%s" has failed %d consecutive health checks since %s. Last error: %s',
			$integration_name,
			$record['failures'],
			gmdate( 'Y-m-d H:i', (int) $record['first_failed_at'] ) . ' UTC',
			$message
		);
		if ( 'publisher' === $record['error_class'] ) {
			$alert_message .= ' The ESP account itself is the problem, so the fix is on the publisher side.';
		}

		/** This action is documented in includes/class-alert-manager.php */
		do_action(
			'newspack_alert',
			[
				'type'      => 'integration_health_check_failed',
				'severity'  => 'error',
				'message'   => $alert_message,
				'context'   => $context,
				'timestamp' => time(),
			]
		);
	}

	/**
	 * Handle an integration health check pass.
	 *
	 * A pass after 'broken' is the other half of the transition: it drops the
	 * record, then fires `newspack_integration_health_changed` with
	 * 'recovered' and a warning-severity alert for the log. The record goes
	 * before dispatch, as in handle_health_check_failed(), so a handler that
	 * throws cannot leave a recovered integration recorded as broken. A pass
	 * while merely 'failing' drops the record silently, since nothing was
	 * reported. A pass with no record is the hourly steady state and costs
	 * no option write.
	 *
	 * @param array $payload Health check pass data (integration_id, integration_name).
	 */
	public static function handle_health_check_passed( $payload ) {
		$integration_id = (string) ( $payload['integration_id'] ?? 'unknown' );
		$state          = get_option( self::HEALTH_STATE_OPTION, [] );
		if ( ! is_array( $state ) || ! isset( $state[ $integration_id ] ) ) {
			return;
		}

		$record = $state[ $integration_id ];
		unset( $state[ $integration_id ] );
		if ( empty( $state ) ) {
			delete_option( self::HEALTH_STATE_OPTION );
		} else {
			update_option( self::HEALTH_STATE_OPTION, $state, false );
		}

		if ( 'broken' !== ( $record['status'] ?? '' ) ) {
			return;
		}

		$integration_name = (string) ( $payload['integration_name'] ?? 'unknown' );
		$failures         = (int) ( $record['failures'] ?? 0 );

		self::fire_health_changed(
			[
				'integration_id'   => $integration_id,
				'integration_name' => $integration_name,
				'state'            => 'recovered',
				'error_class'      => (string) ( $record['error_class'] ?? 'other' ),
				'error'            => (string) ( $record['last_error'] ?? '' ),
				'first_failed_at'  => (int) ( $record['first_failed_at'] ?? 0 ),
				'failures'         => $failures,
			]
		);

		/** This action is documented in includes/class-alert-manager.php */
		do_action(
			'newspack_alert',
			[
				'type'      => 'integration_health_check_recovered',
				'severity'  => 'warning',
				'message'   => sprintf( 'Integration "%s" health check is passing again after %d failed checks.', $integration_name, $failures ),
				'context'   => array_merge( $payload, [ 'health' => $record ] ),
				'timestamp' => time(),
			]
		);
	}

	/**
	 * Drop the records of integrations a health-check run no longer checks.
	 *
	 * A record changes only while its integration is checked, so one that is
	 * disabled or no longer set up would keep its record, and a stale
	 * `broken` one would swallow the page for the integration's next outage.
	 * A broken record closes as 'disconnected' rather than 'recovered', since
	 * no passing check observed a fix.
	 *
	 * @param string[] $checked_ids IDs of the integrations the run checked.
	 */
	public static function prune_health_state( $checked_ids ) {
		$state = get_option( self::HEALTH_STATE_OPTION, [] );
		if ( ! is_array( $state ) || empty( $state ) ) {
			return;
		}
		$stale = array_diff_key( $state, array_flip( array_map( 'strval', (array) $checked_ids ) ) );
		if ( empty( $stale ) ) {
			return;
		}

		$state = array_diff_key( $state, $stale );
		if ( empty( $state ) ) {
			delete_option( self::HEALTH_STATE_OPTION );
		} else {
			update_option( self::HEALTH_STATE_OPTION, $state, false );
		}

		foreach ( $stale as $integration_id => $record ) {
			if ( 'broken' !== ( $record['status'] ?? '' ) ) {
				continue;
			}
			$integration_name = (string) ( $record['integration_name'] ?? $integration_id );
			$failures         = (int) ( $record['failures'] ?? 0 );

			self::fire_health_changed(
				[
					'integration_id'   => (string) $integration_id,
					'integration_name' => $integration_name,
					'state'            => 'disconnected',
					'error_class'      => (string) ( $record['error_class'] ?? 'other' ),
					'error'            => (string) ( $record['last_error'] ?? '' ),
					'first_failed_at'  => (int) ( $record['first_failed_at'] ?? 0 ),
					'failures'         => $failures,
				]
			);

			/** This action is documented in includes/class-alert-manager.php */
			do_action(
				'newspack_alert',
				[
					'type'      => 'integration_health_check_disconnected',
					'severity'  => 'warning',
					'message'   => sprintf( 'Integration "%s" is no longer checked, so its outage is closed after %d failed checks.', $integration_name, $failures ),
					'context'   => [
						'integration_id' => (string) $integration_id,
						'health'         => $record,
					],
					'timestamp' => time(),
				]
			);
		}
	}

	/**
	 * Fire `newspack_integration_health_changed` so that a listener that
	 * throws cannot cancel the alert that follows it.
	 *
	 * The record already holds the new state when this runs, so an exception
	 * reaching the caller would drop the one page or log entry that state
	 * gets, and no later check would send it again.
	 *
	 * @param array $payload The state change, as documented on the action below.
	 */
	private static function fire_health_changed( $payload ) {
		try {
			/**
			 * Fires when an integration's health changes state: it has failed
			 * HEALTH_BROKEN_THRESHOLD consecutive checks ('broken'), a check
			 * passed after that ('recovered'), or a run stopped checking it while
			 * broken because it was disabled or is no longer set up
			 * ('disconnected'). One event per outage in each direction, so a
			 * consumer can open and close a ticket without deduplicating hourly
			 * repeats itself.
			 *
			 * @param array $payload {
			 *     @type string $integration_id   The integration ID.
			 *     @type string $integration_name The integration display name.
			 *     @type string $state            'broken', 'recovered' or 'disconnected'.
			 *     @type string $error_class      'publisher' when the ESP account itself is the
			 *                                    problem, 'other' for provider outages and unknowns.
			 *     @type string $error            The last health-check error message.
			 *     @type int    $first_failed_at  Unix timestamp of the first failure in this outage.
			 *     @type int    $failures         Consecutive failed checks so far.
			 * }
			 */
			do_action( 'newspack_integration_health_changed', $payload );
		} catch ( \Throwable $e ) {
			/** This action is documented in includes/class-alert-manager.php */
			do_action(
				'newspack_alert',
				[
					'type'      => 'integration_health_changed_listener_failed',
					'severity'  => 'warning',
					'message'   => sprintf( 'A newspack_integration_health_changed listener failed for integration "%s": %s', $payload['integration_id'] ?? 'unknown', $e->getMessage() ),
					'context'   => $payload,
					'timestamp' => time(),
				]
			);
		}
	}

	/**
	 * Whether an integration's health record is broken, meaning its outage
	 * has already paged once.
	 *
	 * @param string $integration_id The integration ID.
	 * @return bool
	 */
	private static function is_integration_broken( $integration_id ) {
		$state = get_option( self::HEALTH_STATE_OPTION, [] );
		return is_array( $state ) && 'broken' === ( $state[ (string) $integration_id ]['status'] ?? '' );
	}

	/**
	 * Whether a permanent config failure pages from the sync path, taking the
	 * integration's hourly slot when it does.
	 *
	 * @param string $integration_id The integration ID.
	 * @return bool
	 */
	private static function claim_config_failure_page( $integration_id ) {
		if ( self::is_health_check_running() ) {
			return false;
		}
		// Set before dispatch, so a `newspack_alert` handler that throws cannot
		// leave the slot free for the next failed contact.
		$dedup_key = 'newspack_alert_pf_' . md5( (string) $integration_id );
		if ( get_transient( $dedup_key ) ) {
			return false;
		}
		set_transient( $dedup_key, time(), HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Whether the hourly health check is running: scheduled, and not overdue
	 * by more than HEALTH_STREAK_MAX_GAP. A cron that has stopped firing
	 * leaves the event scheduled, with its next run falling further into the
	 * past.
	 *
	 * @return bool
	 */
	private static function is_health_check_running() {
		$next_run = wp_next_scheduled( Reader_Activation\Integrations::HEALTH_CHECK_CRON_HOOK );
		return false !== $next_run && $next_run >= time() - self::HEALTH_STREAK_MAX_GAP;
	}

	/**
	 * Classify a health-check failure as publisher-side or other.
	 *
	 * @param \WP_Error|mixed $error The health-check error.
	 * @return string 'publisher' or 'other'.
	 */
	private static function classify_health_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return 'other';
		}
		$haystack = strtolower( implode( ' ', $error->get_error_messages() ) );
		foreach ( self::PUBLISHER_ERROR_SIGNATURES as $signature ) {
			if ( str_contains( $haystack, $signature ) ) {
				return 'publisher';
			}
		}
		// A bare auth or payment status with no recognisable text, in the two
		// forms providers print one: "401: …" opening a message, or
		// "status 401". A number anywhere else is not a status, such as the
		// connect duration in cURL's "Failed to connect … after 402 ms".
		foreach ( $error->get_error_messages() as $error_message ) {
			if ( preg_match( '/^40[123]:|\bstatus 40[123]\b/i', trim( (string) $error_message ) ) ) {
				return 'publisher';
			}
		}
		// The Newsletters ActiveCampaign provider keeps the status as the error
		// code when the response has no error body, leaving only the reason
		// phrase as the message: "Forbidden" with code 403.
		foreach ( $error->get_error_codes() as $code ) {
			if ( is_numeric( $code ) && in_array( (int) $code, [ 401, 402, 403 ], true ) ) {
				return 'publisher';
			}
		}
		return 'other';
	}
}
Alert_Manager::init();
