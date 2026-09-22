<?php
/**
 * Newspack Hub Event Log database
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Database;

use Newspack_Network\Debugger;

/**
 * Class to handle the plugin admin pages
 */
class Event_Log {

	/**
	 * The database version
	 *
	 * @var int
	 */
	const DB_VERSION = 3;

	/**
	 * Cron hook that upgrades an existing table outside of web requests.
	 *
	 * @var string
	 */
	const UPGRADE_HOOK = 'newspack_network_event_log_upgrade_db';

	/**
	 * Transient held while an upgrade runs. A failed upgrade leaves it in place,
	 * so the next attempt waits for it to expire.
	 *
	 * @var string
	 */
	const UPGRADE_LOCK = 'newspack_network_event_log_upgrade_db_lock';

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		add_action( self::UPGRADE_HOOK, [ __CLASS__, 'run_scheduled_upgrade' ] );
	}

	/**
	 * Returns the table name
	 *
	 * @return string
	 */
	public static function get_table_name() {
		self::maybe_update_db();
		return self::get_raw_table_name();
	}

	/**
	 * Returns the table name without checking the schema.
	 *
	 * @return string
	 */
	protected static function get_raw_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'newspack_hub_event_log';
	}

	/**
	 * Returns the current option name
	 *
	 * @return string
	 */
	protected static function get_current_option_name() {
		return 'newspack_db_version_event_log';
	}

	/**
	 * Updates the database if needed
	 *
	 * A missing table is created right away. An existing table is upgraded from
	 * cron instead: adding an index reads the whole table, which on a large log
	 * takes minutes, and this method runs inside whatever request first touches
	 * the log—a checkout, for one.
	 *
	 * @return void
	 */
	protected static function maybe_update_db() {
		$db_version = absint( get_option( self::get_current_option_name(), 0 ) );
		if ( $db_version >= self::DB_VERSION ) {
			return;
		}

		if ( ! self::table_exists() ) {
			self::update_db();
			return;
		}

		if ( ! get_transient( self::UPGRADE_LOCK ) && ! wp_next_scheduled( self::UPGRADE_HOOK ) ) {
			wp_schedule_single_event( time(), self::UPGRADE_HOOK );
		}
	}

	/**
	 * Upgrades an existing table. Runs from cron.
	 *
	 * @return void
	 */
	public static function run_scheduled_upgrade() {
		if ( get_transient( self::UPGRADE_LOCK ) ) {
			return;
		}
		set_transient( self::UPGRADE_LOCK, time(), HOUR_IN_SECONDS );

		self::update_db();

		if ( self::has_timestamp_index() ) {
			delete_transient( self::UPGRADE_LOCK );
		}
	}

	/**
	 * Updates the database.
	 *
	 * This method uses dbDelta to create or update the database table, and
	 * records the new version only once the table has the timestamp index, so
	 * an upgrade that did not finish is retried.
	 *
	 * @return void
	 */
	protected static function update_db() {
		Debugger::log( 'Creating or updating the database table' );
		global $wpdb;
		$table_name      = self::get_raw_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// The timestamp index serves the duplicate check in Stores\Event_Log::persist().
		$sql = "CREATE TABLE $table_name (
			id int(11) NOT NULL AUTO_INCREMENT,
			action_name varchar(100) NOT NULL,
			node_id int(11) NOT NULL,
			email varchar(100) NULL,
			data longtext NOT NULL,
			timestamp int(11) NOT NULL,
			PRIMARY KEY  (id),
			KEY timestamp (timestamp)
		) $charset_collate;";

		dbDelta( $sql );

		if ( self::has_timestamp_index() ) {
			update_option( self::get_current_option_name(), self::DB_VERSION );
		}
	}

	/**
	 * Whether the table exists.
	 *
	 * Probes the table directly rather than listing tables, so it also answers
	 * for the session-local tables the WordPress test suite substitutes.
	 *
	 * @return bool
	 */
	protected static function table_exists() {
		global $wpdb;
		$table_name = self::get_raw_table_name();
		$suppress   = $wpdb->suppress_errors( true );
		$column     = $wpdb->get_var( "SHOW COLUMNS FROM $table_name LIKE 'id'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $suppress );
		return null !== $column;
	}

	/**
	 * Whether the table has an index led by the `timestamp` column.
	 *
	 * @return bool
	 */
	protected static function has_timestamp_index() {
		global $wpdb;
		$table_name = self::get_raw_table_name();
		$suppress   = $wpdb->suppress_errors( true );
		$rows       = $wpdb->get_results( "SHOW INDEX FROM $table_name WHERE Column_name = 'timestamp' AND Seq_in_index = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $suppress );
		return ! empty( $rows );
	}
}
