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
 * dispatch through the BigQuery proxy and return hub-computed rates and revenue
 * directly (one proxy call per intent + direction).
 *
 * The 5 collection methods are pinned (Task 3.3): funnel + distribution mirror
 * the Gates state-envelope contract one-for-one; the per-prompt performance
 * table additionally augments each row with per-popup donation and subscription
 * counts sourced from order meta via Donors_Metric / Subscribers_Metric and is
 * asserted to degrade gracefully (engagement columns still render with zeros in
 * the conversion columns) when WooCommerce is not active.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Donors_Metric;
use Newspack\Insights\Prompts_Metric;
use Newspack\Insights\Subscribers_Metric;
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

	/**
	 * Prompts_Metric constructor must not declare Woo_Order_Resolver as a param
	 * or typed property (mirrors Gates_Metric after C2 cleanup).
	 */
	public function test_constructor_needs_no_woo_order_resolver() {
		$class    = new \ReflectionClass( \Newspack\Insights\Prompts_Metric::class );
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
		$this->assertInstanceOf( \Newspack\Insights\Prompts_Metric::class, new \Newspack\Insights\Prompts_Metric() );
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

	/**
	 * SAFE_DIVIDE returns NULL when the denominator is zero (BigQuery semantics).
	 * That's a legitimate "no eligible events" case, not a malformed payload —
	 * surface as `state: 'populated'` with a non-computable zero so the UI
	 * renders "0%" instead of "Data temporarily unavailable".
	 */
	public function test_scalar_treats_null_safe_divide_result_as_non_computable_zero() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_form_submission_rate',
			[ [ 'form_submission_rate' => null ] ]
		);
		$result = $metric->get_form_submission_rate( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 0, $result['value'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	// --- Section 4: Paid reader conversion (BQ-internal) ----------------

	/**
	 * BQ-internal influenced rate: donation — populated row with rate + denominator.
	 * The rate is hub-computed; no Woo join required on the consumer.
	 */
	public function test_donation_conversion_influenced_converter_denominated() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'donation_conversion_influenced_rate' => 0.25,
					'conversion_denominator'              => 12,
					'influenced_revenue'                  => 300.0,
				],
			] 
		);

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_donation_conversion_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 12, $result['denominator'] );
		$this->assertEqualsWithDelta( 0.25, $result['value'], 0.0001 );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	/**
	 * BQ-internal influenced rate: subscription — populated row with rate + denominator.
	 */
	public function test_subscription_conversion_influenced_converter_denominated() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'subscription_conversion_influenced_rate' => 0.25,
					'conversion_denominator' => 16,
					'influenced_revenue'     => 400.0,
				],
			] 
		);

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_subscription_conversion_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 16, $result['denominator'] );
		$this->assertEqualsWithDelta( 0.25, $result['value'], 0.0001 );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	/**
	 * Proxy WP_Error → state 'error' for the BQ-internal influenced rate.
	 */
	public function test_paid_influenced_rate_errors_on_proxy_error() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' ) );

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_donation_conversion_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
	}

	// --- Section 5: Revenue from prompts --------------------------------

	/**
	 * BQ-internal influenced revenue: dispatch the catalog query, read `influenced_revenue`
	 * from the ONE-row response. No Woo resolver required.
	 *
	 * @dataProvider provide_paid_revenue_methods
	 * @param string $method     Method on Prompts_Metric to call.
	 * @param string $query_name Catalog query name dispatched.
	 * @param string $rate_key   Rate key present in the row (not asserted but required for the row shape).
	 */
	public function test_paid_revenue_returns_populated_on_success( string $method, string $query_name, string $rate_key ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn(
				[
					[
						$rate_key                => 0.1,
						'conversion_denominator' => 10,
						'influenced_revenue'     => 25.0,
					],
				] 
			);

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 25.0, $result['value'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'currency', $result['placeholder_type'] );
	}

	/**
	 * Influenced revenue: proxy WP_Error → state 'error'.
	 *
	 * @dataProvider provide_paid_revenue_methods
	 * @param string $method     Method on Prompts_Metric to call.
	 * @param string $query_name Catalog query name dispatched.
	 */
	public function test_paid_revenue_returns_error_state_on_proxy_error( string $method, string $query_name ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' ) );

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 'currency', $result['placeholder_type'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
		$this->assertSame( 'HTTP 500', $result['error_message'] );
		// error_scalar returns int 0 for non-'decimal' types.
		$this->assertSame( 0, $result['value'] );
	}

	/**
	 * Influenced revenue: empty BQ rows → state 'populated' but NON-computable.
	 *
	 * @dataProvider provide_paid_revenue_methods
	 * @param string $method     Method on Prompts_Metric to call.
	 * @param string $query_name Catalog query name dispatched.
	 */
	public function test_paid_revenue_returns_noncomputable_on_empty( string $method, string $query_name ) {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( $query_name, $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn( [] );

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->$method( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 0.0, $result['value'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 'currency', $result['placeholder_type'] );
	}

	/**
	 * Data provider for the BQ-internal influenced revenue methods.
	 * Tuple: [ method, query_name, rate_key ].
	 *
	 * `get_donation_revenue_direct` and `get_subscription_revenue_direct` are
	 * intentionally absent — both source from order meta (Donors_Metric /
	 * Subscribers_Metric). Only the influenced pair is hub-backed.
	 *
	 * @return array
	 */
	public function provide_paid_revenue_methods(): array {
		return [
			'donation_influenced'     => [ 'get_donation_revenue_influenced_14d', 'prompts_donation_conversion_influenced_14d', 'donation_conversion_influenced_rate' ],
			'subscription_influenced' => [ 'get_subscription_revenue_influenced_14d', 'prompts_subscription_conversion_influenced_14d', 'subscription_conversion_influenced_rate' ],
		];
	}

	/**
	 * NPPD-1685: direct donation revenue sums the prompt-attributed order-meta map
	 * from Donors_Metric (anonymous-inclusive), NOT the GA4 attempt → resolver
	 * join. Inject a stub Donors_Metric; assert revenue + conversion count are the
	 * map totals and the card is computable.
	 */
	public function test_donation_revenue_direct_sources_from_order_meta() {
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn(
			[
				'194959' => [
					'conversions' => 2,
					'revenue'     => 50.0,
				],
				'244419' => [
					'conversions' => 1,
					'revenue'     => 25.0,
				],
			]
		);

		$metric = $this->make_donation_revenue_metric( $donors, true );

		$revenue = $metric->get_donation_revenue_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $revenue['state'] );
		$this->assertSame( 75.0, $revenue['value'], 'revenue is the summed order-meta map' );
		$this->assertTrue( $revenue['computable'] );
		$this->assertSame( 3, $revenue['denominator'], 'denominator is the summed conversion count' );
	}

	/**
	 * NPPD-1685: on a non-WooCommerce publisher the direct donation revenue card
	 * is a not-computable empty state (em-dash), NOT a fake $0.
	 */
	public function test_donation_revenue_direct_empty_state_when_not_woocommerce() {
		$donors = $this->createMock( Donors_Metric::class );
		$donors->expects( $this->never() )->method( 'get_prompt_attributed_donation_conversions' );

		$metric = $this->make_donation_revenue_metric( $donors, false );

		$revenue = $metric->get_donation_revenue_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $revenue['state'] );
		$this->assertFalse( $revenue['computable'] );
		$this->assertSame( 0.0, $revenue['value'] );
	}

	/**
	 * Build a Prompts_Metric with an injected Donors_Metric and a forced
	 * `woocommerce_active()` return (the seam), so both the WC-active and non-WC
	 * paths are testable without toggling a global class.
	 *
	 * @param Donors_Metric $donors Injected donors collaborator.
	 * @param bool          $wc     What woocommerce_active() should return.
	 * @return Prompts_Metric
	 */
	private function make_donation_revenue_metric( Donors_Metric $donors, bool $wc ): Prompts_Metric {
		return $this->make_direct_donation_metric( null, $donors, $wc );
	}

	/**
	 * Build a Prompts_Metric for the direct donation cards with an injected proxy +
	 * Donors_Metric and a forced `woocommerce_active()` (via filter — the class is
	 * final). The proxy feeds the rate card's hub impressions denominator; revenue
	 * passes null. Filter removed in tearDown().
	 *
	 * @param BigQuery_Proxy_Client|null $proxy  Injected proxy (rate) or null (revenue).
	 * @param Donors_Metric              $donors Injected donors collaborator.
	 * @param bool                       $wc     What woocommerce_active() should return.
	 * @return Prompts_Metric
	 */
	private function make_direct_donation_metric( ?BigQuery_Proxy_Client $proxy, Donors_Metric $donors, bool $wc ): Prompts_Metric {
		add_filter( 'newspack_insights_woocommerce_active', $wc ? '__return_true' : '__return_false' );
		return new Prompts_Metric( $proxy, null, $donors );
	}

	/**
	 * Stub Donors_Metric whose order-meta map sums to $conversions conversions.
	 *
	 * @param int $conversions Total conversions to spread across two popups.
	 * @return Donors_Metric
	 */
	private function donors_with_conversions( int $conversions ): Donors_Metric {
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn(
			[
				'1' => [
					'conversions' => $conversions,
					'revenue'     => 0.0,
				],
			]
		);
		return $donors;
	}

	/**
	 * Proxy whose `prompts_performance_by_prompt` returns the given total
	 * donation-intent impressions (plus an unrelated registration row).
	 *
	 * @param int $donation_impressions Donation-intent impressions to report.
	 * @return BigQuery_Proxy_Client
	 */
	private function proxy_with_donation_impressions( int $donation_impressions ): BigQuery_Proxy_Client {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'popup_id'    => 1,
					'intent'      => 'donation',
					'impressions' => $donation_impressions,
				],
				[
					'popup_id'    => 9,
					'intent'      => 'registration',
					'impressions' => 500,
				],
			]
		);
		return $proxy;
	}

	/**
	 * NPPD-1745: direct donation rate = order-meta conversions ÷ hub
	 * donation-intent impressions, anonymous-inclusive on both sides.
	 */
	public function test_donation_conversion_direct_rate_from_order_meta_over_impressions() {
		$metric = $this->make_direct_donation_metric(
			$this->proxy_with_donation_impressions( 200 ),
			$this->donors_with_conversions( 50 ),
			true
		);

		$rate = $metric->get_donation_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertSame( 0.25, $rate['value'] );
		$this->assertTrue( $rate['computable'] );
		$this->assertSame( 200, $rate['denominator'] );
	}

	/**
	 * NPPD-1756/1757: when the hub exposes the per-popup `donation_impressions`
	 * capability column, the donation-rate denominator uses it (summed across all
	 * popups) instead of the `action_type='donation'` impression sum. This catches a
	 * multi-block / Undefined-intent prompt that carries a donate block — which the
	 * old intent-sum would have dropped entirely (denominator 0 → not computable).
	 */
	public function test_donation_conversion_direct_prefers_donation_impressions_column() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				// Donation-CAPABLE but NOT donation-intent (multi-block / Undefined).
				[
					'popup_id'             => 1,
					'intent'               => 'undefined',
					'impressions'          => 1000,
					'donation_impressions' => 200,
				],
				// Not donation-capable — must not enter the denominator.
				[
					'popup_id'             => 9,
					'intent'               => 'registration',
					'impressions'          => 500,
					'donation_impressions' => 0,
				],
			]
		);

		$metric = $this->make_direct_donation_metric( $proxy, $this->donors_with_conversions( 50 ), true );
		$rate   = $metric->get_donation_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertSame( 200, $rate['denominator'], 'denominator is the capability column, not action_type=donation (which would be 0 here)' );
		$this->assertSame( 0.25, $rate['value'], '50 conversions / 200 donation-capable impressions' );
	}

	/**
	 * NPPD-1817: the numerator is restricted to donation-CAPABLE popups, so a
	 * converting-but-not-capable popup (donation_impressions = 0) is excluded from the
	 * rate — keeping the numerator on the same population as the capable-impressions
	 * denominator and reconciling with the per-prompt table (which zeroes its row). The
	 * excluded conversion still belongs to the count/revenue cards, which sum all.
	 */
	public function test_donation_conversion_direct_excludes_non_capable_converting_popup() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				// Donation-capable + converting.
				[
					'popup_id'             => 1,
					'intent'               => 'donation',
					'impressions'          => 1000,
					'donation_impressions' => 300,
				],
				// Converting but NOT donation-capable (no donate block) → excluded from the rate.
				[
					'popup_id'             => 2,
					'intent'               => 'undefined',
					'impressions'          => 800,
					'donation_impressions' => 0,
				],
			]
		);
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn(
			[
				'1' => [
					'conversions' => 30,
					'revenue'     => 0.0,
				],
				'2' => [
					'conversions' => 20,
					'revenue'     => 0.0,
				],
			]
		);

		$metric = $this->make_direct_donation_metric( $proxy, $donors, true );
		$rate   = $metric->get_donation_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertSame( 300, $rate['denominator'], 'only the capable popup contributes impressions' );
		$this->assertSame( 0.1, $rate['value'], '30 capable conversions / 300 (popup 2\'s 20 conversions excluded)' );
		$this->assertTrue( $rate['computable'] );
	}

	/**
	 * No donation impressions → no denominator → not computable (em-dash),
	 * distinct from the real 0% below.
	 */
	public function test_donation_conversion_direct_not_computable_without_impressions() {
		$metric = $this->make_direct_donation_metric(
			$this->proxy_with_donation_impressions( 0 ),
			$this->donors_with_conversions( 5 ),
			true
		);

		$rate = $metric->get_donation_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertFalse( $rate['computable'] );
		$this->assertSame( 0.0, $rate['value'] );
	}

	/**
	 * Em-dash unification: impressions exist but zero conversions is a REAL 0%
	 * (computable), distinct from the not-computable no-impressions case.
	 */
	public function test_donation_conversion_direct_real_zero_percent_when_no_conversions() {
		$metric = $this->make_direct_donation_metric(
			$this->proxy_with_donation_impressions( 200 ),
			$this->donors_with_conversions( 0 ),
			true
		);

		$rate = $metric->get_donation_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertTrue( $rate['computable'], 'viewed-but-no-conversion is a real 0%, not an em-dash' );
		$this->assertSame( 0.0, $rate['value'] );
		$this->assertSame( 200, $rate['denominator'] );
	}

	/**
	 * Coherence guard: conversions (order-meta surface) exceeding impressions
	 * (GA4 surface) must not fabricate a >100% rate — suppress to not-computable.
	 */
	public function test_donation_conversion_direct_coherence_guard_suppresses_over_100() {
		$metric = $this->make_direct_donation_metric(
			$this->proxy_with_donation_impressions( 10 ),
			$this->donors_with_conversions( 50 ),
			true
		);

		$rate = $metric->get_donation_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertFalse( $rate['computable'], 'a >100% cross-surface ratio is suppressed, not shown' );
		$this->assertSame( 0.0, $rate['value'] );
	}

	/**
	 * Hybrid card: if the hub impressions call errors, the rate is genuinely
	 * uncomputable → error state (so it counts toward the tab-error banner).
	 */
	public function test_donation_conversion_direct_errors_when_impressions_hub_errors() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_proxy_error', 'down' ) );

		$metric = $this->make_direct_donation_metric( $proxy, $this->donors_with_conversions( 5 ), true );

		$rate = $metric->get_donation_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'error', $rate['state'] );
	}

	/**
	 * NPPD-1745 #3: a malformed impressions response (the hub succeeded but returned a
	 * non-array shape) must error, NOT collapse to a fabricated "0 impressions → em-
	 * dash". Mirrors the sibling per-prompt table's malformed_collection so a hub
	 * fault counts toward the banner instead of hiding.
	 */
	public function test_donation_conversion_direct_errors_on_malformed_impressions() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( 'not-an-array' ); // Malformed (non-array) hub response.

		$metric = $this->make_direct_donation_metric( $proxy, $this->donors_with_conversions( 5 ), true );

		$rate = $metric->get_donation_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'error', $rate['state'], 'malformed impressions errors, not a fabricated 0%' );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $rate['error_code'] );
	}

	/**
	 * Non-WC publisher: empty state (not a fake 0%); the order-meta numerator is
	 * never queried.
	 */
	public function test_donation_conversion_direct_empty_state_when_not_woocommerce() {
		$donors = $this->createMock( Donors_Metric::class );
		$donors->expects( $this->never() )->method( 'get_prompt_attributed_donation_conversions' );

		$metric = $this->make_direct_donation_metric( null, $donors, false );

		$rate = $metric->get_donation_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertFalse( $rate['computable'] );
		$this->assertSame( 0.0, $rate['value'] );
	}

	// --- NPPD-1746: direct subscription rate + revenue from order meta (popup surface) ---

	/**
	 * Build a Prompts_Metric for the direct subscription cards with an injected
	 * proxy + Subscribers_Metric and a forced `woocommerce_active()` (via filter —
	 * the class is final). The proxy feeds the rate card's per-popup hub impressions
	 * denominator; revenue passes null. Filter removed in tear_down().
	 *
	 * @param BigQuery_Proxy_Client|null $proxy       Injected proxy (rate) or null (revenue).
	 * @param Subscribers_Metric         $subscribers Injected subscribers collaborator.
	 * @param bool                       $wc          What woocommerce_active() should return.
	 * @return Prompts_Metric
	 */
	private function make_direct_subscription_metric( ?BigQuery_Proxy_Client $proxy, Subscribers_Metric $subscribers, bool $wc ): Prompts_Metric {
		add_filter( 'newspack_insights_woocommerce_active', $wc ? '__return_true' : '__return_false' );
		return new Prompts_Metric( $proxy, null, null, $subscribers );
	}

	/**
	 * Stub Subscribers_Metric with empty surfaces — for tests that exercise the
	 * donation columns on a WC-active publisher and need the subscription
	 * augmentation to no-op without hitting a real storage backend.
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
	 * Stub Subscribers_Metric whose popup surface holds one popup (id 1) with the
	 * given conversions + revenue, and an empty gate surface.
	 *
	 * @param int   $conversions Popup-attributed subscription conversions.
	 * @param float $revenue     Popup-attributed subscription revenue.
	 * @return Subscribers_Metric
	 */
	private function subscribers_with_popup( int $conversions, float $revenue ): Subscribers_Metric {
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->method( 'get_attributed_subscription_conversions' )->willReturn(
			[
				'by_gate'  => [],
				'by_popup' => [
					'1' => [
						'conversions' => $conversions,
						'revenue'     => $revenue,
					],
				],
			]
		);
		return $subscribers;
	}

	/**
	 * Proxy whose `prompts_performance_by_prompt` reports impressions for popup 1
	 * (the converting popup) PLUS an unrelated registration-intent popup 9. Popup 9
	 * must NOT enter the denominator — the rate is per-popup keyed to the popups that
	 * actually converted, which is the whole point of not using a tab-level bucket.
	 *
	 * @param int $popup_one_impressions Impressions to report for popup 1.
	 * @return BigQuery_Proxy_Client
	 */
	private function proxy_with_popup_impressions( int $popup_one_impressions ): BigQuery_Proxy_Client {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				[
					'popup_id'    => 1,
					'intent'      => 'registration',
					'impressions' => $popup_one_impressions,
				],
				[
					'popup_id'    => 9,
					'intent'      => 'registration',
					'impressions' => 500,
				],
			]
		);
		return $proxy;
	}

	/**
	 * Direct subscription revenue sums the popup surface of the two-key order-meta
	 * map (anonymous-inclusive), NOT the GA4 attempt → resolver join.
	 */
	public function test_subscription_revenue_direct_sources_from_order_meta() {
		$metric = $this->make_direct_subscription_metric( null, $this->subscribers_with_popup( 3, 75.0 ), true );

		$revenue = $metric->get_subscription_revenue_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $revenue['state'] );
		$this->assertSame( 75.0, $revenue['value'], 'revenue is the summed popup-surface map' );
		$this->assertTrue( $revenue['computable'] );
		$this->assertSame( 3, $revenue['denominator'], 'denominator is the summed conversion count' );
	}

	/**
	 * Non-WC publisher: direct subscription revenue is a not-computable empty state,
	 * and the order-meta numerator is never queried.
	 */
	public function test_subscription_revenue_direct_empty_state_when_not_woocommerce() {
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->expects( $this->never() )->method( 'get_attributed_subscription_conversions' );

		$metric  = $this->make_direct_subscription_metric( null, $subscribers, false );
		$revenue = $metric->get_subscription_revenue_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $revenue['state'] );
		$this->assertFalse( $revenue['computable'] );
		$this->assertSame( 0.0, $revenue['value'] );
	}

	/**
	 * Forward-compat fallback (NPPD-1759): until the hub ships `checkout_impressions`,
	 * the direct subscription rate keeps today's per-popup keying — popup-attributed
	 * conversions ÷ impressions of the SAME popups. Popup 9's 500 impressions are
	 * excluded because popup 9 had no subscription conversions (denominator 200, not
	 * 700). These rows carry no `checkout_impressions` key, so the fallback runs.
	 */
	public function test_subscription_conversion_direct_rate_per_popup_over_impressions() {
		$metric = $this->make_direct_subscription_metric(
			$this->proxy_with_popup_impressions( 200 ),
			$this->subscribers_with_popup( 50, 0.0 ),
			true
		);

		$rate = $metric->get_subscription_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertSame( 0.25, $rate['value'] );
		$this->assertTrue( $rate['computable'] );
		$this->assertSame( 200, $rate['denominator'], 'denominator is popup 1 only, not the unrelated popup 9' );
	}

	/**
	 * NPPD-1759: once the hub exposes `checkout_impressions`, the direct subscription
	 * rate prefers it — a TAB-LEVEL capability denominator (summed across ALL
	 * checkout-capable popups), dropping the per-popup keying. Conversions are summed
	 * across the whole popup surface. Here popup 9 is checkout-capable but did NOT
	 * convert, yet its 100 capable impressions still enter the denominator (300 total),
	 * which the old per-popup keying would have excluded — proving the keying is gone.
	 */
	public function test_subscription_conversion_direct_prefers_checkout_impressions_column() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				// Checkout-CAPABLE and converting (Undefined intent — a "50% off" promo).
				[
					'popup_id'             => 1,
					'intent'               => 'undefined',
					'impressions'          => 1000,
					'checkout_impressions' => 200,
				],
				// Checkout-capable but did NOT convert — still in the capability denominator.
				[
					'popup_id'             => 9,
					'intent'               => 'registration',
					'impressions'          => 500,
					'checkout_impressions' => 100,
				],
			]
		);

		$metric = $this->make_direct_subscription_metric( $proxy, $this->subscribers_with_popup( 50, 0.0 ), true );
		$rate   = $metric->get_subscription_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertSame( 300, $rate['denominator'], 'tab-level checkout_impressions sum (200 + 100), not the per-popup-keyed 200' );
		$this->assertEqualsWithDelta( 0.16667, $rate['value'], 0.0001, '50 conversions / 300 checkout-capable impressions' );
		$this->assertTrue( $rate['computable'] );
	}

	/**
	 * NPPD-1817: the numerator is restricted to checkout-CAPABLE popups, so a
	 * converting-but-not-capable popup (checkout_impressions = 0) is excluded from the
	 * rate — keeping the numerator on the same population as the capable-impressions
	 * denominator and reconciling with the per-prompt table (which zeroes its row). The
	 * excluded conversion still belongs to the count/revenue cards, which sum all.
	 */
	public function test_subscription_conversion_direct_excludes_non_capable_converting_popup() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn(
			[
				// Checkout-capable + converting.
				[
					'popup_id'             => 1,
					'intent'               => 'undefined',
					'impressions'          => 1000,
					'checkout_impressions' => 300,
				],
				// Converting but NOT checkout-capable (no checkout block) → excluded from the rate.
				[
					'popup_id'             => 2,
					'intent'               => 'registration',
					'impressions'          => 800,
					'checkout_impressions' => 0,
				],
			]
		);
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->method( 'get_attributed_subscription_conversions' )->willReturn(
			[
				'by_gate'  => [],
				'by_popup' => [
					'1' => [
						'conversions' => 30,
						'revenue'     => 0.0,
					],
					'2' => [
						'conversions' => 20,
						'revenue'     => 0.0,
					],
				],
			]
		);

		$metric = $this->make_direct_subscription_metric( $proxy, $subscribers, true );
		$rate   = $metric->get_subscription_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertSame( 300, $rate['denominator'], 'only the capable popup contributes impressions' );
		$this->assertSame( 0.1, $rate['value'], '30 capable conversions / 300 (popup 2\'s 20 conversions excluded)' );
		$this->assertTrue( $rate['computable'] );
	}

	/**
	 * No impressions for the converting popup → no denominator → not computable
	 * (em-dash), distinct from a real 0%.
	 */
	public function test_subscription_conversion_direct_not_computable_without_impressions() {
		$metric = $this->make_direct_subscription_metric(
			$this->proxy_with_popup_impressions( 0 ),
			$this->subscribers_with_popup( 5, 0.0 ),
			true
		);

		$rate = $metric->get_subscription_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertFalse( $rate['computable'] );
		$this->assertSame( 0.0, $rate['value'] );
	}

	/**
	 * Impressions exist but zero conversions is a REAL 0% (computable), distinct
	 * from the not-computable no-impressions case.
	 */
	public function test_subscription_conversion_direct_real_zero_percent_when_no_conversions() {
		$metric = $this->make_direct_subscription_metric(
			$this->proxy_with_popup_impressions( 200 ),
			$this->subscribers_with_popup( 0, 0.0 ),
			true
		);

		$rate = $metric->get_subscription_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertTrue( $rate['computable'], 'viewed-but-no-conversion is a real 0%, not an em-dash' );
		$this->assertSame( 0.0, $rate['value'] );
		$this->assertSame( 200, $rate['denominator'] );
	}

	/**
	 * Coherence guard: popup-surface conversions exceeding that popup's impressions
	 * must not fabricate a >100% rate — suppress to not-computable.
	 */
	public function test_subscription_conversion_direct_coherence_guard_suppresses_over_100() {
		$metric = $this->make_direct_subscription_metric(
			$this->proxy_with_popup_impressions( 10 ),
			$this->subscribers_with_popup( 50, 0.0 ),
			true
		);

		$rate = $metric->get_subscription_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertFalse( $rate['computable'], 'a >100% cross-surface ratio is suppressed, not shown' );
		$this->assertSame( 0.0, $rate['value'] );
	}

	/**
	 * Hybrid card: if the hub impressions call errors, the rate is genuinely
	 * uncomputable → error state (counts toward the tab-error banner).
	 */
	public function test_subscription_conversion_direct_errors_when_impressions_hub_errors() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( new \WP_Error( 'bigquery_proxy_error', 'down' ) );

		$metric = $this->make_direct_subscription_metric( $proxy, $this->subscribers_with_popup( 5, 0.0 ), true );

		$rate = $metric->get_subscription_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'error', $rate['state'] );
	}

	/**
	 * NPPD-1745 #3 (mirrored to subscriptions): a malformed impressions response (the
	 * hub succeeded but returned a non-array shape) errors the rate rather than
	 * collapsing to a fabricated "0 impressions → em-dash".
	 */
	public function test_subscription_conversion_direct_errors_on_malformed_impressions() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( 'not-an-array' ); // Malformed (non-array) hub response.

		$metric = $this->make_direct_subscription_metric( $proxy, $this->subscribers_with_popup( 5, 0.0 ), true );

		$rate = $metric->get_subscription_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'error', $rate['state'], 'malformed impressions errors, not a fabricated 0%' );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $rate['error_code'] );
	}

	/**
	 * Non-WC publisher: empty state (not a fake 0%); the order-meta numerator is
	 * never queried.
	 */
	public function test_subscription_conversion_direct_empty_state_when_not_woocommerce() {
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->expects( $this->never() )->method( 'get_attributed_subscription_conversions' );

		$metric = $this->make_direct_subscription_metric( null, $subscribers, false );

		$rate = $metric->get_subscription_conversion_direct( $this->start(), $this->end() );

		$this->assertSame( 'populated', $rate['state'] );
		$this->assertFalse( $rate['computable'] );
		$this->assertSame( 0.0, $rate['value'] );
	}

	/**
	 * Remove the WC-active seam filter set by make_donation_revenue_metric().
	 */
	public function tear_down(): void {
		remove_all_filters( 'newspack_insights_woocommerce_active' );
		parent::tear_down();
	}

	/**
	 * Two different BQ-internal influenced intents must each make their own proxy
	 * round-trip (distinct query_names → 2 calls). The rate and revenue methods for
	 * the SAME intent share one call — but cross-intent calls do not share a cache.
	 */
	public function test_paid_cache_is_keyed_per_query_name() {
		$row   = [
			'donation_conversion_influenced_rate' => 0.1,
			'conversion_denominator'              => 5,
			'influenced_revenue'                  => 100.0,
		];
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		// Two distinct query_names → two proxy calls.
		$proxy->expects( $this->exactly( 2 ) )
			->method( 'query' )
			->willReturn( [ $row ] );

		$metric = new Prompts_Metric( $proxy );
		// Each method dispatches its own catalog query.
		$metric->get_donation_conversion_influenced_14d( $this->start(), $this->end() );
		$metric->get_subscription_conversion_influenced_14d( $this->start(), $this->end() );
	}

	// --- Section 4b: BQ-internal paid-influenced rate + revenue (C2) ----

	/**
	 * Donation influenced rate: row { donation_conversion_influenced_rate: 0.2,
	 * conversion_denominator: 10, influenced_revenue: 250.0 }
	 * → value 0.2, computable true, denominator 10, data_missing false.
	 */
	public function test_donation_influenced_rate_bq_internal_populated() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'prompts_donation_conversion_influenced_14d', $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn(
				[
					[
						'donation_conversion_influenced_rate' => 0.2,
						'conversion_denominator' => 10,
						'influenced_revenue'     => 250.0,
					],
				] 
			);

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_donation_conversion_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertEqualsWithDelta( 0.2, $result['value'], 0.0001 );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 10, $result['denominator'] );
		$this->assertFalse( $result['data_missing'] ?? false );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	/**
	 * Donation influenced revenue: same row { ..., influenced_revenue: 250.0 }
	 * → value 250.0, placeholder_type 'currency'.
	 */
	public function test_donation_influenced_revenue_bq_internal_populated() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'prompts_donation_conversion_influenced_14d', $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn(
				[
					[
						'donation_conversion_influenced_rate' => 0.2,
						'conversion_denominator' => 10,
						'influenced_revenue'     => 250.0,
					],
				] 
			);

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_donation_revenue_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 250.0, $result['value'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'currency', $result['placeholder_type'] );
	}

	/**
	 * Subscription influenced rate: row { subscription_conversion_influenced_rate: 0.2,
	 * conversion_denominator: 10, influenced_revenue: 250.0 }
	 * → value 0.2, computable true, denominator 10.
	 */
	public function test_subscription_influenced_rate_bq_internal_populated() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'prompts_subscription_conversion_influenced_14d', $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn(
				[
					[
						'subscription_conversion_influenced_rate' => 0.2,
						'conversion_denominator' => 10,
						'influenced_revenue'     => 250.0,
					],
				] 
			);

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_subscription_conversion_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertEqualsWithDelta( 0.2, $result['value'], 0.0001 );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 10, $result['denominator'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	/**
	 * Subscription influenced revenue: same row → value 250.0, placeholder_type 'currency'.
	 */
	public function test_subscription_influenced_revenue_bq_internal_populated() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->expects( $this->once() )
			->method( 'query' )
			->with( 'prompts_subscription_conversion_influenced_14d', $this->isInstanceOf( DateTimeImmutable::class ), $this->isInstanceOf( DateTimeImmutable::class ) )
			->willReturn(
				[
					[
						'subscription_conversion_influenced_rate' => 0.2,
						'conversion_denominator' => 10,
						'influenced_revenue'     => 250.0,
					],
				] 
			);

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_subscription_revenue_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 250.0, $result['value'] );
		$this->assertTrue( $result['computable'] );
		$this->assertSame( 'currency', $result['placeholder_type'] );
	}

	/**
	 * Null rate: { donation_conversion_influenced_rate: null, conversion_denominator: 0,
	 * influenced_revenue: null } → rate non-computable, denominator 0.
	 */
	public function test_donation_influenced_rate_null_rate_non_computable() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturn(
				[
					[
						'donation_conversion_influenced_rate' => null,
						'conversion_denominator' => 0,
						'influenced_revenue'     => null,
					],
				] 
			);

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_donation_conversion_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertFalse( $result['computable'] );
		$this->assertSame( 0, $result['denominator'] );
		$this->assertSame( 'rate', $result['placeholder_type'] );
	}

	/**
	 * Missing columns: row WITHOUT the rate/denominator keys → data_missing true.
	 */
	public function test_donation_influenced_rate_missing_columns_data_missing() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturn( [ [ 'influenced_revenue' => 100.0 ] ] ); // Missing rate + denominator keys.

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_donation_conversion_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertTrue( $result['data_missing'] ?? false );
	}

	/**
	 * Proxy WP_Error → state 'error' for both rate and revenue.
	 */
	public function test_donation_influenced_rate_proxy_error() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturn( new \WP_Error( 'bigquery_proxy_http_error', 'HTTP 500' ) );

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_donation_conversion_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_http_error', $result['error_code'] );
	}

	/**
	 * Non-integer denominator → state 'error' with bigquery_proxy_malformed_value.
	 */
	public function test_donation_influenced_rate_non_integer_denominator_error() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )
			->willReturn(
				[
					[
						'donation_conversion_influenced_rate' => 0.2,
						'conversion_denominator' => 8.5,
						'influenced_revenue'     => 100.0,
					],
				] 
			);

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_donation_conversion_influenced_14d( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_value', $result['error_code'] );
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

	/**
	 * Distribution: top-level is an array but contains a non-array row (the
	 * hub's contract is broken). Surface as malformed so a PHP-8 TypeError on
	 * string-offset access can't crash the endpoint.
	 */
	public function test_distribution_returns_error_state_on_non_array_row() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_exposures_before_conversion',
			[ 'not-a-row' ]
		);
		$result = $metric->get_exposures_distribution( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
		$this->assertSame( [], $result['buckets'] );
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
	 * @param array|\WP_Error $donation_rows     Unused since NPPD-1745 (donations come
	 *                                           from order meta, not the proxy); kept for
	 *                                           call-site compatibility.
	 * @param array|\WP_Error $subscription_rows Unused since NPPD-1746 (subscriptions come
	 *                                           from order meta, not the proxy); kept for
	 *                                           call-site compatibility.
	 * @param int             $expected_queries  Expected proxy `query()` count: 1 — only the
	 *                                           perf table hits the proxy now; both donation
	 *                                           (NPPD-1745) and subscription (NPPD-1746)
	 *                                           augmentation read order meta off-proxy.
	 * @return BigQuery_Proxy_Client
	 */
	protected function make_performance_proxy( array $perf_rows, $donation_rows, $subscription_rows, int $expected_queries = 1 ): BigQuery_Proxy_Client {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		// Only the perf table hits the proxy now. Donation (NPPD-1745) and subscription
		// (NPPD-1746) per-popup counts both come from order meta (Donors_Metric /
		// Subscribers_Metric), so neither augmentation dispatches a proxy query.
		$proxy->expects( $this->exactly( $expected_queries ) )
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

		add_filter( 'newspack_insights_woocommerce_active', '__return_true' ); // Removed in tearDown.
		// NPPD-1745/1746: both donation AND subscription per-popup counts now come
		// from order meta (Donors_Metric / Subscribers_Metric), not the resolver. The
		// subscription count is the popup surface of the two-key map, keyed by popup id.
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn(
			[
				'42' => [
					'conversions' => 5,
					'revenue'     => 0.0,
				],
			]
		);
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->method( 'get_attributed_subscription_conversions' )->willReturn(
			[
				'by_gate'  => [],
				'by_popup' => [
					'99' => [
						'conversions' => 5,
						'revenue'     => 0.0,
					],
				],
			]
		);

		$metric = new Prompts_Metric( $proxy, null, $donors, $subscribers );
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
	 * NPPD-1746 (live-data fix): a prompt that drove subscriptions but is NOT
	 * registration-intent (e.g. an "Undefined"-intent "50% off" promo) still shows
	 * its subscription count + rate in the per-prompt table — matching the scalar
	 * card, which sums the whole `by_popup` map regardless of intent. Pre-fix this
	 * column gated on `intent=registration` and silently rendered 0, contradicting
	 * the scalar (found on a live publisher whose converting popups were Undefined).
	 */
	public function test_performance_by_prompt_shows_subscriptions_for_non_registration_intent() {
		$perf_rows = [ $this->performance_row( 77, '50% off promo', '', 1000 ) ]; // '' = Undefined intent.
		$proxy     = $this->make_performance_proxy( $perf_rows, [], [] );

		add_filter( 'newspack_insights_woocommerce_active', '__return_true' ); // Removed in tear_down.
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn( [] );
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->method( 'get_attributed_subscription_conversions' )->willReturn(
			[
				'by_gate'  => [],
				'by_popup' => [
					'77' => [
						'conversions' => 3,
						'revenue'     => 150.0,
					],
				],
			]
		);

		$metric = new Prompts_Metric( $proxy, null, $donors, $subscribers );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( '', $result['rows'][0]['intent'], 'guard: the row really is non-registration intent' );
		$this->assertSame( 3, $result['rows'][0]['subscription_conversions'], 'an Undefined-intent prompt still surfaces its subscriptions' );
		$this->assertEqualsWithDelta( 0.003, $result['rows'][0]['subscription_conversion_rate'], 0.0001, '3 / 1000 impressions' );
		// Donation column is N/A on this row (non-donation intent, not in the donation
		// map): a null rate, not a misleading 0%.
		$this->assertSame( 0, $result['rows'][0]['donation_conversions'] );
		$this->assertNull( $result['rows'][0]['donation_conversion_rate'] );
	}

	/**
	 * NPPD-1756/1757 (capability path): a prompt that is donation-CAPABLE via the hub
	 * `donation_impressions` column but is NOT donation-intent (multi-block /
	 * Undefined) still surfaces its donations + a rate, with the donation-capable
	 * impressions as the denominator. Pre-column, the `action_type='donation'` gate
	 * hid it (rendered 0 / null).
	 */
	public function test_performance_by_prompt_shows_donations_for_non_donation_intent_when_capable() {
		$row = array_merge(
			$this->performance_row( 42, 'Member campaign', 'undefined', 1000 ),
			[ 'donation_impressions' => 500 ] // Donation-capable (carries a donate block) despite intent.
		);
		$proxy = $this->make_performance_proxy( [ $row ], [], [] );

		add_filter( 'newspack_insights_woocommerce_active', '__return_true' ); // Removed in tear_down.
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn(
			[
				'42' => [
					'conversions' => 3,
					'revenue'     => 0.0,
				],
			]
		);

		$metric = new Prompts_Metric( $proxy, null, $donors, $this->empty_subscribers() );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 'undefined', $result['rows'][0]['intent'], 'guard: row is non-donation intent' );
		$this->assertSame( 3, $result['rows'][0]['donation_conversions'], 'a donation-capable prompt surfaces its donations regardless of intent' );
		$this->assertEqualsWithDelta( 0.006, $result['rows'][0]['donation_conversion_rate'], 0.0001, '3 / 500 donation-capable impressions, not / 1000 total' );
	}

	/**
	 * NPPD-1745: the per-prompt donation_conversion_rate shares the tab-level
	 * coherence guard (donation_rate_value) — conversions exceeding a popup's
	 * impressions suppress the rate to null, never a >100% value.
	 */
	public function test_per_prompt_donation_rate_coherence_guard_suppresses_over_100() {
		$proxy = $this->make_performance_proxy( [ $this->performance_row( 42, 'Donate', 'donation', 10 ) ], [], [] );
		add_filter( 'newspack_insights_woocommerce_active', '__return_true' );
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn(
			[
				'42' => [
					'conversions' => 50,
					'revenue'     => 0.0,
				],
			]
		);

		$metric = new Prompts_Metric( $proxy, null, $donors, $this->empty_subscribers() );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 50, $result['rows'][0]['donation_conversions'] );
		$this->assertNull( $result['rows'][0]['donation_conversion_rate'], 'per-prompt rate uses the shared coherence guard' );
	}

	/**
	 * NPPD-1745: per-prompt em-dash semantics match the tab-level card —
	 * impressions with zero conversions is a real 0%, not a null em-dash.
	 */
	public function test_per_prompt_donation_rate_real_zero_percent_when_no_conversions() {
		$proxy = $this->make_performance_proxy( [ $this->performance_row( 42, 'Donate', 'donation', 200 ) ], [], [] );
		add_filter( 'newspack_insights_woocommerce_active', '__return_true' );
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn( [] ); // No conversions for popup 42.

		$metric = new Prompts_Metric( $proxy, null, $donors, $this->empty_subscribers() );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 0, $result['rows'][0]['donation_conversions'] );
		$this->assertSame( 0.0, $result['rows'][0]['donation_conversion_rate'], 'real 0% per-prompt, not an em-dash' );
	}

	/**
	 * NPPD-1756/1757 (capability path): a donation-CAPABLE prompt (non-zero
	 * `donation_impressions`) that converted nobody is a real 0% — over the
	 * donation-capable impressions, not total — and is shown regardless of intent.
	 * The sibling test above covers the same semantics on the legacy intent path.
	 */
	public function test_per_prompt_donation_rate_real_zero_percent_on_capability_path() {
		$row = array_merge(
			$this->performance_row( 42, 'Member campaign', 'undefined', 1000 ),
			[ 'donation_impressions' => 300 ] // Capable (carries a donate block) despite non-donation intent.
		);
		$proxy = $this->make_performance_proxy( [ $row ], [], [] );
		add_filter( 'newspack_insights_woocommerce_active', '__return_true' );
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn( [] ); // No conversions for popup 42.

		$metric = new Prompts_Metric( $proxy, null, $donors, $this->empty_subscribers() );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 0, $result['rows'][0]['donation_conversions'] );
		$this->assertSame( 0.0, $result['rows'][0]['donation_conversion_rate'], 'capable-but-no-conversion is a real 0% over donation_impressions, not an em-dash' );
	}

	/**
	 * NPPD-1759 (capability path): a subscription-CAPABLE prompt (non-zero hub
	 * `checkout_impressions`) surfaces its subscriptions + a rate over the checkout-
	 * capable impressions, not total — symmetric with the donation capability column
	 * and the gates paywall column. Here an Undefined-intent "50% off" promo carries a
	 * checkout block: 3 / 500 capable impressions, not / 1000 total.
	 */
	public function test_performance_by_prompt_subscription_rate_prefers_checkout_impressions() {
		$row = array_merge(
			$this->performance_row( 77, '50% off promo', 'undefined', 1000 ),
			[ 'checkout_impressions' => 500 ] // Checkout-capable despite non-registration intent.
		);
		$proxy = $this->make_performance_proxy( [ $row ], [], [] );

		add_filter( 'newspack_insights_woocommerce_active', '__return_true' ); // Removed in tear_down.
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn( [] );
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->method( 'get_attributed_subscription_conversions' )->willReturn(
			[
				'by_gate'  => [],
				'by_popup' => [
					'77' => [
						'conversions' => 3,
						'revenue'     => 150.0,
					],
				],
			]
		);

		$metric = new Prompts_Metric( $proxy, null, $donors, $subscribers );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 3, $result['rows'][0]['subscription_conversions'] );
		$this->assertEqualsWithDelta( 0.006, $result['rows'][0]['subscription_conversion_rate'], 0.0001, '3 / 500 checkout-capable impressions, not / 1000 total' );
	}

	/**
	 * NPPD-1759 (capability gate): the subscription column is now CAPABILITY-gated, not
	 * map-gated. A prompt that converted (is in the subscription map) but is NOT
	 * checkout-capable (`checkout_impressions` = 0) gets null subscription columns —
	 * the capability gate overrides map membership. This is the behavior change from
	 * the interim map-membership gate; a checkout block is what makes the rate apply.
	 */
	public function test_performance_by_prompt_subscription_not_capable_when_checkout_impressions_zero() {
		$row = array_merge(
			$this->performance_row( 77, 'Registration only', 'registration', 1000 ),
			[ 'checkout_impressions' => 0 ] // No checkout block → not subscription-capable.
		);
		$proxy = $this->make_performance_proxy( [ $row ], [], [] );

		add_filter( 'newspack_insights_woocommerce_active', '__return_true' ); // Removed in tear_down.
		$donors = $this->createMock( Donors_Metric::class );
		$donors->method( 'get_prompt_attributed_donation_conversions' )->willReturn( [] );
		$subscribers = $this->createMock( Subscribers_Metric::class );
		$subscribers->method( 'get_attributed_subscription_conversions' )->willReturn(
			[
				'by_gate'  => [],
				'by_popup' => [
					// Popup converted, but the capability column (0) gates it out anyway.
					'77' => [
						'conversions' => 4,
						'revenue'     => 0.0,
					],
				],
			]
		);

		$metric = new Prompts_Metric( $proxy, null, $donors, $subscribers );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 0, $result['rows'][0]['subscription_conversions'], 'capability gate (checkout_impressions 0) overrides map membership' );
		$this->assertNull( $result['rows'][0]['subscription_conversion_rate'], 'a non-checkout-capable prompt gets an em-dash, not a rate' );
	}

	/**
	 * Performance by prompt: locks the canonical row-key contract the React
	 * layer consumes (`PromptPerformanceRow`). A column rename on either side
	 * silently blanks the table, so assert the exact set + order.
	 */
	public function test_performance_by_prompt_row_schema_is_locked() {
		$perf_rows = [ $this->performance_row( 42, 'Donate now', 'donation', 100 ) ];
		$proxy     = $this->make_performance_proxy( $perf_rows, [], [], 1 ); // Non-WC env: perf query only.

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame(
			[
				'popup_id',
				'prompt_title',
				'intent',
				'intent_label',
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

		$metric = new Prompts_Metric( $proxy );
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

		$metric = new Prompts_Metric( $proxy );
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

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
	}

	/**
	 * Performance by prompt: top-level is an array but contains a non-array row
	 * (the hub's contract is broken). Surface as malformed so a PHP-8 TypeError
	 * on string-offset access can't crash the endpoint.
	 */
	public function test_performance_by_prompt_returns_error_state_on_non_array_row() {
		$proxy = $this->createMock( BigQuery_Proxy_Client::class );
		$proxy->method( 'query' )->willReturn( [ 'not-a-row' ] );

		$metric = new Prompts_Metric( $proxy );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
		$this->assertSame( [], $result['rows'] );
	}

	/**
	 * Performance by prompt: on a non-WooCommerce publisher the donation columns
	 * degrade to 0 / null (no order data) while the engagement table still renders
	 * — and the order-meta reader is never queried (NPPD-1745). Replaces the old
	 * donation-proxy-failure degradation test; donations no longer hit the proxy.
	 */
	public function test_performance_by_prompt_donation_columns_degrade_on_non_woocommerce() {
		$perf_rows = [ $this->performance_row( 42, 'Donate now', 'donation', 1000 ) ];
		$proxy     = $this->make_performance_proxy( $perf_rows, [], [], 1 ); // Non-WC: subscription augmentation is gated off too.

		// No WC filter → woocommerce_active() is false; the order-meta reader must
		// not be queried.
		$donors = $this->createMock( Donors_Metric::class );
		$donors->expects( $this->never() )->method( 'get_prompt_attributed_donation_conversions' );

		$metric = new Prompts_Metric( $proxy, null, $donors, $this->empty_subscribers() );
		$result = $metric->get_performance_by_prompt( $this->start(), $this->end() );

		// Table renders successfully — engagement data is load-bearing.
		$this->assertSame( 'populated', $result['state'] );
		$this->assertSame( 1000, $result['rows'][0]['impressions'] );
		$this->assertSame( 0.1, $result['rows'][0]['ctr'] );
		// Donation columns degrade to N/A on a non-WC publisher.
		$this->assertSame( 0, $result['rows'][0]['donation_conversions'] );
		$this->assertNull( $result['rows'][0]['donation_conversion_rate'], 'non-WC donation column is N/A, not 0%' );
		// Subscription columns degrade too — the resolver path also requires WC, so it
		// is gated off on a non-WC publisher (no wc_get_orders() call).
		$this->assertSame( 0, $result['rows'][0]['subscription_conversions'] );
		$this->assertNull( $result['rows'][0]['subscription_conversion_rate'], 'non-WC subscription column is N/A' );
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
				// NPPD-1758: checkout-button prompts emit action_type='checkout'.
				[
					'intent'               => 'checkout',
					'impressions'          => 1500,
					'unique_viewers'       => 400,
					'ctr'                  => 0.12,
					'form_submission_rate' => 0.0,
					'dismissal_rate'       => 0.05,
				],
				// An intent with no friendly override → intent_label is null (the
				// frontend humanizes the raw value).
				[
					'intent'               => 'undefined',
					'impressions'          => 100,
					'unique_viewers'       => 50,
					'ctr'                  => 0.01,
					'form_submission_rate' => 0.0,
					'dismissal_rate'       => 0.0,
				],
			]
		);
		$result = $metric->get_performance_by_intent( $this->start(), $this->end() );

		$this->assertSame( 'populated', $result['state'] );
		$this->assertCount( 5, $result['rows'] );

		$this->assertSame( 'donation', $result['rows'][0]['intent'] );
		$this->assertSame( 'Donation', $result['rows'][0]['intent_label'] );
		$this->assertSame( 5000, $result['rows'][0]['impressions'] );

		$this->assertSame( 'registration', $result['rows'][1]['intent'] );
		$this->assertSame( 'Registration', $result['rows'][1]['intent_label'] );

		$this->assertSame( 'newsletters_subscription', $result['rows'][2]['intent'] );
		$this->assertSame( 'Newsletter signup', $result['rows'][2]['intent_label'] );

		// NPPD-1758: 'checkout' → reader-facing 'Subscription'.
		$this->assertSame( 'checkout', $result['rows'][3]['intent'] );
		$this->assertSame( 'Subscription', $result['rows'][3]['intent_label'] );

		// Unmapped intent → null label, so the frontend falls back to humanizing.
		$this->assertSame( 'undefined', $result['rows'][4]['intent'] );
		$this->assertNull( $result['rows'][4]['intent_label'] );
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
	 * Performance by intent: top-level is an array but contains a non-array row.
	 * Surface as malformed so a PHP-8 TypeError on string-offset access can't
	 * crash the endpoint.
	 */
	public function test_performance_by_intent_returns_error_state_on_non_array_row() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_performance_by_intent',
			[ 'not-a-row' ]
		);
		$result = $metric->get_performance_by_intent( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
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

	/**
	 * Performance by placement: top-level is an array but contains a non-array
	 * row. Surface as malformed so a PHP-8 TypeError on string-offset access
	 * can't crash the endpoint.
	 */
	public function test_performance_by_placement_returns_error_state_on_non_array_row() {
		$metric = $this->make_metric_with_proxy_returning(
			'prompts_performance_by_placement',
			[ 'not-a-row' ]
		);
		$result = $metric->get_performance_by_placement( $this->start(), $this->end() );

		$this->assertSame( 'error', $result['state'] );
		$this->assertSame( 'bigquery_proxy_malformed_rows', $result['error_code'] );
		$this->assertSame( [], $result['rows'] );
	}

	// --- Per-intent capability gate (NPPD-1720) -------------------------

	/**
	 * Build a Prompts_Metric whose capability detector reads the given fixture
	 * prompts instead of newspack-popups (not loaded in this suite). Mirrors the
	 * proxy injection seam.
	 *
	 * @param string[] $prompts List of prompt `post_content` strings.
	 * @return Prompts_Metric
	 */
	protected function make_metric_with_prompts( array $prompts ): Prompts_Metric {
		return new Prompts_Metric(
			$this->createMock( BigQuery_Proxy_Client::class ),
			function () use ( $prompts ) {
				return $prompts;
			}
		);
	}

	/**
	 * Serialized prompt `post_content` containing the given blocks.
	 *
	 * @param string[] $block_names Fully-qualified block names, e.g. 'newspack-blocks/donate'.
	 * @return string
	 */
	protected function prompt_with_blocks( array $block_names ): string {
		$content = '';
		foreach ( $block_names as $block_name ) {
			$content .= sprintf( "<!-- wp:%1\$s -->\n<!-- /wp:%1\$s -->\n", $block_name );
		}
		return $content;
	}

	/**
	 * Zero active prompts → every gated metric is not capable.
	 */
	public function test_capability_flags_all_false_when_no_active_prompts() {
		$flags = $this->make_metric_with_prompts( [] )->get_capability_flags();

		$this->assertCount( 13, $flags );
		foreach ( $flags as $capable ) {
			$this->assertFalse( $capable );
		}
	}

	/**
	 * Informational-only prompts (no conversion block) → not capable. This is the
	 * sponsor-note / hand-rolled-CTA case.
	 */
	public function test_capability_flags_all_false_for_informational_only_prompts() {
		$prompts = [
			$this->prompt_with_blocks( [ 'core/paragraph', 'core/buttons', 'core/button' ] ),
			$this->prompt_with_blocks( [ 'newspack-blocks/homepage-articles' ] ),
		];
		$flags = $this->make_metric_with_prompts( $prompts )->get_capability_flags();

		foreach ( $flags as $capable ) {
			$this->assertFalse( $capable );
		}
	}

	/**
	 * Newsletter-only prompt → newsletter + form-submission-rate capable, every
	 * other intent not capable. The common newsletter-only setup.
	 */
	public function test_capability_flags_newsletter_only() {
		$prompts = [ $this->prompt_with_blocks( [ 'newspack-newsletters/subscribe' ] ) ];
		$flags   = $this->make_metric_with_prompts( $prompts )->get_capability_flags();

		$this->assertTrue( $flags['newsletter_signup_conversion_direct'] );
		$this->assertTrue( $flags['newsletter_signup_conversion_influenced_7d'] );
		// Form submission rate is capable when ANY watched block is present.
		$this->assertTrue( $flags['form_submission_rate'] );

		$this->assertFalse( $flags['registration_conversion_direct'] );
		$this->assertFalse( $flags['donation_conversion_direct'] );
		$this->assertFalse( $flags['donation_revenue_direct'] );
		$this->assertFalse( $flags['subscription_conversion_direct'] );
		$this->assertFalse( $flags['subscription_revenue_direct'] );
	}

	/**
	 * Newsletter + donation across two prompts → both intents capable, the other
	 * two not.
	 */
	public function test_capability_flags_mixed_newsletter_and_donation() {
		$prompts = [
			$this->prompt_with_blocks( [ 'newspack-newsletters/subscribe' ] ),
			$this->prompt_with_blocks( [ 'newspack-blocks/donate' ] ),
		];
		$flags = $this->make_metric_with_prompts( $prompts )->get_capability_flags();

		$this->assertTrue( $flags['newsletter_signup_conversion_direct'] );
		$this->assertTrue( $flags['donation_conversion_direct'] );
		$this->assertTrue( $flags['donation_revenue_influenced_14d'] );
		$this->assertTrue( $flags['form_submission_rate'] );

		$this->assertFalse( $flags['registration_conversion_direct'] );
		$this->assertFalse( $flags['subscription_conversion_direct'] );
	}

	/**
	 * A single prompt carrying BOTH a donate and a subscribe block registers both
	 * capabilities — newspack-popups' `action_type` would collapse this to
	 * 'undefined', which is exactly why the gate reads raw block presence.
	 */
	public function test_capability_flags_multi_block_single_prompt() {
		$prompts = [
			$this->prompt_with_blocks( [ 'newspack-blocks/donate', 'newspack-newsletters/subscribe' ] ),
		];
		$flags = $this->make_metric_with_prompts( $prompts )->get_capability_flags();

		$this->assertTrue( $flags['donation_conversion_direct'] );
		$this->assertTrue( $flags['newsletter_signup_conversion_direct'] );

		$this->assertFalse( $flags['registration_conversion_direct'] );
		$this->assertFalse( $flags['subscription_conversion_direct'] );
	}

	/**
	 * Checkout-button is detected though it's NOT in newspack-popups' stock
	 * watched-blocks map — the gate adds it so checkout-button membership prompts
	 * drive the subscription intent rather than reading as informational.
	 */
	public function test_capability_flags_detects_checkout_button() {
		$prompts = [ $this->prompt_with_blocks( [ 'newspack-blocks/checkout-button' ] ) ];
		$flags   = $this->make_metric_with_prompts( $prompts )->get_capability_flags();

		$this->assertTrue( $flags['subscription_conversion_direct'] );
		$this->assertTrue( $flags['subscription_revenue_direct'] );
		// Checkout-button is a click-through, not an inline form, so it does NOT make
		// the form-submission rate capable (matches the form-bearing nudge copy).
		$this->assertFalse( $flags['form_submission_rate'] );

		$this->assertFalse( $flags['donation_conversion_direct'] );
		$this->assertFalse( $flags['registration_conversion_direct'] );
	}

	/**
	 * Form submission rate is form-bearing: capable when a registration, donation,
	 * or newsletter block is present, but not for checkout-button alone.
	 */
	public function test_form_submission_rate_is_form_bearing() {
		$this->assertTrue(
			$this->make_metric_with_prompts( [ $this->prompt_with_blocks( [ 'newspack-blocks/donate' ] ) ] )
				->get_capability_flags()['form_submission_rate']
		);
		$this->assertFalse(
			$this->make_metric_with_prompts( [ $this->prompt_with_blocks( [ 'newspack-blocks/checkout-button' ] ) ] )
				->get_capability_flags()['form_submission_rate']
		);
	}

	/**
	 * All four blocks present somewhere in active prompts → every gated metric is
	 * capable.
	 */
	public function test_capability_flags_all_true_when_all_blocks_present() {
		$prompts = [
			$this->prompt_with_blocks(
				[
					'newspack/reader-registration',
					'newspack-blocks/donate',
					'newspack-newsletters/subscribe',
					'newspack-blocks/checkout-button',
				]
			),
		];
		$flags = $this->make_metric_with_prompts( $prompts )->get_capability_flags();

		$this->assertCount( 13, $flags );
		foreach ( $flags as $capable ) {
			$this->assertTrue( $capable );
		}
	}

	/**
	 * With no injected provider and the prompt CPT not registered (newspack-popups
	 * inactive), the detector can't inspect prompts and must fail open (all capable)
	 * rather than gate every conversion card on a misconfiguration.
	 */
	public function test_capability_flags_fail_open_when_popups_unavailable() {
		$this->assertFalse( post_type_exists( 'newspack_popups_cpt' ), 'prompt CPT should be unregistered in this suite' );

		$metric = new Prompts_Metric( $this->createMock( BigQuery_Proxy_Client::class ) );
		$flags  = $metric->get_capability_flags();

		foreach ( $flags as $capable ) {
			$this->assertTrue( $capable );
		}
	}

	/**
	 * The real read path (no injected provider): with the prompt CPT registered,
	 * the detector enumerates published prompts via get_posts() and reads their
	 * block content, excluding drafts. Guards against a has_block miss on real
	 * stored content and pins the publish-only filter.
	 */
	public function test_capability_flags_read_published_prompts_via_get_posts() {
		register_post_type( 'newspack_popups_cpt', [ 'public' => false ] );

		self::factory()->post->create(
			[
				'post_type'    => 'newspack_popups_cpt',
				'post_status'  => 'publish',
				// Block nested inside a group — the common "wrapped" case — still detected.
				'post_content' => "<!-- wp:group -->\n<!-- wp:newspack-blocks/donate /-->\n<!-- /wp:group -->",
			]
		);
		self::factory()->post->create(
			[
				'post_type'    => 'newspack_popups_cpt',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:newspack/reader-registration /-->',
			]
		);

		$flags = ( new Prompts_Metric( $this->createMock( BigQuery_Proxy_Client::class ) ) )->get_capability_flags();

		// Published donation prompt → donation capable (read via get_posts, nested block found).
		$this->assertTrue( $flags['donation_conversion_direct'] );
		// Registration block lives only on a draft → publish-only filter excludes it.
		$this->assertFalse( $flags['registration_conversion_direct'] );
		$this->assertFalse( $flags['subscription_conversion_direct'] );

		unregister_post_type( 'newspack_popups_cpt' );
	}

	/**
	 * Populated_scalar must expose a `data_missing` key (defaults false;
	 * can be set true via the optional 5th param).
	 */
	public function test_populated_scalar_includes_data_missing_flag() {
		$metric = new Prompts_Metric( $this->createMock( BigQuery_Proxy_Client::class ) );
		$ref    = new \ReflectionMethod( $metric, 'populated_scalar' );
		$ref->setAccessible( true );
		$default = $ref->invoke( $metric, 1.0, true, 5, 'rate' );
		$this->assertArrayHasKey( 'data_missing', $default );
		$this->assertFalse( $default['data_missing'] );
		$this->assertTrue( $ref->invoke( $metric, 0.0, false, null, 'rate', true )['data_missing'] );
	}
}
