<?php
/**
 * Test Prompts_Metric (NPPD-1607, Phase 1).
 *
 * Phase 1 orchestrator returns placeholder envelopes only — no proxy,
 * no SQL. These tests pin the envelope shape every Phase 2 swap must
 * preserve: scalar scorecards carry `value` / `pending` / `computable`
 * / `placeholder_type`, the funnel/distribution carry `pending` + an
 * ordered collection, and the three performance tables carry `pending`
 * + an empty `rows` array.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\Insights\Prompts_Metric;
use WP_UnitTestCase;

/**
 * Prompts_Metric test class.
 *
 * @group insights
 */
class Test_Prompts_Metric extends WP_UnitTestCase {

	/**
	 * Subject under test.
	 *
	 * @var Prompts_Metric
	 */
	private $metric;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->metric = new Prompts_Metric();
	}

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
	 * Every scalar scorecard returns the Phase 1 placeholder envelope:
	 * a non-computable, pending, zero value of the documented type.
	 *
	 * @dataProvider provide_scalar_methods
	 * @param string $method           Method on Prompts_Metric to call.
	 * @param string $placeholder_type Expected `placeholder_type`.
	 */
	public function test_scalar_returns_placeholder_envelope( string $method, string $placeholder_type ) {
		$result = $this->metric->$method( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'value', $result );
		$this->assertArrayHasKey( 'computable', $result );
		$this->assertArrayHasKey( 'pending', $result );
		$this->assertArrayHasKey( 'denominator', $result );
		$this->assertArrayHasKey( 'placeholder_type', $result );

		$this->assertTrue( $result['pending'], "$method should be pending in Phase 1" );
		$this->assertFalse( $result['computable'], "$method should be non-computable in Phase 1" );
		$this->assertNull( $result['denominator'] );
		$this->assertSame( $placeholder_type, $result['placeholder_type'] );

		// Decimal placeholders are floats; everything else is an integer zero.
		if ( 'decimal' === $placeholder_type ) {
			$this->assertSame( 0.0, $result['value'] );
		} else {
			$this->assertSame( 0, $result['value'] );
		}
	}

	/**
	 * Every scalar scorecard method, mapped to its expected placeholder type.
	 * Mirrors the formulas-doc query set for Tab 5 Sections 1–5.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function provide_scalar_methods(): array {
		return [
			// Section 1 — exposure.
			'total_prompt_impressions'                   => [ 'get_total_prompt_impressions', 'count' ],
			'unique_readers_reached'                     => [ 'get_unique_readers_reached', 'count' ],
			'avg_prompts_per_reader'                     => [ 'get_avg_prompts_per_reader', 'decimal' ],
			// Section 2 — engagement.
			'click_through_rate'                         => [ 'get_click_through_rate', 'rate' ],
			'form_submission_rate'                       => [ 'get_form_submission_rate', 'rate' ],
			'dismissal_rate'                             => [ 'get_dismissal_rate', 'rate' ],
			// Section 3 — free reader conversion.
			'registration_conversion_direct'             => [ 'get_registration_conversion_direct', 'rate' ],
			'registration_conversion_influenced_7d'      => [ 'get_registration_conversion_influenced_7d', 'rate' ],
			'newsletter_signup_conversion_direct'        => [ 'get_newsletter_signup_conversion_direct', 'rate' ],
			'newsletter_signup_conversion_influenced_7d' => [ 'get_newsletter_signup_conversion_influenced_7d', 'rate' ],
			// Section 4 — paid reader conversion.
			'donation_conversion_direct'                 => [ 'get_donation_conversion_direct', 'rate' ],
			'donation_conversion_influenced_14d'         => [ 'get_donation_conversion_influenced_14d', 'rate' ],
			'subscription_conversion_direct'             => [ 'get_subscription_conversion_direct', 'rate' ],
			'subscription_conversion_influenced_14d'     => [ 'get_subscription_conversion_influenced_14d', 'rate' ],
			// Section 5 — revenue.
			'donation_revenue_direct'                    => [ 'get_donation_revenue_direct', 'currency' ],
			'donation_revenue_influenced_14d'            => [ 'get_donation_revenue_influenced_14d', 'currency' ],
			'subscription_revenue_direct'                => [ 'get_subscription_revenue_direct', 'currency' ],
			'subscription_revenue_influenced_14d'        => [ 'get_subscription_revenue_influenced_14d', 'currency' ],
		];
	}

	/**
	 * The funnel returns a pending envelope with three ordered, zeroed stages.
	 */
	public function test_funnel_returns_three_zero_stages() {
		$result = $this->metric->get_conversion_funnel( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertTrue( $result['pending'] );
		$this->assertCount( 3, $result['stages'] );
		foreach ( $result['stages'] as $stage ) {
			$this->assertArrayHasKey( 'label', $stage );
			$this->assertNotSame( '', $stage['label'] );
			$this->assertSame( 0, $stage['count'] );
			$this->assertSame( 0.0, $stage['pct_of_top'] );
		}
	}

	/**
	 * The distribution returns a pending envelope with four zeroed buckets.
	 */
	public function test_distribution_returns_four_zero_buckets() {
		$result = $this->metric->get_exposures_distribution( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertTrue( $result['pending'] );
		$this->assertCount( 4, $result['buckets'] );
		foreach ( $result['buckets'] as $bucket ) {
			$this->assertArrayHasKey( 'label', $bucket );
			$this->assertNotSame( '', $bucket['label'] );
			$this->assertSame( 0, $bucket['count'] );
			$this->assertSame( 0.0, $bucket['pct'] );
		}
	}

	/**
	 * Each performance table returns a pending envelope with an empty
	 * `rows` array, so the React layer renders the spec's empty-state row.
	 *
	 * @dataProvider provide_table_methods
	 * @param string $method Method on Prompts_Metric to call.
	 */
	public function test_performance_table_returns_empty_rows( string $method ) {
		$result = $this->metric->$method( $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) );

		$this->assertTrue( $result['pending'] );
		$this->assertArrayHasKey( 'rows', $result );
		$this->assertSame( [], $result['rows'] );
	}

	/**
	 * The three Section 7 performance tables.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_table_methods(): array {
		return [
			'by_prompt'    => [ 'get_performance_by_prompt' ],
			'by_intent'    => [ 'get_performance_by_intent' ],
			'by_placement' => [ 'get_performance_by_placement' ],
		];
	}
}
