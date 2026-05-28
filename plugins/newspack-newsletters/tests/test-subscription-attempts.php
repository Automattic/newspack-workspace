<?php
/**
 * Class Newsletters Test Subscription_Attempts
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_Attempts;

/**
 * Tests the Subscription_Attempts class
 */
class Subscription_Attempts_Test extends WP_UnitTestCase {
	/**
	 * Test DB version.
	 */
	public static function test_subscription_attempts_db_version() {
		$version = get_option( Newspack_Newsletters_Subscription_Attempts::TABLE_VERSION_OPTION );
		self::assertEquals( Newspack_Newsletters_Subscription_Attempts::TABLE_VERSION, $version );
	}

	/**
	 * Set up
	 */
	public function set_up() {
		global $wpdb;
		$table_name = Newspack_Newsletters_Subscription_Attempts::get_table_name();
		$wpdb->query( "DELETE FROM $table_name" ); // phpcs:ignore
	}

	/**
	 * Test if the attempt is added to the custom table when the WP hook is called.
	 */
	public function test_subscription_attempts_add_and_update() {
		$lists = [ 'list1', 'list2' ];
		$contact = [
			'email' => 'tester@example.com',
		];
		do_action( 'newspack_newsletters_pre_add_contact', $lists, $contact );

		$result = Newspack_Newsletters_Subscription_Attempts::get_by_email( $contact['email'] );
		self::assertEquals( $contact['email'], $result->email );
		self::assertEquals( implode( ',', $lists ), $result->list_ids );

		$lists_added = [ 'list3', 'list4' ];
		do_action( 'newspack_newsletters_update_contact_lists', 'some_esp', $contact['email'], $lists_added, [], true, 'test' );

		$result = Newspack_Newsletters_Subscription_Attempts::get_by_email( $contact['email'] );
		$lists_expected = array_merge( $lists, $lists_added );
		self::assertEquals( implode( ',', $lists_expected ), $result->list_ids );

		$lists_removed = [ 'list1', 'list3' ];
		do_action( 'newspack_newsletters_update_contact_lists', 'some_esp', $contact['email'], [], $lists_removed, true, 'test' );

		$result = Newspack_Newsletters_Subscription_Attempts::get_by_email( $contact['email'] );
		$lists_expected = [ 'list2', 'list4' ];
		self::assertEquals( implode( ',', $lists_expected ), $result->list_ids );
	}

	/**
	 * Test data cleanup.
	 */
	public function test_subscription_attempts_cleanup() {
		global $wpdb;
		$table_name = Newspack_Newsletters_Subscription_Attempts::get_table_name();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			[
				'email'      => 'some@email.com',
				'list_ids'   => '1,2',
				// Set created time to one year ago.
				'created_at' => \strtotime( '-1 year' ),
			],
			[
				'%s',
				'%s',
				'%s',
			]
		);

		$all_entries = $wpdb->get_results( "SELECT * FROM $table_name", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::assertEquals( 1, count( $all_entries ) );

		Newspack_Newsletters_Subscription_Attempts::cleanup();
		$all_entries = $wpdb->get_results( "SELECT * FROM $table_name", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::assertEmpty( $all_entries );
	}
}
