<?php
/**
 * Test Audience_Metric registered-readers counts (NPPD-1733).
 *
 * The one Audience metric sourced from local wp_users rather than GA4. Covers
 * the is_user_reader()-equivalent role filter (reader roles minus staff), the
 * inclusive registration window, the honest-zero baseline, and the
 * not-computable guard.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Audience_Metric;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Donation_Product_Classifier;
use WP_UnitTestCase;

// Supporter-type detection (NPPD-1767) reads `WC_Subscriptions` + `wc_get_products`;
// the unit bootstrap loads neither, so pull in the shared WC stubs for those tests.
require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Audience_Metric registered-readers test class.
 *
 * @group insights
 */
class Test_Audience_Metric extends WP_UnitTestCase {

	/**
	 * Create a user with a role and an explicit registration datetime (UTC, as
	 * WordPress stores `user_registered`).
	 *
	 * @param string $role       Role slug.
	 * @param string $registered `Y-m-d H:i:s` in UTC.
	 * @return int User ID.
	 */
	private function make_user( string $role, string $registered ): int {
		return self::factory()->user->create(
			[
				'role'            => $role,
				'user_registered' => $registered,
			]
		);
	}

	/**
	 * Total counts the configured reader roles (subscriber + customer) and
	 * excludes staff (administrator + editor) and non-reader roles (author).
	 */
	public function test_total_counts_reader_roles_excluding_staff() {
		$this->make_user( 'subscriber', '2026-01-10 12:00:00' );
		$this->make_user( 'subscriber', '2026-02-10 12:00:00' );
		$this->make_user( 'customer', '2026-03-10 12:00:00' );
		$this->make_user( 'administrator', '2026-01-05 12:00:00' );
		$this->make_user( 'editor', '2026-01-06 12:00:00' );
		$this->make_user( 'author', '2026-01-07 12:00:00' );

		$payload = Audience_Metric::registered_readers_total();

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'count', $payload['type'] );
		$this->assertSame( 3, $payload['value'], 'Counts subscriber + customer; excludes admin, editor, author.' );
	}

	/**
	 * New counts only accounts registered within the window.
	 */
	public function test_new_counts_only_within_window() {
		$this->make_user( 'subscriber', '2026-01-15 12:00:00' );
		$this->make_user( 'customer', '2026-01-20 12:00:00' );
		$this->make_user( 'subscriber', '2025-12-31 12:00:00' );
		$this->make_user( 'subscriber', '2026-02-01 12:00:00' );

		$payload = Audience_Metric::registered_readers_new( '2026-01-01', '2026-01-31' );

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 2, $payload['value'] );
	}

	/**
	 * The window is inclusive of both calendar boundaries (00:00:00 → 23:59:59).
	 */
	public function test_window_bounds_are_inclusive() {
		$this->make_user( 'subscriber', '2026-01-01 00:00:00' );
		$this->make_user( 'subscriber', '2026-01-31 23:59:59' );

		$payload = Audience_Metric::registered_readers_new( '2026-01-01', '2026-01-31' );

		$this->assertSame( 2, $payload['value'] );
	}

	/**
	 * No reader accounts → an honest, computable 0 (NOT a not-computable state):
	 * a new publisher's real zero, per NPPD-1733's empty-state contract.
	 */
	public function test_new_publisher_zero_is_computable() {
		$payload = Audience_Metric::registered_readers_total();

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 0, $payload['value'] );
	}

	/**
	 * When no reader roles are configured the count is genuinely unknowable, so
	 * the metric reports not-computable (the UI's em-dash treatment) rather than a
	 * misleading 0.
	 */
	public function test_not_computable_when_no_reader_roles() {
		add_filter( 'newspack_reader_user_roles', '__return_empty_array' );
		$payload = Audience_Metric::registered_readers_total();
		remove_filter( 'newspack_reader_user_roles', '__return_empty_array' );

		$this->assertFalse( $payload['computable'] );
		$this->assertNull( $payload['value'] );
	}

	/**
	 * Reset the supporter-detection seams between tests: the staged subscription
	 * product IDs, the classifier cache, and the canonical donation option.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['newspack_test_wc_products'] );
		delete_transient( Donation_Product_Classifier::TRANSIENT_KEY );
		delete_option( 'newspack_donation_product_id' );
		parent::tear_down();
	}

	/**
	 * Invoke the private `detect_supporter_products()` directly.
	 *
	 * @return array{subscriptions:bool,donations:bool}
	 */
	private function detect_supporter_products(): array {
		$method = new \ReflectionMethod( Audience_Metric::class, 'detect_supporter_products' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * Seed the donation classifier's cache so `get_donation_product_ids()` returns
	 * the given set without touching real products/options.
	 *
	 * @param int[] $ids Donation product IDs.
	 */
	private function set_donation_product_ids( array $ids ): void {
		set_transient( Donation_Product_Classifier::TRANSIENT_KEY, $ids, HOUR_IN_SECONDS );
	}

	/**
	 * NPPD-1767: donations are detected via the shared classifier, so a product
	 * designated a donation ONLY via the `_newspack_is_donation` checkbox (no
	 * canonical `newspack_donation_product_id` option) still registers — matching
	 * Donors/Tab 7. The raw-option check this replaced would have read false.
	 */
	public function test_supporter_products_detects_checkbox_only_donations() {
		delete_option( 'newspack_donation_product_id' );
		$this->set_donation_product_ids( [ 555 ] );

		$result = $this->detect_supporter_products();

		$this->assertTrue( $result['donations'], 'checkbox-flagged donation (no canonical option) registers as a donation' );
	}

	/**
	 * No products in the classifier set → no donation slice, even if some unrelated
	 * option exists. (The classifier is the single source of truth.)
	 */
	public function test_supporter_products_no_donations_when_classifier_empty() {
		$this->set_donation_product_ids( [] );

		$result = $this->detect_supporter_products();

		$this->assertFalse( $result['donations'] );
	}

	/**
	 * NPPD-1767: a subscription-type product the publisher flagged as a donation is
	 * counted as a donation here, NOT a subscription — keeping the pie's categories
	 * complementary and consistent with Tabs 6/7. A genuine (non-donation)
	 * subscription product still counts as a subscription.
	 */
	public function test_supporter_products_flagged_subscription_is_donation_not_subscription() {
		// The only published subscription-type product (700) is flagged as a donation.
		$this->set_donation_product_ids( [ 700 ] );
		$GLOBALS['newspack_test_wc_products'] = [ 700 ];

		$result = $this->detect_supporter_products();

		$this->assertTrue( $result['donations'], 'the flagged product is a donation' );
		$this->assertFalse( $result['subscriptions'], 'a donation-flagged subscription product is not double-counted as a subscription' );

		// Add a genuine, non-donation subscription product (800): now subscriptions exist.
		$GLOBALS['newspack_test_wc_products'] = [ 700, 800 ];

		$result = $this->detect_supporter_products();

		$this->assertTrue( $result['subscriptions'], 'a non-donation subscription product counts as a subscription' );
	}

	/*
	===================================================================
	 * BQ proxy shaper tests (NPPD-1729 Task B1)
	 * ===================================================================
	 */

	/**
	 * Build a fake BigQuery_Proxy_Client that returns canned rows for a
	 * specific query name. Any other query name returns an empty array.
	 *
	 * @param string $expected_name  Catalog query name to intercept.
	 * @param array  $rows           Rows to return for that query.
	 * @return BigQuery_Proxy_Client
	 */
	private function makeProxyReturning( string $expected_name, array $rows ): BigQuery_Proxy_Client {
		return new class( $expected_name, $rows ) extends BigQuery_Proxy_Client {
			public function __construct( private string $expected_name, private array $rows ) {}
			public function query( string $query_name, \DateTimeInterface $start, \DateTimeInterface $end ) {
				return $query_name === $this->expected_name ? $this->rows : [];
			}
		};
	}

	/**
	 * active_readers_via_bq shapes a first-row 'active_readers' column into a
	 * scalar { value, computable, type: 'count' } payload.
	 */
	public function test_active_readers_via_bq_shapes_scalar() {
		$proxy = $this->makeProxyReturning( 'audience_active_readers', [ [ 'active_readers' => 4200 ] ] );
		$out   = Audience_Metric::active_readers_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 4200, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'count', $out['type'] );
	}

	/**
	 * pageviews_via_bq shapes a first-row 'pageviews' column into a
	 * scalar { value, computable, type: 'count' } payload.
	 */
	public function test_pageviews_via_bq_shapes_scalar() {
		$proxy = $this->makeProxyReturning( 'audience_pageviews', [ [ 'pageviews' => 98765 ] ] );
		$out   = Audience_Metric::pageviews_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 98765, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'count', $out['type'] );
	}

	/**
	 * newsletter_signups_via_bq shapes a first-row 'newsletter_signups' column
	 * into a scalar { value, computable, type: 'count' } payload.
	 */
	public function test_newsletter_signups_via_bq_shapes_scalar() {
		$proxy = $this->makeProxyReturning( 'audience_newsletter_signups', [ [ 'newsletter_signups' => 150 ] ] );
		$out   = Audience_Metric::newsletter_signups_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 150, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'count', $out['type'] );
	}

	/**
	 * avg_sessions_per_reader_via_bq computes sessions / active_readers and
	 * exposes the numerator/denominator alongside the decimal value.
	 */
	public function test_avg_sessions_per_reader_via_bq_computes_ratio() {
		$proxy = $this->makeProxyReturning(
			'audience_avg_sessions_per_reader',
			[
				[
					'sessions'       => 6000,
					'active_readers' => 2000,
				],
			]
		);
		$out = Audience_Metric::avg_sessions_per_reader_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 3.0, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'decimal', $out['type'] );
		$this->assertSame( 6000, $out['numerator'] );
		$this->assertSame( 2000, $out['denominator'] );
	}

	/**
	 * avg_sessions_per_reader_via_bq returns computable=false with value 0
	 * when active_readers is zero (avoids divide-by-zero).
	 */
	public function test_avg_sessions_per_reader_via_bq_zero_readers_not_computable() {
		$proxy = $this->makeProxyReturning(
			'audience_avg_sessions_per_reader',
			[
				[
					'sessions'       => 0,
					'active_readers' => 0,
				],
			]
		);
		$out = Audience_Metric::avg_sessions_per_reader_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 0, $out['value'] );
		$this->assertFalse( $out['computable'] );
	}

	/**
	 * proxy_scalar returns computable=false when the column is absent.
	 */
	public function test_active_readers_via_bq_missing_column_not_computable() {
		$proxy = $this->makeProxyReturning( 'audience_active_readers', [ [ 'some_other_column' => 99 ] ] );
		$out   = Audience_Metric::active_readers_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertFalse( $out['computable'] );
		$this->assertSame( 0, $out['value'] );
	}

	/*
	===================================================================
	 * BQ proxy shaper tests (NPPD-1729 Task B2)
	 * ===================================================================
	 */

	/**
	 * traffic_sources_breakdown_via_bq passes rows through with type 'breakdown'.
	 */
	public function test_traffic_sources_breakdown_via_bq_passes_rows() {
		$proxy = $this->makeProxyReturning(
			'audience_traffic_sources_breakdown',
			[
				[
					'channel' => 'Organic Search',
					'readers' => 100,
				],
				[
					'channel' => '(direct)',
					'readers' => 40,
				],
			] 
		);
		$out = Audience_Metric::traffic_sources_breakdown_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'breakdown', $out['type'] );
		$this->assertCount( 2, $out['rows'] );
		$this->assertSame( 'Organic Search', $out['rows'][0]['channel'] );
	}

	/**
	 * readership_by_hour_of_day_via_bq shifts UTC hours by the given offset, then
	 * emits the frontend contract key `hour` as a zero-padded 2-char string (the
	 * proxy's int `hour_of_day` must not survive in the payload). UTC hour 0 with a
	 * -5h site offset maps to local hour 19 → '19'.
	 */
	public function test_readership_by_hour_applies_timezone_offset() {
		$proxy = $this->makeProxyReturning(
			'audience_readership_by_hour_of_day',
			[
				[
					'hour_of_day'    => 0,
					'active_readers' => 10,
				],
			]
		);
		// With a -5h site offset, UTC hour 0 maps to local hour 19.
		$out = Audience_Metric::readership_by_hour_of_day_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ), -5 );
		$this->assertSame( '19', $out['rows'][0]['hour'], 'frontend reads the `hour` key, zero-padded' );
		$this->assertSame( 10, $out['rows'][0]['active_readers'] );
		$this->assertArrayNotHasKey( 'hour_of_day', $out['rows'][0], 'raw proxy column must be dropped' );
	}

	/**
	 * Pre-midnight local hours stay 2-char zero-padded: UTC hour 5 with a -5h
	 * offset maps to local hour 0 → '00' (not '0').
	 */
	public function test_readership_by_hour_zero_pads_single_digit_hours() {
		$proxy = $this->makeProxyReturning(
			'audience_readership_by_hour_of_day',
			[
				[
					'hour_of_day'    => 5,
					'active_readers' => 7,
				],
			]
		);
		$out = Audience_Metric::readership_by_hour_of_day_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ), -5 );
		$this->assertSame( '00', $out['rows'][0]['hour'] );
	}

	/*
	===================================================================
	 * BQ proxy shaper tests (NPPD-1729 Task B3 — hidden metrics enabled)
	 * ===================================================================
	 */

	/**
	 * top_categories_via_bq passes rows through with type 'table'.
	 */
	public function test_top_categories_via_bq_enabled() {
		$proxy = $this->makeProxyReturning(
			'audience_top_categories',
			[
				[
					'category'       => 'Politics',
					'unique_readers' => 50,
					'pageviews'      => 120,
				],
			] 
		);
		$out   = Audience_Metric::top_categories_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertSame( 'Politics', $out['rows'][0]['category'] );
	}

	/**
	 * returning_reader_rate_via_bq shapes a single-row rate into a
	 * { value, computable, type: 'rate' } payload.
	 */
	public function test_returning_reader_rate_via_bq_enabled() {
		$proxy = $this->makeProxyReturning( 'audience_returning_reader_rate', [ [ 'returning_reader_rate' => 0.42 ] ] );
		$out   = Audience_Metric::returning_reader_rate_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 0.42, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'rate', $out['type'] );
	}

	/**
	 * Guard: no GA4 constant, no _via_ga4 methods, no safe_run_report remain in
	 * class-audience-metric.php after the GA4 path has been deleted.
	 */
	public function test_no_ga4_constant_or_methods_remain() {
		$src = file_get_contents( NEWSPACK_ABSPATH . 'includes/wizards/insights/metrics/class-audience-metric.php' );
		$this->assertStringNotContainsString( 'NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4', $src );
		$this->assertStringNotContainsString( '_via_ga4', $src );
		$this->assertStringNotContainsString( 'safe_run_report', $src );
	}

	/*
	===================================================================
	 * supporter_type_via_bq product-gating + slice-fold (NPPD-1729 Task B3 regression fix)
	 * ===================================================================
	 */

	/**
	 * BQ rows for all four segments, used across the gating/folding tests.
	 *
	 * @return array
	 */
	private function supporterTypeBqRows(): array {
		return [
			[
				'segment'      => 'Both',
				'reader_count' => 300,
			],
			[
				'segment'      => 'Subscriber only',
				'reader_count' => 700,
			],
			[
				'segment'      => 'Donor only',
				'reader_count' => 200,
			],
			[
				'segment'      => 'Logged-in only',
				'reader_count' => 800,
			],
		];
	}

	/**
	 * (a) Neither product configured → hidden_in_v1 payload; proxy NOT called.
	 * Verifies product-gating: if the publisher has no supporter products, the
	 * card must be hidden (there is nothing to segment by).
	 */
	public function test_supporter_type_via_bq_neither_product_returns_hidden_payload() {
		// No donation products, no subscription products.
		$this->set_donation_product_ids( [] );
		// newspack_test_wc_products is unset by tear_down, so no subscription products exist here.

		// Use a proxy that would fail if query() were called.
		$proxy = $this->makeProxyReturning( 'audience_supporter_type', $this->supporterTypeBqRows() );

		$out = Audience_Metric::supporter_type_via_bq(
			$proxy,
			new \DateTimeImmutable( '2026-01-01' ),
			new \DateTimeImmutable( '2026-01-31' )
		);

		$this->assertTrue( isset( $out['hidden_in_v1'] ) && $out['hidden_in_v1'], 'hidden_in_v1 must be true when neither product is configured' );
		$this->assertFalse( $out['computable'] );
		$this->assertNull( $out['value'] );
		// Ensure BQ was NOT queried: a real proxy call would have returned rows.
		$this->assertArrayNotHasKey( 'rows', $out, 'proxy must not be called; no rows key should appear' );
	}

	/**
	 * (b) Donations only → two-slice fold.
	 * "Both" folds into "Donor" (300+200=500).
	 * "Subscriber only" folds into "Logged-in only" (800+700=1500).
	 */
	public function test_supporter_type_via_bq_donations_only_folds_to_two_slices() {
		$this->set_donation_product_ids( [ 42 ] );
		// No subscription products.
		unset( $GLOBALS['newspack_test_wc_products'] );

		$proxy = $this->makeProxyReturning( 'audience_supporter_type', $this->supporterTypeBqRows() );

		$out = Audience_Metric::supporter_type_via_bq(
			$proxy,
			new \DateTimeImmutable( '2026-01-01' ),
			new \DateTimeImmutable( '2026-01-31' )
		);

		$this->assertArrayNotHasKey( 'hidden_in_v1', $out, 'should NOT be hidden when donations exist' );
		$this->assertSame( 'breakdown', $out['type'] );
		$this->assertCount( 2, $out['rows'] );

		// Build a label → value map for order-independent assertions.
		$by_label = array_column( $out['rows'], 'value', 'label' );

		$this->assertArrayHasKey( 'Donor', $by_label, 'Donor slice must be present' );
		$this->assertArrayHasKey( 'Logged-in only', $by_label, 'Logged-in only slice must be present' );

		// Both (300) + Donor only (200) = 500.
		$this->assertSame( 500, $by_label['Donor'], 'Both folds into Donor' );
		// Logged-in only (800) + Subscriber only (700) = 1500.
		$this->assertSame( 1500, $by_label['Logged-in only'], 'Subscriber only folds into Logged-in only' );
	}

	/**
	 * (c) Both products → four buckets pass through unchanged.
	 */
	public function test_supporter_type_via_bq_both_products_passes_four_buckets() {
		$this->set_donation_product_ids( [ 42 ] );
		// A non-donation subscription product.
		$GLOBALS['newspack_test_wc_products'] = [ 99 ];

		$proxy = $this->makeProxyReturning( 'audience_supporter_type', $this->supporterTypeBqRows() );

		$out = Audience_Metric::supporter_type_via_bq(
			$proxy,
			new \DateTimeImmutable( '2026-01-01' ),
			new \DateTimeImmutable( '2026-01-31' )
		);

		$this->assertArrayNotHasKey( 'hidden_in_v1', $out, 'should NOT be hidden when both products exist' );
		$this->assertSame( 'breakdown', $out['type'] );
		// Both products → four slices, relabeled to the frontend label/value contract
		// (the proxy's segment/reader_count keys must not leak through).
		$this->assertCount( 4, $out['rows'] );
		$this->assertArrayHasKey( 'label', $out['rows'][0], 'pie reads `label`, not `segment`' );
		$this->assertArrayHasKey( 'value', $out['rows'][0], 'pie reads `value`, not `reader_count`' );
		$this->assertArrayNotHasKey( 'segment', $out['rows'][0] );
		$this->assertArrayNotHasKey( 'reader_count', $out['rows'][0] );

		$by_label = array_column( $out['rows'], 'value', 'label' );
		$this->assertSame( 300, $by_label['Both'] );
		$this->assertSame( 700, $by_label['Subscriber only'] );
		$this->assertSame( 200, $by_label['Donor only'] );
		$this->assertSame( 800, $by_label['Logged-in only'] );
	}

	/*
	===================================================================
	 * Fixture-parity reconciliation tests (NPPD-1729 — payload contract).
	 *
	 * The Audience tab BQ swap is "zero UI change": each *_via_bq payload must
	 * match the canonical fixture's shape key-for-key, since the React components
	 * (and the dev fixture) are unchanged. These tests load the fixture as the
	 * spec and assert each metric's row keys (and relabeled strings) against it.
	 * ===================================================================
	 */

	/**
	 * Load the current-window block of the canonical Audience fixture — the
	 * authoritative payload contract the frontend renders.
	 *
	 * @return array
	 */
	private function fixture_current(): array {
		$fixture = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/audience-fixture.php';
		return $fixture['current'];
	}

	/**
	 * The row-key set the fixture defines for a given metric.
	 *
	 * @param string $metric Fixture metric key.
	 * @return string[] Sorted row keys.
	 */
	private function fixture_row_keys( string $metric ): array {
		$rows = $this->fixture_current()[ $metric ]['rows'];
		$keys = array_keys( $rows[0] );
		sort( $keys );
		return $keys;
	}

	/**
	 * Assert a shaped payload's first-row keys equal the fixture metric's keys.
	 *
	 * @param string $metric Fixture metric key.
	 * @param array  $out    Shaped via_bq payload.
	 */
	private function assertRowKeysMatchFixture( string $metric, array $out ): void {
		$keys = array_keys( $out['rows'][0] );
		sort( $keys );
		$this->assertSame(
			$this->fixture_row_keys( $metric ),
			$keys,
			"row keys for $metric must match the frontend fixture contract"
		);
	}

	/**
	 * New_vs_returning_over_time: proxy day/new_readers/returning_readers →
	 * fixture date/new/returning.
	 */
	public function test_new_vs_returning_matches_fixture_keys() {
		$proxy = $this->makeProxyReturning(
			'audience_new_vs_returning_over_time',
			[
				[
					'day'               => '20260101',
					'new_readers'       => 120,
					'returning_readers' => 80,
				],
			]
		);
		$out = Audience_Metric::new_vs_returning_over_time_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'timeseries', $out['type'] );
		$this->assertRowKeysMatchFixture( 'new_vs_returning_over_time', $out );
		$this->assertSame( '20260101', $out['rows'][0]['date'] );
		$this->assertSame( 120, $out['rows'][0]['new'] );
		$this->assertSame( 80, $out['rows'][0]['returning'] );
	}

	/**
	 * Readership_by_day_of_week: proxy int day_of_week (1-7, Sun=1) → fixture day
	 * NAME, ordered Monday→Sunday.
	 */
	public function test_readership_by_day_of_week_maps_to_names_and_order() {
		// Supply rows out of order and as BigQuery's 1-7 (Sun=1) ints.
		$proxy = $this->makeProxyReturning(
			'audience_readership_by_day_of_week',
			[
				[
					'day_of_week'    => 1, // Sunday.
					'active_readers' => 70,
				],
				[
					'day_of_week'    => 4, // Wednesday.
					'active_readers' => 40,
				],
				[
					'day_of_week'    => 2, // Monday.
					'active_readers' => 10,
				],
			]
		);
		$out = Audience_Metric::readership_by_day_of_week_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'breakdown', $out['type'] );
		$this->assertRowKeysMatchFixture( 'readership_by_day_of_week', $out );

		// Present days are ordered Monday→Sunday (matching the fixture order).
		$days = array_column( $out['rows'], 'day_of_week' );
		$this->assertSame( [ 'Monday', 'Wednesday', 'Sunday' ], $days );
		$by_day = array_column( $out['rows'], 'active_readers', 'day_of_week' );
		$this->assertSame( 10, $by_day['Monday'] );
		$this->assertSame( 40, $by_day['Wednesday'] );
		$this->assertSame( 70, $by_day['Sunday'] );
	}

	/**
	 * Readership_by_hour_of_day: padded `hour` string key matches the fixture.
	 */
	public function test_readership_by_hour_matches_fixture_keys() {
		$proxy = $this->makeProxyReturning(
			'audience_readership_by_hour_of_day',
			[
				[
					'hour_of_day'    => 13,
					'active_readers' => 10,
				],
			]
		);
		$out = Audience_Metric::readership_by_hour_of_day_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ), 0 );
		$this->assertRowKeysMatchFixture( 'readership_by_hour_of_day', $out );
		$this->assertSame( '13', $out['rows'][0]['hour'] );
	}

	/**
	 * Newsletter_subscriber_composition: proxy segment/reader_count → fixture
	 * label/value, with the segment strings relabeled to the fixture's labels.
	 */
	public function test_newsletter_subscriber_composition_relabels_to_fixture() {
		$proxy = $this->makeProxyReturning(
			'audience_newsletter_subscriber_composition',
			[
				[
					'segment'      => 'newsletter subscriber',
					'reader_count' => 320,
				],
				[
					'segment'      => 'not subscribed',
					'reader_count' => 960,
				],
			]
		);
		$out = Audience_Metric::newsletter_subscriber_composition_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'breakdown', $out['type'] );
		$this->assertRowKeysMatchFixture( 'newsletter_subscriber_composition', $out );

		$by_label = array_column( $out['rows'], 'value', 'label' );
		// Labels must match the fixture strings exactly.
		$fixture_labels = array_column( $this->fixture_current()['newsletter_subscriber_composition']['rows'], 'label' );
		$this->assertContains( 'Newsletter subscriber', $fixture_labels );
		$this->assertContains( 'Not subscribed', $fixture_labels );
		$this->assertSame( 320, $by_label['Newsletter subscriber'] );
		$this->assertSame( 960, $by_label['Not subscribed'] );
	}

	/**
	 * Logged_in_vs_anonymous_composition: proxy segment/reader_count → fixture
	 * label/value with relabeled strings.
	 */
	public function test_logged_in_vs_anonymous_composition_relabels_to_fixture() {
		$proxy = $this->makeProxyReturning(
			'audience_logged_in_vs_anonymous_composition',
			[
				[
					'segment'      => 'logged in',
					'reader_count' => 385,
				],
				[
					'segment'      => 'anonymous',
					'reader_count' => 899,
				],
			]
		);
		$out = Audience_Metric::logged_in_vs_anonymous_composition_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'breakdown', $out['type'] );
		$this->assertRowKeysMatchFixture( 'logged_in_vs_anonymous_composition', $out );

		$by_label = array_column( $out['rows'], 'value', 'label' );
		$this->assertSame( 385, $by_label['Logged in'] );
		$this->assertSame( 899, $by_label['Anonymous'] );
	}

	/**
	 * Top_pages: proxy carries post_id + page_url; the fixture (and frontend) only
	 * render page_title/unique_readers/pageviews — the extra columns must be dropped.
	 */
	public function test_top_pages_drops_post_id_and_page_url() {
		$proxy = $this->makeProxyReturning(
			'audience_top_pages',
			[
				[
					'post_id'        => 42,
					'page_url'       => 'https://example.test/a',
					'page_title'     => 'Headline A',
					'unique_readers' => 500,
					'pageviews'      => 900,
				],
			]
		);
		$out = Audience_Metric::top_pages_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertRowKeysMatchFixture( 'top_pages', $out );
		$this->assertArrayNotHasKey( 'post_id', $out['rows'][0] );
		$this->assertArrayNotHasKey( 'page_url', $out['rows'][0] );
		$this->assertSame( 'Headline A', $out['rows'][0]['page_title'] );
	}

	/**
	 * Passthrough metrics whose proxy aliases already match the fixture keep their
	 * shape: top_regions, top_cities, top_authors_by_reader_count, top_campaigns,
	 * traffic_sources_breakdown, device_breakdown.
	 */
	public function test_passthrough_metrics_match_fixture_keys() {
		$cases = [
			[
				'method' => 'top_regions_via_bq',
				'query'  => 'audience_top_regions',
				'metric' => 'top_regions',
				'row'    => [
					'country' => 'United States',
					'region'  => 'Illinois',
					'readers' => 41200,
				],
			],
			[
				'method' => 'top_cities_via_bq',
				'query'  => 'audience_top_cities',
				'metric' => 'top_cities',
				'row'    => [
					'country' => 'United States',
					'region'  => 'Illinois',
					'city'    => 'Chicago',
					'readers' => 33400,
				],
			],
			[
				'method' => 'top_authors_by_reader_count_via_bq',
				'query'  => 'audience_top_authors_by_reader_count',
				'metric' => 'top_authors_by_reader_count',
				'row'    => [
					'author'         => 'Maria Alvarez',
					'unique_readers' => 34200,
					'pageviews'      => 51200,
				],
			],
			[
				'method' => 'top_campaigns_via_bq',
				'query'  => 'audience_top_campaigns',
				'metric' => 'top_campaigns',
				'row'    => [
					'source'   => 'newsletter',
					'medium'   => 'email',
					'campaign' => 'weekly-digest',
					'readers'  => 8200,
					'sessions' => 11400,
				],
			],
			[
				'method' => 'traffic_sources_breakdown_via_bq',
				'query'  => 'audience_traffic_sources_breakdown',
				'metric' => 'traffic_sources_breakdown',
				'row'    => [
					'channel' => 'Organic Search',
					'readers' => 51200,
				],
			],
			[
				'method' => 'device_breakdown_via_bq',
				'query'  => 'audience_device_breakdown',
				'metric' => 'device_breakdown',
				'row'    => [
					'device'  => 'mobile',
					'readers' => 89400,
				],
			],
		];
		foreach ( $cases as $case ) {
			$proxy = $this->makeProxyReturning( $case['query'], [ $case['row'] ] );
			$out   = Audience_Metric::{$case['method']}( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
			$this->assertRowKeysMatchFixture( $case['metric'], $out );
		}
	}
}
