<?php
/**
 * Tests for Conversion Journey (Tab 3) storage methods and metric wrappers
 * (NPPD-1609 Phase 2B).
 *
 * What these tests cover and why:
 *
 *   CONTRACT / INTERFACE CHECKS — verify the four storage classes implement
 *   the expected Storage_Interface and Donors_Storage_Interface method sets.
 *
 *   EMPTY-LIST SHORT-CIRCUIT GUARDS — verify the PHP early-return guards
 *   (before any SQL is issued) in count_* / get_subscriber_donors_in_window /
 *   count_completed_donation_order_customers_by_customer_ids return 0 for an
 *   empty input list. These exercise real PHP logic, not the database.
 *
 *   METRIC-WRAPPER DELEGATION + CACHING — verify Subscribers_Metric and
 *   Donors_Metric delegate to storage, cache results in transients, and pass
 *   list parameters straight through. These use PHPUnit mock storage objects
 *   injected via reflection, so no WC tables are needed.
 *
 * What these tests do NOT cover:
 *
 *   The SQL bodies of get_at_risk_subscribers(), get_active_non_donation_
 *   subscriber_customer_ids(), get_stale_registered_users(),
 *   get_subscriber_donors_in_window() (non-empty list),
 *   count_completed_donation_order_customers_by_customer_ids() (non-empty list)
 *   and every other method that queries wc_orders / posts / wc_order_product_
 *   lookup cannot be behaviorally unit-tested here: the test bootstrap does not
 *   install WooCommerce DB tables (consistent with how the existing Subscribers
 *   and Donors storage methods are handled — they have no SQL-body unit tests
 *   either). Behavioral correctness is verified via the live-environment smoke
 *   test. A future DB-backed integration harness could add that coverage without
 *   touching this file.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Donors_Metric;
use Newspack\Insights\Donors_Storage_Interface;
use Newspack\Insights\HPOS_Donors_Storage;
use Newspack\Insights\HPOS_Storage;
use Newspack\Insights\Legacy_Donors_Storage;
use Newspack\Insights\Legacy_Storage;
use Newspack\Insights\Storage_Interface;
use Newspack\Insights\Subscribers_Metric;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

/**
 * Tests for Conversion Journey storage methods and metric wrappers.
 *
 * @group insights
 */
class Test_Conversion_Journey_Storage extends WP_UnitTestCase {

	/**
	 * Make a UTC DateTimeImmutable from a YYYY-MM-DD string.
	 *
	 * @param string $ymd Date string.
	 * @return DateTimeImmutable
	 */
	private function make_date( string $ymd ): DateTimeImmutable {
		return new DateTimeImmutable( $ymd, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Create a Subscribers_Metric with an injected mock storage via reflection.
	 *
	 * @param Storage_Interface $mock_storage Mock storage to inject.
	 * @return Subscribers_Metric
	 */
	private function make_subscribers_metric( Storage_Interface $mock_storage ): Subscribers_Metric {
		$metric = new Subscribers_Metric();

		$ref = new \ReflectionProperty( Subscribers_Metric::class, 'storage' );
		$ref->setAccessible( true );
		$ref->setValue( $metric, $mock_storage );

		return $metric;
	}

	/**
	 * Create a Donors_Metric with an injected mock storage via reflection.
	 *
	 * @param Donors_Storage_Interface $mock_storage Mock storage to inject.
	 * @return Donors_Metric
	 */
	private function make_donors_metric( Donors_Storage_Interface $mock_storage ): Donors_Metric {
		$metric = new Donors_Metric();

		$ref = new \ReflectionProperty( Donors_Metric::class, 'storage' );
		$ref->setAccessible( true );
		$ref->setValue( $metric, $mock_storage );

		return $metric;
	}

	/**
	 * Clean transients between tests.
	 */
	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_newspack_insights_tab6_%'
			   OR option_name LIKE '_transient_timeout_newspack_insights_tab6_%'
			   OR option_name LIKE '_transient_newspack_insights_tab7_%'
			   OR option_name LIKE '_transient_timeout_newspack_insights_tab7_%'"
		);
		parent::tear_down();
	}

	// =========================================================================
	// Storage_Interface — new method signature verification.
	// =========================================================================

	/**
	 * HPOS_Storage implements the three new Storage_Interface methods.
	 */
	public function test_hpos_storage_implements_new_subscriber_interface_methods(): void {
		$storage = new HPOS_Storage( [] );
		$this->assertInstanceOf( Storage_Interface::class, $storage );
		$this->assertTrue( method_exists( $storage, 'get_at_risk_subscribers' ) );
		$this->assertTrue( method_exists( $storage, 'get_active_non_donation_subscriber_customer_ids' ) );
		$this->assertTrue( method_exists( $storage, 'count_active_non_donation_subscribers_by_customer_ids' ) );
		$this->assertTrue( method_exists( $storage, 'get_stale_registered_users' ) );
	}

	/**
	 * Legacy_Storage implements the three new Storage_Interface methods.
	 */
	public function test_legacy_storage_implements_new_subscriber_interface_methods(): void {
		$storage = new Legacy_Storage( [] );
		$this->assertInstanceOf( Storage_Interface::class, $storage );
		$this->assertTrue( method_exists( $storage, 'get_at_risk_subscribers' ) );
		$this->assertTrue( method_exists( $storage, 'get_active_non_donation_subscriber_customer_ids' ) );
		$this->assertTrue( method_exists( $storage, 'count_active_non_donation_subscribers_by_customer_ids' ) );
		$this->assertTrue( method_exists( $storage, 'get_stale_registered_users' ) );
	}

	/**
	 * HPOS_Donors_Storage implements the two new Donors_Storage_Interface methods.
	 */
	public function test_hpos_donors_storage_implements_new_donor_interface_methods(): void {
		$storage = new HPOS_Donors_Storage( [] );
		$this->assertInstanceOf( Donors_Storage_Interface::class, $storage );
		$this->assertTrue( method_exists( $storage, 'get_subscriber_donors_in_window' ) );
		$this->assertTrue( method_exists( $storage, 'count_completed_donation_order_customers_by_customer_ids' ) );
	}

	/**
	 * Legacy_Donors_Storage implements the two new Donors_Storage_Interface methods.
	 */
	public function test_legacy_donors_storage_implements_new_donor_interface_methods(): void {
		$storage = new Legacy_Donors_Storage( [] );
		$this->assertInstanceOf( Donors_Storage_Interface::class, $storage );
		$this->assertTrue( method_exists( $storage, 'get_subscriber_donors_in_window' ) );
		$this->assertTrue( method_exists( $storage, 'count_completed_donation_order_customers_by_customer_ids' ) );
	}

	// =========================================================================
	// Empty-list short-circuit — storage layer (no DB needed).
	// =========================================================================

	/**
	 * HPOS: count_active_non_donation_subscribers_by_customer_ids([]) → 0.
	 */
	public function test_hpos_count_active_non_donation_empty_list_returns_zero(): void {
		$storage = new HPOS_Storage( [] );
		$this->assertSame( 0, $storage->count_active_non_donation_subscribers_by_customer_ids( [] ) );
	}

	/**
	 * Legacy: count_active_non_donation_subscribers_by_customer_ids([]) → 0.
	 */
	public function test_legacy_count_active_non_donation_empty_list_returns_zero(): void {
		$storage = new Legacy_Storage( [] );
		$this->assertSame( 0, $storage->count_active_non_donation_subscribers_by_customer_ids( [] ) );
	}

	/**
	 * HPOS: get_subscriber_donors_in_window([], ...) → 0.
	 */
	public function test_hpos_get_subscriber_donors_in_window_empty_list_returns_zero(): void {
		$storage = new HPOS_Donors_Storage( [] );
		$this->assertSame( 0, $storage->get_subscriber_donors_in_window( [], $this->make_date( '2026-01-01' ), $this->make_date( '2026-01-31' ) ) );
	}

	/**
	 * Legacy: get_subscriber_donors_in_window([], ...) → 0.
	 */
	public function test_legacy_get_subscriber_donors_in_window_empty_list_returns_zero(): void {
		$storage = new Legacy_Donors_Storage( [] );
		$this->assertSame( 0, $storage->get_subscriber_donors_in_window( [], $this->make_date( '2026-01-01' ), $this->make_date( '2026-01-31' ) ) );
	}

	/**
	 * HPOS: count_completed_donation_order_customers_by_customer_ids([]) → 0.
	 */
	public function test_hpos_count_completed_donation_empty_list_returns_zero(): void {
		$storage = new HPOS_Donors_Storage( [] );
		$this->assertSame( 0, $storage->count_completed_donation_order_customers_by_customer_ids( [] ) );
	}

	/**
	 * Legacy: count_completed_donation_order_customers_by_customer_ids([]) → 0.
	 */
	public function test_legacy_count_completed_donation_empty_list_returns_zero(): void {
		$storage = new Legacy_Donors_Storage( [] );
		$this->assertSame( 0, $storage->count_completed_donation_order_customers_by_customer_ids( [] ) );
	}

	// =========================================================================
	// Metric wrapper — Subscribers_Metric delegates correctly.
	// =========================================================================

	/**
	 * Subscribers_Metric::get_at_risk_subscribers() delegates to storage and caches.
	 */
	public function test_subscribers_metric_get_at_risk_subscribers_delegates(): void {
		$mock = $this->createMock( Storage_Interface::class );
		$mock->expects( $this->once() )  // called once; second call is cached.
			->method( 'get_at_risk_subscribers' )
			->willReturn( 7 );

		$metric = $this->make_subscribers_metric( $mock );

		$first  = $metric->get_at_risk_subscribers();
		$second = $metric->get_at_risk_subscribers(); // served from transient.

		$this->assertSame( 7, $first );
		$this->assertSame( 7, $second );
	}

	/**
	 * Subscribers_Metric::get_winback_subscribers_in_window() delegates to storage
	 * and caches per window — a repeat call for the same window is served from the
	 * transient; a distinct window is a cache miss that hits storage again.
	 */
	public function test_subscribers_metric_get_winback_subscribers_delegates(): void {
		$start = $this->make_date( '2026-06-01' );
		$end   = $this->make_date( '2026-06-30' );

		$mock = $this->createMock( Storage_Interface::class );
		$mock->expects( $this->exactly( 2 ) ) // once per distinct window; the repeat is cached.
			->method( 'get_winback_subscribers_in_window' )
			->willReturn( 18 );

		$metric = $this->make_subscribers_metric( $mock );

		$first  = $metric->get_winback_subscribers_in_window( $start, $end );
		$second = $metric->get_winback_subscribers_in_window( $start, $end ); // cached — no storage call.
		$other  = $metric->get_winback_subscribers_in_window( $start, $this->make_date( '2026-07-31' ) ); // distinct window → storage call.

		$this->assertSame( 18, $first );
		$this->assertSame( 18, $second );
		$this->assertSame( 18, $other );
	}

	/**
	 * Subscribers_Metric::get_active_non_donation_subscriber_customer_ids() delegates.
	 */
	public function test_subscribers_metric_get_customer_ids_delegates(): void {
		$mock = $this->createMock( Storage_Interface::class );
		$mock->expects( $this->once() )
			->method( 'get_active_non_donation_subscriber_customer_ids' )
			->willReturn( [ 1, 2, 3 ] );

		$metric = $this->make_subscribers_metric( $mock );

		$ids = $metric->get_active_non_donation_subscriber_customer_ids();
		$this->assertSame( [ 1, 2, 3 ], $ids );
	}

	/**
	 * List-param count method delegates directly (no caching — list varies per call).
	 */
	public function test_subscribers_metric_count_by_ids_delegates_directly(): void {
		$mock = $this->createMock( Storage_Interface::class );
		// Two separate calls with different lists → storage is called twice.
		$mock->expects( $this->exactly( 2 ) )
			->method( 'count_active_non_donation_subscribers_by_customer_ids' )
			->willReturnOnConsecutiveCalls( 3, 1 );

		$metric = $this->make_subscribers_metric( $mock );

		$this->assertSame( 3, $metric->count_active_non_donation_subscribers_by_customer_ids( [ 1, 2, 3 ] ) );
		$this->assertSame( 1, $metric->count_active_non_donation_subscribers_by_customer_ids( [ 99 ] ) );
	}

	/**
	 * Empty list short-circuits to 0 via storage's own guard.
	 */
	public function test_subscribers_metric_count_by_ids_empty_list(): void {
		$mock = $this->createMock( Storage_Interface::class );
		$mock->method( 'count_active_non_donation_subscribers_by_customer_ids' )
			->willReturn( 0 );

		$metric = $this->make_subscribers_metric( $mock );
		$this->assertSame( 0, $metric->count_active_non_donation_subscribers_by_customer_ids( [] ) );
	}

	/**
	 * Stale-registered count delegates to storage and is cached.
	 */
	public function test_subscribers_metric_get_stale_registered_users_delegates(): void {
		$mock = $this->createMock( Storage_Interface::class );
		$mock->expects( $this->once() )
			->method( 'get_stale_registered_users' )
			->willReturn( 42 );

		$metric = $this->make_subscribers_metric( $mock );

		$first  = $metric->get_stale_registered_users();
		$second = $metric->get_stale_registered_users(); // served from transient.

		$this->assertSame( 42, $first );
		$this->assertSame( 42, $second );
	}

	// =========================================================================
	// Metric wrapper — Donors_Metric delegates correctly.
	// =========================================================================

	/**
	 * Donors_Metric::get_subscriber_donors_in_window() delegates directly (no caching).
	 */
	public function test_donors_metric_get_subscriber_donors_in_window_delegates(): void {
		$mock = $this->createMock( Donors_Storage_Interface::class );
		// Called twice with different subscriber lists → storage called twice.
		$mock->expects( $this->exactly( 2 ) )
			->method( 'get_subscriber_donors_in_window' )
			->willReturnOnConsecutiveCalls( 5, 2 );

		$metric = $this->make_donors_metric( $mock );
		$start  = $this->make_date( '2026-03-01' );
		$end    = $this->make_date( '2026-03-31' );

		$this->assertSame( 5, $metric->get_subscriber_donors_in_window( [ 1, 2, 3 ], $start, $end ) );
		$this->assertSame( 2, $metric->get_subscriber_donors_in_window( [ 10, 11 ], $start, $end ) );
	}

	/**
	 * Empty subscriber list short-circuits to 0 in get_subscriber_donors_in_window.
	 */
	public function test_donors_metric_get_subscriber_donors_in_window_empty_list(): void {
		$mock = $this->createMock( Donors_Storage_Interface::class );
		$mock->method( 'get_subscriber_donors_in_window' )->willReturn( 0 );

		$metric = $this->make_donors_metric( $mock );
		$start  = $this->make_date( '2026-03-01' );
		$end    = $this->make_date( '2026-03-31' );

		$this->assertSame( 0, $metric->get_subscriber_donors_in_window( [], $start, $end ) );
	}

	/**
	 * Completed-donation count delegates directly (no caching — list varies).
	 */
	public function test_donors_metric_count_completed_donation_delegates(): void {
		$mock = $this->createMock( Donors_Storage_Interface::class );
		$mock->expects( $this->exactly( 2 ) )
			->method( 'count_completed_donation_order_customers_by_customer_ids' )
			->willReturnOnConsecutiveCalls( 4, 0 );

		$metric = $this->make_donors_metric( $mock );

		$this->assertSame( 4, $metric->count_completed_donation_order_customers_by_customer_ids( [ 1, 2, 3, 4 ] ) );
		$this->assertSame( 0, $metric->count_completed_donation_order_customers_by_customer_ids( [] ) );
	}

	/**
	 * HPOS_Storage exposes get_first_subscription_order_dates().
	 */
	public function test_hpos_storage_implements_first_subscription_order_dates(): void {
		$storage = new HPOS_Storage( [] );
		$this->assertTrue( method_exists( $storage, 'get_first_subscription_order_dates' ) );
	}

	/**
	 * Legacy_Storage exposes get_first_subscription_order_dates().
	 */
	public function test_legacy_storage_implements_first_subscription_order_dates(): void {
		$storage = new Legacy_Storage( [] );
		$this->assertTrue( method_exists( $storage, 'get_first_subscription_order_dates' ) );
	}

	/**
	 * HPOS: get_first_subscription_order_dates([]) → [] (no DB round-trip).
	 */
	public function test_hpos_get_first_subscription_order_dates_empty_list_returns_empty_array(): void {
		$storage = new HPOS_Storage( [] );
		$this->assertSame( [], $storage->get_first_subscription_order_dates( [] ) );
	}

	/**
	 * Legacy: get_first_subscription_order_dates([]) → [].
	 */
	public function test_legacy_get_first_subscription_order_dates_empty_list_returns_empty_array(): void {
		$storage = new Legacy_Storage( [] );
		$this->assertSame( [], $storage->get_first_subscription_order_dates( [] ) );
	}

	/**
	 * Subscribers_Metric::get_first_subscription_order_dates() delegates directly
	 * (list-param — NOT cached, so two distinct lists hit storage twice).
	 */
	public function test_subscribers_metric_get_first_subscription_order_dates_delegates(): void {
		$dates = [
			1 => $this->make_date( '2026-01-15' ),
			2 => $this->make_date( '2026-02-20' ),
		];
		$mock = $this->createMock( Storage_Interface::class );
		$mock->expects( $this->exactly( 2 ) )
			->method( 'get_first_subscription_order_dates' )
			->willReturnOnConsecutiveCalls( $dates, [] );

		$metric = $this->make_subscribers_metric( $mock );

		$this->assertSame( $dates, $metric->get_first_subscription_order_dates( [ 1, 2 ] ) );
		$this->assertSame( [], $metric->get_first_subscription_order_dates( [ 99 ] ) );
	}

	/**
	 * HPOS_Donors_Storage exposes get_first_donation_order_dates().
	 */
	public function test_hpos_donors_storage_implements_first_donation_order_dates(): void {
		$storage = new HPOS_Donors_Storage( [] );
		$this->assertTrue( method_exists( $storage, 'get_first_donation_order_dates' ) );
	}

	/**
	 * Legacy_Donors_Storage exposes get_first_donation_order_dates().
	 */
	public function test_legacy_donors_storage_implements_first_donation_order_dates(): void {
		$storage = new Legacy_Donors_Storage( [] );
		$this->assertTrue( method_exists( $storage, 'get_first_donation_order_dates' ) );
	}

	/**
	 * HPOS: get_first_donation_order_dates([]) → [] (no DB round-trip).
	 */
	public function test_hpos_get_first_donation_order_dates_empty_list_returns_empty_array(): void {
		$storage = new HPOS_Donors_Storage( [] );
		$this->assertSame( [], $storage->get_first_donation_order_dates( [] ) );
	}

	/**
	 * Legacy: get_first_donation_order_dates([]) → [].
	 */
	public function test_legacy_get_first_donation_order_dates_empty_list_returns_empty_array(): void {
		$storage = new Legacy_Donors_Storage( [] );
		$this->assertSame( [], $storage->get_first_donation_order_dates( [] ) );
	}

	/**
	 * Donors_Metric::get_first_donation_order_dates() delegates directly
	 * (list-param — NOT cached, so two distinct lists hit storage twice).
	 */
	public function test_donors_metric_get_first_donation_order_dates_delegates(): void {
		$dates = [
			3 => $this->make_date( '2026-03-10' ),
			4 => $this->make_date( '2026-04-05' ),
		];
		$mock = $this->createMock( Donors_Storage_Interface::class );
		$mock->expects( $this->exactly( 2 ) )
			->method( 'get_first_donation_order_dates' )
			->willReturnOnConsecutiveCalls( $dates, [] );

		$metric = $this->make_donors_metric( $mock );

		$this->assertSame( $dates, $metric->get_first_donation_order_dates( [ 3, 4 ] ) );
		$this->assertSame( [], $metric->get_first_donation_order_dates( [ 99 ] ) );
	}

	// =========================================================================
	// Stale-registered: Subscribers_Metric wrapper delegates correctly.
	// The actual SQL in the storage classes queries WC HPOS/legacy tables
	// which are not present in the PHPUnit test environment. DB-backed
	// correctness is verified by the live-environment smoke test; here we
	// verify the metric-layer delegation and caching via a mock storage.
	// =========================================================================

	/**
	 * Stale-registered count via mock storage returns the correct int.
	 */
	public function test_subscribers_metric_stale_registered_users_count_is_correct(): void {
		$mock = $this->createMock( Storage_Interface::class );
		$mock->method( 'get_stale_registered_users' )->willReturn( 120 );

		$metric = $this->make_subscribers_metric( $mock );
		$this->assertSame( 120, $metric->get_stale_registered_users() );
	}

	/**
	 * Stale-registered count via mock storage returns zero when no stale readers.
	 */
	public function test_subscribers_metric_stale_registered_users_zero(): void {
		$mock = $this->createMock( Storage_Interface::class );
		$mock->method( 'get_stale_registered_users' )->willReturn( 0 );

		$metric = $this->make_subscribers_metric( $mock );
		$this->assertSame( 0, $metric->get_stale_registered_users() );
	}

	/**
	 * HPOS storages expose the new windowed-record methods.
	 */
	public function test_storages_implement_windowed_record_methods(): void {
		$this->assertTrue( method_exists( new HPOS_Storage( [] ), 'get_new_subscriber_records_in_window' ) );
		$this->assertTrue( method_exists( new Legacy_Storage( [] ), 'get_new_subscriber_records_in_window' ) );
		$this->assertTrue( method_exists( new HPOS_Donors_Storage( [] ), 'get_new_donor_records_in_window' ) );
		$this->assertTrue( method_exists( new Legacy_Donors_Storage( [] ), 'get_new_donor_records_in_window' ) );
	}

	/**
	 * Subscribers_Metric::get_new_subscriber_records_in_window delegates (list-param, NOT cached).
	 */
	public function test_subscribers_metric_new_subscriber_records_delegates(): void {
		$recs = [
			[
				'customer_id' => 1,
				'ts'          => 1700000000,
			],
		];
		$mock = $this->createMock( Storage_Interface::class );
		$mock->expects( $this->exactly( 2 ) )
			->method( 'get_new_subscriber_records_in_window' )
			->willReturnOnConsecutiveCalls( $recs, [] );
		$metric = $this->make_subscribers_metric( $mock );
		$this->assertSame( $recs, $metric->get_new_subscriber_records_in_window( $this->make_date( '2026-03-01' ), $this->make_date( '2026-03-31' ) ) );
		$this->assertSame( [], $metric->get_new_subscriber_records_in_window( $this->make_date( '2026-04-01' ), $this->make_date( '2026-04-30' ) ) );
	}

	/**
	 * Donors_Metric::get_new_donor_records_in_window delegates (list-param, NOT cached).
	 */
	public function test_donors_metric_new_donor_records_delegates(): void {
		$recs = [
			[
				'customer_id' => 5,
				'ts'          => 1700000500,
			],
		];
		$mock = $this->createMock( Donors_Storage_Interface::class );
		$mock->expects( $this->exactly( 2 ) )
			->method( 'get_new_donor_records_in_window' )
			->willReturnOnConsecutiveCalls( $recs, [] );
		$metric = $this->make_donors_metric( $mock );
		$this->assertSame( $recs, $metric->get_new_donor_records_in_window( $this->make_date( '2026-03-01' ), $this->make_date( '2026-03-31' ) ) );
		$this->assertSame( [], $metric->get_new_donor_records_in_window( $this->make_date( '2026-04-01' ), $this->make_date( '2026-04-30' ) ) );
	}

	/**
	 * Storages expose the conversion-lag methods.
	 */
	public function test_storages_implement_conversion_lag_methods(): void {
		$this->assertTrue( method_exists( new HPOS_Storage( [] ), 'get_subscription_conversion_lags' ) );
		$this->assertTrue( method_exists( new Legacy_Storage( [] ), 'get_subscription_conversion_lags' ) );
		$this->assertTrue( method_exists( new HPOS_Donors_Storage( [] ), 'get_donation_conversion_lags' ) );
		$this->assertTrue( method_exists( new Legacy_Donors_Storage( [] ), 'get_donation_conversion_lags' ) );
	}

	/**
	 * Subscribers_Metric::get_subscription_conversion_lags delegates (NOT cached).
	 */
	public function test_subscribers_metric_subscription_conversion_lags_delegates(): void {
		$rows = [
			[
				'customer_id'   => 1,
				'registered_ts' => 1000,
				'first_sub_ts'  => 1000 + 86400 * 10,
			],
		];
		$mock = $this->createMock( Storage_Interface::class );
		$mock->expects( $this->exactly( 2 ) )->method( 'get_subscription_conversion_lags' )->willReturnOnConsecutiveCalls( $rows, [] );
		$metric = $this->make_subscribers_metric( $mock );
		$this->assertSame( $rows, $metric->get_subscription_conversion_lags() );
		$this->assertSame( [], $metric->get_subscription_conversion_lags() );
	}

	/**
	 * Donors_Metric::get_donation_conversion_lags delegates (NOT cached).
	 */
	public function test_donors_metric_donation_conversion_lags_delegates(): void {
		$rows = [
			[
				'customer_id'       => 2,
				'registered_ts'     => 2000,
				'first_donation_ts' => 2000 + 86400 * 5,
			],
		];
		$mock = $this->createMock( Donors_Storage_Interface::class );
		$mock->expects( $this->exactly( 2 ) )->method( 'get_donation_conversion_lags' )->willReturnOnConsecutiveCalls( $rows, [] );
		$metric = $this->make_donors_metric( $mock );
		$this->assertSame( $rows, $metric->get_donation_conversion_lags() );
		$this->assertSame( [], $metric->get_donation_conversion_lags() );
	}

	// =========================================================================
	// Reader registration dates — Task 2 (5.1 cohort population).
	// =========================================================================

	/**
	 * HPOS + Legacy storage expose get_reader_registration_dates().
	 */
	public function test_storages_implement_reader_registration_dates(): void {
		$this->assertTrue( method_exists( new HPOS_Storage( [] ), 'get_reader_registration_dates' ) );
		$this->assertTrue( method_exists( new Legacy_Storage( [] ), 'get_reader_registration_dates' ) );
		$this->assertTrue(
			( new \ReflectionClass( Storage_Interface::class ) )->hasMethod( 'get_reader_registration_dates' )
		);
	}

	/**
	 * Subscribers_Metric::get_reader_registration_dates() delegates to storage.
	 */
	public function test_subscribers_metric_reader_registration_dates_delegates(): void {
		$dates = [
			1 => $this->make_date( '2026-01-15' ),
			2 => $this->make_date( '2026-02-20' ),
		];
		$mock = $this->createMock( Storage_Interface::class );
		$mock->expects( $this->once() )
			->method( 'get_reader_registration_dates' )
			->willReturn( $dates );

		$metric = $this->make_subscribers_metric( $mock );
		$this->assertSame( $dates, $metric->get_reader_registration_dates() );
	}

	/**
	 * Donor storages expose get_subscriber_to_donor_lags.
	 */
	public function test_donor_storages_implement_sub_to_donor_lags(): void {
		$this->assertTrue( method_exists( new HPOS_Donors_Storage( [] ), 'get_subscriber_to_donor_lags' ) );
		$this->assertTrue( method_exists( new Legacy_Donors_Storage( [] ), 'get_subscriber_to_donor_lags' ) );
	}

	/**
	 * Donors_Metric::get_subscriber_to_donor_lags delegates (NOT cached).
	 */
	public function test_donors_metric_sub_to_donor_lags_delegates(): void {
		$rows = [ [ 'lag_days' => 12 ], [ 'lag_days' => 40 ] ];
		$mock = $this->createMock( Donors_Storage_Interface::class );
		$mock->expects( $this->exactly( 2 ) )->method( 'get_subscriber_to_donor_lags' )->willReturnOnConsecutiveCalls( $rows, [] );
		$metric = $this->make_donors_metric( $mock );
		$this->assertSame( $rows, $metric->get_subscriber_to_donor_lags() );
		$this->assertSame( [], $metric->get_subscriber_to_donor_lags() );
	}

	// =========================================================================
	// New-subscriber cohort intervals — Task 3 (5.2 retention input).
	// =========================================================================

	/**
	 * HPOS + Legacy storage expose get_new_subscriber_cohort_intervals().
	 */
	public function test_storages_implement_cohort_intervals(): void {
		$this->assertTrue( method_exists( new HPOS_Storage( [] ), 'get_new_subscriber_cohort_intervals' ) );
		$this->assertTrue( method_exists( new Legacy_Storage( [] ), 'get_new_subscriber_cohort_intervals' ) );
		$this->assertTrue(
			( new \ReflectionClass( Storage_Interface::class ) )->hasMethod( 'get_new_subscriber_cohort_intervals' )
		);
	}

	/**
	 * Subscribers_Metric::get_new_subscriber_cohort_intervals() delegates.
	 */
	public function test_subscribers_metric_cohort_intervals_delegates(): void {
		$rows = [
			[
				'customer_id' => 7,
				'start'       => '2026-01-10 00:00:00',
				'cancelled'   => null,
				'end'         => null,
			],
		];
		$mock = $this->createMock( Storage_Interface::class );
		$mock->expects( $this->once() )
			->method( 'get_new_subscriber_cohort_intervals' )
			->willReturn( $rows );

		$metric = $this->make_subscribers_metric( $mock );
		$this->assertSame( $rows, $metric->get_new_subscriber_cohort_intervals() );
	}
}
