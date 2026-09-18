<?php
/**
 * Integrations push log.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

use Newspack\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Push Log Class.
 *
 * The publisher-facing record of what was sent to each integration. A row is
 * one reader, one integration and one triggering push: retries update the row
 * in place so a retry chain reads as one entry, and a successful push
 * identical to the previous one bumps a counter instead of adding a row, so
 * routine traffic does not grow the table.
 *
 * Writing here must never break a sync. Every write is checked, and a
 * database failure is reported once per request instead of reaching the
 * caller.
 */
final class Push_Log {
	const TABLE_NAME           = 'newspack_integrations_push_log';
	const TABLE_VERSION        = '1.0';
	const TABLE_VERSION_OPTION = '_newspack_integrations_push_log_version';
	const CLEANUP_HOOK         = 'newspack_integrations_push_log_cleanup';
	const CLEANUP_SCHEDULE     = 'newspack_integrations_push_log_cleanup_interval';

	const STATUS_SUCCESS  = 'success';
	const STATUS_RETRYING = 'retrying';
	const STATUS_FAILED   = 'failed';

	const OPERATION_UPSERT = 'upsert';
	const OPERATION_FLAG   = 'flag';
	const OPERATION_DELETE = 'delete';

	/**
	 * Whether a write failure was already reported during this request.
	 *
	 * @var bool
	 */
	private static $write_failure_reported = false;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_create_table' ] );
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cleanup_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'init', [ __CLASS__, 'schedule_cleanup' ] );
		// Zero accepted args: a bare do_action() passes an empty string, which
		// must not land in cleanup()'s batch size.
		add_action( self::CLEANUP_HOOK, [ __CLASS__, 'cleanup' ], 10, 0 );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
	}

	/**
	 * Get the table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or update the table when the stored schema version differs.
	 *
	 * The version is recorded only once the table exists, so a failed creation
	 * is retried on the next request instead of leaving every write to fail.
	 */
	public static function maybe_create_table() {
		if ( self::TABLE_VERSION === get_option( self::TABLE_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// The dbDelta parser needs two spaces after PRIMARY KEY and no spaces
		// inside index column lists, or it re-adds the indexes on every run.
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			integration_id varchar(64) NOT NULL,
			email varchar(191) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			operation varchar(20) NOT NULL DEFAULT 'upsert',
			context varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 1,
			max_attempts tinyint(3) unsigned NOT NULL DEFAULT 1,
			repeat_count int(10) unsigned NOT NULL DEFAULT 0,
			error_class varchar(32) DEFAULT NULL,
			error_code varchar(100) DEFAULT NULL,
			error_message text DEFAULT NULL,
			payload longtext DEFAULT NULL,
			payload_hash char(32) DEFAULT NULL,
			retry_action_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email,integration_id),
			KEY user_id (user_id),
			KEY integration_updated (integration_id,updated_at),
			KEY integration_status (integration_id,status,updated_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Some hosts store table names lowercased, so an exact match would never
		// record the version and dbDelta would run on every request.
		if ( strtolower( (string) $found ) === strtolower( $table_name ) ) {
			update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
		}
	}

	/**
	 * Record one push attempt.
	 *
	 * @param array $args {
	 *     The attempt.
	 *
	 *     @type int            $log_id         The row this attempt belongs to, when it is a retry.
	 *     @type string         $integration_id The integration pushed to. Required.
	 *     @type string         $email          The contact's email at push time. Required.
	 *     @type int            $user_id        The WP user, 0 when none resolves.
	 *     @type string         $operation      One of the OPERATION_* constants.
	 *     @type string         $context        The sync context.
	 *     @type array|null     $payload        The contact as handed to the integration; null for hard deletes.
	 *     @type string         $hash_prefix    The integration's metadata prefix, to recognize volatile fields.
	 *     @type true|\WP_Error $result         The push result.
	 *     @type string|null    $error_class    The caller's classification of a WP_Error result:
	 *                                          'benign', 'transient', 'permanent_contact' or
	 *                                          'permanent_config'.
	 *     @type int            $attempts       Pushes made so far in this sync, this one included.
	 *     @type int            $max_attempts   The most this sync can make.
	 * }
	 *
	 * @return int The row ID, or 0 when nothing was written.
	 */
	public static function record_attempt( array $args ): int {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'log_id'         => 0,
				'integration_id' => '',
				'email'          => '',
				'user_id'        => 0,
				'operation'      => self::OPERATION_UPSERT,
				'context'        => '',
				'payload'        => null,
				'hash_prefix'    => '',
				'result'         => true,
				'error_class'    => null,
				'attempts'       => 1,
				'max_attempts'   => 1,
			]
		);

		// Truncate rather than let the database reject the row over a length.
		$integration_id = mb_substr( (string) $args['integration_id'], 0, 64 );
		$email          = mb_substr( (string) $args['email'], 0, 191 );
		if ( '' === $integration_id || '' === $email ) {
			return 0;
		}

		$failed      = is_wp_error( $args['result'] );
		$error_class = null;
		if ( $failed ) {
			$error_class = '' !== (string) $args['error_class'] ? (string) $args['error_class'] : 'transient';
		}
		$payload = is_array( $args['payload'] ) ? $args['payload'] : null;
		$now     = current_time( 'mysql', true );

		// A benign error means the provider already holds the contact (or, on
		// the deletion path, no longer does): the sync reached its end state.
		$status = ( $failed && 'benign' !== $error_class ) ? self::STATUS_FAILED : self::STATUS_SUCCESS;

		$data = [
			'status'          => $status,
			'attempts'        => max( 1, (int) $args['attempts'] ),
			'max_attempts'    => max( 1, (int) $args['max_attempts'] ),
			'payload'         => null === $payload ? null : wp_json_encode( $payload ),
			'payload_hash'    => null === $payload ? null : self::hash_payload( $payload, (string) $args['hash_prefix'] ),
			'retry_action_id' => null,
			'updated_at'      => $now,
		];
		// Error fields are only ever written, never cleared, so a row that
		// succeeds on a later attempt still says what the earlier ones hit.
		if ( $failed ) {
			$data['error_class']   = $error_class;
			$data['error_code']    = mb_substr( (string) $args['result']->get_error_code(), 0, 100 );
			// An oversized provider message would make the database reject the
			// whole row, losing the record of the failure it describes.
			$data['error_message'] = mb_substr( implode( '; ', $args['result']->get_error_messages() ), 0, 10000 );
		}

		$log_id = (int) $args['log_id'];
		if ( $log_id > 0 ) {
			$updated = $wpdb->update( self::get_table_name(), $data, [ 'id' => $log_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $updated ) {
				self::report_write_failure();
				return 0;
			}
			// The database reports rows changed, not rows matched, so 0 also
			// means the row already held these values. Only a row that is
			// really gone (pruned mid-chain) gets recorded as a new one.
			if ( $updated > 0 || self::row_exists( $log_id ) ) {
				return $log_id;
			}
		}

		$is_clean_first_upsert = ! $failed
			&& 1 === $data['attempts']
			&& self::OPERATION_UPSERT === $args['operation']
			&& null !== $data['payload_hash'];
		if ( $is_clean_first_upsert ) {
			$collapsed_into = self::collapse_into_latest( $integration_id, $email, $data );
			if ( $collapsed_into > 0 ) {
				return $collapsed_into;
			}
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array_merge(
				[
					'integration_id' => $integration_id,
					'email'          => $email,
					'user_id'        => (int) $args['user_id'],
					'operation'      => (string) $args['operation'],
					'context'        => mb_substr( (string) $args['context'], 0, 255 ),
					'created_at'     => $now,
				],
				$data
			)
		);
		if ( false === $inserted ) {
			self::report_write_failure();
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Whether a row exists.
	 *
	 * @param int $log_id The row ID.
	 *
	 * @return bool
	 */
	private static function row_exists( int $log_id ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', self::get_table_name(), $log_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $found;
	}

	/**
	 * Mark a row as waiting for a scheduled retry.
	 *
	 * An error row is written as failed and only becomes retrying here, and
	 * only for a retry that was really scheduled, so a code path that
	 * schedules nothing can never leave a row stuck retrying.
	 *
	 * @param int $log_id    The row ID. 0 is ignored.
	 * @param int $action_id The pending ActionScheduler action. 0 means nothing
	 *                       was scheduled, and is ignored.
	 */
	public static function mark_retrying( int $log_id, int $action_id ): void {
		if ( $log_id <= 0 || $action_id <= 0 ) {
			return;
		}
		global $wpdb;
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_table_name(),
			[
				'status'          => self::STATUS_RETRYING,
				'retry_action_id' => $action_id,
			],
			[ 'id' => $log_id ]
		);
		if ( false === $updated ) {
			self::report_write_failure();
		}
	}

	/**
	 * End a row as failed for a reason other than a push result: a retry that
	 * gave up before pushing, or a follow-up step that failed.
	 *
	 * The reason comes first and the last push error is kept after it, so the
	 * row still says what the sync was hitting.
	 *
	 * @param int    $log_id     The row ID. 0 is ignored.
	 * @param string $error_code A code naming the reason.
	 * @param string $reason     A sentence a publisher can read.
	 */
	public static function mark_failed( int $log_id, string $error_code, string $reason ): void {
		if ( $log_id <= 0 ) {
			return;
		}
		global $wpdb;
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE %i SET status = %s, retry_action_id = NULL, error_code = %s, error_message = CONCAT( %s, IF( error_message IS NULL OR error_message = '', '', CONCAT( ' Last error: ', error_message ) ) ), updated_at = %s WHERE id = %d",
				self::get_table_name(),
				self::STATUS_FAILED,
				mb_substr( $error_code, 0, 100 ),
				$reason,
				current_time( 'mysql', true ),
				$log_id
			)
		);
		if ( false === $updated ) {
			self::report_write_failure();
		}
	}

	/**
	 * Fingerprint a payload, to tell whether two pushes sent the same data.
	 *
	 * Key order is not data, and neither are fields that change on every
	 * visit: counting those as a change would add a row for every push an
	 * active reader triggers.
	 *
	 * @param array  $payload The contact as handed to the integration.
	 * @param string $prefix  The integration's metadata prefix.
	 *
	 * @return string
	 */
	private static function hash_payload( array $payload, string $prefix ): string {
		/**
		 * Filters the metadata fields the push log ignores when deciding
		 * whether a push sent the same data as the previous one.
		 *
		 * Names are the field names as sent to the integration, without the
		 * integration's prefix — so "Last Active", not the raw "Last_Active"
		 * metadata key the contact arrives with.
		 *
		 * @param string[] $fields Field names as sent, without the integration's prefix.
		 */
		$volatile_fields = (array) apply_filters( 'newspack_integrations_push_log_volatile_fields', [ 'Last Active' ] );

		$metadata = isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ? $payload['metadata'] : [];
		foreach ( array_keys( $metadata ) as $key ) {
			$bare_key = ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) ? substr( $key, strlen( $prefix ) ) : $key;
			if ( in_array( $bare_key, $volatile_fields, true ) ) {
				unset( $metadata[ $key ] );
			}
		}
		ksort( $metadata );
		$payload['metadata'] = $metadata;
		ksort( $payload );

		return md5( wp_json_encode( $payload ) );
	}

	/**
	 * Fold a clean successful upsert into the reader's latest row when that
	 * row already stands for the same data at the provider.
	 *
	 * The payload is refreshed so volatile values stay current, and the row's
	 * context is kept: it names what caused this data state.
	 *
	 * @param string $integration_id The integration pushed to.
	 * @param string $email          The contact's email, already truncated.
	 * @param array  $data           The row data built by record_attempt().
	 *
	 * @return int The row collapsed into, or 0 when a new row is needed.
	 */
	private static function collapse_into_latest( string $integration_id, string $email, array $data ): int {
		global $wpdb;
		$table_name = self::get_table_name();

		$latest = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT id, status, error_class, operation, payload_hash FROM %i WHERE email = %s AND integration_id = %s ORDER BY id DESC LIMIT 1',
				$table_name,
				$email,
				$integration_id
			),
			ARRAY_A
		);
		if (
			! $latest
			|| self::STATUS_SUCCESS !== $latest['status']
			|| null !== $latest['error_class']
			|| self::OPERATION_UPSERT !== $latest['operation']
			|| $data['payload_hash'] !== $latest['payload_hash']
		) {
			return 0;
		}

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE %i SET repeat_count = repeat_count + 1, payload = %s, updated_at = %s WHERE id = %d',
				$table_name,
				$data['payload'],
				$data['updated_at'],
				(int) $latest['id']
			)
		);
		if ( false === $updated ) {
			self::report_write_failure();
			return 0;
		}
		// repeat_count + 1 always changes the row, so nothing changed means the
		// row was pruned between the SELECT and this UPDATE. Let the caller
		// insert rather than return an id that no longer exists.
		if ( 0 === (int) $updated ) {
			return 0;
		}

		return (int) $latest['id'];
	}

	/**
	 * Report a failed write, once per request.
	 *
	 * A broken table fails every push of a batch the same way; one entry is
	 * enough to notice it, and one per push would flood the remote log.
	 */
	private static function report_write_failure() {
		if ( self::$write_failure_reported ) {
			return;
		}
		self::$write_failure_reported = true;

		global $wpdb;
		Logger::newspack_log(
			'newspack_integrations_push_log_write_failed',
			'Could not write to the integrations push log.',
			[ 'db_error' => $wpdb->last_error ],
			'error'
		);
	}

	/**
	 * Register the cleanup's schedule: every six hours.
	 *
	 * WordPress has no schedule between hourly and twice daily, and an event
	 * scheduled on an unregistered schedule is silently not scheduled at all.
	 *
	 * @param array $schedules Registered cron schedules.
	 * @return array
	 */
	public static function add_cleanup_schedule( $schedules ) {
		$schedules[ self::CLEANUP_SCHEDULE ] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Newspack integrations push log cleanup interval', 'newspack-plugin' ),
		];
		return $schedules;
	}

	/**
	 * Schedule the cleanup four times a day, unless the site disabled it.
	 *
	 * More than once a day because the cap is per run: a large site, or a CLI
	 * backfill that expires a day's rows at once, needs several runs a day to
	 * keep up.
	 */
	public static function schedule_cleanup() {
		register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'unschedule_cleanup' ] );

		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CLEANUP_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			self::unschedule_cleanup();
		} elseif ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), self::CLEANUP_SCHEDULE, self::CLEANUP_HOOK );
		}
	}

	/**
	 * Unschedule the cleanup.
	 */
	public static function unschedule_cleanup() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * How many days rows are kept, by outcome.
	 *
	 * @return array{success:int,failed:int}
	 */
	public static function get_retention_days(): array {
		$defaults = [
			'success' => 30,
			'failed'  => 90,
		];

		/**
		 * Filters how many days push log rows are kept.
		 *
		 * @param array $retention_days {
		 *     Days since a row's last update.
		 *
		 *     @type int $success Rows that ended in success. Default 30.
		 *     @type int $failed  Rows that failed or are still retrying. Default 90.
		 * }
		 */
		$retention_days = wp_parse_args( (array) apply_filters( 'newspack_integrations_push_log_retention_days', $defaults ), $defaults );

		return [
			'success' => max( 1, (int) $retention_days['success'] ),
			'failed'  => max( 1, (int) $retention_days['failed'] ),
		];
	}

	/**
	 * Delete rows past their retention window.
	 *
	 * Deletes run one integration and status at a time, the shape the
	 * integration_status index is there to serve, in bounded batches so a
	 * backlog never turns into one long delete. Only a batch that deletes
	 * rows counts against the cap, which is per run.
	 *
	 * @param int $batch_size  Rows per delete.
	 * @param int $max_batches Deleting batches per run.
	 */
	public static function cleanup( $batch_size = 1000, $max_batches = 20 ) {
		global $wpdb;
		// A batch size of 0 deletes nothing while still looking like a full
		// batch, which would loop forever.
		$batch_size     = max( 1, (int) $batch_size );
		$max_batches    = max( 1, (int) $max_batches );
		$table_name     = self::get_table_name();
		$retention_days = self::get_retention_days();
		$windows        = [
			self::STATUS_SUCCESS  => $retention_days['success'],
			self::STATUS_FAILED   => $retention_days['failed'],
			// A retry chain lasts under three hours, so an old retrying row is stuck.
			self::STATUS_RETRYING => $retention_days['failed'],
		];

		$integration_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT integration_id FROM %i', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$batches         = 0;

		foreach ( $integration_ids as $integration_id ) {
			foreach ( $windows as $status => $days ) {
				$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
				do {
					if ( $batches >= $max_batches ) {
						Logger::newspack_log(
							'newspack_integrations_push_log_cleanup_capped',
							'The integrations push log cleanup stopped at its batch cap; remaining expired rows are left for the next run.',
							[
								'max_batches' => $max_batches,
								'batch_size'  => $batch_size,
							],
							'debug'
						);
						return;
					}
					$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->prepare(
							'DELETE FROM %i WHERE integration_id = %s AND status = %s AND updated_at < %s LIMIT %d',
							$table_name,
							$integration_id,
							$status,
							$cutoff,
							(int) $batch_size
						)
					);
					if ( false === $deleted ) {
						self::report_write_failure();
						return;
					}
					// The cap bounds deleting, not looking: a probe that finds nothing
					// to prune is an index lookup and does not count.
					if ( $deleted > 0 ) {
						++$batches;
					}
				} while ( $deleted >= $batch_size );
			}
		}
	}

	/**
	 * Register the push log's personal-data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['newspack-integrations-push-log'] = [
			'eraser_friendly_name' => __( 'Newspack integrations push log', 'newspack-plugin' ),
			'callback'             => [ __CLASS__, 'erase_personal_data' ],
		];
		return $erasers;
	}

	/**
	 * Erase a reader's rows.
	 *
	 * Rows hold the email and the field values that were pushed. Matching on
	 * the account as well reaches rows written under an earlier address. A
	 * reader has few rows, so one delete covers them and there is one page.
	 *
	 * @param string $email_address The reader's email.
	 * @param int    $page          The eraser page. Unused.
	 *
	 * @return array The eraser response.
	 */
	public static function erase_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		$table_name = self::get_table_name();
		$reader     = get_user_by( 'email', $email_address );

		if ( $reader ) {
			$removed = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s OR user_id = %d', $table_name, $email_address, $reader->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$removed = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s', $table_name, $email_address ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		// A delete that failed leaves the rows in place. Reporting the erasure
		// as done would tell the admin the data is gone while it is still here.
		if ( false === $removed ) {
			self::report_write_failure();
			return [
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => [ __( 'The integrations push log could not be erased. Please try again.', 'newspack-plugin' ) ],
				'done'           => true,
			];
		}

		return [
			'items_removed'  => (int) $removed > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}
}
