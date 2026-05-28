<?php
/**
 * Custom table for temporary data required by OAuth flows.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class OAuth_Transients {
	const TABLE_NAME = 'newspack_oauth_transients';
	const TABLE_VERSION = '1.0';
	const TABLE_VERSION_OPTION = '_newspack_oauth_transients_version';
	const CRON_HOOK = 'np_oauth_transients_cleanup';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\register_activation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'create_custom_table' ] );
		\add_action( 'init', [ __CLASS__, 'check_update_version' ] );
		\add_action( 'init', [ __CLASS__, 'cron_init' ] );
		\add_action( self::CRON_HOOK, [ __CLASS__, 'cleanup' ] );
	}

	/**
	 * Schedule cron job to prune unused transients. If the OAuth process is interrupted,
	 * a transient might never be deleted.
	 */
	public static function cron_init() {
		\register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'cron_deactivate' ] );

		/**
		 * Array of cron hook names to disable. Use this to selectively
		 * disable Newspack cron jobs on specific environments.
		 *
		 * @constant NEWSPACK_CRON_DISABLE
		 * @type     array
		 * @default  All cron jobs enabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_CRON_DISABLE', [ 'newspack_oauth_transients_cleanup' ] );
		 */
		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CRON_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			self::cron_deactivate();
		} elseif ( ! \wp_next_scheduled( self::CRON_HOOK ) ) {
				\wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivate the cron job.
	 */
	public static function cron_deactivate() {
		\wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Get custom table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Checks if the custom table has been created and is up-to-date.
	 * If not, run the create_custom_table method.
	 * See: https://codex.wordpress.org/Creating_Tables_with_Plugins
	 */
	public static function check_update_version() {
		$current_version = \get_option( self::TABLE_VERSION_OPTION, false );

		if ( self::TABLE_VERSION !== $current_version ) {
			self::create_custom_table();
			\update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
		}
	}

	/**
	 * Create a custom DB table to store transient data needed for OAuth.
	 * Avoids the use of slow post meta for query sorting purposes.
	 * Only create the table if it doesn't already exist.
	 */
	public static function create_custom_table() {
		global $wpdb;
		$table_name = self::get_table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) != $table_name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$charset_collate = $wpdb->get_charset_collate();
			$sql             = "CREATE TABLE $table_name (
				-- Reader's unique ID.
				id varchar(100) NOT NULL,
				-- Scope of the data.
				scope varchar(30) NOT NULL,
				-- Value of the data.
				value varchar(100) NOT NULL,
				-- Timestamp when data was created.
				created_at datetime NOT NULL,
				PRIMARY KEY (id, scope),
				KEY (scope)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			\dbDelta( $sql );
		}
	}

	/**
	 * Get a value from the database.
	 *
	 * @param string $id The reader's unique ID.
	 * @param string $scope The scope of the data to get.
	 * @param string $field_to_get The column to get. Defaults to 'value'.
	 * @param bool   $delete Whether to delete the row after getting the value.
	 *
	 * @return mixed The value of the data, or false if not found.
	 */
	public static function get( $id, $scope, $field_to_get = 'value', $delete = true ) {
		global $wpdb;
		$table_name = self::get_table_name();

		if ( empty( $id ) ) {
			return false;
		}

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT %1$s FROM %2$s WHERE id = "%3$s" AND scope = "%4$s"', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$field_to_get,
				$table_name,
				$id,
				$scope
			)
		);

		/**
		 * Prevents OAuth transients from being deleted after reading.
		 * Useful for debugging OAuth flows. Do not enable in production.
		 *
		 * @constant NEWSPACK_OAUTH_TRANSIENTS_DEBUG
		 * @type     bool
		 * @default  Transients deleted after reading
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_OAUTH_TRANSIENTS_DEBUG', true );
		 */
		if ( $delete && ! empty( $value ) && ( ! defined( 'NEWSPACK_OAUTH_TRANSIENTS_DEBUG' ) || ! NEWSPACK_OAUTH_TRANSIENTS_DEBUG ) ) {
			self::delete( $id, $scope );
		}

		return $value ?? false;
	}

	/**
	 * Delete a row from the database.
	 *
	 * @param string $id The reader's unique ID.
	 * @param string $scope The scope of the data to get.
	 *
	 * @return boolean True if the row was deleted.
	 */
	public static function delete( $id, $scope ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			[
				'id'    => $id,
				'scope' => $scope,
			],
			[ '%s', '%s' ]
		);

		return boolval( $result );
	}

	/**
	 * Set a value in the database.
	 *
	 * @param string $id The reader's unique ID.
	 * @param string $scope The scope of the data to set.
	 * @param string $value The value of the data to set.
	 *
	 * @return mixed The value if it was set, false otherwise.
	 */
	public static function set( $id, $scope, $value ) {
		if ( empty( $id ) ) {
			return false;
		}

		global $wpdb;

		$existing = self::get( $id, $scope, 'value', false );
		if ( $existing ) {
			return $existing;
		}

		$table_name = self::get_table_name();
		$result     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			[
				'id'         => $id,
				'scope'      => $scope,
				'value'      => $value,
				'created_at' => \current_time( 'mysql', true ), // GMT time.
			],
			[
				'%s',
				'%s',
				'%s',
				'%s',
			]
		);

		if ( $result === false ) {
			return false;
		}

		return $value;
	}

	/**
	 * Cleanup old transients. Limit to max 1000 at a time, just in case.
	 */
	public static function cleanup() {
		global $wpdb;
		$table_name = self::get_table_name();
		$wpdb->query( "DELETE FROM $table_name WHERE created_at < utc_timestamp() - interval 30 MINUTE LIMIT 1000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

OAuth_Transients::init();
