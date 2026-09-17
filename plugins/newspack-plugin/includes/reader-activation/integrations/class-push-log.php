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
		if ( $found === $table_name ) {
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
			$data['error_message'] = implode( '; ', $args['result']->get_error_messages() );
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
		 * @param string[] $fields Metadata keys, without the integration's prefix.
		 */
		$volatile_fields = (array) apply_filters( 'newspack_integrations_push_log_volatile_fields', [ 'Last_Active' ] );

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
}
