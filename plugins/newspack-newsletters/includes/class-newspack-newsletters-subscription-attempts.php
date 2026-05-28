<?php
/**
 * Custom table for subscription attempts.
 * This will be a backup copy of any attempts to subscribe to newsletter lists,
 * so recovery is possible in case things go wrong.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Newspack_Newsletters_Subscription_Attempts {
	const TABLE_NAME = 'newspack_newsletters_subscription_attempts';
	const TABLE_VERSION = '1.0';
	const TABLE_VERSION_OPTION = '_newspack_newsletters_subscription_attempts_version';
	const CRON_HOOK = 'np_newsletters_subscription_attempts_cleanup';

	/**
	 * Initialize hooks.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		register_activation_hook( NEWSPACK_NEWSLETTERS_PLUGIN_FILE, [ __CLASS__, 'create_custom_table' ] );
		add_action( 'init', [ __CLASS__, 'check_update_version' ] );
		add_action( 'init', [ __CLASS__, 'cron_init' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'cleanup' ] );

		add_action( 'newspack_newsletters_pre_add_contact', [ __CLASS__, 'save_attempt' ], 10, 2 );
		add_action( 'newspack_newsletters_update_contact_lists', [ __CLASS__, 'save_update' ], 10, 5 );
	}

	/**
	 * Periodically delete old subscription attempts.
	 *
	 * @codeCoverageIgnore
	 */
	public static function cron_init() {
		\register_deactivation_hook( NEWSPACK_NEWSLETTERS_PLUGIN_FILE, [ __CLASS__, 'cron_deactivate' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			\wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivate the cron job.
	 *
	 * @codeCoverageIgnore
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
	 *
	 * @codeCoverageIgnore
	 */
	public static function check_update_version() {
		$current_version = \get_option( self::TABLE_VERSION_OPTION, false );
		if ( self::TABLE_VERSION !== $current_version ) {
			self::create_custom_table();
			\update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
		}
	}

	/**
	 * Create a custom DB table to store subscription attempts.
	 *
	 * @codeCoverageIgnore
	 */
	public static function create_custom_table() {
		global $wpdb;
		$table_name = self::get_table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) != $table_name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$charset_collate = $wpdb->get_charset_collate();
			$sql             = "CREATE TABLE $table_name (
				-- Email address.
				email varchar(150) NOT NULL,
				-- List IDs.
				list_ids varchar(300) NOT NULL,
				-- Timestamp when data was created.
				created_at datetime NOT NULL,
				KEY (email)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}

	/**
	 * Get a row by email.
	 *
	 * @param string $email Email address.
	 */
	public static function get_by_email( $email ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT email,list_ids FROM %i WHERE email = %s',
				self::get_table_name(),
				$email
			)
		);
	}

	/**
	 * Set a value in the database.
	 *
	 * @param string $email The reader's unique ID.
	 * @param string $list_ids The list_ids of the data to set.
	 *
	 * @return mixed The value if it was set, false otherwise.
	 */
	private static function set( $email, $list_ids ) {
		global $wpdb;

		// Check if the entry with this email address exists.
		$existing_row = self::get_by_email( $email );
		if ( $existing_row ) {
			// Update the row.
			return $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				self::get_table_name(),
				[ 'list_ids' => $list_ids ],
				[ 'email' => $email ],
				[ '%s' ],
				[ '%s' ]
			);
		}

		return $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_table_name(),
			[
				'email'      => $email,
				'list_ids'   => $list_ids,
				'created_at' => \current_time( 'mysql', true ), // GMT time.
			],
			[
				'%s',
				'%s',
				'%s',
			]
		);
	}

	/**
	 * Cleanup old subscription attempts. Limit to max 1000 at a time, just in case.
	 */
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < now() - interval 6 MONTH LIMIT 1000', self::get_table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Save a subscription attempt.
	 *
	 * @param string[]|false $lists    Array of list IDs the contact will be subscribed to, or false.
	 * @param array          $contact  {
	 *          Contact information.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 * }
	 */
	public static function save_attempt( $lists, $contact ) {
		$list_ids = '';
		if ( false !== $lists && is_array( $lists ) ) {
			$list_ids = implode( ',', $lists );
		}
		self::set( $contact['email'], $list_ids );
	}

	/**
	 * Handle a subscription lists update.
	 *
	 * @param string        $provider        The provider name.
	 * @param string        $email           Contact email address.
	 * @param string[]      $lists_to_add    Array of list IDs to subscribe the contact to.
	 * @param string[]      $lists_to_remove Array of list IDs to remove the contact from.
	 * @param bool|WP_Error $result          True if the contact was updated or error if failed.
	 */
	public static function save_update( $provider, $email, $lists_to_add, $lists_to_remove, $result ) {
		$existing_row = self::get_by_email( $email );
		if ( ! $existing_row ) {
			return self::set( $email, implode( ',', $lists_to_add ) );
		}
		$lists_updated = explode( ',', $existing_row->list_ids );
		$lists_updated = array_merge( array_diff( $lists_updated, $lists_to_remove ), $lists_to_add );
		return self::set( $email, implode( ',', $lists_updated ) );
	}
}

Newspack_Newsletters_Subscription_Attempts::init();
