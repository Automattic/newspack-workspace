<?php
/**
 * Test Gates_Metric.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Gates_Metric;
use Newspack\Insights\Subscribers_Metric;
use WP_UnitTestCase;

/**
 * Gates_Metric test class.
 *
 * @group insights
 */
class Test_Gates_Metric extends WP_UnitTestCase {

	/**
	 * Make a UTC DateTimeImmutable from a YYYY-MM-DD string.
	 *
	 * @param string $ymd Date string.
	 * @return DateTimeImmutable
	 */
	protected function make_date( string $ymd ): DateTimeImmutable {
		return new DateTimeImmutable( $ymd, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Constructor accepts an injected proxy client (test seam).
	 */
	public function test_constructor_accepts_injected_proxy() {
		$proxy  = $this->createMock( BigQuery_Proxy_Client::class );
		$metric = new Gates_Metric( $proxy );
		$this->assertInstanceOf( Gates_Metric::class, $metric );
	}

	/**
	 * Scorecards return real values on a successful proxy response.
	 *
	 * @dataProvider provide_scorecard_success_cases
	 * @param string $method           Method on Gates_Metric to call.
	 * @param string $query_name       Catalog query name the orchestrator should dispatch.
	 * @param string $row_key          Column the orchestrator reads from row 0.
	 * @param mixed  $row_value        Value the mock client returns for that column.
	 * @param string $placeholder_type Expected `placeholder_type` in the payload.
	 * @param mixed  $expected_value   Expected `value` in the payload.
	 */
	public function test_scorecard_returns_real_value_on_success( string $method, string $query_name, string $row_key, $row_value, string $placeholder_type, $expected_value ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( [ [ $row_key => $row_value ] ] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->$method( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( $expected_value, $result['value'] );
		$this->assertSame( 'populated', $result['state'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( $placeholder_type, $result['placeholder_type'] );
	}

	/**
	 * Data provider for scorecard success cases.
	 *
	 * @return array
	 */
	public function provide_scorecard_success_cases(): array {
		return [
			'total_impressions'  => [ 'get_total_gate_impressions', 'gates_total_impressions', 'gate_impressions', 12345, 'count', 12345 ],
			'unique_viewers'     => [ 'get_unique_readers_reached', 'gates_unique_viewers', 'unique_gate_viewers', 678, 'count', 678 ],
			'avg_exposures'      => [ 'get_avg_exposures_per_reader', 'gates_avg_exposures_per_reader', 'avg_exposures_per_reader', 3.5, 'decimal', 3.5 ],
			'pct_sessions'       => [ 'get_sessions_with_gate', 'gates_sessions_with_gate', 'pct_sessions_with_gate', 0.42, 'rate', 0.42 ],
			'regwall_direct'     => [ 'get_regwall_conversion_direct', 'gates_regwall_conversion_direct', 'regwall_conversion_rate_direct', 0.18, 'rate', 0.18 ],
			'regwall_influenced' => [ 'get_regwall_conversion_influenced_7d', 'gates_regwall_conversion_influenced_7d', 'regwall_conversion_influenced', 0.31, 'rate', 0.31 ],
		];
	}

	/**
	 * Scorecards report state 'error' (with the proxy error code) on proxy error.
	 *
	 * @dataProvider provide_scorecard_method_names
	 * @param string $method Method on Gates_Metric to call.
	 */
	public function test_scorecard_returns_error_state_on_proxy_error( string $method ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_query_failed', 'BQ down' ) );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->$method( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'error', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 'bigquery_query_failed', $result['error_code'] );
		$this->assertSame( 'BQ down', $result['error_message'] );
	}

	/**
	 * Data provider for scorecard method names.
	 *
	 * @return array
	 */
	public function provide_scorecard_method_names(): array {
		return [
			[ 'get_total_gate_impressions' ],
			[ 'get_unique_readers_reached' ],
			[ 'get_avg_exposures_per_reader' ],
			[ 'get_sessions_with_gate' ],
			[ 'get_regwall_conversion_direct' ],
			[ 'get_regwall_conversion_influenced_7d' ],
		];
	}

	/**
	 * A successful but empty scalar query is a non-computable zero, not an error.
	 */
	public function test_section_1_empty_rows_is_noncomputable_zero() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_total_gate_impressions( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 0, $result['value'] );
	}

	/**
	 * Section 1 scorecards fall back to placeholder when the catalog returns a non-numeric value.
	 *
	 * Covers the `! is_numeric()` branch in `compute_metric_from_proxy()`. This protects
	 * against upstream catalog drift where a builder might return a string or null.
	 */
	public function test_section_1_falls_back_on_non_numeric_value() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [ [ 'gate_impressions' => 'banana' ] ] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_total_gate_impressions( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		// Malformed data is a quality bug, not an empty window → error state.
		$this->assertSame( 'error', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 'bigquery_proxy_malformed_value', $result['error_code'] );
	}

	/**
	 * SAFE_DIVIDE returns NULL when the denominator is zero (BigQuery semantics).
	 * That's a legitimate "no eligible events" case, not a malformed payload —
	 * surface as `state: 'populated'` with a non-computable zero so the UI
	 * renders "0%" instead of "Data temporarily unavailable".
	 */
	public function test_scalar_treats_null_safe_divide_result_as_non_computable_zero() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [ [ 'regwall_conversion_rate_direct' => null ] ] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_regwall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 0, $result['value'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	/**
	 * Section 1 count metrics fall back when the catalog returns a non-integer numeric value.
	 *
	 * A `count` should always be a whole number; a float (e.g. 3.7) indicates upstream
	 * catalog drift and is rejected to surface the contract break rather than silently
	 * truncate.
	 */
	public function test_section_1_count_metric_rejects_non_integer() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [ [ 'gate_impressions' => 3.7 ] ] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_total_gate_impressions( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		// A non-integer count signals catalog drift → error state, not a zero.
		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_value', $result['error_code'] );
	}

	// --- NPPD-1746: direct paywall rate + revenue from order meta (gate surface) ---

	/**
	 * Remove the WC-active seam filter set by the direct-paywall test helpers.
	 */
	public function tear_down(): void {
		remove_all_filters( 'newspack_insights_woocommerce_active' );
		parent::tear_down();
	}

	/**
	 * Build a Gates_Metric for the direct paywall cards with an injected proxy +
	 * Subscribers_Metric and a forced `woocommerce_active()` (via filter — the class
	 * is final). The proxy feeds the rate card's per-gate impressions denominator;
	 * revenue passes null. Filter removed in tear_down().
	 *
	 * @param BigQuery_Proxy_Client|null $proxy       Injected proxy (rate) or null (revenue).
	 * @param Subscribers_Metric         $subscribers Injected subscribers collaborator.
	 * @param bool                       $wc          What woocommerce_active() should return.
	 * @return Gates_Metric
	 */
	private function make_direct_paywall_metric( ?BigQuery_Proxy_Client $proxy, Subscribers_Metric $subscribers, bool $wc ): Gates_Metric {
		add_filter( 'newspack_insights_woocommerce_active', $wc ? '__return_true' : '__return_false' );
		return new Gates_Metric( $proxy, $subscribers );
	}

	/**
	 * Stub Subscribers_Metric whose gate surface holds one gate (id 77) with the
	 * given conversions + revenue, and an empty popup surface.
	 *
	 * @param int   $conversions Gate-attributed subscription conversions.
	 * @param float $revenue     Gate-attributed subscription revenue.
	 * @return Subscribers_Metric
	 */
	private function subscribers_with_gate( int $conversions, float $revenue ): Subscribers_Metric {
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->method( 'get_attributed_subscription_conversions' )->willReturn(
			[
				'by_gate'  => [
					'77' => [
						'conversions' => $conversions,
						'revenue'     => $revenue,
					],
				],
				'by_popup' => [],
			]
		);
		return $subscribers;
	}

	/**
	 * Proxy whose `gates_performance_by_gate` reports impressions for gate 77 (the
	 * converting gate) PLUS an unrelated gate 88. Gate 88 must NOT enter the
	 * denominator — the rate is per-gate keyed to the gates that actually converted.
	 *
	 * @param int $gate_77_impressions Impressions to report for gate 77.
	 * @return BigQuery_Proxy_Client
	 */
	private function proxy_with_gate_impressions( int $gate_77_impressions ): BigQuery_Proxy_Client {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'gate_post_id' => 77,
					'impressions'  => $gate_77_impressions,
				],
				[
					'gate_post_id' => 88,
					'impressions'  => 500,
				],
			]
		);
		return $proxy;
	}

	/**
	 * Direct paywall rate = gate-attributed conversions ÷ impressions of the SAME
	 * gates. Gate 88's 500 impressions are excluded (no conversions there) — proving
	 * the per-gate keying (denominator 4, not 504).
	 */
	public function test_paywall_conversion_direct_per_gate_over_impressions() {
		$metric = $this->make_direct_paywall_metric(
			$this->proxy_with_gate_impressions( 4 ),
			$this->subscribers_with_gate( 1, 0.0 ),
			true
		);

		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 0.25, $result['value'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 4, $result['denominator'], 'denominator is gate 77 only, not the unrelated gate 88' );
		$this->assertSame( 1, $result['numerator'] );
	}

	/**
	 * NPPD-1749: once the hub exposes `checkout_impressions` (the paywall-capable
	 * subset), the rate uses it instead of total gate `impressions` — so a
	 * registration-heavy mixed gate isn't diluted. Here 12 conversions over a gate
	 * with 156,117 total impressions but 10,500 checkout impressions yields a rate on
	 * the 10,500 denominator, not 156,117. Forward-compatible: when the column is
	 * absent (rows without `checkout_impressions`), the other tests cover the
	 * `impressions` fallback.
	 */
	public function test_paywall_conversion_direct_prefers_checkout_impressions() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'gate_post_id'         => 77,
					'impressions'          => 156117,
					'checkout_impressions' => 10500,
				],
			]
		);

		$metric = $this->make_direct_paywall_metric( $proxy, $this->subscribers_with_gate( 12, 680.70 ), true );
		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 10500, $result['denominator'], 'uses checkout_impressions, not the diluted total impressions' );
		$this->assertSame( 12, $result['numerator'] );
		$this->assertEqualsWithDelta( 12 / 10500, $result['value'], 0.000001 );
	}

	/**
	 * NPPD-1817: with the capability column present, the numerator and denominator are
	 * both restricted to paywall-CAPABLE gates (checkout_impressions > 0). A capable
	 * gate that did NOT convert still enters the (tab-level) denominator; a converting-
	 * but-not-capable gate is excluded from BOTH sides — keeping the rate on one
	 * population and reconciling with the per-gate table. The excluded conversion still
	 * belongs to the inclusive count surfaces.
	 */
	public function test_paywall_conversion_direct_restricts_numerator_to_capable_gates() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				// Capable + converting.
				[
					'gate_post_id'         => 77,
					'impressions'          => 5000,
					'checkout_impressions' => 300,
				],
				// Capable but did NOT convert — still in the capable denominator.
				[
					'gate_post_id'         => 88,
					'impressions'          => 2000,
					'checkout_impressions' => 100,
				],
				// Converting but NOT paywall-capable (no checkout button) → excluded from both sides.
				[
					'gate_post_id'         => 99,
					'impressions'          => 1500,
					'checkout_impressions' => 0,
				],
			]
		);
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->method( 'get_attributed_subscription_conversions' )->willReturn(
			[
				'by_gate'  => [
					'77' => [
						'conversions' => 30,
						'revenue'     => 0.0,
					],
					'99' => [
						'conversions' => 20,
						'revenue'     => 0.0,
					],
				],
				'by_popup' => [],
			]
		);

		$metric = $this->make_direct_paywall_metric( $proxy, $subscribers, true );
		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 400, $result['denominator'], 'tab-level capable impressions (300 + 100); non-capable gate 99 excluded' );
		$this->assertSame( 30, $result['numerator'], 'gate 99\'s 20 conversions are excluded — not paywall-capable' );
		$this->assertSame( 0.075, $result['value'], '30 / 400' );
	}

	/**
	 * No impressions for the converting gate → no denominator → not computable.
	 */
	public function test_paywall_conversion_direct_not_computable_without_impressions() {
		$metric = $this->make_direct_paywall_metric(
			$this->proxy_with_gate_impressions( 0 ),
			$this->subscribers_with_gate( 3, 0.0 ),
			true
		);

		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
	}

	/**
	 * Coherence guard: gate-surface conversions exceeding that gate's impressions
	 * must not fabricate a >100% rate — suppress to not-computable.
	 */
	public function test_paywall_conversion_direct_coherence_guard_suppresses_over_100() {
		$metric = $this->make_direct_paywall_metric(
			$this->proxy_with_gate_impressions( 2 ),
			$this->subscribers_with_gate( 10, 0.0 ),
			true
		);

		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'], 'a >100% cross-surface ratio is suppressed, not shown' );
	}

	/**
	 * Hybrid card: if the hub impressions call errors, the rate is genuinely
	 * uncomputable → error state (counts toward the tab-error banner).
	 */
	public function test_paywall_conversion_direct_errors_when_impressions_hub_errors() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_proxy_error', 'down' ) );

		$metric = $this->make_direct_paywall_metric( $proxy, $this->subscribers_with_gate( 1, 0.0 ), true );

		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'error', $result['state'] );
	}

	/**
	 * NPPD-1745 #3 (mirrored to paywall): a malformed impressions response (the hub
	 * succeeded but returned a non-array shape) errors the rate rather than collapsing
	 * to a fabricated "0 impressions → em-dash".
	 */
	public function test_paywall_conversion_direct_errors_on_malformed_impressions() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( 'not-an-array' ); // Malformed (non-array) hub response.

		$metric = $this->make_direct_paywall_metric( $proxy, $this->subscribers_with_gate( 1, 0.0 ), true );

		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'error', $result['state'], 'malformed impressions errors, not a fabricated 0%' );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
	}

	/**
	 * Non-WC publisher: the paywall rate is an empty state (not a fake 0%), and the
	 * order-meta numerator is never queried.
	 */
	public function test_paywall_conversion_direct_empty_state_when_not_woocommerce() {
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->expects( $this->never() )->method( 'get_attributed_subscription_conversions' );

		$metric = $this->make_direct_paywall_metric( null, $subscribers, false );
		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
	}

	/**
	 * Total paywall revenue sums the gate surface of the two-key order-meta map.
	 */
	public function test_total_paywall_revenue_direct_sums_order_meta() {
		$metric = $this->make_direct_paywall_metric( null, $this->subscribers_with_gate( 2, 99.99 ), true );

		$result = $metric->get_total_paywall_revenue_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 99.99, $result['value'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 2, $result['denominator'] );
	}

	/**
	 * Non-WC publisher: total paywall revenue is a not-computable empty state.
	 */
	public function test_total_paywall_revenue_direct_empty_state_when_not_woocommerce() {
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->expects( $this->never() )->method( 'get_attributed_subscription_conversions' );

		$metric = $this->make_direct_paywall_metric( null, $subscribers, false );
		$result = $metric->get_total_paywall_revenue_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 0.0, $result['value'] );
	}

	/**
	 * Avg revenue per paywall conversion is revenue ÷ conversions from the same
	 * order-meta gate surface.
	 */
	public function test_avg_revenue_per_paywall_conversion_from_order_meta() {
		$metric = $this->make_direct_paywall_metric( null, $this->subscribers_with_gate( 2, 200.00 ), true );

		$result = $metric->get_avg_revenue_per_paywall_conversion( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 100.0, $result['value'] );
		$this->assertTrue( $result['computable'] );
	}

	/**
	 * Avg revenue: zero conversions → non-computable $0.00, not divide-by-zero.
	 */
	public function test_avg_revenue_per_paywall_conversion_with_zero_conversions() {
		$metric = $this->make_direct_paywall_metric( null, $this->subscribers_with_gate( 0, 0.0 ), true );

		$result = $metric->get_avg_revenue_per_paywall_conversion( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
	}

	/**
	 * Total revenue and avg-per-conversion read ONE order-meta source, so they
	 * reconcile: total === avg × conversions. Replaces the prior "share a single
	 * proxy call" regression — both now source from the cached Subscribers_Metric
	 * gate surface, not a hub query.
	 */
	public function test_revenue_methods_reconcile_from_one_source() {
		$start = $this->make_date( '2026-03-22' );
		$end   = $this->make_date( '2026-04-21' );

		$metric = $this->make_direct_paywall_metric( null, $this->subscribers_with_gate( 2, 150.00 ), true );

		$total = $metric->get_total_paywall_revenue_direct( $start, $end );
		$avg   = $metric->get_avg_revenue_per_paywall_conversion( $start, $end );

		$this->assertSame( 150.00, $total['value'] );
		$this->assertSame( 75.0, $avg['value'] );
		$this->assertSame( $total['value'], $avg['value'] * $total['denominator'], 'total === avg × conversions' );
	}

	/**
	 * Funnel: maps BQ row to React-ready stages with pct_of_top.
	 */
	public function test_funnel_maps_bq_rows_to_stages() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'gates_funnel', $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn(
				[
					[
						'step_1_impression' => 1000,
						'step_2_engagement' => 200,
						'step_3_conversion' => 50,
					],
				]
			);

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_conversion_funnel( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 3, $result['stages'] );
		$this->assertSame( 1000, $result['stages'][0]['count'] );
		$this->assertSame( 200, $result['stages'][1]['count'] );
		$this->assertSame( 50, $result['stages'][2]['count'] );
		$this->assertEqualsWithDelta( 1.0, $result['stages'][0]['pct_of_top'], 0.001 );
		$this->assertEqualsWithDelta( 0.2, $result['stages'][1]['pct_of_top'], 0.001 );
		$this->assertEqualsWithDelta( 0.05, $result['stages'][2]['pct_of_top'], 0.001 );
	}

	/**
	 * Funnel reports state 'error' (with error code) and no stages on proxy error.
	 */
	public function test_funnel_returns_error_state_on_proxy_error() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_query_failed', 'BQ down' ) );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_conversion_funnel( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_query_failed', $result['error_code'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * Funnel reports state 'empty' (no stages) when the query returns no rows.
	 */
	public function test_funnel_returns_empty_state_on_no_rows() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_conversion_funnel( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * Distribution: maps BQ rows to React-ready buckets in the expected order.
	 */
	public function test_distribution_maps_bq_rows_to_buckets() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturn(
				[
					[
						'bucket'               => '1',
						'converters_in_bucket' => 50,
						'pct_of_converters'    => 0.5,
					],
					[
						'bucket'               => '2',
						'converters_in_bucket' => 30,
						'pct_of_converters'    => 0.3,
					],
					[
						'bucket'               => '3-5',
						'converters_in_bucket' => 15,
						'pct_of_converters'    => 0.15,
					],
					[
						'bucket'               => '6+',
						'converters_in_bucket' => 5,
						'pct_of_converters'    => 0.05,
					],
				]
			);

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_exposures_distribution( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 4, $result['buckets'] );
		$this->assertSame( 50, $result['buckets'][0]['count'] );
		$this->assertSame( 5, $result['buckets'][3]['count'] );
	}

	/**
	 * Distribution: buckets with missing rows from BQ default to zero, preserving order.
	 */
	public function test_distribution_handles_partial_buckets() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturn(
				[
					[
						'bucket'               => '1',
						'converters_in_bucket' => 80,
						'pct_of_converters'    => 0.8,
					],
					[
						'bucket'               => '6+',
						'converters_in_bucket' => 20,
						'pct_of_converters'    => 0.2,
					],
				]
			);

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_exposures_distribution( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 80, $result['buckets'][0]['count'] ); // bucket '1'.
		$this->assertSame( 0, $result['buckets'][1]['count'] );  // bucket '2' (missing).
		$this->assertSame( 0, $result['buckets'][2]['count'] );  // bucket '3-5' (missing).
		$this->assertSame( 20, $result['buckets'][3]['count'] ); // bucket '6+'.
	}

	/**
	 * Performance by gate: maps BQ rows and enriches gate_post_id with post titles.
	 */
	public function test_performance_by_gate_enriches_titles() {
		// Seed two gates.
		$gate_a = $this->factory->post->create(
			[
				'post_title' => 'Welcome paywall',
				'post_type'  => 'newspack_popups_cpt',
			] 
		);
		$gate_b = $this->factory->post->create(
			[
				'post_title' => 'Member regwall',
				'post_type'  => 'newspack_popups_cpt',
			] 
		);

		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturn(
				[
					[
						'gate_post_id'            => (string) $gate_a,
						'impressions'             => 5000,
						'unique_viewers'          => 1200,
						'registrations'           => 0,
						'regwall_conversion_rate' => null,
						'checkout_impressions'    => 200,
					],
					[
						'gate_post_id'            => (string) $gate_b,
						'impressions'             => 3000,
						'unique_viewers'          => 900,
						'registrations'           => 150,
						'regwall_conversion_rate' => 0.07,
						'checkout_impressions'    => 0,
					],
				]
			);

		// WC off → paywall columns are null (covered with WC on by the dedicated
		// per-gate paywall tests below). Title enrichment is independent of WC.
		add_filter( 'newspack_insights_woocommerce_active', '__return_false' );
		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_performance_by_gate( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 2, $result['rows'] );

		// Lock the canonical row-key contract the React layer consumes
		// (`GatesPerformanceRow` in src/wizards/insights/api/gates.ts). These keys
		// mirror the table's output one-for-one; a rename on either side silently
		// blanks the table, so assert the exact set.
		$this->assertSame(
			[
				'gate_post_id',
				'gate_name',
				'impressions',
				'unique_viewers',
				'registrations',
				'regwall_conversion_rate',
				'paywall_conversions',
				'paywall_conversion_rate',
			],
			array_keys( $result['rows'][0] )
		);

		$this->assertSame( 'Welcome paywall', $result['rows'][0]['gate_name'] );
		$this->assertSame( 5000, $result['rows'][0]['impressions'] );
		$this->assertSame( 1200, $result['rows'][0]['unique_viewers'] );
		$this->assertSame( 0, $result['rows'][0]['registrations'] );
		$this->assertNull( $result['rows'][0]['regwall_conversion_rate'] );
		$this->assertNull( $result['rows'][0]['paywall_conversions'], 'WC inactive → paywall columns null' );
		$this->assertNull( $result['rows'][0]['paywall_conversion_rate'] );

		$this->assertSame( 'Member regwall', $result['rows'][1]['gate_name'] );
		$this->assertSame( 150, $result['rows'][1]['registrations'] );
		$this->assertSame( 0.07, $result['rows'][1]['regwall_conversion_rate'] );
		$this->assertNull( $result['rows'][1]['paywall_conversions'] );
		$this->assertNull( $result['rows'][1]['paywall_conversion_rate'] );
	}

	/**
	 * Performance by gate: state 'error' (with error code) and no rows on proxy error.
	 */
	public function test_performance_by_gate_returns_error_state_on_proxy_error() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_query_failed', 'BQ down' ) );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_performance_by_gate( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_query_failed', $result['error_code'] );
		$this->assertSame( [], $result['rows'] );
	}

	/**
	 * Performance by gate: state 'empty' (no rows) when the query returns no rows.
	 */
	public function test_performance_by_gate_returns_empty_state_on_no_rows() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_performance_by_gate( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['rows'] );
	}

	/**
	 * NPPD-1686: per-gate PAYWALL CONVERSIONS — gate-attributed subscription conversions
	 * (Woo order meta, `by_gate`) ÷ that gate's checkout-capable impressions. A
	 * paywall-capable gate (checkout_impressions > 0) surfaces its conversions + rate; a
	 * regwall-only gate (checkout_impressions 0) gets null paywall columns (em-dash).
	 */
	public function test_performance_by_gate_paywall_conversions_per_gate() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'gate_post_id'         => 77, // Paywall-capable + converted (subscribers_with_gate keys gate 77).
					'impressions'          => 5000,
					'checkout_impressions' => 300,
				],
				[
					'gate_post_id'         => 88, // Regwall-only: no checkout impressions.
					'impressions'          => 3000,
					'registrations'        => 150,
					'checkout_impressions' => 0,
				],
			]
		);
		$metric = $this->make_direct_paywall_metric( $proxy, $this->subscribers_with_gate( 3, 150.0 ), true );
		$result = $metric->get_performance_by_gate( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		// Gate 77: 3 order-meta conversions over 300 checkout impressions.
		$this->assertSame( 3, $result['rows'][0]['paywall_conversions'] );
		$this->assertEqualsWithDelta( 0.01, $result['rows'][0]['paywall_conversion_rate'], 0.0001, '3 / 300 checkout impressions, not / 5000 total' );
		// Gate 88: regwall-only → paywall columns null, not a misleading 0.
		$this->assertNull( $result['rows'][1]['paywall_conversions'] );
		$this->assertNull( $result['rows'][1]['paywall_conversion_rate'] );
	}

	/**
	 * NPPD-1686: a paywall-capable gate (checkout_impressions > 0) that converted nobody
	 * is a real 0 / 0%, not an em-dash — distinct from the regwall-only null above.
	 */
	public function test_performance_by_gate_capable_gate_zero_completions_is_real_zero() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'gate_post_id'         => 77,
					'impressions'          => 5000,
					'checkout_impressions' => 300,
				],
			]
		);
		// Gate 77 is paywall-capable but drove zero subscriptions.
		$metric = $this->make_direct_paywall_metric( $proxy, $this->subscribers_with_gate( 0, 0.0 ), true );
		$result = $metric->get_performance_by_gate( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 0, $result['rows'][0]['paywall_conversions'], 'capable gate shows a real 0, not null' );
		$this->assertSame( 0.0, $result['rows'][0]['paywall_conversion_rate'], 'real 0%, not an em-dash' );
	}

	/**
	 * NPPD-1686: the per-gate table and the scalar paywall denominator both read
	 * `gates_performance_by_gate`; the per-window memo collapses them to a single
	 * round-trip (no second hub call when both render on the same request).
	 */
	public function test_performance_by_gate_shares_hub_fetch_with_scalar() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'gates_performance_by_gate', $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn(
				[
					[
						'gate_post_id'         => 77,
						'impressions'          => 5000,
						'checkout_impressions' => 300,
					],
				]
			);
		$metric = $this->make_direct_paywall_metric( $proxy, $this->subscribers_with_gate( 3, 150.0 ), true );
		$start  = $this->make_date( '2026-03-22' );
		$end    = $this->make_date( '2026-04-21' );

		$metric->get_performance_by_gate( $start, $end );
		$metric->get_paywall_conversion_direct( $start, $end );
		// expects( $this->once() ) asserts the single shared fetch across both callers.
	}

	/**
	 * Performance by gate: missing gate post ID falls back to a generic label.
	 */
	public function test_performance_by_gate_handles_missing_title() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturn(
				[
					[
						'gate_post_id'            => '999999', // No such post.
						'impressions'             => 100,
						'unique_viewers'          => 50,
						'registrations'           => 5,
						'regwall_conversion_rate' => 0.05,
						'paywall_attempts'        => 0,
						'paywall_attempt_rate'    => null,
					],
				]
			);

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_performance_by_gate( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertCount( 1, $result['rows'] );
		$this->assertStringContainsString( '999999', $result['rows'][0]['gate_name'] ); // Generic fallback.
	}

	// --- NPPD-1694: envelope additions for the empty-state pattern ----------

	/**
	 * Stub Subscribers_Metric with empty surfaces — for the no-conversions paywall
	 * cases (the gate map only ever contains gates that converted).
	 *
	 * @return Subscribers_Metric
	 */
	private function empty_subscribers(): Subscribers_Metric {
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->method( 'get_attributed_subscription_conversions' )->willReturn(
			[
				'by_gate'  => [],
				'by_popup' => [],
			]
		);
		return $subscribers;
	}

	/**
	 * A populated paywall rate scorecard surfaces both numerator (gate-attributed
	 * conversions) and denominator (impressions of those gates) so the card can
	 * render "N of M" (NPPD-1694).
	 */
	public function test_paywall_rate_surfaces_numerator_and_denominator() {
		$metric = $this->make_direct_paywall_metric(
			$this->proxy_with_gate_impressions( 17 ),
			$this->subscribers_with_gate( 3, 0.0 ),
			true
		);

		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 3, $result['numerator'] );
		$this->assertSame( 17, $result['denominator'] );
		$this->assertTrue( $result['computable'] );
	}

	/**
	 * No gate-attributed conversions → the gate map is empty, so numerator and
	 * denominator are both an explicit 0 and the rate is not computable (the em-dash
	 * "no paywall conversions" state). In the per-gate-keyed model there is no
	 * attempt-based "0 of N": the denominator is impressions of CONVERTING gates,
	 * and with none converting there is nothing to divide by.
	 */
	public function test_paywall_rate_not_computable_when_no_conversions() {
		$metric = $this->make_direct_paywall_metric(
			$this->proxy_with_gate_impressions( 17 ),
			$this->empty_subscribers(),
			true
		);

		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 0, $result['numerator'] );
		$this->assertSame( 0, $result['denominator'] );
		$this->assertFalse( $result['computable'] );
	}

	/**
	 * The error scalar carries a null numerator alongside the null denominator: when
	 * the hub impressions denominator errors, the hybrid rate is an error state.
	 */
	public function test_error_scalar_includes_null_numerator() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_query_failed', 'BQ down' ) );

		$metric = $this->make_direct_paywall_metric( $proxy, $this->subscribers_with_gate( 1, 0.0 ), true );
		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'error', $result['state'] );
		$this->assertArrayHasKey( 'numerator', $result );
		$this->assertNull( $result['numerator'] );
	}

	/**
	 * Paid section totals: attempts come from the Direct denominator; conversions
	 * are the inclusive max of the Direct numerator and the Influenced denominator
	 * (the BQ-internal converter count — the Influenced metric has no numerator).
	 */
	public function test_paywall_section_totals_normal_data() {
		$totals = Gates_Metric::paywall_section_totals(
			[
				'denominator' => 17,
				'numerator'   => 2,
			],
			[
				'denominator' => 8,
				'numerator'   => null,
			]
		);

		$this->assertSame( 17, $totals['paywall_impressions_total'] );
		// max( direct numerator 2, influenced denominator 8 ) — don't hide Influenced converters.
		$this->assertSame( 8, $totals['paywall_conversions_total'] );
	}

	/**
	 * Paid section totals: attempts > 0 but zero conversions in either attribution
	 * (no Direct conversions, no Influenced converters) → the no_conversions trigger.
	 */
	public function test_paywall_section_totals_zero_conversions() {
		$totals = Gates_Metric::paywall_section_totals(
			[
				'denominator' => 17,
				'numerator'   => 0,
			],
			[
				'denominator' => 0,
				'numerator'   => null,
			]
		);

		$this->assertSame( 17, $totals['paywall_impressions_total'] );
		$this->assertSame( 0, $totals['paywall_conversions_total'] );
	}

	/**
	 * Regression (PR #443 review): an Influenced-only window — zero Direct
	 * conversions but a positive Influenced result — must still render. The
	 * BQ-internal Influenced metric reports its converter count as `denominator`
	 * (no numerator), so the section total picks it up and the scorecards are not
	 * hidden behind the "No paywall conversions" empty state.
	 */
	public function test_paywall_section_totals_influenced_only_renders() {
		$totals = Gates_Metric::paywall_section_totals(
			[
				'denominator' => 17,
				'numerator'   => 0,
			],
			[
				'denominator' => 8,
				'numerator'   => null,
			]
		);

		$this->assertSame( 8, $totals['paywall_conversions_total'] );
	}

	/**
	 * Paid section totals: zero attempts → no_opportunity. Missing/null keys
	 * coerce to 0 rather than warning (e.g. an error scalar with null numerator).
	 */
	public function test_paywall_section_totals_zero_attempts_and_missing_keys() {
		$zero = Gates_Metric::paywall_section_totals(
			[
				'denominator' => 0,
				'numerator'   => 0,
			],
			[
				'denominator' => 0,
				'numerator'   => 0,
			]
		);
		$this->assertSame( 0, $zero['paywall_impressions_total'] );
		$this->assertSame( 0, $zero['paywall_conversions_total'] );

		$missing = Gates_Metric::paywall_section_totals( [], [] );
		$this->assertSame( 0, $missing['paywall_impressions_total'] );
		$this->assertSame( 0, $missing['paywall_conversions_total'] );
	}

	// --- NPPD-1702: Free-section count fields + empty-state envelope ---------

	/**
	 * When the hub returns the new count columns, the regwall rate scalar surfaces
	 * them as numerator (registrations) + denominator (impressions), same shape as
	 * the Paid rate scalars — so the card can render "0 of N".
	 *
	 * @dataProvider provide_regwall_methods
	 * @param string $method          Method on Gates_Metric to call.
	 * @param string $query_name      Catalog query name.
	 * @param string $rate_key        Precomputed-rate column.
	 * @param string $denominator_col Column holding the denominator count.
	 * @param string $numerator_col   Column holding the numerator count.
	 */
	public function test_regwall_surfaces_counts_when_fields_present( string $method, string $query_name, string $rate_key, string $denominator_col, string $numerator_col ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn(
				[
					[
						$rate_key        => 0.05,
						$denominator_col => 200,
						$numerator_col   => 10,
					],
				]
			);

		$metric = new Gates_Metric( $proxy );
		$result = $metric->$method( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 0.05, $result['value'] );
		$this->assertSame( 200, $result['denominator'] );
		$this->assertSame( 10, $result['numerator'] );
	}

	/**
	 * Production-safety crux: when the hub has NOT deployed the count columns, the
	 * regwall scalar's numerator/denominator stay null — byte-for-byte today's
	 * envelope — so the React layer renders percentages, not an empty state. An
	 * absent field is not a zero.
	 *
	 * @dataProvider provide_regwall_methods
	 * @param string $method     Method on Gates_Metric to call.
	 * @param string $query_name Catalog query name (unused; dispatch asserted elsewhere).
	 * @param string $rate_key   Precomputed-rate column.
	 */
	public function test_regwall_counts_null_when_fields_absent( string $method, string $query_name, string $rate_key ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		// Only the precomputed rate column — no count columns (pre-hub-deploy).
		$proxy->method( 'query' )->willReturn( [ [ $rate_key => 0.08 ] ] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->$method( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 0.08, $result['value'] );
		$this->assertNull( $result['denominator'] );
		$this->assertNull( $result['numerator'] );
	}

	/**
	 * A half-populated response (one count column present, the other absent) is
	 * treated as absent — both counts null — so a malformed envelope degrades to
	 * percentages rather than half-rendering a count fallback.
	 */
	public function test_regwall_counts_null_when_only_one_field_present() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'regwall_conversion_rate_direct' => 0.05,
					'registration_impressions_total' => 200,
					// registrations_total absent.
				],
			]
		);

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_regwall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertNull( $result['denominator'] );
		$this->assertNull( $result['numerator'] );
	}

	/**
	 * A present-but-zero impressions column is a real "no impressions" signal, NOT
	 * absence: it surfaces as denominator 0 (the section's no_opportunity trigger),
	 * distinct from the null-denominator degradation path.
	 */
	public function test_regwall_counts_present_zero_is_not_absent() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'regwall_conversion_rate_direct' => 0.0,
					'registration_impressions_total' => 0,
					'registrations_total'            => 0,
				],
			]
		);

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_regwall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 0, $result['denominator'] );
		$this->assertSame( 0, $result['numerator'] );
	}

	/**
	 * A non-integer count column signals catalog drift and is treated as absent
	 * (null), not truncated — the section degrades rather than trusting bad data.
	 */
	public function test_regwall_counts_null_on_non_integer_field() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'regwall_conversion_rate_direct' => 0.05,
					'registration_impressions_total' => 12.5,
					'registrations_total'            => 3,
				],
			]
		);

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_regwall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertNull( $result['denominator'] );
		$this->assertNull( $result['numerator'] );
	}

	/**
	 * Data provider: the two regwall rate methods + their catalog dispatch.
	 *
	 * @return array
	 */
	public function provide_regwall_methods(): array {
		return [
			// method, query, rate_key, denominator_col, numerator_col. NPPD-1821: the
			// influenced query is converter-denominated, so it exposes different count
			// columns than Direct.
			'direct'     => [ 'get_regwall_conversion_direct', 'gates_regwall_conversion_direct', 'regwall_conversion_rate_direct', 'registration_impressions_total', 'registrations_total' ],
			'influenced' => [ 'get_regwall_conversion_influenced_7d', 'gates_regwall_conversion_influenced_7d', 'regwall_conversion_influenced', 'new_registrations_total', 'influenced_registrations_total' ],
		];
	}

	/**
	 * Free section totals: impressions from the Direct denominator; registrations
	 * the inclusive max across Direct and Influenced numerators.
	 */
	public function test_regwall_section_totals_normal_data() {
		$totals = Gates_Metric::regwall_section_totals(
			[
				'denominator' => 14000,
				'numerator'   => 994,
			],
			[
				'denominator' => 12000,
				'numerator'   => 1476,
			]
		);

		$this->assertSame( 14000, $totals['registration_impressions_total'] );
		// max( direct 994, influenced 1476 ) — don't hide Influenced-only registrations.
		$this->assertSame( 1476, $totals['registrations_total'] );
	}

	/**
	 * Free section totals: impressions > 0 but zero registrations in either
	 * attribution → the section's no_conversions trigger (a present 0, not null).
	 */
	public function test_regwall_section_totals_zero_registrations() {
		$totals = Gates_Metric::regwall_section_totals(
			[
				'denominator' => 14000,
				'numerator'   => 0,
			],
			[
				'denominator' => 12000,
				'numerator'   => 0,
			]
		);

		$this->assertSame( 14000, $totals['registration_impressions_total'] );
		$this->assertSame( 0, $totals['registrations_total'] );
	}

	/**
	 * Free section totals: a present-zero impressions count → no_opportunity. This
	 * is a real 0, distinct from the null degradation path below.
	 */
	public function test_regwall_section_totals_zero_impressions() {
		$totals = Gates_Metric::regwall_section_totals(
			[
				'denominator' => 0,
				'numerator'   => 0,
			],
			[
				'denominator' => 0,
				'numerator'   => 0,
			]
		);

		$this->assertSame( 0, $totals['registration_impressions_total'] );
		$this->assertSame( 0, $totals['registrations_total'] );
	}

	/**
	 * The production-safety crux at the helper level: when the regwall scalars
	 * carry null counts (hub fields absent), the totals are null — NOT 0 — so the
	 * React layer degrades to percentages instead of a false no_opportunity. This
	 * is the deliberate divergence from `paywall_section_totals`, which coerces to
	 * 0 because its denominator is always computed locally.
	 */
	public function test_regwall_section_totals_null_when_fields_absent() {
		$absent = Gates_Metric::regwall_section_totals(
			[
				'denominator' => null,
				'numerator'   => null,
			],
			[
				'denominator' => null,
				'numerator'   => null,
			]
		);
		$this->assertNull( $absent['registration_impressions_total'] );
		$this->assertNull( $absent['registrations_total'] );

		// Missing keys entirely (e.g. a payload shape that predates the field) also
		// degrade to null, not 0.
		$missing = Gates_Metric::regwall_section_totals( [], [] );
		$this->assertNull( $missing['registration_impressions_total'] );
		$this->assertNull( $missing['registrations_total'] );
	}

	/**
	 * End-to-end envelope guard: a fields-absent proxy response produces a regwall
	 * scalar with null counts AND null section totals — the whole graceful-
	 * degradation path, asserted through the public method that the REST controller
	 * consumes. This is the test that fails loudly if a future refactor reintroduces
	 * a `?? 0` and silently breaks a working production section.
	 */
	public function test_regwall_envelope_unchanged_when_hub_fields_absent() {
		// A pre-hub-deploy response: each query returns its precomputed rate but
		// neither count column. Both regwall scalars are produced by their real
		// public methods (not hand-built) so the guard exercises the full path the
		// REST controller consumes — including the Influenced method's own rate_key.
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturnCallback(
			static function ( string $query_name ) {
				if ( 'gates_regwall_conversion_influenced_7d' === $query_name ) {
					return [ [ 'regwall_conversion_influenced' => 0.123 ] ];
				}
				return [ [ 'regwall_conversion_rate_direct' => 0.071 ] ];
			}
		);

		$metric     = new Gates_Metric( $proxy );
		$direct     = $metric->get_regwall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );
		$influenced = $metric->get_regwall_conversion_influenced_7d( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );
		$totals     = Gates_Metric::regwall_section_totals( $direct, $influenced );

		// Rates compute; counts stay null on both scalars (columns absent).
		$this->assertTrue( $direct['computable'] );
		$this->assertNull( $direct['denominator'] );
		$this->assertNull( $direct['numerator'] );
		$this->assertTrue( $influenced['computable'] );
		$this->assertNull( $influenced['denominator'] );
		$this->assertNull( $influenced['numerator'] );
		// Section totals degrade to null (never `?? 0`), the production-safety crux.
		$this->assertNull( $totals['registration_impressions_total'] );
		$this->assertNull( $totals['registrations_total'] );
	}

	// --- C1: data_missing flag on populated_scalar ---

	/**
	 * Populated_scalar carries a `data_missing` flag (default false); callers can
	 * set it to true to signal schema drift without changing the payload shape.
	 */
	public function test_populated_scalar_includes_data_missing_flag() {
		$metric = new Gates_Metric();
		$ref    = new \ReflectionMethod( $metric, 'populated_scalar' );
		$ref->setAccessible( true );
		$default = $ref->invoke( $metric, 1.0, true, 5, 'rate' );
		$this->assertArrayHasKey( 'data_missing', $default );
		$this->assertFalse( $default['data_missing'] );
		$flagged = $ref->invoke( $metric, 0.0, false, null, 'rate', null, true );
		$this->assertTrue( $flagged['data_missing'] );
	}

	/**
	 * Gates_Metric no longer depends on Woo_Order_Resolver. Verifies structurally
	 * (via reflection on constructor parameters and typed properties) that the
	 * dependency is gone, and that the class instantiates with zero arguments.
	 */
	public function test_constructor_needs_no_woo_order_resolver() {
		$class    = new \ReflectionClass( \Newspack\Insights\Gates_Metric::class );
		$resolver = \Newspack\Insights\Woo_Order_Resolver::class;

		foreach ( $class->getConstructor()->getParameters() as $param ) {
			$type = $param->getType();
			$name = $type instanceof \ReflectionNamedType ? ltrim( $type->getName(), '\\' ) : null;
			$this->assertNotSame( $resolver, $name, 'Constructor must not depend on Woo_Order_Resolver.' );
		}
		foreach ( $class->getProperties() as $property ) {
			$type = $property->getType();
			$name = $type instanceof \ReflectionNamedType ? ltrim( $type->getName(), '\\' ) : null;
			$this->assertNotSame( $resolver, $name, 'No property may be typed Woo_Order_Resolver.' );
		}

		$this->assertInstanceOf( \Newspack\Insights\Gates_Metric::class, new \Newspack\Insights\Gates_Metric() );
	}

	/**
	 * A non-integer numeric denominator (e.g. 8.5) is malformed for a count and
	 * errors rather than being silently truncated.
	 */
	public function test_paywall_influenced_non_integer_denominator_errors() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'paywall_conversion_influenced_rate' => 0.25,
					'conversion_denominator'             => 8.5,
				],
			]
		);
		$metric = new Gates_Metric( $proxy );
		$out    = $metric->get_paywall_conversion_influenced_14d( $this->make_date( '2026-06-01' ), $this->make_date( '2026-06-15' ) );
		$this->assertSame( 'error', $out['state'] );
	}

	/**
	 * Empty rows from the hub → non-computable (no data in window).
	 */
	public function test_paywall_influenced_empty_rows_is_non_computable() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [] );
		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_paywall_conversion_influenced_14d( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
	}

	// --- BQ-internal influenced rate (hub-computed, no Woo join) ---

	/**
	 * Happy path: hub returns a single row with the precomputed rate and denominator.
	 */
	public function test_paywall_influenced_reads_rate_and_denominator() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'paywall_conversion_influenced_rate' => 0.25,
					'conversion_denominator'             => 8,
				],
			] 
		);
		$metric = new Gates_Metric( $proxy );
		$out    = $metric->get_paywall_conversion_influenced_14d( $this->make_date( '2026-06-01' ), $this->make_date( '2026-06-15' ) );
		$this->assertSame( 0.25, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 8, $out['denominator'] );
		$this->assertFalse( $out['data_missing'] );
	}

	/**
	 * Null rate (SAFE_DIVIDE with 0 converters) is non-computable but carries
	 * the denominator so the UI can distinguish "no data" from "no converters".
	 */
	public function test_paywall_influenced_null_rate_is_non_computable_with_denominator() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'paywall_conversion_influenced_rate' => null,
					'conversion_denominator'             => 0,
				],
			] 
		);
		$metric = new Gates_Metric( $proxy );
		$out    = $metric->get_paywall_conversion_influenced_14d( $this->make_date( '2026-06-01' ), $this->make_date( '2026-06-15' ) );
		$this->assertFalse( $out['computable'] );
		$this->assertSame( 0, $out['denominator'] );
		$this->assertFalse( $out['data_missing'] );
	}

	/**
	 * Missing expected columns (schema drift) flags data_missing rather than erroring.
	 */
	public function test_paywall_influenced_missing_column_flags_data_missing() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [ [ 'something_else' => 1 ] ] );
		$metric = new Gates_Metric( $proxy );
		$out    = $metric->get_paywall_conversion_influenced_14d( $this->make_date( '2026-06-01' ), $this->make_date( '2026-06-15' ) );
		$this->assertTrue( $out['data_missing'] );
	}

	/**
	 * A proxy WP_Error bubbles up as state 'error'.
	 */
	public function test_paywall_influenced_proxy_error_is_error_scalar() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'boom', 'nope' ) );
		$metric = new Gates_Metric( $proxy );
		$out    = $metric->get_paywall_conversion_influenced_14d( $this->make_date( '2026-06-01' ), $this->make_date( '2026-06-15' ) );
		$this->assertSame( 'error', $out['state'] );
	}
}
