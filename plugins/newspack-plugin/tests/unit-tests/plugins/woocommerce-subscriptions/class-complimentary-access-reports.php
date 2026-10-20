<?php
/**
 * Tests keeping complimentary subscriptions and orders out of reports.
 *
 * @package Newspack\Tests
 */

use Newspack\Complimentary_Access;
use Newspack\Complimentary_Access_Reports;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';
require_once __DIR__ . '/../../../../includes/plugins/woocommerce-subscriptions/class-complimentary-access.php';
require_once __DIR__ . '/../../../../includes/plugins/woocommerce-subscriptions/class-complimentary-access-reports.php';

/**
 * The report queries run against the posts table here, as on a site that doesn't use
 * WooCommerce's order tables: three active subscriptions, one of them stamped.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Complimentary_Access_Reports extends WP_UnitTestCase {
	/**
	 * Paid subscription IDs.
	 *
	 * @var int[]
	 */
	private $paid_ids = [];

	/**
	 * Stamped subscription ID.
	 *
	 * @var int
	 */
	private $comp_id;

	/**
	 * Create the subscriptions.
	 */
	public function set_up() {
		parent::set_up();
		$this->paid_ids = [ $this->subscription_post(), $this->subscription_post() ];
		$this->comp_id = $this->subscription_post();
		update_post_meta( $this->comp_id, Complimentary_Access::META_KEY, 'yes' );
	}

	/**
	 * Insert an active subscription as a post.
	 *
	 * @return int
	 */
	private function subscription_post() {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			[
				'post_type'   => 'shop_subscription',
				'post_status' => 'wc-active',
				'post_title'  => 'Subscription',
			]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * A report query that checks the subscription's status inside a `CASE` as well as its type
	 * in `WHERE` excludes comps from every column, not just the one the `CASE` feeds.
	 */
	public function test_report_sql_excludes_comps_from_the_whole_query() {
		global $wpdb;
		$query = "SELECT COUNT(subscription_posts.ID) AS total,
				SUM(CASE WHEN subscription_posts.post_status IN ( 'wc-active' ) THEN 1 ELSE 0 END) AS active
			FROM {$wpdb->posts} subscription_posts
			WHERE subscription_posts.post_type = 'shop_subscription'
				AND subscription_posts.post_status NOT IN ('trash')";

		$before = $wpdb->get_row( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$after  = $wpdb->get_row( Complimentary_Access_Reports::exclude_from_report_sql( $query, 'subscription_posts' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$this->assertSame( [ '3', '3' ], [ $before->total, $before->active ] );
		$this->assertSame( [ '2', '2' ], [ $after->total, $after->active ] );
	}

	/**
	 * A query that checks only the status, as the dashboard's cancellation count does, gets the
	 * condition there. A query with neither check is left as it is.
	 */
	public function test_report_sql_falls_back_to_the_status_check() {
		global $wpdb;
		$status_only = "SELECT COUNT(DISTINCT wcsubs.ID) FROM {$wpdb->posts} AS wcsubs
			JOIN {$wpdb->posts} AS other ON other.ID = wcsubs.ID AND wcsubs.post_status NOT IN ( 'trash', 'auto-draft' )";
		$this->assertSame( '2', $wpdb->get_var( Complimentary_Access_Reports::exclude_from_report_sql( $status_only, 'wcsubs' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$unrecognized = "SELECT COUNT(*) FROM {$wpdb->posts} AS wcsubs";
		$this->assertSame( $unrecognized, Complimentary_Access_Reports::exclude_from_report_sql( $unrecognized, 'wcsubs' ) );
	}

	/**
	 * Legacy WooCommerce reports build their query in parts on the `posts` alias.
	 */
	public function test_order_report_query_excludes_comps() {
		global $wpdb;
		$query = Complimentary_Access_Reports::filter_order_report_query(
			[
				'select' => 'SELECT COUNT(posts.ID)',
				'from'   => "FROM {$wpdb->posts} AS posts",
				'where'  => "WHERE posts.post_type IN ( 'shop_subscription' )",
			]
		);
		$this->assertSame( '2', $wpdb->get_var( implode( ' ', $query ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * "Events by date" rows lose the comp from both the count and the ID list behind the
	 * report's links; a row of only comps drops to zero but stays, since the subscriber links
	 * address rows by position.
	 */
	public function test_event_rows_lose_comps() {
		$rows = Complimentary_Access_Reports::filter_subscription_event_rows(
			[
				(object) [
					'count'            => 3,
					'subscription_ids' => implode( ',', [ $this->paid_ids[0], $this->comp_id, $this->paid_ids[1] ] ),
				],
				(object) [
					'count'            => 1,
					'subscription_ids' => (string) $this->comp_id,
				],
			]
		);

		$this->assertSame( 2, $rows[0]->count );
		$this->assertSame( implode( ',', $this->paid_ids ), $rows[0]->subscription_ids );
		$this->assertSame( 0, $rows[1]->count );
		$this->assertSame( '', $rows[1]->subscription_ids );
	}

	/**
	 * The ID lists go back to the connection's original `GROUP_CONCAT()` length, cut at a whole
	 * ID, so the week-long report cache is no larger than before the limit was raised.
	 */
	public function test_event_row_lists_return_to_the_original_length() {
		$limit_property = new ReflectionProperty( Complimentary_Access_Reports::class, 'original_group_concat_limit' );
		$limit_property->setAccessible( true );
		$limit_property->setValue( null, 10 );
		try {
			$rows = Complimentary_Access_Reports::filter_subscription_event_rows(
				[
					(object) [
						'count'            => 4,
						'subscription_ids' => "1001,1002,{$this->comp_id},1003",
					],
				]
			);
		} finally {
			$limit_property->setValue( null, 0 );
		}

		$this->assertSame( 3, $rows[0]->count, 'The count comes from the whole list.' );
		$this->assertSame( '1001,1002', $rows[0]->subscription_ids );
	}

	/**
	 * "Upcoming recurring revenue" projects renewals from parallel per-subscription lists, so
	 * the comp's entry leaves every list at the same position. Rows without a comp keep their
	 * values exactly, and a row of only comps goes.
	 */
	public function test_upcoming_revenue_rows_keep_their_lists_aligned() {
		[ $paid, $other_paid ] = $this->paid_ids;
		$untouched             = (object) [
			'recurring_total'     => '10.00000000',
			'total_renewals'      => '1',
			'subscription_ids'    => (string) $other_paid,
			'billing_intervals'   => '1',
			'billing_periods'     => 'month',
			'scheduled_ends'      => '0',
			'subscription_totals' => '10.00',
		];
		$rows                  = Complimentary_Access_Reports::filter_upcoming_recurring_revenue_rows(
			[
				'2026-11-01' => (object) [
					'recurring_total'     => '15.00000000',
					'total_renewals'      => '2',
					'subscription_ids'    => "{$this->comp_id},{$paid}",
					'billing_intervals'   => '3,1',
					'billing_periods'     => 'year,month',
					'scheduled_ends'      => '2027-01-01 00:00:00,0',
					'subscription_totals' => '5.00,10.00',
				],
				'2026-11-02' => (object) [
					'recurring_total'     => '0',
					'total_renewals'      => '1',
					'subscription_ids'    => (string) $this->comp_id,
					'billing_intervals'   => '1',
					'billing_periods'     => 'month',
					'scheduled_ends'      => '0',
					'subscription_totals' => '0.00',
				],
				'2026-11-03' => clone $untouched,
			]
		);

		$this->assertSame( [ '2026-11-01', '2026-11-03' ], array_keys( $rows ) );
		$this->assertSame(
			[ (string) $paid, '1', 'month', '0', '10.00', 1, 10.0 ],
			[
				$rows['2026-11-01']->subscription_ids,
				$rows['2026-11-01']->billing_intervals,
				$rows['2026-11-01']->billing_periods,
				$rows['2026-11-01']->scheduled_ends,
				$rows['2026-11-01']->subscription_totals,
				$rows['2026-11-01']->total_renewals,
				$rows['2026-11-01']->recurring_total,
			]
		);
		$this->assertSame( (array) $untouched, (array) $rows['2026-11-03'] );
	}

	/**
	 * The "Complimentary" filter narrows a list stored as posts; the list keeps comps without it.
	 */
	public function test_list_filter_narrows_the_posts_list() {
		set_current_screen( 'edit-shop_subscription' );
		$list_ids = function () {
			$query                  = new WP_Query();
			$GLOBALS['wp_the_query'] = $query;
			$query->set( 'post_type', 'shop_subscription' );
			$query->set( 'post_status', 'wc-active' );
			$query->set( 'fields', 'ids' );
			Complimentary_Access_Reports::filter_legacy_list_query( $query );
			$ids = array_map( 'intval', $query->get_posts() );
			sort( $ids );
			return $ids;
		};

		$this->assertCount( 3, $list_ids() );
		try {
			$_GET[ Complimentary_Access_Reports::LIST_FILTER_PARAM ] = 'only';
			$this->assertSame( [ $this->comp_id ], $list_ids() );
			$_GET[ Complimentary_Access_Reports::LIST_FILTER_PARAM ] = 'exclude';
			$this->assertSame( $this->paid_ids, $list_ids() );
		} finally {
			unset( $_GET[ Complimentary_Access_Reports::LIST_FILTER_PARAM ] );
		}
	}

	/**
	 * Analytics records a stamped order under a status its reports leave out.
	 */
	public function test_analytics_records_comps_under_an_excluded_status() {
		$comp_order = new WC_Order( [ 'status' => 'completed' ] );
		$comp_order->update_meta_data( Complimentary_Access::META_KEY, 'yes' );
		$paid_order = new WC_Order( [ 'status' => 'completed' ] );

		$this->assertSame( 'wc-complimentary', Complimentary_Access_Reports::filter_analytics_order_stats( [ 'status' => 'wc-completed' ], $comp_order )['status'] );
		$this->assertSame( 'wc-completed', Complimentary_Access_Reports::filter_analytics_order_stats( [ 'status' => 'wc-completed' ], $paid_order )['status'] );
		$this->assertContains( 'complimentary', Complimentary_Access_Reports::filter_analytics_excluded_statuses( [ 'failed' ] ) );
	}

	/**
	 * The export leaves comps out unless asked; an export from a list filtered to comps keeps
	 * that filter.
	 */
	public function test_export_excludes_comps_by_default() {
		$excluded = Complimentary_Access::get_meta_query_clause( 'exclude' );
		$only     = Complimentary_Access::get_meta_query_clause( 'only' );

		$this->assertSame( [ $excluded ], Complimentary_Access_Reports::filter_export_query_args( [], [], [] )['meta_query'] );
		$this->assertArrayNotHasKey( 'meta_query', Complimentary_Access_Reports::filter_export_query_args( [], [], [ 'include_complimentary' => true ] ) );
		$this->assertSame( [ $only ], Complimentary_Access_Reports::filter_export_query_args( [], [ Complimentary_Access_Reports::LIST_FILTER_PARAM => 'only' ], [] )['meta_query'] );
	}
}
