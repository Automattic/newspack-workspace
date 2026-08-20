<?php
/**
 * Tests for the nonce store's self-managed table.
 *
 * The store creates and updates its own table: on activation for a site
 * configured as a Hub, lazily on first use everywhere else, and in place when
 * the schema version advances. These tests pin each of those paths, including
 * the failure leg — an update that does not take is retried on a later use
 * rather than recorded as done.
 *
 * The tests rebuild the table directly, so they live apart from the webhook
 * tests: schema statements commit the test transaction, which would leak the
 * webhook tests' fixtures.
 *
 * @package Newspack_Network
 */

use Newspack_Network\Crypto;
use Newspack_Network\Initializer;
use Newspack_Network\Site_Role;
use Newspack_Network\Hub\Used_Nonces;

/**
 * Test the used-nonce table's install and update paths.
 *
 * @group hub-webhook
 */
class TestHubUsedNoncesInstall extends \WP_UnitTestCase {

	/**
	 * The fully-prefixed table name.
	 *
	 * @return string
	 */
	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'newspack_network_used_nonces';
	}

	/**
	 * Rebuild the table as the previous schema version shipped it, with the
	 * previous version recorded, so a test can drive the in-place update.
	 */
	private function install_previous_schema() {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$wpdb->query(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nonce varchar(48) NOT NULL,
				created_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY nonce (nonce)
			)"
		);
		// phpcs:enable
		update_option( Used_Nonces::DB_VERSION_OPTION, '1' );
	}

	/**
	 * Activation installs the table for a Hub, and only for a Hub.
	 */
	public function test_activation_installs_the_table_for_a_hub() {
		delete_option( Used_Nonces::DB_VERSION_OPTION );

		update_option( Site_Role::OPTION_NAME, Site_Role::NODE_ROLE );
		Initializer::activation_hook();
		$this->assertFalse( get_option( Used_Nonces::DB_VERSION_OPTION ), 'A site that is not a Hub does not install the table on activation.' );

		update_option( Site_Role::OPTION_NAME, Site_Role::HUB_ROLE );
		Initializer::activation_hook();
		$this->assertSame( Used_Nonces::DB_VERSION, get_option( Used_Nonces::DB_VERSION_OPTION ), 'A Hub installs the table on activation.' );
	}

	/**
	 * A table at the previous schema version is updated in place on first use:
	 * the store works immediately and the recorded version advances, with no
	 * manual migration.
	 */
	public function test_a_table_at_an_earlier_version_is_updated_in_place() {
		$this->install_previous_schema();

		$nonce = Crypto::generate_nonce();
		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'A claim works once the table has been brought up to date.' );
		$this->assertTrue( Used_Nonces::complete( $nonce ), 'The claim can be completed on the updated table.' );
		$this->assertSame( 'completed', Used_Nonces::claim( $nonce ), 'The completed state is read back from the updated table.' );
		$this->assertSame( Used_Nonces::DB_VERSION, get_option( Used_Nonces::DB_VERSION_OPTION ), 'The recorded schema version is current once the update has run.' );
	}

	/**
	 * A record from before the update keeps its meaning: under the previous
	 * schema a record's existence meant the delivery was processed, so the
	 * update marks those records completed. Records made after the update
	 * still begin as pending.
	 */
	public function test_records_from_an_earlier_version_read_as_completed_after_the_update() {
		global $wpdb;
		$this->install_previous_schema();

		// Seed a record as the previous version wrote it.
		$nonce = Crypto::generate_nonce();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table_name(),
			[
				'nonce'      => $nonce,
				'created_at' => time(),
			],
			[ '%s', '%d' ]
		);

		$this->assertSame( 'completed', Used_Nonces::claim( $nonce ), 'A record from before the update reads as a completed delivery, which is what it meant.' );

		$fresh = Crypto::generate_nonce();
		$this->assertSame( 'claimed', Used_Nonces::claim( $fresh ), 'A record made after the update starts fresh.' );
		$this->assertSame( 'pending', Used_Nonces::claim( $fresh ), 'A record made after the update still begins as pending.' );
	}

	/**
	 * The completed marking is bounded to records that existed before the update
	 * began: a record made while the update is under way keeps its pending state,
	 * while records from the earlier version come out completed.
	 */
	public function test_update_marks_only_records_that_predate_it() {
		global $wpdb;
		$table = $this->table_name();
		$this->install_previous_schema();

		// A record as the previous version wrote it.
		$legacy = Crypto::generate_nonce();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			[
				'nonce'      => $legacy,
				'created_at' => time(),
			],
			[ '%s', '%d' ]
		);

		// While the update runs, another request records a claim. Recreate that
		// by inserting at the moment the update's schema statement comes through.
		$during               = Crypto::generate_nonce();
		$inserted             = false;
		$insert_during_update = function ( $query ) use ( $table, $during, &$inserted ) {
			global $wpdb;
			if ( ! $inserted && 0 === stripos( ltrim( $query ), 'ALTER' ) && false !== strpos( $query, $table ) ) {
				$inserted = true;
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table,
					[
						'nonce'      => $during,
						'created_at' => time(),
					],
					[ '%s', '%d' ]
				);
			}
			return $query;
		};
		add_filter( 'query', $insert_during_update );

		$this->assertSame( 'completed', Used_Nonces::claim( $legacy ), 'A record from before the update reads as completed.' );

		remove_filter( 'query', $insert_during_update );

		$this->assertTrue( $inserted, 'The mid-update record was made.' );
		$this->assertSame( 'pending', Used_Nonces::claim( $during ), 'A record made while the update ran keeps its pending state.' );
	}

	/**
	 * A table update that does not take is not recorded as done: the store
	 * reports no state while the table is out of shape, and a later use retries
	 * the update and recovers.
	 */
	public function test_a_failed_table_update_is_retried() {
		global $wpdb;
		$table = $this->table_name();
		$this->install_previous_schema();

		// Make the update statement fail, standing in for a host that
		// restricts schema changes.
		$break_alter = function ( $query ) use ( $table ) {
			if ( 0 === stripos( ltrim( $query ), 'ALTER' ) && false !== strpos( $query, $table ) ) {
				return "ALTER TABLE {$table}_missing ADD COLUMN unused int";
			}
			return $query;
		};
		add_filter( 'query', $break_alter );
		$suppress = $wpdb->suppress_errors( true );

		$first = Used_Nonces::claim( Crypto::generate_nonce() );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_alter );

		$this->assertNull( $first, 'While the table is out of shape, a claim reports no state, so the delivery is retried.' );
		$this->assertNotSame( Used_Nonces::DB_VERSION, get_option( Used_Nonces::DB_VERSION_OPTION ), 'A failed update is not recorded as done.' );

		// With the obstruction gone, the next use updates the table and works.
		$nonce = Crypto::generate_nonce();
		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'The update is retried on a later use.' );
		$this->assertSame( Used_Nonces::DB_VERSION, get_option( Used_Nonces::DB_VERSION_OPTION ), 'The recorded schema version advances once the update has taken.' );
	}
}
