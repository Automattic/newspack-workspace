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
use Newspack\Insights\Woo_Order_Resolver;
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

	/**
	 * Paywall direct conversion rate: matched orders / BQ row count.
	 */
	public function test_paywall_conversion_direct_uses_woo_join() {
		$bq_rows = [
			[
				'user_pseudo_id' => '1',
				'session_id'     => 's1',
				'attempt_ts'     => '1000000000000000',
			],
			[
				'user_pseudo_id' => '2',
				'session_id'     => 's2',
				'attempt_ts'     => '1000001000000000',
			],
			[
				'user_pseudo_id' => '3',
				'session_id'     => 's3',
				'attempt_ts'     => '1000002000000000',
			],
			[
				'user_pseudo_id' => '4',
				'session_id'     => 's4',
				'attempt_ts'     => '1000003000000000',
			],
		];

		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'gates_paywall_conversion_direct', $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( $bq_rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 1 ); // 1 of 4 attempts converted.

		$metric = new Gates_Metric( $proxy, $resolver );
		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 0.25, $result['value'] );
		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 4, $result['denominator'] );
	}

	/**
	 * Paywall direct conversion rate: no attempts -> non-computable 0% (not error).
	 */
	public function test_paywall_conversion_direct_with_zero_denominator() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_paywall_conversion_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
	}

	/**
	 * Total paywall revenue (Direct): sum_completed_revenue passthrough.
	 */
	public function test_total_paywall_revenue_direct_sums_woo() {
		$bq_rows = [
			[
				'user_pseudo_id' => '1',
				'session_id'     => 's1',
				'attempt_ts'     => '1000000000000000',
			],
		];

		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( $bq_rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 99.99 );

		$metric = new Gates_Metric( $proxy, $resolver );
		$result = $metric->get_total_paywall_revenue_direct( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 99.99, $result['value'] );
		$this->assertSame( 'populated', $result['state'] );
	}

	/**
	 * Avg revenue per conversion is derived from the two queries it depends on.
	 */
	public function test_avg_revenue_per_paywall_conversion_derives_from_two_queries() {
		$bq_rows = [
			[
				'user_pseudo_id' => '1',
				'session_id'     => 's1',
				'attempt_ts'     => '1000000000000000',
			],
			[
				'user_pseudo_id' => '2',
				'session_id'     => 's2',
				'attempt_ts'     => '1000001000000000',
			],
		];

		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( $bq_rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 2 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 200.00 );

		$metric = new Gates_Metric( $proxy, $resolver );
		$result = $metric->get_avg_revenue_per_paywall_conversion( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 100.0, $result['value'] );
		$this->assertSame( 'populated', $result['state'] );
	}

	/**
	 * Avg revenue per conversion: zero conversions -> non-computable $0.00, not divide-by-zero.
	 */
	public function test_avg_revenue_per_paywall_conversion_with_zero_conversions() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [] );

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_avg_revenue_per_paywall_conversion( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
	}

	/**
	 * Avg revenue per conversion: non-empty BQ rows but zero matched orders -> placeholder.
	 *
	 * The realistic production case: paywall impressions led to checkout attempts,
	 * but none completed within the 30-min window. Distinct from the empty-rows
	 * path (no impressions at all).
	 */
	public function test_avg_revenue_per_paywall_conversion_with_zero_matched_orders() {
		$bq_rows = [
			[
				'user_pseudo_id' => '1',
				'session_id'     => 's1',
				'attempt_ts'     => '1000000000000000',
			],
			[
				'user_pseudo_id' => '2',
				'session_id'     => 's2',
				'attempt_ts'     => '1000001000000000',
			],
		];

		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( $bq_rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 0 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 0.0 );

		$metric = new Gates_Metric( $proxy, $resolver );
		$result = $metric->get_avg_revenue_per_paywall_conversion( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
	}

	/**
	 * Two revenue-side methods in the same request share a single BQ proxy call.
	 *
	 * Regression test: `get_total_paywall_revenue_direct` and
	 * `get_avg_revenue_per_paywall_conversion` both source from
	 * `gates_paywall_revenue_direct`. The orchestrator memoizes the join result
	 * so we never round-trip the hub twice per request for identical data.
	 */
	public function test_revenue_methods_share_single_proxy_call() {
		$bq_rows = [
			[
				'user_pseudo_id' => '1',
				'session_id'     => 's1',
				'attempt_ts'     => '1000000000000000',
			],
		];

		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		// `expects($this->once())` is the regression guard: a second call fails the test.
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'gates_paywall_revenue_direct', $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( $bq_rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 1 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 50.00 );

		$metric = new Gates_Metric( $proxy, $resolver );
		$start  = $this->make_date( '2026-03-22' );
		$end    = $this->make_date( '2026-04-21' );

		$total = $metric->get_total_paywall_revenue_direct( $start, $end );
		$avg   = $metric->get_avg_revenue_per_paywall_conversion( $start, $end );

		$this->assertSame( 50.00, $total['value'] );
		$this->assertSame( 50.0, $avg['value'] );
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
						'paywall_attempts'        => 80,
						'paywall_attempt_rate'    => 0.04,
					],
					[
						'gate_post_id'            => (string) $gate_b,
						'impressions'             => 3000,
						'unique_viewers'          => 900,
						'registrations'           => 150,
						'regwall_conversion_rate' => 0.07,
						'paywall_attempts'        => 0,
						'paywall_attempt_rate'    => null,
					],
				]
			);

		$metric = new Gates_Metric( $proxy );
		$result = $metric->get_performance_by_gate( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 2, $result['rows'] );

		// Lock the canonical row-key contract the React layer consumes
		// (`GatesPerformanceRow` in src/wizards/insights/api/gates.ts). These keys
		// mirror the `gates_performance_by_gate` catalog columns one-for-one; a
		// rename on either side silently blanks the table, so assert the exact set.
		$this->assertSame(
			[
				'gate_post_id',
				'gate_name',
				'impressions',
				'unique_viewers',
				'registrations',
				'regwall_conversion_rate',
				'paywall_attempts',
				'paywall_attempt_rate',
			],
			array_keys( $result['rows'][0] )
		);

		$this->assertSame( 'Welcome paywall', $result['rows'][0]['gate_name'] );
		$this->assertSame( 5000, $result['rows'][0]['impressions'] );
		$this->assertSame( 1200, $result['rows'][0]['unique_viewers'] );
		$this->assertSame( 0, $result['rows'][0]['registrations'] );
		$this->assertNull( $result['rows'][0]['regwall_conversion_rate'] );
		$this->assertSame( 80, $result['rows'][0]['paywall_attempts'] );
		$this->assertSame( 0.04, $result['rows'][0]['paywall_attempt_rate'] );

		$this->assertSame( 'Member regwall', $result['rows'][1]['gate_name'] );
		$this->assertSame( 150, $result['rows'][1]['registrations'] );
		$this->assertSame( 0.07, $result['rows'][1]['regwall_conversion_rate'] );
		$this->assertSame( 0, $result['rows'][1]['paywall_attempts'] );
		$this->assertNull( $result['rows'][1]['paywall_attempt_rate'] );
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
}
