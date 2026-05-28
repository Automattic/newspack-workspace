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
	const DB_VERSION = 2;

	/**
	 * Returns the table name
	 *
	 * @return string
	 */
	public static function get_table_name() {
		self::maybe_update_db();
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
	 * @return void
	 */
	protected static function maybe_update_db() {
		$db_version = absint( get_option( self::get_current_option_name(), 0 ) );
		update_option( self::get_current_option_name(), self::DB_VERSION );
		if ( $db_version < self::DB_VERSION ) {
			self::update_db();
		}
	}

	/**
	 * Updates the database.
	 *
	 * This method uses dbDelta to create or update the database table.
	 *
	 * @return void
	 */
	protected static function update_db() {
		Debugger::log( 'Creating or updating the database table' );
		global $wpdb;
		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE $table_name (
			id int(11) NOT NULL AUTO_INCREMENT,
			action_name varchar(100) NOT NULL,
			node_id int(11) NOT NULL,
			email varchar(100) NULL,
			data longtext NOT NULL,
			timestamp int(11) NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		dbDelta( $sql );
	}
}
