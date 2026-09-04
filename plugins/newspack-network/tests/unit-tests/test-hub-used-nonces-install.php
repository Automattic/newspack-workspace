<?php
/**
 * Tests for the nonce store's self-managed table.
 *
 * The store creates its own table — on activation for a site configured as a
 * Hub, lazily on first use everywhere else — and certifies it with a shape
 * probe before recording the schema version. These tests pin those paths and
 * their failure legs: an install that does not take is retried on a later use,
 * and a table lost or damaged after certification is restored rather than
 * wedged.
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
use Newspack_Network\Used_Nonces;

/**
 * Test the used-nonce table's install and repair paths.
 *
 * @group hub-webhook
 */
class TestHubUsedNoncesInstall extends \WP_UnitTestCase {

	/**
	 * Start the class with no table and no recorded version.
	 *
	 * Inside a test, the suite turns CREATE and DROP into their session-local
	 * TEMPORARY forms, so a table made before the tests began would sit
	 * underneath every scenario and absorb the statements meant for the
	 * scenario's own copy. Removing it here — outside any test, where DDL runs
	 * unrewritten — lets each test build and tear down the exact world it
	 * asserts against.
	 *
	 * @param \WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'newspack_network_used_nonces' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		delete_option( Used_Nonces::DB_VERSION_OPTION );
	}

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
	 * Put this test's world back to nothing: no table, no recorded version.
	 */
	private function start_from_nothing() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table_name()}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( Used_Nonces::DB_VERSION_OPTION );
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
	 * A table install that does not take is not recorded as done: the store
	 * reports no state while the table is missing, and a later use retries the
	 * install and recovers.
	 */
	public function test_a_failed_table_install_is_retried() {
		global $wpdb;
		$table = $this->table_name();

		$this->start_from_nothing();

		// Make the install statement fail, standing in for a host that restricts
		// schema changes.
		$break_create = function ( $query ) use ( $table ) {
			if ( 0 === stripos( ltrim( $query ), 'CREATE' ) && false !== strpos( $query, $table ) ) {
				return $query . ' BROKEN';
			}
			return $query;
		};
		add_filter( 'query', $break_create );
		$suppress = $wpdb->suppress_errors( true );

		$first = Used_Nonces::claim( Crypto::generate_nonce() );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_create );

		$this->assertNull( $first, 'While the table is missing, a claim reports no state, so the delivery is retried.' );
		$this->assertFalse( get_option( Used_Nonces::DB_VERSION_OPTION ), 'A failed install is not recorded as done.' );

		// With the obstruction gone, the next use installs the table and works.
		$nonce = Crypto::generate_nonce();
		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'The install is retried on a later use.' );
		$this->assertSame( Used_Nonces::DB_VERSION, get_option( Used_Nonces::DB_VERSION_OPTION ), 'The schema version is recorded once the install has taken.' );
	}

	/**
	 * A lost table is recreated on use even while the recorded version matches:
	 * certification rests on the shape probe, not the marker alone, so the store
	 * heals itself instead of failing every claim until the marker is cleared by
	 * hand.
	 */
	public function test_a_lost_table_is_recreated_on_use() {
		global $wpdb;
		$table = $this->table_name();
		$this->start_from_nothing();

		// A healthy install, then the table goes away while the marker stays —
		// the state a partial restore leaves behind.
		$this->assertSame( 'claimed', Used_Nonces::claim( Crypto::generate_nonce() ), 'The store starts healthy.' );
		$this->assertSame( Used_Nonces::DB_VERSION, get_option( Used_Nonces::DB_VERSION_OPTION ), 'The version is recorded.' );
		$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$nonce = Crypto::generate_nonce();
		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'A claim recreates the table and works.' );
		$this->assertSame( 'pending', Used_Nonces::claim( $nonce ), 'The recreated table recognises the claim it just recorded.' );
	}

	/**
	 * The shape probe answers for everything the store rests on — the status
	 * column, the unique key on nonce, and the table existing at all — so
	 * certification is withheld until every piece is present. The unique key is
	 * the piece that matters most: without it, a repeated claim would be
	 * reported as first-seen.
	 */
	public function test_the_shape_probe_checks_column_key_and_table() {
		global $wpdb;
		$table = $this->table_name();
		$probe = new \ReflectionMethod( Used_Nonces::class, 'table_has_current_shape' );
		$probe->setAccessible( true );
		$this->start_from_nothing();

		$this->assertSame( 'claimed', Used_Nonces::claim( Crypto::generate_nonce() ), 'The store starts healthy.' );
		$this->assertTrue( $probe->invoke( null, $table ), 'A healthy table passes the probe.' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE {$table}" );
		$this->assertFalse( $probe->invoke( null, $table ), 'A missing table does not pass.' );

		// A table that kept the column but lost the unique key. The marker is
		// freed first so the store rebuilds from scratch on its next use, whatever
		// this test's outcome.
		delete_option( Used_Nonces::DB_VERSION_OPTION );
		$wpdb->query(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nonce varchar(48) NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				created_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id)
			)"
		);
		$this->assertFalse( $probe->invoke( null, $table ), 'A table without the unique key does not pass.' );
		$wpdb->query( "DROP TABLE {$table}" );

		// A unique key with the right name spanning a second column: a repeated
		// nonce could then insert beside the original, so it must not certify.
		$wpdb->query(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nonce varchar(48) NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				created_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY nonce (nonce, created_at)
			)"
		);
		$this->assertFalse( $probe->invoke( null, $table ), 'A unique key spanning more than the nonce column does not pass.' );
		$wpdb->query( "DROP TABLE {$table}" );

		// A unique key with the right name on a different column entirely.
		$wpdb->query(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nonce varchar(48) NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				created_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY nonce (created_at)
			)"
		);
		$this->assertFalse( $probe->invoke( null, $table ), 'A unique key with the right name on a different column does not pass.' );
		$wpdb->query( "DROP TABLE {$table}" );
		// phpcs:enable
	}
}
