<?php
/**
 * Tests for the Hub event log's timestamp index.
 *
 * Every incoming event runs a duplicate check against the event log before it
 * is stored, and that check runs inside the request that raised the event —
 * a WooCommerce checkout, for one. Without an index on `timestamp`, the check
 * reads the whole table, which on a Hub with a large log takes long enough to
 * time the checkout out. These tests pin the index on fresh installs, and the
 * upgrade path for existing tables: the index is built from cron, never from
 * the web request that notices it is missing.
 *
 * The tests rebuild the table directly, so they live apart from the webhook
 * tests: schema statements commit the test transaction, which would leak the
 * webhook tests' fixtures.
 *
 * @package Newspack_Network
 */

use Newspack_Network\Hub\Database\Event_Log as Event_Log_Database;
use Newspack_Network\Hub\Stores\Event_Log;

/**
 * Test the event log table's timestamp index and its upgrade path.
 *
 * @group event-log
 */
class TestEventLogTimestampIndex extends \WP_UnitTestCase {

	/**
	 * The option recording the table's schema version.
	 */
	const VERSION_OPTION = 'newspack_db_version_event_log';

	/**
	 * Start the class with no table and no recorded version.
	 *
	 * Inside a test, the suite turns CREATE and DROP into their session-local
	 * TEMPORARY forms, so the table is removed here, outside any test, to let
	 * each test build the exact table it asserts against.
	 *
	 * @param \WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'newspack_hub_event_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Put this test's world back to nothing: no table, no recorded version, no
	 * scheduled upgrade.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table_name()}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::VERSION_OPTION );
		delete_transient( Event_Log_Database::UPGRADE_LOCK );
		wp_clear_scheduled_hook( Event_Log_Database::UPGRADE_HOOK );
	}

	/**
	 * The fully-prefixed table name.
	 *
	 * @return string
	 */
	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'newspack_hub_event_log';
	}

	/**
	 * Create the table as schema version 2 left it: no index but the primary key.
	 */
	private function create_version_2_table() {
		global $wpdb;
		$sql = "CREATE TABLE {$this->table_name()} (
			id int(11) NOT NULL AUTO_INCREMENT,
			action_name varchar(100) NOT NULL,
			node_id int(11) NOT NULL,
			email varchar(100) NULL,
			data longtext NOT NULL,
			timestamp int(11) NOT NULL,
			PRIMARY KEY  (id)
		) {$wpdb->get_charset_collate()}";
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
		update_option( self::VERSION_OPTION, 2 );
	}

	/**
	 * Whether the table has an index led by the `timestamp` column.
	 *
	 * @return bool
	 */
	private function has_timestamp_index() {
		global $wpdb;
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$this->table_name()} WHERE Column_name = 'timestamp' AND Seq_in_index = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return ! empty( $rows );
	}

	/**
	 * The duplicate check run before storing an event looks rows up through the
	 * timestamp index instead of reading the whole table.
	 */
	public function test_duplicate_check_uses_the_timestamp_index() {
		global $wpdb;
		$table = Event_Log_Database::get_table_name();

		$start = 1700000000;
		for ( $i = 0; $i < 500; $i++ ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				[
					'action_name' => 0 === $i % 2 ? 'reader_registered' : 'network_post_updated',
					'node_id'     => 0,
					'email'       => "reader{$i}@example.test",
					'data'        => wp_json_encode( [ 'i' => $i ] ),
					'timestamp'   => $start + ( $i * 60 ),
				]
			);
		}

		// The same arguments Event_Log::persist() passes to its duplicate check.
		Event_Log::get(
			[
				'node_id'     => 0,
				'action_name' => 'reader_registered',
				'email'       => 'reader250@example.test',
				'data'        => wp_json_encode( [ 'i' => 250 ] ),
				'timestamp'   => $start + ( 250 * 60 ),
			]
		);
		$duplicate_check = $wpdb->last_query;
		$this->assertStringContainsString( 'AND timestamp >=', $duplicate_check, 'The captured query is the duplicate check.' );

		$plan = $wpdb->get_row( "EXPLAIN $duplicate_check" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotSame( 'ALL', $plan->type, 'The duplicate check must not read the whole table.' );
		$this->assertSame( 'timestamp', $plan->key, 'The duplicate check looks rows up through the timestamp index.' );
	}

	/**
	 * A web request that finds a version-2 table leaves the index build to cron
	 * rather than running it itself, since on a large log the build takes minutes.
	 */
	public function test_upgrade_of_an_existing_table_is_deferred_to_cron() {
		$this->create_version_2_table();

		Event_Log_Database::get_table_name();

		$this->assertFalse( $this->has_timestamp_index(), 'The web request does not build the index.' );
		$this->assertNotFalse( wp_next_scheduled( Event_Log_Database::UPGRADE_HOOK ), 'The web request schedules the upgrade.' );
		$this->assertSame( 2, absint( get_option( self::VERSION_OPTION ) ), 'The version is not recorded before the index exists.' );
	}

	/**
	 * The scheduled upgrade builds the index, then records the new version.
	 */
	public function test_scheduled_upgrade_builds_the_index_and_records_the_version() {
		$this->create_version_2_table();
		Event_Log_Database::init();

		do_action( Event_Log_Database::UPGRADE_HOOK );

		$this->assertTrue( $this->has_timestamp_index(), 'The scheduled upgrade builds the index.' );
		$this->assertSame( 3, absint( get_option( self::VERSION_OPTION ) ), 'The version is recorded once the index exists.' );
		$this->assertFalse( get_transient( Event_Log_Database::UPGRADE_LOCK ), 'A finished upgrade releases its lock.' );
	}

	/**
	 * While an upgrade holds its lock, web requests do not queue another one and
	 * a second cron run does not start a second index build.
	 */
	public function test_upgrade_does_not_run_twice_while_locked() {
		$this->create_version_2_table();
		Event_Log_Database::init();
		set_transient( Event_Log_Database::UPGRADE_LOCK, time(), HOUR_IN_SECONDS );

		Event_Log_Database::get_table_name();
		do_action( Event_Log_Database::UPGRADE_HOOK );

		$this->assertFalse( wp_next_scheduled( Event_Log_Database::UPGRADE_HOOK ), 'No second upgrade is queued behind a running one.' );
		$this->assertFalse( $this->has_timestamp_index(), 'A locked cron run does not build the index.' );
	}

	/**
	 * An index on `timestamp` added by hand, under another name, is accepted as
	 * is: no second index is built on the table.
	 */
	public function test_existing_timestamp_index_under_another_name_is_accepted() {
		global $wpdb;
		$this->create_version_2_table();
		$wpdb->query( "ALTER TABLE {$this->table_name()} ADD KEY manual_ts (timestamp)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Event_Log_Database::get_table_name();

		$indexes = $wpdb->get_col( "SHOW INDEX FROM {$this->table_name()} WHERE Column_name = 'timestamp'", 2 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( [ 'manual_ts' ], $indexes, 'No second timestamp index is built.' );
		$this->assertSame( 3, absint( get_option( self::VERSION_OPTION ) ), 'The existing index satisfies the upgrade.' );
		$this->assertFalse( wp_next_scheduled( Event_Log_Database::UPGRADE_HOOK ), 'No upgrade is scheduled.' );
	}

	/**
	 * An index build that fails keeps its lock, so the retry waits for the lock
	 * to expire, and does not record the new version.
	 */
	public function test_failed_upgrade_keeps_the_lock_and_the_old_version() {
		$this->create_version_2_table();
		Event_Log_Database::init();
		$drop_index_from_schema = function( $create_queries ) {
			return array_map(
				function( $query ) {
					return preg_replace( '/,\s*KEY timestamp \(timestamp\)/', '', $query );
				},
				$create_queries
			);
		};
		add_filter( 'dbdelta_create_queries', $drop_index_from_schema );

		do_action( Event_Log_Database::UPGRADE_HOOK );

		$this->assertFalse( $this->has_timestamp_index(), 'The index build did not run.' );
		$this->assertNotFalse( get_transient( Event_Log_Database::UPGRADE_LOCK ), 'A failed upgrade keeps its lock as backoff.' );
		$this->assertSame( 2, absint( get_option( self::VERSION_OPTION ) ), 'A failed upgrade does not record the new version.' );
	}

	/**
	 * A fresh install creates the table with the index in place.
	 */
	public function test_fresh_install_creates_the_timestamp_index() {
		Event_Log_Database::get_table_name();

		$this->assertTrue( $this->has_timestamp_index(), 'A new table has the timestamp index.' );
		$this->assertSame( 3, absint( get_option( self::VERSION_OPTION ) ), 'A new table records the current version.' );
	}
}
