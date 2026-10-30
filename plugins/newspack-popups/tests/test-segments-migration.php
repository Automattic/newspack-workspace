<?php
/**
 * Tests for Newspack_Segments_Migration.
 *
 * @package Newspack_Popups
 */

/**
 * Test the migration of legacy reader events to reader data.
 */
class SegmentsMigrationTest extends WP_UnitTestCase {

	/**
	 * Load newspack-plugin's reader data classes, which this suite's bootstrap
	 * does not load.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! class_exists( '\Newspack\Reader_Data' ) ) {
			$plugin_dir = dirname( __DIR__, 2 ) . '/newspack-plugin/';
			$dir        = $plugin_dir . 'includes/reader-activation';
			if ( ! file_exists( $dir . '/class-reader-data.php' ) ) {
				$this->markTestSkipped( 'newspack-plugin is not available.' );
			}
			if ( ! defined( 'NEWSPACK_ABSPATH' ) ) {
				define( 'NEWSPACK_ABSPATH', $plugin_dir );
			}
			require_once $dir . '/class-reader-data-trust.php';
			require_once $dir . '/class-reader-data.php';
		}

		global $wpdb;
		// The test suite turns this into a temporary table, dropped with the connection.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}newspack_campaigns_reader_events ( id bigint(20) NOT NULL AUTO_INCREMENT, client_id varchar(100) NOT NULL, type varchar(20) NOT NULL, context varchar(255) NOT NULL DEFAULT '', date_created datetime NOT NULL, PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * Drop the legacy events table.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}newspack_campaigns_reader_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		parent::tear_down();
	}

	/**
	 * The migration relays values recorded in the browser, so it writes them
	 * without the mark that says the server wrote them.
	 *
	 * Runs in its own process because the account-arrival suite defines a minimal
	 * stand-in for Reader_Data when its file is included, and a class cannot be
	 * redefined, so this test would otherwise never see the real one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_migration_writes_values_without_server_mark() {
		global $wpdb;
		$user_id = $this->factory->user->create();
		foreach ( [ 'subscription', 'donation' ] as $type ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				"{$wpdb->prefix}newspack_campaigns_reader_events",
				[
					'client_id'    => 'client-example',
					'type'         => $type,
					'date_created' => '2024-01-01 00:00:00',
				]
			);
		}

		Newspack_Segments_Migration::migrate_client_ids_to_user( [ 'client-example' ], $user_id );

		foreach ( [ 'is_newsletter_subscriber', 'is_donor' ] as $key ) {
			$this->assertSame( 'true', \Newspack\Reader_Data::get_data( $user_id, $key ), $key );
			$this->assertFalse( \Newspack\Reader_Data_Trust::has_valid_mark( $user_id, $key ), $key );
		}
	}
}
