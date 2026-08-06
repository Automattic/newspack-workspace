<?php
/**
 * Tests the Users_CSV_Exporter class.
 *
 * @package Newspack\Tests
 */

use Newspack\Users_CSV_Exporter;

require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
require_once dirname( __DIR__, 3 ) . '/includes/export/class-users-csv-exporter.php';

/**
 * Test the users CSV exporter at full fidelity (real WP users, real
 * WP_User_Query paging), plus its param translation and filter contract.
 *
 * @group csv-export
 */
class Newspack_Test_Users_CSV_Exporter extends WP_UnitTestCase {

	/**
	 * Create a subscriber with WC billing meta.
	 *
	 * @param int $i Index used to build unique values.
	 * @return int User ID.
	 */
	private function create_user_with_billing( $i ) {
		$user_id = self::factory()->user->create(
			[
				'user_login' => "csv_export_user_$i",
				'user_email' => "csv-export-$i@example.com",
				'first_name' => "First$i",
				'last_name'  => "Last$i",
				'role'       => 'subscriber',
			]
		);
		foreach ( [
			'billing_first_name'  => "First$i",
			'billing_last_name'   => "Last$i",
			'billing_company'     => '',
			'billing_address_1'   => "$i Main St",
			'billing_address_2'   => '',
			'billing_city'        => 'Springfield',
			'billing_state'       => 'CO',
			'billing_postcode'    => '80001',
			'billing_country'     => 'US',
			'billing_email'       => "csv-export-$i@example.com",
			'billing_phone'       => '555-0100',
			'shipping_first_name' => "First$i",
			'shipping_city'       => 'Shelbyville',
			'shipping_country'    => 'US',
		] as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}
		return $user_id;
	}

	/**
	 * Row data maps core user fields, joined roles, and WC billing/shipping
	 * meta; row keys exactly match the column ids.
	 */
	public function test_users_row_data() {
		$user_id = $this->create_user_with_billing( 1 );
		$user    = get_user_by( 'id', $user_id );
		$user->add_role( 'editor' );

		$exporter = new Users_CSV_Exporter();
		$row      = $exporter->get_row_data( get_user_by( 'id', $user_id ) );

		$this->assertSame( array_keys( $exporter->get_column_names() ), array_keys( $row ) );

		$this->assertSame( $user_id, $row['ID'] );
		$this->assertSame( 'csv_export_user_1', $row['user_login'] );
		$this->assertSame( 'csv-export-1@example.com', $row['user_email'] );
		$this->assertSame( 'First1', $row['first_name'] );
		$this->assertSame( 'Last1', $row['last_name'] );
		$this->assertSame( 'subscriber, editor', $row['roles'] );
		$this->assertNotEmpty( $row['user_registered'] );

		$this->assertSame( 'First1', $row['billing_first_name'] );
		$this->assertSame( '1 Main St', $row['billing_address_1'] );
		$this->assertSame( 'US', $row['billing_country'] );
		$this->assertSame( '555-0100', $row['billing_phone'] );
		$this->assertSame( 'Shelbyville', $row['shipping_city'] );

		// Meta the user never set exports as an empty string.
		$this->assertSame( '', $row['shipping_postcode'] );
	}

	/**
	 * List params translate to WP_User_Query args: role passthrough, core
	 * wildcard search, and the users_list_table_query_args replay with $_GET
	 * visible to third-party callbacks (and restored afterwards).
	 */
	public function test_users_build_query_args() {
		$args = Users_CSV_Exporter::build_query_args( [ 'role' => 'subscriber' ] );
		$this->assertSame( 'subscriber', $args['role'] );

		$search = Users_CSV_Exporter::build_query_args( [ 's' => 'jane' ] );
		$this->assertSame( '*jane*', $search['search'] );

		// The core list-table filter is replayed with $_GET populated — but
		// only in admin context (the filter's callbacks may assume admin-only
		// APIs, which would fatal under WP-CLI).
		$seen_get = null;
		$replay   = function ( $args ) use ( &$seen_get ) {
			$seen_get        = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['include'] = [ 123 ];
			return $args;
		};
		add_filter( 'users_list_table_query_args', $replay );

		$non_admin = Users_CSV_Exporter::build_query_args( [ 'role' => 'author' ] );
		$this->assertArrayNotHasKey( 'include', $non_admin, 'The list-table filter must not be replayed outside admin context.' );

		set_current_screen( 'users' );
		$_GET     = [ 'original' => '1' ];
		$filtered = Users_CSV_Exporter::build_query_args( [ 'role' => 'author' ] );
		set_current_screen( 'front' );
		remove_filter( 'users_list_table_query_args', $replay );

		$this->assertSame( [ 123 ], $filtered['include'] );
		$this->assertSame( 'author', $seen_get['role'] ?? null, 'Replayed callbacks must see the captured list params in $_GET.' );
		$this->assertSame( [ 'original' => '1' ], $_GET, '$_GET must be restored after the replay.' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * A replayed list-table callback that assumes screen context admin-ajax
	 * cannot provide (a throwing callback) degrades to "filter not honored"
	 * instead of fataling the export step.
	 */
	public function test_users_build_query_args_survives_throwing_callback() {
		$throwing = function ( $args ) {
			throw new Error( 'Call to a member function id() on null' );
		};
		add_filter( 'users_list_table_query_args', $throwing );
		set_current_screen( 'users' );
		$_GET = [];
		$args = Users_CSV_Exporter::build_query_args( [ 'role' => 'subscriber' ] );
		set_current_screen( 'front' );
		remove_filter( 'users_list_table_query_args', $throwing );

		$this->assertSame( 'subscriber', $args['role'], 'The unfiltered args must survive a throwing callback.' );
	}

	/**
	 * Array-shaped params (a mangled ?s[]=... URL) are dropped instead of
	 * fataling in the string handling.
	 */
	public function test_users_build_query_args_drops_array_params() {
		$args = Users_CSV_Exporter::build_query_args(
			[
				's'    => [ 'jane' ],
				'role' => 'subscriber',
			]
		);
		$this->assertArrayNotHasKey( 'search', $args );
		$this->assertSame( 'subscriber', $args['role'] );
	}

	/**
	 * Paging math: with 5 matching users, limit 2 and page 2 prepare rows
	 * 3–4, total_rows is the full count, and percent-complete follows.
	 */
	public function test_users_paging() {
		$ids = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = $this->create_user_with_billing( $i );
		}
		sort( $ids );

		$exporter = new Users_CSV_Exporter();
		$exporter->set_list_params( [ 'role' => 'subscriber' ] );
		$exporter->set_limit( 2 );
		$exporter->set_page( 2 );
		$exporter->prepare_data_to_export();

		$rows = $exporter->get_prepared_row_data();
		$this->assertCount( 2, $rows );
		// Deterministic ID ASC ordering: page 2 holds the 3rd and 4th users.
		$this->assertSame( $ids[2], $rows[0]['ID'] );
		$this->assertSame( $ids[3], $rows[1]['ID'] );
		$this->assertSame( 5, $exporter->get_mock_total_rows() );

		$exporter->mock_export_rows();
		$this->assertSame( 80, $exporter->get_percent_complete() );
	}

	/**
	 * The users headers/row filter pair supports adding a custom column.
	 */
	public function test_users_headers_and_row_filters() {
		$add_header = function ( $columns ) {
			$columns['newsletter_lists'] = 'Newsletter Lists';
			return $columns;
		};
		$add_cell   = function ( $row, $user ) {
			$row['newsletter_lists'] = 'daily-brief';
			return $row;
		};
		add_filter( 'newspack_users_export_headers', $add_header );
		add_filter( 'newspack_users_export_row', $add_cell, 10, 2 );

		$user_id  = $this->create_user_with_billing( 9 );
		$exporter = new Users_CSV_Exporter();
		$this->assertArrayHasKey( 'newsletter_lists', $exporter->get_column_names() );
		$row = $exporter->get_row_data( get_user_by( 'id', $user_id ) );
		$this->assertSame( 'daily-brief', $row['newsletter_lists'] );

		remove_filter( 'newspack_users_export_headers', $add_header );
		remove_filter( 'newspack_users_export_row', $add_cell );
	}
}
