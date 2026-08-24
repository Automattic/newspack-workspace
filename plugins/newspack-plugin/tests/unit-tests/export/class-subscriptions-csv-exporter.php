<?php
/**
 * Tests the Subscriptions_CSV_Exporter class.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscriptions_CSV_Exporter;
use Newspack\Group_Subscription_Settings;

require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
require_once dirname( __DIR__, 3 ) . '/includes/export/class-subscriptions-csv-exporter.php';

/**
 * Test the subscriptions CSV exporter: row building, list-param → query-arg
 * translation, extensibility filters, CSV-injection escaping, and paging.
 *
 * @group csv-export
 */
class Newspack_Test_Subscriptions_CSV_Exporter extends WP_UnitTestCase {

	/**
	 * Reset mock fixtures between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $wcs_mock_subscription_search_results, $wcs_mock_hpos_enabled, $wcs_mock_orders_with_meta_query_args, $wcs_mock_orders_with_meta_query_result;
		$subscriptions_database                 = [];
		$wcs_mock_subscription_search_results   = [];
		$wcs_mock_hpos_enabled                  = false;
		$wcs_mock_orders_with_meta_query_args   = null;
		unset( $GLOBALS['wcs_mock_orders_with_meta_query_result'] );
		WCS_Customer_Store::$mock_user_subscription_ids = [];
		delete_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT );
	}

	/**
	 * Remove the export files the paging tests stage under uploads.
	 */
	public function tear_down() {
		newspack_test_remove_export_files();
		parent::tear_down();
	}

	/**
	 * Build a fully-populated mock subscription.
	 *
	 * @param array $overrides Data overrides.
	 * @return WC_Subscription
	 */
	private function create_full_subscription( $overrides = [] ) {
		$line_item_digital = new WC_Order_Item_Product(
			[
				'name'       => 'Digital Monthly',
				'product_id' => 11,
				'quantity'   => 1,
			]
		);
		$line_item_print   = new WC_Order_Item_Product(
			[
				'name'       => 'Print, Deluxe Edition',
				'product_id' => 12,
				'quantity'   => 2,
			]
		);
		return wcs_create_subscription(
			array_merge(
				[
					'id'                   => 42,
					'status'               => 'active',
					'date_created'         => '2026-01-15 10:00:00',
					'billing_period'       => 'month',
					'billing_interval'     => 1,
					'total'                => '25.00',
					'currency'             => 'USD',
					'payment_method_title' => 'Credit card (Stripe)',
					'parent_id'            => 100,
					'items'                => [ $line_item_digital, $line_item_print ],
					'dates'                => [
						'start'                   => '2026-01-15 10:00:00',
						'trial_end'               => 0,
						'next_payment'            => '2026-08-15 10:00:00',
						'last_order_date_created' => '2026-07-15 10:00:00',
						'end'                     => 0,
					],
					'billing_first_name'   => 'Jane',
					'billing_last_name'    => 'Reader',
					'billing_company'      => 'Example Co',
					'billing_address_1'    => '1 Main St',
					'billing_address_2'    => 'Suite 2',
					'billing_city'         => 'Springfield',
					'billing_state'        => 'CO',
					'billing_postcode'     => '80001',
					'billing_country'      => 'US',
					'billing_email'        => 'jane@example.com',
					'billing_phone'        => '555-0100',
					'shipping_first_name'  => 'Jane',
					'shipping_last_name'   => 'Reader',
					'shipping_company'     => '',
					'shipping_address_1'   => '1 Main St',
					'shipping_address_2'   => '',
					'shipping_city'        => 'Springfield',
					'shipping_state'       => 'CO',
					'shipping_postcode'    => '80001',
					'shipping_country'     => 'US',
				],
				$overrides
			)
		);
	}

	/**
	 * Row data maps all subscription fields; multi-line-item values are
	 * concatenated in item order; row keys exactly match the column ids.
	 */
	public function test_row_data_maps_subscription_fields() {
		$customer_id  = self::factory()->user->create(
			[
				'user_login' => 'jane_reader',
				'user_email' => 'jane@example.com',
				'first_name' => 'Jane',
				'last_name'  => 'Reader',
			]
		);
		$subscription = $this->create_full_subscription( [ 'customer_id' => $customer_id ] );
		$exporter     = new Subscriptions_CSV_Exporter();
		$row          = $exporter->get_row_data( $subscription );

		// Header/row key parity: every column id has a row key and vice versa.
		$this->assertSame( array_keys( $exporter->get_column_names() ), array_keys( $row ) );

		$this->assertSame( 42, $row['subscription_id'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( '2026-01-15 10:00:00', $row['date_created'] );
		$this->assertSame( '2026-01-15 10:00:00', $row['start_date'] );
		$this->assertSame( '', $row['trial_end_date'], 'A 0 date must be normalized to an empty string.' );
		$this->assertSame( '2026-08-15 10:00:00', $row['next_payment_date'] );
		$this->assertSame( '2026-07-15 10:00:00', $row['last_payment_date'] );
		$this->assertSame( '', $row['end_date'] );
		$this->assertSame( 'month', $row['billing_period'] );
		$this->assertSame( 1, $row['billing_interval'] );

		// Multi-item concatenation, aligned across the three product columns.
		$this->assertSame( '11, 12', $row['product_ids'] );
		$this->assertSame( 'Digital Monthly, Print\\, Deluxe Edition', $row['product_names'], 'Commas inside product names must be escaped.' );
		$this->assertSame( '1, 2', $row['quantities'] );

		$this->assertSame( '25.00', $row['total'] );
		$this->assertSame( 'USD', $row['currency'] );
		$this->assertSame( 'Credit card (Stripe)', $row['payment_method'] );

		$this->assertSame( $customer_id, $row['customer_id'] );
		$this->assertSame( 'jane_reader', $row['customer_username'] );
		$this->assertSame( 'jane@example.com', $row['customer_email'] );
		$this->assertSame( 'Jane', $row['customer_first_name'] );
		$this->assertSame( 'Reader', $row['customer_last_name'] );

		$this->assertSame( 100, $row['parent_order_id'] );

		$this->assertSame( 'Jane', $row['billing_first_name'] );
		$this->assertSame( 'Example Co', $row['billing_company'] );
		$this->assertSame( 'jane@example.com', $row['billing_email'] );
		$this->assertSame( '555-0100', $row['billing_phone'] );
		$this->assertSame( 'Springfield', $row['shipping_city'] );
		$this->assertSame( 'US', $row['shipping_country'] );
	}

	/**
	 * A manual-renewal subscription with no payment method title reports
	 * "Manual renewal" in the payment method column.
	 */
	public function test_row_data_manual_renewal_payment_method() {
		$subscription = $this->create_full_subscription(
			[
				'customer_id'             => 0,
				'payment_method_title'    => '',
				'requires_manual_renewal' => true,
			]
		);
		$exporter     = new Subscriptions_CSV_Exporter();
		$row          = $exporter->get_row_data( $subscription );
		$this->assertSame( 'Manual renewal', $row['payment_method'] );
	}

	/**
	 * Deleted customer: customer_id column keeps the stale id (useful for
	 * joins), user-derived columns are blank, billing columns still populate
	 * from the subscription itself.
	 */
	public function test_row_data_deleted_customer() {
		$subscription = $this->create_full_subscription( [ 'customer_id' => 987654 ] );
		$exporter     = new Subscriptions_CSV_Exporter();
		$row          = $exporter->get_row_data( $subscription );

		$this->assertSame( 987654, $row['customer_id'] );
		$this->assertSame( '', $row['customer_username'] );
		$this->assertSame( '', $row['customer_email'] );
		$this->assertSame( '', $row['customer_first_name'] );
		$this->assertSame( '', $row['customer_last_name'] );
		$this->assertSame( 'jane@example.com', $row['billing_email'] );
		$this->assertSame( 'Jane', $row['billing_first_name'] );
	}

	/**
	 * With no list params, the query defaults to all subscription statuses
	 * (matching the admin list default), shop_subscription type, and a fixed
	 * insert-stable ID ASC order.
	 */
	public function test_build_query_args_defaults() {
		$args = Subscriptions_CSV_Exporter::build_query_args( [] );
		$this->assertSame( array_keys( wcs_get_subscription_statuses() ), $args['status'] );
		$this->assertSame( 'shop_subscription', $args['type'] );
		$this->assertSame( 'ID', $args['orderby'] );
		$this->assertSame( 'ASC', $args['order'] );
		$this->assertArrayNotHasKey( 'post__in', $args );
	}

	/**
	 * Status params normalize in both the CPT shape (post_status) and the
	 * HPOS shape (status), with and without the wc- prefix.
	 */
	public function test_build_query_args_status() {
		$cpt = Subscriptions_CSV_Exporter::build_query_args( [ 'post_status' => 'wc-active' ] );
		$this->assertSame( [ 'wc-active' ], $cpt['status'] );

		$hpos = Subscriptions_CSV_Exporter::build_query_args( [ 'status' => 'active' ] );
		$this->assertSame( [ 'wc-active' ], $hpos['status'] );

		$all = Subscriptions_CSV_Exporter::build_query_args( [ 'post_status' => 'all' ] );
		$this->assertSame( array_keys( wcs_get_subscription_statuses() ), $all['status'] );
	}

	/**
	 * Product and customer filters resolve to subscription ID sets and
	 * intersect; a disjoint intersection yields the [ 0 ] sentinel.
	 */
	public function test_build_query_args_product_and_customer_intersect() {
		foreach ( [ 1, 2, 3 ] as $subscription_id ) {
			wcs_create_subscription(
				[
					'id'       => $subscription_id,
					'products' => [ 5 ],
				]
			);
		}
		WCS_Customer_Store::$mock_user_subscription_ids = [ 7 => [ 2, 3, 4 ] ];

		$args = Subscriptions_CSV_Exporter::build_query_args(
			[
				'_wcs_product'   => '5',
				'_customer_user' => '7',
			]
		);
		$this->assertSame( [ 2, 3 ], array_values( $args['post__in'] ) );

		WCS_Customer_Store::$mock_user_subscription_ids = [ 7 => [ 8, 9 ] ];
		$disjoint = Subscriptions_CSV_Exporter::build_query_args(
			[
				'_wcs_product'   => '5',
				'_customer_user' => '7',
			]
		);
		$this->assertSame( [ 0 ], $disjoint['post__in'], 'Disjoint filters must short-circuit to the empty sentinel.' );
	}

	/**
	 * A product filter matching nothing yields the [ 0 ] sentinel.
	 */
	public function test_build_query_args_product_with_no_subscriptions() {
		$args = Subscriptions_CSV_Exporter::build_query_args( [ '_wcs_product' => '55' ] );
		$this->assertSame( [ 0 ], $args['post__in'] );
	}

	/**
	 * The payment method filter maps _manual_renewal to the manual-renewal
	 * meta query and gateway ids to the payment_method arg.
	 */
	public function test_build_query_args_payment_method() {
		$manual = Subscriptions_CSV_Exporter::build_query_args( [ '_payment_method' => '_manual_renewal' ] );
		$this->assertSame(
			[
				[
					'key'   => '_requires_manual_renewal',
					'value' => 'true',
				],
			],
			$manual['meta_query']
		);
		$this->assertArrayNotHasKey( 'payment_method', $manual );

		$stripe = Subscriptions_CSV_Exporter::build_query_args( [ '_payment_method' => 'stripe' ] );
		$this->assertSame( 'stripe', $stripe['payment_method'] );
	}

	/**
	 * The Newspack group filter applies the same post__in / post__not_in
	 * logic as the admin list, using the cached group id set.
	 */
	public function test_build_query_args_group_filter() {
		set_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT, [ 2, 9 ] );

		$group = Subscriptions_CSV_Exporter::build_query_args( [ '_newspack_group_subscription' => 'group' ] );
		$this->assertSame( [ 2, 9 ], $group['post__in'] );

		$non_group = Subscriptions_CSV_Exporter::build_query_args( [ '_newspack_group_subscription' => 'non-group' ] );
		$this->assertSame( [ 2, 9 ], $non_group['post__not_in'] );
		$this->assertArrayNotHasKey( 'post__in', $non_group );
	}

	/**
	 * Search: native 's' in HPOS mode; resolved to an ID set via
	 * wcs_subscription_search() in CPT mode.
	 */
	public function test_build_query_args_search() {
		global $wcs_mock_hpos_enabled, $wcs_mock_subscription_search_results;

		$wcs_mock_hpos_enabled = true;
		$hpos                  = Subscriptions_CSV_Exporter::build_query_args( [ 's' => 'jane' ] );
		$this->assertSame( 'jane', $hpos['s'] );
		$this->assertArrayNotHasKey( 'post__in', $hpos );

		$wcs_mock_hpos_enabled                = false;
		$wcs_mock_subscription_search_results = [ 'jane' => [ 3, 4 ] ];
		$cpt                                  = Subscriptions_CSV_Exporter::build_query_args( [ 's' => 'jane' ] );
		$this->assertArrayNotHasKey( 's', $cpt );
		$this->assertSame( [ 3, 4 ], $cpt['post__in'] );
	}

	/**
	 * The month filter (m=YYYYMM) becomes an inclusive date_created range.
	 */
	public function test_build_query_args_month() {
		$args = Subscriptions_CSV_Exporter::build_query_args( [ 'm' => '202605' ] );
		$this->assertSame( '2026-05-01...2026-05-31', $args['date_created'] );

		$feb = Subscriptions_CSV_Exporter::build_query_args( [ 'm' => '202402' ] );
		$this->assertSame( '2024-02-01...2024-02-29', $feb['date_created'], 'Leap-year February must end on the 29th.' );
	}

	/**
	 * List-table sorting params are intentionally not honored: the export
	 * order is always the insert-stable ID ASC.
	 */
	public function test_build_query_args_ignores_orderby() {
		$args = Subscriptions_CSV_Exporter::build_query_args(
			[
				'orderby' => 'status',
				'order'   => 'desc',
			]
		);
		$this->assertSame( 'ID', $args['orderby'] );
		$this->assertSame( 'ASC', $args['order'] );
	}

	/**
	 * Array-shaped params (a mangled ?m[]=... URL) are dropped instead of
	 * fataling in the string handling.
	 */
	public function test_build_query_args_drops_array_params() {
		$args = Subscriptions_CSV_Exporter::build_query_args(
			[
				'm'           => [ '202605' ],
				's'           => [ 'jane' ],
				'post_status' => 'wc-active',
			]
		);
		$this->assertArrayNotHasKey( 'date_created', $args );
		$this->assertArrayNotHasKey( 's', $args );
		$this->assertSame( [ 'wc-active' ], $args['status'] );
	}

	/**
	 * The WC batch exporter's exported-row counter accumulates per instance —
	 * the documented reason both the AJAX flow and the CLI loop use a fresh
	 * exporter instance per page. This pins that behavior so a future
	 * "simplification" back to a reused instance fails loudly.
	 */
	public function test_exported_row_counter_accumulates_per_instance() {
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->create_full_subscription(
				[
					'id'          => $i,
					'customer_id' => 0,
				]
			);
		}

		// A filename per exporter keeps each one its own "run" (the pinned
		// total is keyed by filename).
		$reused = new Subscriptions_CSV_Exporter();
		$reused->set_filename( 'newspack-subscriptions-export-reused.csv' );
		$reused->set_limit( 2 );
		$reused->set_page( 1 );
		$reused->generate_file();
		$reused->set_page( 2 );
		$reused->generate_file();
		// 4 rows written, but the counter double-counts on the reused
		// instance: (page-1)*limit + accumulated rows = 2 + 4.
		$this->assertSame( 6, $reused->get_total_exported() );

		$fresh = new Subscriptions_CSV_Exporter();
		$fresh->set_filename( 'newspack-subscriptions-export-fresh.csv' );
		$fresh->set_limit( 2 );
		$fresh->set_page( 2 );
		$fresh->generate_file();
		$this->assertSame( 4, $fresh->get_total_exported(), 'A fresh instance per page reports the true progress.' );
	}

	/**
	 * The headers + row filter pair supports adding a custom column
	 * (the NPPD-1719 group-columns contract), end to end.
	 */
	public function test_headers_and_row_filters_add_column() {
		$add_header = function ( $columns ) {
			$columns['group_name'] = 'Group Name';
			return $columns;
		};
		$add_cell   = function ( $row, $subscription ) {
			$row['group_name'] = 'Newsroom ' . $subscription->get_id();
			return $row;
		};
		add_filter( 'newspack_subscriptions_export_headers', $add_header );
		add_filter( 'newspack_subscriptions_export_row', $add_cell, 10, 2 );

		$subscription = $this->create_full_subscription( [ 'customer_id' => 0 ] );
		$exporter     = new Subscriptions_CSV_Exporter();

		$this->assertArrayHasKey( 'group_name', $exporter->get_column_names() );
		$row = $exporter->get_row_data( $subscription );
		$this->assertSame( 'Newsroom 42', $row['group_name'] );

		remove_filter( 'newspack_subscriptions_export_headers', $add_header );
		remove_filter( 'newspack_subscriptions_export_row', $add_cell );
	}

	/**
	 * The query-args filter can inject additional constraints.
	 */
	public function test_query_args_filter() {
		$inject = function ( $args, $list_params ) {
			$args['meta_query'][] = [
				'key'   => '_custom',
				'value' => 'yes',
			];
			return $args;
		};
		add_filter( 'newspack_subscriptions_export_query_args', $inject, 10, 2 );
		$args = Subscriptions_CSV_Exporter::build_query_args( [] );
		remove_filter( 'newspack_subscriptions_export_query_args', $inject );

		$this->assertSame( '_custom', $args['meta_query'][0]['key'] );
	}

	/**
	 * Row data stays raw; formula-triggering cells are escaped with a
	 * leading quote only at CSV write time (WC's format_data path).
	 */
	public function test_csv_injection_escaped_on_export() {
		$subscription = $this->create_full_subscription(
			[
				'customer_id'        => 0,
				'billing_first_name' => '=HYPERLINK("https://evil.example","click")',
			]
		);
		$exporter     = new Subscriptions_CSV_Exporter();
		$row          = $exporter->get_row_data( $subscription );

		// Raw in row data.
		$this->assertSame( '=HYPERLINK("https://evil.example","click")', $row['billing_first_name'] );

		// Escaped in CSV output.
		$exporter->test_set_row_data( [ $row ] );
		$csv = $exporter->mock_export_rows();
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
	}

	/**
	 * Preparing data pages via limit/offset (never wcs_get_subscriptions'
	 * broken paged arg), requests a paginated ID query, and sets total_rows
	 * from the count — the percent-complete math follows.
	 */
	public function test_prepare_data_to_export_pages_with_offset() {
		global $wcs_mock_orders_with_meta_query_args;
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->create_full_subscription(
				[
					'id'          => $i,
					'customer_id' => 0,
				]
			);
		}

		$exporter = new Subscriptions_CSV_Exporter();
		$exporter->set_filename( 'newspack-subscriptions-export-offset.csv' );
		$exporter->set_limit( 2 );
		$exporter->set_page( 2 );
		$exporter->prepare_data_to_export();

		$this->assertSame( 2, $wcs_mock_orders_with_meta_query_args['limit'] );
		$this->assertSame( 2, $wcs_mock_orders_with_meta_query_args['offset'] );
		$this->assertTrue( $wcs_mock_orders_with_meta_query_args['paginate'] );
		$this->assertSame( 'ids', $wcs_mock_orders_with_meta_query_args['return'] );

		$rows = $exporter->get_prepared_row_data();
		$this->assertCount( 2, $rows );
		$this->assertSame( 3, $rows[0]['subscription_id'] );
		$this->assertSame( 4, $rows[1]['subscription_id'] );
		$this->assertSame( 5, $exporter->get_mock_total_rows() );

		$exporter->mock_export_rows();
		$this->assertSame( 4, $exporter->get_total_exported() );
		$this->assertSame( 80, $exporter->get_percent_complete() );
	}

	/**
	 * A result set that shrinks mid-run (rows deleted, or a status change
	 * dropping them out of a status-filtered view) must not cut the export
	 * short: the total stays pinned to what page 1 counted, so the run ends on
	 * an empty page — which both the AJAX handler and the CLI report as a
	 * possibly-incomplete snapshot — instead of reporting a truthful-looking
	 * 100% over a half-written CSV.
	 */
	public function test_total_rows_pinned_across_a_shrinking_run() {
		global $subscriptions_database;
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->create_full_subscription(
				[
					'id'          => $i,
					'customer_id' => 0,
				]
			);
		}
		$filename = 'newspack-subscriptions-export-pinned.csv';

		// Page 1 exports 2 of 4 rows.
		$page_one = new Subscriptions_CSV_Exporter();
		$page_one->set_filename( $filename );
		$page_one->set_limit( 2 );
		$page_one->set_page( 1 );
		$page_one->generate_file();
		$this->assertSame( 4, $page_one->get_mock_total_rows() );
		$this->assertSame( 50, $page_one->get_percent_complete() );

		// The two unexported subscriptions leave the filtered set.
		unset( $subscriptions_database[3], $subscriptions_database[4] );

		$page_two = new Subscriptions_CSV_Exporter();
		$page_two->set_filename( $filename );
		$page_two->set_limit( 2 );
		$page_two->set_page( 2 );
		$page_two->generate_file();

		// Recounting live would give a total of 2 against 2 exported rows —
		// 100%, export "complete", half the rows missing.
		$this->assertSame( 4, $page_two->get_mock_total_rows() );
		$this->assertSame( 50, $page_two->get_percent_complete() );
		$this->assertTrue( $page_two->page_was_empty(), 'The offset walked past the shrunken set, so the run must end on an empty page.' );
		$this->assertTrue( $page_two->ended_short() );
	}

	/**
	 * The shrink above is exactly one page wide, which the percentage alone
	 * would also have caught. A *sub-page* shrink is the likelier case and the
	 * one it hides: the WC counter assumes every prior page was full, so on the
	 * terminal empty page it catches back up to the pinned total and reports
	 * exactly 100% over a file that is a row short. ended_short() is what both
	 * surfaces gate their incomplete-snapshot warning on for that reason.
	 */
	public function test_a_sub_page_shrink_still_ends_short() {
		global $subscriptions_database;
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->create_full_subscription(
				[
					'id'          => $i,
					'customer_id' => 0,
				]
			);
		}
		$filename = 'newspack-subscriptions-export-partial.csv';

		$page_one = new Subscriptions_CSV_Exporter();
		$page_one->set_filename( $filename );
		$page_one->set_limit( 2 );
		$page_one->set_page( 1 );
		$page_one->generate_file();
		$this->assertSame( 50, $page_one->get_percent_complete() );

		// A single already-exported row leaves the set, sliding the last two
		// rows back into offsets the run has already walked past.
		unset( $subscriptions_database[1] );

		// Page 2 (offset 2 over 3 remaining rows) writes one row instead of two.
		$page_two = new Subscriptions_CSV_Exporter();
		$page_two->set_filename( $filename );
		$page_two->set_limit( 2 );
		$page_two->set_page( 2 );
		$page_two->generate_file();
		$this->assertSame( 3, $page_two->get_total_exported() );
		$this->assertSame( 75, $page_two->get_percent_complete() );
		$this->assertFalse( $page_two->ended_short(), 'A short page still made progress; only an empty one ends the run.' );

		// Page 3 walks past the end. Three rows are in the file, one row that
		// still exists was never exported — and the percentage says 100.
		$page_three = new Subscriptions_CSV_Exporter();
		$page_three->set_filename( $filename );
		$page_three->set_limit( 2 );
		$page_three->set_page( 3 );
		$page_three->generate_file();
		$this->assertSame( 100, $page_three->get_percent_complete(), 'The counter assumes full pages, so it catches back up to the pinned total.' );
		$this->assertTrue( $page_three->ended_short(), 'Gating on the percentage here would report a short file as complete.' );
	}

	/**
	 * The pinned total survives a run longer than a single transient lifetime:
	 * every page slides the expiry forward, so a large export doesn't silently
	 * revert to the live recount the pin exists to prevent.
	 */
	public function test_pinned_total_expiry_slides_with_each_page() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient expiry is not readable from the options table under a persistent object cache.' );
		}
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->create_full_subscription(
				[
					'id'          => $i,
					'customer_id' => 0,
				]
			);
		}
		$filename  = 'newspack-subscriptions-export-sliding.csv';
		$transient = '_transient_timeout_newspack_export_total_' . md5( $filename );

		$page_one = new Subscriptions_CSV_Exporter();
		$page_one->set_filename( $filename );
		$page_one->set_limit( 2 );
		$page_one->set_page( 1 );
		$page_one->generate_file();

		// Wind the expiry back to a minute from now, as a long run would.
		update_option( $transient, time() + MINUTE_IN_SECONDS );

		$page_two = new Subscriptions_CSV_Exporter();
		$page_two->set_filename( $filename );
		$page_two->set_limit( 2 );
		$page_two->set_page( 2 );
		$page_two->generate_file();

		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, (int) get_option( $transient ), 'Reading the pin must push its expiry back out.' );

		$page_two->clear_pinned_total();
		$this->assertFalse( get_transient( 'newspack_export_total_' . md5( $filename ) ) );
	}
}
