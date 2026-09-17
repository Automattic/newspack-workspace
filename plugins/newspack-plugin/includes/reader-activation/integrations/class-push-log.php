<?php
/**
 * Integrations push log.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Push Log Class.
 *
 * The publisher-facing record of what was sent to each integration. A row is
 * one reader, one integration and one triggering push: retries update the row
 * in place so a retry chain reads as one entry, and a successful push
 * identical to the previous one bumps a counter instead of adding a row, so
 * routine traffic does not grow the table.
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
}
