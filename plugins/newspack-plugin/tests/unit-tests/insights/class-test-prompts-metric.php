<?php
/**
 * Test Prompts_Metric (NPPD-1607, Phase 2).
 *
 * Pins the state-envelope contract every wired scalar metric must satisfy:
 *   - state 'populated' with a real value on a successful proxy response,
 *   - state 'error' (with `error_code`/`error_message`) on a proxy WP_Error,
 *   - state 'populated' non-computable zero when the query succeeds with no
 *     usable value, and
 *   - state 'error' with `bigquery_proxy_malformed_value` when the catalog
 *     returns a non-numeric column.
 *
 * The 8 paid-conversion + revenue methods are also pinned (Task 3.2): they
 * dispatch through the BigQuery proxy, Woo-join the rows via Woo_Order_Resolver,
 * and share a per-window memoization cache (one proxy call per intent +
 * direction).
 *
 * The 5 collection methods are pinned (Task 3.3): funnel + distribution mirror
 * the Gates state-envelope contract one-for-one; the per-prompt performance
 * table additionally augments each row with per-popup Woo-completed donation
 * and subscription counts and is asserted to degrade gracefully (engagement
 * columns still render with zeros in the Woo columns) when the Woo-side proxy
 * call fails.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Prompts_Metric;
use Newspack\Insights\Woo_Order_Resolver;
use WP_UnitTestCase;

/**
 * Prompts_Metric test class.
 *
 * @group insights
 */
class Test_Prompts_Metric extends WP_UnitTestCase {

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
	 * Window start convenience.
	 */
	protected function start(): DateTimeImmutable {
		return $this->make_date( '2026-03-22' );
	}

	/**
	 * Window end convenience.
	 */
	protected function end(): DateTimeImmutable {
		return $this->make_date( '2026-04-21' );
	}

	/**
	 * Build a Prompts_Metric with a proxy stub that returns the given value
	 * from every `query()` call. Asserts the dispatched query name matches.
	 *
	 * @param string $expected_query_name The catalog name the orchestrator must dispatch.
	 * @param mixed  $return              Value the mock client returns (rows array or WP_Error).
	 * @return Prompts_Metric
	 */
	protected function make_metric_with_proxy_returning( string $expected_query_name, $return ): Prompts_Metric {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $expected_query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( $return );
		return new Prompts_Metric( $proxy );
	}

	/**
	 * Constructor accepts an injected proxy client (test seam).
	 */
	public function test_constructor_accepts_injected_proxy() {
		$proxy  = $this->createMock( BigQuery_Proxy_Client::class );
		$metric = new Prompts_Metric( $proxy );
		$this->assertInstanceOf( Prompts_Metric::class, $metric );
	}

	// --- Section 1: Prompt exposure ------------------------------------

	/**
	 * Wired scalar metrics: dispatch the right query, read the right row key,
	 * type-coerce per `placeholder_type`.
	 *
	 * @dataProvider provide_scalar_success_cases
	 * @param string $method           Method on Prompts_Metric to call.
	 * @param string $query_name       Catalog query name the orchestrator should dispatch.
	 * @param string $row_key          Column the orchestrator reads from row 0.
	 * @param mixed  $row_value        Value the mock client returns for that column.
	 * @param string $placeholder_type Expected `placeholder_type` in the payload.
	 * @param mixed  $expected_value   Expected `value` in the payload (already coerced).
	 */
	public function test_scalar_returns_populated_on_success( string $method, string $query_name, string $row_key, $row_value, string $placeholder_type, $expected_value ) {
		$metric = $this->make_metric_with_proxy_returning( $query_name, [ [ $row_key => $row_value ] ] );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( $expected_value, $result['value'] );
		$this->assertTrue( $result['computable'] );
		$this->assertNull( $result['denominator'] );
		$this->assertSame( $placeholder_type, $result['placeholder_type'] );
	}

	/**
	 * Wired scalar metrics: proxy WP_Error → state 'error' with code/message.
	 *
	 * @dataProvider provide_scalar_methods
	 * @param string $method           Method on Prompts_Metric to call.
	 * @param string $query_name       Catalog query name dispatched (unused but provided for symmetry).
	 * @param string $row_key          Row key (unused but provided for symmetry).
	 * @param string $placeholder_type Expected `placeholder_type` in the error payload.
	 */
	public function test_scalar_returns_error_state_on_proxy_error( string $method, string $query_name, string $row_key, string $placeholder_type ) {
		unset( $row_key );
		$metric = $this->make_metric_with_proxy_returning(
			$query_name,
			new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' )
		);
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertNull( $result['denominator'] );
		$this->assertSame( $placeholder_type, $result['placeholder_type'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 500', $result['error_message'] );
		// Value is a typed zero so React never crashes if it ignores `state`.
		$this->assertSame( 'decimal' === $placeholder_type ? 0.0 : 0, $result['value'] );
	}

	/**
	 * Wired scalar metrics: empty rows → state 'populated' non-computable zero.
	 *
	 * @dataProvider provide_scalar_methods
	 * @param string $method           Method on Prompts_Metric to call.
	 * @param string $query_name       Catalog query name dispatched.
	 * @param string $row_key          Row key (unused but provided for symmetry).
	 * @param string $placeholder_type Expected `placeholder_type` in the payload.
	 */
	public function test_scalar_returns_noncomputable_zero_on_empty( string $method, string $query_name, string $row_key, string $placeholder_type ) {
		unset( $row_key );
		$metric = $this->make_metric_with_proxy_returning( $query_name, [] );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertNull( $result['denominator'] );
		$this->assertSame( $placeholder_type, $result['placeholder_type'] );
		$this->assertSame( 'decimal' === $placeholder_type ? 0.0 : 0, $result['value'] );
	}

	/**
	 * Data provider for scalar success cases. Real values, type-coerced.
	 *
	 * @return array
	 */
	public function provide_scalar_success_cases(): array {
		return [
			// Section 1 — exposure.
			'total_impressions'       => [ 'get_total_prompt_impressions', 'prompts_total_impressions', 'prompt_impressions', 12345, 'count', 12345 ],
			'unique_viewers'          => [ 'get_unique_readers_reached', 'prompts_unique_viewers', 'unique_prompt_viewers', 678, 'count', 678 ],
			'avg_prompts_per_reader'  => [ 'get_avg_prompts_per_reader', 'prompts_avg_prompts_per_reader', 'avg_prompts_per_reader', 3.5, 'decimal', 3.5 ],
			// Section 2 — engagement.
			'click_through_rate'      => [ 'get_click_through_rate', 'prompts_click_through_rate', 'click_through_rate', 0.12, 'rate', 0.12 ],
			'form_submission_rate'    => [ 'get_form_submission_rate', 'prompts_form_submission_rate', 'form_submission_rate', 0.21, 'rate', 0.21 ],
			'dismissal_rate'          => [ 'get_dismissal_rate', 'prompts_dismissal_rate', 'dismissal_rate', 0.07, 'rate', 0.07 ],
			// Section 3 — free conversion.
			'registration_direct'     => [ 'get_registration_conversion_direct', 'prompts_registration_conversion_direct', 'registration_conversion_direct', 0.18, 'rate', 0.18 ],
			'registration_influenced' => [ 'get_registration_conversion_influenced_7d', 'prompts_registration_conversion_influenced_7d', 'registration_conversion_influenced', 0.31, 'rate', 0.31 ],
			'newsletter_direct'       => [ 'get_newsletter_signup_conversion_direct', 'prompts_newsletter_signup_conversion_direct', 'newsletter_signup_conversion_direct', 0.09, 'rate', 0.09 ],
			'newsletter_influenced'   => [ 'get_newsletter_signup_conversion_influenced_7d', 'prompts_newsletter_signup_conversion_influenced_7d', 'newsletter_signup_conversion_influenced', 0.14, 'rate', 0.14 ],
		];
	}

	/**
	 * Data provider for scalar methods (no value column — used by error /
	 * empty path tests where the column isn't returned at all).
	 *
	 * Tuple shape: [ method, query_name, row_key, placeholder_type ].
	 *
	 * @return array
	 */
	public function provide_scalar_methods(): array {
		return [
			'total_impressions'       => [ 'get_total_prompt_impressions', 'prompts_total_impressions', 'prompt_impressions', 'count' ],
			'unique_viewers'          => [ 'get_unique_readers_reached', 'prompts_unique_viewers', 'unique_prompt_viewers', 'count' ],
			'avg_prompts_per_reader'  => [ 'get_avg_prompts_per_reader', 'prompts_avg_prompts_per_reader', 'avg_prompts_per_reader', 'decimal' ],
			'click_through_rate'      => [ 'get_click_through_rate', 'prompts_click_through_rate', 'click_through_rate', 'rate' ],
			'form_submission_rate'    => [ 'get_form_submission_rate', 'prompts_form_submission_rate', 'form_submission_rate', 'rate' ],
			'dismissal_rate'          => [ 'get_dismissal_rate', 'prompts_dismissal_rate', 'dismissal_rate', 'rate' ],
			'registration_direct'     => [ 'get_registration_conversion_direct', 'prompts_registration_conversion_direct', 'registration_conversion_direct', 'rate' ],
			'registration_influenced' => [ 'get_registration_conversion_influenced_7d', 'prompts_registration_conversion_influenced_7d', 'registration_conversion_influenced', 'rate' ],
			'newsletter_direct'       => [ 'get_newsletter_signup_conversion_direct', 'prompts_newsletter_signup_conversion_direct', 'newsletter_signup_conversion_direct', 'rate' ],
			'newsletter_influenced'   => [ 'get_newsletter_signup_conversion_influenced_7d', 'prompts_newsletter_signup_conversion_influenced_7d', 'newsletter_signup_conversion_influenced', 'rate' ],
		];
	}

	/**
	 * Malformed scalar value (non-numeric) → state 'error' with
	 * `bigquery_proxy_malformed_value`. Single representative case — the
	 * branch is shared across every scalar method via compute_metric_from_proxy.
	 */
	public function test_scalar_rejects_non_numeric_value() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_total_impressions',
			[ [ 'prompt_impressions' => 'banana' ] ]
		);
		$result = $metric->get_total_prompt_impressions( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_value', $result['error_code'] );
	}

	// --- Section 4: Paid reader conversion (Woo join) -------------------

	/**
	 * Sample BQ rows in the shape returned by the 4 paid-conversion catalog
	 * queries. Shared across the conversion-rate / revenue tests.
	 *
	 * @return array
	 */
	protected function paid_attempt_rows(): array {
		return [
			[
				'user_pseudo_id' => '101',
				'session_id'     => 's1',
				'attempt_ts'     => 1717000000000000,
				'popup_id'       => '42',
			],
			[
				'user_pseudo_id' => '102',
				'session_id'     => 's2',
				'attempt_ts'     => 1717001000000000,
				'popup_id'       => '42',
			],
		];
	}

	/**
	 * Wired paid-conversion RATE methods: dispatch the query, Woo-join, and
	 * return `conversions / attempts` as a `placeholder_type: 'rate'` envelope.
	 *
	 * @dataProvider provide_paid_conversion_rate_methods
	 * @param string $method     Method on Prompts_Metric to call.
	 * @param string $query_name Catalog name the orchestrator must dispatch.
	 */
	public function test_paid_conversion_rate_returns_populated_on_success( string $method, string $query_name ) {
		$rows  = $this->paid_attempt_rows();
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( $rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 1 ); // 1 of 2 attempts converted.
		$resolver->method( 'sum_completed_revenue' )->willReturn( 25.0 );

		$metric = new Prompts_Metric( $proxy, $resolver );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 0.5, $result['value'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 2, $result['denominator'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	/**
	 * Wired paid-conversion RATE methods: proxy WP_Error → state 'error'.
	 *
	 * @dataProvider provide_paid_conversion_rate_methods
	 * @param string $method     Method on Prompts_Metric to call.
	 * @param string $query_name Catalog name the orchestrator must dispatch.
	 */
	public function test_paid_conversion_rate_returns_error_state_on_proxy_error( string $method, string $query_name ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' ) );

		$metric = new Prompts_Metric( $proxy, $this->createMock( Woo_Order_Resolver::class ) );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 500', $result['error_message'] );
		$this->assertSame( 0, $result['value'] );
	}

	/**
	 * Wired paid-conversion RATE methods: empty BQ response → non-computable
	 * 0.0 with denominator 0 (a real "no attempts in window", not an error).
	 *
	 * @dataProvider provide_paid_conversion_rate_methods
	 * @param string $method     Method on Prompts_Metric to call.
	 * @param string $query_name Catalog name the orchestrator must dispatch.
	 */
	public function test_paid_conversion_rate_returns_noncomputable_zero_on_empty( string $method, string $query_name ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( [] );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 0 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 0.0 );

		$metric = new Prompts_Metric( $proxy, $resolver );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 0, $result['denominator'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
		$this->assertSame( 0.0, $result['value'] );
	}

	/**
	 * Data provider for the 4 paid-conversion rate methods.
	 *
	 * @return array
	 */
	public function provide_paid_conversion_rate_methods(): array {
		return [
			'donation_direct'         => [ 'get_donation_conversion_direct', 'prompts_donation_conversion_direct' ],
			'donation_influenced'     => [ 'get_donation_conversion_influenced_14d', 'prompts_donation_conversion_influenced_14d' ],
			'subscription_direct'     => [ 'get_subscription_conversion_direct', 'prompts_subscription_conversion_direct' ],
			'subscription_influenced' => [ 'get_subscription_conversion_influenced_14d', 'prompts_subscription_conversion_influenced_14d' ],
		];
	}

	// --- Section 5: Revenue from prompts --------------------------------

	/**
	 * Wired revenue methods: dispatch the underlying CONVERSION query name
	 * (sharing cache with the matching rate method), return summed Woo revenue
	 * as a `placeholder_type: 'currency'` envelope.
	 *
	 * @dataProvider provide_paid_revenue_methods
	 * @param string $method     Method on Prompts_Metric to call.
	 * @param string $query_name Underlying conversion query name dispatched.
	 */
	public function test_paid_revenue_returns_populated_on_success( string $method, string $query_name ) {
		$rows  = $this->paid_attempt_rows();
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( $rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 1 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 25.0 );

		$metric = new Prompts_Metric( $proxy, $resolver );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 25.0, $result['value'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 1, $result['denominator'] );
		$this->assertSame( 'currency', $result['placeholder_type'] );
	}

	/**
	 * Wired revenue methods: proxy WP_Error → state 'error'.
	 *
	 * @dataProvider provide_paid_revenue_methods
	 * @param string $method     Method on Prompts_Metric to call.
	 * @param string $query_name Underlying conversion query name dispatched.
	 */
	public function test_paid_revenue_returns_error_state_on_proxy_error( string $method, string $query_name ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' ) );

		$metric = new Prompts_Metric( $proxy, $this->createMock( Woo_Order_Resolver::class ) );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 'currency', $result['placeholder_type'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 500', $result['error_message'] );
		$this->assertSame( 0, $result['value'] );
	}

	/**
	 * Wired revenue methods: empty BQ rows → $0.00 populated, computable=true
	 * (the sum is a real 0), denominator 0 (zero conversions matched).
	 *
	 * @dataProvider provide_paid_revenue_methods
	 * @param string $method     Method on Prompts_Metric to call.
	 * @param string $query_name Underlying conversion query name dispatched.
	 */
	public function test_paid_revenue_returns_zero_on_empty( string $method, string $query_name ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( [] );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 0 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 0.0 );

		$metric = new Prompts_Metric( $proxy, $resolver );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 0.0, $result['value'] );
		$this->assertSame( 0, $result['denominator'] );
		$this->assertSame( 'currency', $result['placeholder_type'] );
	}

	/**
	 * Data provider for the 4 revenue methods. The `query_name` is the
	 * underlying conversion-query name (revenue methods share the cache with
	 * their rate counterparts; the hub's revenue alias is byte-identical).
	 *
	 * @return array
	 */
	public function provide_paid_revenue_methods(): array {
		return [
			'donation_direct'         => [ 'get_donation_revenue_direct', 'prompts_donation_conversion_direct' ],
			'donation_influenced'     => [ 'get_donation_revenue_influenced_14d', 'prompts_donation_conversion_influenced_14d' ],
			'subscription_direct'     => [ 'get_subscription_revenue_direct', 'prompts_subscription_conversion_direct' ],
			'subscription_influenced' => [ 'get_subscription_revenue_influenced_14d', 'prompts_subscription_conversion_influenced_14d' ],
		];
	}

	/**
	 * Per-(query_name, window) memoization: the matching rate + revenue
	 * methods for the same intent + direction share one proxy round-trip.
	 *
	 * `expects($this->once())` is the regression guard — a second call fails
	 * the test. The two methods read different fields from the same Woo-joined
	 * result, so they must dispatch the underlying conversion query exactly
	 * once.
	 */
	public function test_paid_methods_share_single_proxy_call_per_window() {
		$rows  = $this->paid_attempt_rows();
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'prompts_donation_conversion_direct', $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( $rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 1 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 25.0 );

		$metric = new Prompts_Metric( $proxy, $resolver );
		$start  = $this->start();
		$end    = $this->end();

		$rate    = $metric->get_donation_conversion_direct( $start, $end );
		$revenue = $metric->get_donation_revenue_direct( $start, $end );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertSame( 0.5, $rate['value'] );
		$this->assertSame( 'populated', $revenue['state'] );
		$this->assertSame( 25.0, $revenue['value'] );
	}

	/**
	 * Memoization keys by (query_name, window): two different intents must
	 * NOT share a cache entry. Each distinct query_name triggers its own
	 * proxy round-trip; the cache only deduplicates within an intent.
	 */
	public function test_paid_cache_is_keyed_per_query_name() {
		$rows  = $this->paid_attempt_rows();
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		// Two distinct query_names → two proxy calls.
		$proxy->expects( $this->exactly( 2 ) )
			->method( 'query' )
			->willReturn( $rows );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 1 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 25.0 );

		$metric = new Prompts_Metric( $proxy, $resolver );
		$metric->get_donation_conversion_direct( $this->start(), $this->end() );
		$metric->get_subscription_conversion_direct( $this->start(), $this->end() );
	}

	// --- Section 6: Conversion funnel + exposures distribution ---------

	/**
	 * Funnel: maps the single BQ row to three React-ready stages with
	 * pct_of_top normalized against step 1.
	 */
	public function test_funnel_maps_bq_rows_to_stages() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_funnel',
			[
				[
					'step_1_impression' => 100,
					'step_2_engagement' => 20,
					'step_3_conversion' => 5,
				],
			]
		);
		$result = $metric->get_conversion_funnel( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 3, $result['stages'] );
		$this->assertSame( 100, $result['stages'][0]['count'] );
		$this->assertSame( 20, $result['stages'][1]['count'] );
		$this->assertSame( 5, $result['stages'][2]['count'] );
		$this->assertEqualsWithDelta( 1.0, $result['stages'][0]['pct_of_top'], 0.001 );
		$this->assertEqualsWithDelta( 0.2, $result['stages'][1]['pct_of_top'], 0.001 );
		$this->assertEqualsWithDelta( 0.05, $result['stages'][2]['pct_of_top'], 0.001 );
	}

	/**
	 * Funnel: empty rows → state 'empty' with no stages.
	 */
	public function test_funnel_returns_empty_state_on_no_rows() {
		$metric = $this->make_metric_with_proxy_returning( 'prompts_funnel', [] );
		$result = $metric->get_conversion_funnel( $this->start(), $this->end() );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * Funnel: proxy WP_Error → state 'error' with code + empty stages.
	 */
	public function test_funnel_returns_error_state_on_proxy_error() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_funnel',
			new \WP_Error( 'bigquery_query_failed', 'BQ down' )
		);
		$result = $metric->get_conversion_funnel( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_query_failed', $result['error_code'] );
		$this->assertSame( [], $result['stages'] );
	}

	/**
	 * Funnel: malformed shape (non-array row) → state 'error' with
	 * `bigquery_proxy_malformed_rows`.
	 */
	public function test_funnel_returns_error_state_on_malformed_response() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_funnel',
			[ 'not-a-row' ]
		);
		$result = $metric->get_conversion_funnel( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
	}

	/**
	 * Distribution: maps BQ rows to React-ready buckets in spec order
	 * (1 / 2 / 3-5 / 6+).
	 */
	public function test_distribution_maps_bq_rows_to_buckets() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_exposures_before_conversion',
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
		$result = $metric->get_exposures_distribution( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 4, $result['buckets'] );
		$this->assertSame( 50, $result['buckets'][0]['count'] );
		$this->assertSame( 30, $result['buckets'][1]['count'] );
		$this->assertSame( 15, $result['buckets'][2]['count'] );
		$this->assertSame( 5, $result['buckets'][3]['count'] );
	}

	/**
	 * Distribution: empty rows → state 'empty' with no buckets.
	 */
	public function test_distribution_returns_empty_state_on_no_rows() {
		$metric = $this->make_metric_with_proxy_returning( 'prompts_exposures_before_conversion', [] );
		$result = $metric->get_exposures_distribution( $this->start(), $this->end() );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['buckets'] );
	}

	/**
	 * Distribution: proxy WP_Error → state 'error' with empty buckets.
	 */
	public function test_distribution_returns_error_state_on_proxy_error() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_exposures_before_conversion',
			new \WP_Error( 'bigquery_query_failed', 'BQ down' )
		);
		$result = $metric->get_exposures_distribution( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_query_failed', $result['error_code'] );
		$this->assertSame( [], $result['buckets'] );
	}

	/**
	 * Distribution: malformed shape (non-array) → state 'error' with
	 * `bigquery_proxy_malformed_rows`.
	 */
	public function test_distribution_returns_error_state_on_malformed_response() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_exposures_before_conversion',
			'not-an-array'
		);
		$result = $metric->get_exposures_distribution( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
	}

	// --- Section 7: Performance breakdown ------------------------------

	/**
	 * Sample BQ row for the performance-by-prompt query. Columns mirror the
	 * hub `prompts_performance_by_prompt` SELECT one-for-one.
	 *
	 * @param int    $popup_id    Popup ID for the row.
	 * @param string $title       Prompt title.
	 * @param string $intent      One of `donation`, `registration`, `newsletters_subscription`.
	 * @param int    $impressions Impressions count (also used as ctr denominator on the publisher side).
	 * @return array
	 */
	protected function performance_row( int $popup_id, string $title, string $intent, int $impressions ): array {
		return [
			'popup_id'             => (string) $popup_id, // BQ emits string scalars.
			'prompt_title'         => $title,
			'intent'               => $intent,
			'placement'            => 'overlay',
			'impressions'          => $impressions,
			'unique_viewers'       => (int) round( $impressions * 0.8 ),
			'ctr'                  => 0.1,
			'form_submission_rate' => 0.05,
			'dismissal_rate'       => 0.02,
			'registrations'        => 0,
			'newsletter_signups'   => 0,
		];
	}

	/**
	 * Build a proxy mock that returns the performance-by-prompt rows on the
	 * main query call and the given donation/subscription rows on the
	 * augmentation queries (in dispatch order).
	 *
	 * @param array           $perf_rows         Rows for `prompts_performance_by_prompt`.
	 * @param array|\WP_Error $donation_rows Rows for `prompts_donation_conversion_direct`.
	 * @param array|\WP_Error $subscription_rows Rows for `prompts_subscription_conversion_direct`.
	 * @return BigQuery_Proxy_Client
	 */
	protected function make_performance_proxy( array $perf_rows, $donation_rows, $subscription_rows ): BigQuery_Proxy_Client {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		// Dispatch order: perf table, donation augmentation, subscription augmentation.
		$proxy->expects( $this->exactly( 3 ) )
			->method( 'query' )
			->willReturnCallback(
				function ( $query_name ) use ( $perf_rows, $donation_rows, $subscription_rows ) {
					switch ( $query_name ) {
						case 'prompts_performance_by_prompt':
							return $perf_rows;
						case 'prompts_donation_conversion_direct':
							return $donation_rows;
						case 'prompts_subscription_conversion_direct':
							return $subscription_rows;
					}
					return null;
				}
			);
		return $proxy;
	}

	/**
	 * Performance by prompt: maps BQ rows and intent-scopes Woo-completed
	 * donation / subscription counts per popup.
	 */
	public function test_performance_by_prompt_augments_with_woo_counts() {
		$perf_rows = [
			$this->performance_row( 42, 'Donate now', 'donation', 1000 ),
			$this->performance_row( 99, 'Join us', 'registration', 500 ),
		];
		$donation_attempts = [
			[
				'popup_id'       => '42',
				'user_pseudo_id' => 'u1',
				'session_id'     => 's1',
				'attempt_ts'     => 1717000000000000,
			],
			[
				'popup_id'       => '42',
				'user_pseudo_id' => 'u2',
				'session_id'     => 's2',
				'attempt_ts'     => 1717001000000000,
			],
		];
		$subscription_attempts = [
			[
				'popup_id'       => '99',
				'user_pseudo_id' => 'u3',
				'session_id'     => 's3',
				'attempt_ts'     => 1717002000000000,
			],
		];

		$proxy = $this->make_performance_proxy( $perf_rows, $donation_attempts, $subscription_attempts );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		// Each per-popup augmentation call gets its own scoped row subset; we
		// stub a fixed return per call regardless of which subset arrived. The
		// per-popup grouping itself is what the test exercises.
		$resolver->method( 'count_completed_orders' )->willReturn( 5 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 0.0 );

		$metric = new Prompts_Metric( $proxy, $resolver );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 2, $result['rows'] );

		// Donation popup → donation columns populated, subscription columns zeroed.
		$this->assertSame( 42, $result['rows'][0]['popup_id'] );
		$this->assertSame( 'Donate now', $result['rows'][0]['prompt_title'] );
		$this->assertSame( 'donation', $result['rows'][0]['intent'] );
		$this->assertSame( 5, $result['rows'][0]['donation_conversions'] );
		$this->assertEqualsWithDelta( 0.005, $result['rows'][0]['donation_conversion_rate'], 0.0001 );
		$this->assertSame( 0, $result['rows'][0]['subscription_conversions'] );
		$this->assertNull( $result['rows'][0]['subscription_conversion_rate'] );

		// Registration popup → subscription columns populated, donation columns zeroed.
		$this->assertSame( 99, $result['rows'][1]['popup_id'] );
		$this->assertSame( 'registration', $result['rows'][1]['intent'] );
		$this->assertSame( 0, $result['rows'][1]['donation_conversions'] );
		$this->assertNull( $result['rows'][1]['donation_conversion_rate'] );
		$this->assertSame( 5, $result['rows'][1]['subscription_conversions'] );
		$this->assertEqualsWithDelta( 0.01, $result['rows'][1]['subscription_conversion_rate'], 0.0001 );

		// Engagement columns flow through untouched.
		$this->assertSame( 1000, $result['rows'][0]['impressions'] );
		$this->assertSame( 0.1, $result['rows'][0]['ctr'] );
		$this->assertSame( 0.05, $result['rows'][0]['form_submission_rate'] );
	}

	/**
	 * Performance by prompt: locks the canonical row-key contract the React
	 * layer consumes (`PromptPerformanceRow`). A column rename on either side
	 * silently blanks the table, so assert the exact set + order.
	 */
	public function test_performance_by_prompt_row_schema_is_locked() {
		$perf_rows = [ $this->performance_row( 42, 'Donate now', 'donation', 100 ) ];
		$proxy     = $this->make_performance_proxy( $perf_rows, [], [] );

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 0 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 0.0 );

		$metric = new Prompts_Metric( $proxy, $resolver );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame(
			[
				'popup_id',
				'prompt_title',
				'intent',
				'placement',
				'impressions',
				'unique_viewers',
				'ctr',
				'form_submission_rate',
				'dismissal_rate',
				'registrations',
				'newsletter_signups',
				'donation_conversions',
				'donation_conversion_rate',
				'subscription_conversions',
				'subscription_conversion_rate',
			],
			array_keys( $result['rows'][0] )
		);
	}

	/**
	 * Performance by prompt: empty rows → state 'empty' with no augmentation
	 * queries dispatched.
	 */
	public function test_performance_by_prompt_returns_empty_state_on_no_rows() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'prompts_performance_by_prompt' )
			->willReturn( [] );

		$metric = new Prompts_Metric( $proxy, $this->createMock( Woo_Order_Resolver::class ) );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['rows'] );
	}

	/**
	 * Performance by prompt: proxy WP_Error on the main query → state 'error'
	 * with empty rows; augmentation queries are not dispatched.
	 */
	public function test_performance_by_prompt_returns_error_state_on_proxy_error() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'prompts_performance_by_prompt' )
			->willReturn( new \WP_Error( 'bigquery_query_failed', 'BQ down' ) );

		$metric = new Prompts_Metric( $proxy, $this->createMock( Woo_Order_Resolver::class ) );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_query_failed', $result['error_code'] );
		$this->assertSame( [], $result['rows'] );
	}

	/**
	 * Performance by prompt: malformed shape (non-array) → state 'error' with
	 * `bigquery_proxy_malformed_rows`.
	 */
	public function test_performance_by_prompt_returns_error_state_on_malformed_response() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'prompts_performance_by_prompt' )
			->willReturn( 'not-an-array' );

		$metric = new Prompts_Metric( $proxy, $this->createMock( Woo_Order_Resolver::class ) );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
	}

	/**
	 * Performance by prompt: when the donation-augmentation proxy call fails,
	 * the table still renders with engagement columns intact; donation
	 * columns degrade to 0 / null (no error envelope, no exception).
	 */
	public function test_performance_by_prompt_degrades_gracefully_on_woo_query_failure() {
		$perf_rows = [ $this->performance_row( 42, 'Donate now', 'donation', 1000 ) ];

		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->exactly( 3 ) )
			->method( 'query' )
			->willReturnCallback(
				function ( $query_name ) use ( $perf_rows ) {
					switch ( $query_name ) {
						case 'prompts_performance_by_prompt':
							return $perf_rows;
						case 'prompts_donation_conversion_direct':
							return new \WP_Error( 'bigquery_query_failed', 'donation BQ down' );
						case 'prompts_subscription_conversion_direct':
							return [];
					}
					return null;
				}
			);

		$resolver = $this->createMock( Woo_Order_Resolver::class );
		$resolver->method( 'count_completed_orders' )->willReturn( 0 );
		$resolver->method( 'sum_completed_revenue' )->willReturn( 0.0 );

		$metric = new Prompts_Metric( $proxy, $resolver );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		// Table renders successfully — engagement data is load-bearing.
		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 1000, $result['rows'][0]['impressions'] );
		$this->assertSame( 0.1, $result['rows'][0]['ctr'] );
		// Donation augmentation degraded to zero conversions; the rate stays
		// computable (0/impressions = 0.0) since the intent matches and the
		// engagement denominator is real — only the numerator is missing.
		$this->assertSame( 0, $result['rows'][0]['donation_conversions'] );
		$this->assertEqualsWithDelta( 0.0, $result['rows'][0]['donation_conversion_rate'], 0.0001 );
	}

	/**
	 * Performance by intent: maps BQ rows with title-cased intent labels per
	 * `INTENT_LABELS` (including the special `newsletters_subscription` →
	 * "Newsletter signup" mapping).
	 */
	public function test_performance_by_intent_maps_bq_rows_with_titlecased_labels() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_performance_by_intent',
			[
				[
					'intent'               => 'donation',
					'impressions'          => 5000,
					'unique_viewers'       => 1200,
					'ctr'                  => 0.1,
					'form_submission_rate' => 0.05,
					'dismissal_rate'       => 0.02,
				],
				[
					'intent'               => 'registration',
					'impressions'          => 3000,
					'unique_viewers'       => 900,
					'ctr'                  => 0.15,
					'form_submission_rate' => 0.08,
					'dismissal_rate'       => 0.03,
				],
				[
					'intent'               => 'newsletters_subscription',
					'impressions'          => 2000,
					'unique_viewers'       => 600,
					'ctr'                  => 0.2,
					'form_submission_rate' => 0.1,
					'dismissal_rate'       => 0.04,
				],
			]
		);
		$result = $metric->get_performance_by_intent( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 3, $result['rows'] );

		$this->assertSame( 'donation', $result['rows'][0]['intent'] );
		$this->assertSame( 'Donation', $result['rows'][0]['intent_label'] );
		$this->assertSame( 5000, $result['rows'][0]['impressions'] );

		$this->assertSame( 'registration', $result['rows'][1]['intent'] );
		$this->assertSame( 'Registration', $result['rows'][1]['intent_label'] );

		$this->assertSame( 'newsletters_subscription', $result['rows'][2]['intent'] );
		$this->assertSame( 'Newsletter signup', $result['rows'][2]['intent_label'] );
	}

	/**
	 * Performance by intent: empty rows → state 'empty'.
	 */
	public function test_performance_by_intent_returns_empty_state_on_no_rows() {
		$metric = $this->make_metric_with_proxy_returning( 'prompts_performance_by_intent', [] );
		$result = $metric->get_performance_by_intent( $this->start(), $this->end() );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['rows'] );
	}

	/**
	 * Performance by intent: proxy WP_Error → state 'error'.
	 */
	public function test_performance_by_intent_returns_error_state_on_proxy_error() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_performance_by_intent',
			new \WP_Error( 'bigquery_query_failed', 'BQ down' )
		);
		$result = $metric->get_performance_by_intent( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_query_failed', $result['error_code'] );
		$this->assertSame( [], $result['rows'] );
	}

	/**
	 * Performance by placement: maps BQ rows with humanized placement labels
	 * (`above-header` → "Above header") and no form_submission_rate column.
	 */
	public function test_performance_by_placement_maps_bq_rows_with_humanized_labels() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_performance_by_placement',
			[
				[
					'placement'      => 'overlay',
					'impressions'    => 5000,
					'unique_viewers' => 1200,
					'ctr'            => 0.1,
					'dismissal_rate' => 0.02,
				],
				[
					'placement'      => 'inline',
					'impressions'    => 3000,
					'unique_viewers' => 900,
					'ctr'            => 0.15,
					'dismissal_rate' => 0.03,
				],
				[
					'placement'      => 'above-header',
					'impressions'    => 2000,
					'unique_viewers' => 600,
					'ctr'            => 0.2,
					'dismissal_rate' => 0.04,
				],
			]
		);
		$result = $metric->get_performance_by_placement( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 3, $result['rows'] );

		$this->assertSame( 'overlay', $result['rows'][0]['placement'] );
		$this->assertSame( 'Overlay', $result['rows'][0]['placement_label'] );
		$this->assertSame( 'Inline', $result['rows'][1]['placement_label'] );
		$this->assertSame( 'Above header', $result['rows'][2]['placement_label'] );

		// No form_submission_rate column per spec.
		$this->assertArrayNotHasKey( 'form_submission_rate', $result['rows'][0] );
	}

	/**
	 * Performance by placement: empty rows → state 'empty'.
	 */
	public function test_performance_by_placement_returns_empty_state_on_no_rows() {
		$metric = $this->make_metric_with_proxy_returning( 'prompts_performance_by_placement', [] );
		$result = $metric->get_performance_by_placement( $this->start(), $this->end() );

		$this->assertSame( 'empty', $result['state'] );
		$this->assertSame( [], $result['rows'] );
	}

	/**
	 * Performance by placement: proxy WP_Error → state 'error'.
	 */
	public function test_performance_by_placement_returns_error_state_on_proxy_error() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_performance_by_placement',
			new \WP_Error( 'bigquery_query_failed', 'BQ down' )
		);
		$result = $metric->get_performance_by_placement( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_query_failed', $result['error_code'] );
		$this->assertSame( [], $result['rows'] );
	}
}
