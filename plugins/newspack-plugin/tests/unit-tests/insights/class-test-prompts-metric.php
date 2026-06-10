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
 * The 5 not-yet-wired collection methods (funnel / distribution / 3 perf
 * tables) remain pinned to the bridge envelope:
 * state 'error' with `error_code: newspack_insights_prompts_not_yet_implemented`.
 * Locks the bridge behavior so Task 3.3 can refactor with confidence.
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
	 * Build a Prompts_Metric with a proxy stub that fails the test if `query()`
	 * is ever called. Used for the not-yet-implemented bridge smoke tests.
	 */
	protected function make_metric_with_unused_proxy(): Prompts_Metric {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->never() )->method( 'query' );
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

	// --- Section 6/7: not-yet-implemented bridge (collections) ---------

	/**
	 * Placeholder collection methods (funnel, distribution, 3 perf tables)
	 * return the bridge envelope with the proper rows-key and an empty list.
	 *
	 * @dataProvider provide_placeholder_collection_methods
	 * @param string $method   Method on Prompts_Metric to call.
	 * @param string $rows_key Key holding the (empty) collection: 'stages'|'buckets'|'rows'.
	 */
	public function test_placeholder_collection_returns_bridge_envelope( string $method, string $rows_key ) {
		$metric = $this->make_metric_with_unused_proxy();
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'newspack_insights_prompts_not_yet_implemented', $result['error_code'] );
		$this->assertNotSame( '', $result['error_message'] );
		$this->assertSame( [], $result[ $rows_key ] );
		$this->assertArrayNotHasKey( 'pending', $result );
	}

	/**
	 * Data provider for the 5 not-yet-wired collection methods.
	 *
	 * @return array
	 */
	public function provide_placeholder_collection_methods(): array {
		return [
			'funnel'                => [ 'get_conversion_funnel', 'stages' ],
			'distribution'          => [ 'get_exposures_distribution', 'buckets' ],
			'performance_by_prompt' => [ 'get_performance_by_prompt', 'rows' ],
			'performance_by_intent' => [ 'get_performance_by_intent', 'rows' ],
			'performance_by_place'  => [ 'get_performance_by_placement', 'rows' ],
		];
	}
}
