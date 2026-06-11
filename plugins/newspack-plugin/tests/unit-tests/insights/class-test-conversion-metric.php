<?php
/**
 * Test Conversion_Metric (NPPD-1609, Phase 1).
 *
 * Phase 1 orchestrator returns placeholder envelopes only — no proxy, no
 * SQL. These tests pin the envelope shape every Phase 2 swap must preserve:
 * scalar scorecards carry `value` / `pending` / `computable` /
 * `placeholder_type`; the five funnels carry `pending` + ordered zeroed
 * stages (two of them visibility-gated); the three PieCharts carry zeroed
 * source slices; the four cumulative distributions carry empty point/series
 * collections (4.4 visibility-gated); the two cohorts carry empty `cohorts`
 * + a hardcoded reference line; the weekly trends carry empty `weeks` + the
 * series keys; and the opportunity table carries empty `rows` + a threshold.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\Insights\Conversion_Metric;
use WP_UnitTestCase;

/**
 * Conversion_Metric test class.
 *
 * @group insights
 */
class Test_Conversion_Metric extends WP_UnitTestCase {

	/**
	 * Subject under test.
	 *
	 * @var Conversion_Metric
	 */
	private $metric;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->metric = new Conversion_Metric();
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
	 * The window start/end passed to every method under test.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	private function window(): array {
		return [ $this->make_date( '2026-03-22' ), $this->make_date( '2026-04-21' ) ];
	}

	/**
	 * Every scalar scorecard returns the Phase 1 placeholder envelope: a
	 * non-computable, pending, zero value of the documented type.
	 *
	 * @dataProvider provide_scalar_methods
	 * @param string $method           Method on Conversion_Metric to call.
	 * @param string $placeholder_type Expected `placeholder_type`.
	 */
	public function test_scalar_returns_placeholder_envelope( string $method, string $placeholder_type ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

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

		if ( 'decimal' === $placeholder_type ) {
			$this->assertSame( 0.0, $result['value'] );
		} else {
			$this->assertSame( 0, $result['value'] );
		}
	}

	/**
	 * The seven scalar scorecards: Section 7 influenced rates (4) and
	 * Section 8 opportunity snapshot counts (3).
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function provide_scalar_methods(): array {
		return [
			// Section 7 — cross-tab influenced attribution.
			'influenced_registration_rate_7d'  => [ 'get_influenced_registration_rate_7d', 'rate' ],
			'influenced_subscription_rate_14d' => [ 'get_influenced_subscription_rate_14d', 'rate' ],
			'influenced_donation_rate_14d'     => [ 'get_influenced_donation_rate_14d', 'rate' ],
			'influenced_newsletter_rate_7d'    => [ 'get_influenced_newsletter_rate_7d', 'rate' ],
			// Section 8 — opportunity buckets (snapshot counts).
			'stale_registered_count'           => [ 'get_stale_registered_count', 'count' ],
			'at_risk_subscriber_count'         => [ 'get_at_risk_subscriber_count', 'count' ],
			'lapsed_donor_count'               => [ 'get_lapsed_donor_count', 'count' ],
		];
	}

	/**
	 * Every funnel returns a pending envelope with the expected number of
	 * ordered, zeroed stages.
	 *
	 * @dataProvider provide_funnel_methods
	 * @param string $method      Method on Conversion_Metric to call.
	 * @param int    $stage_count Expected number of stages.
	 */
	public function test_funnel_returns_zeroed_stages( string $method, int $stage_count ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertCount( $stage_count, $result['stages'] );
		foreach ( $result['stages'] as $stage ) {
			$this->assertArrayHasKey( 'label', $stage );
			$this->assertNotSame( '', $stage['label'] );
			$this->assertSame( 0, $stage['count'] );
			$this->assertSame( 0.0, $stage['pct_of_top'] );
		}
	}

	/**
	 * The five funnels and their stage counts: the Section 1 lifecycle
	 * funnel (5) and the four Section 2 per-journey funnels (3/3/3/2).
	 *
	 * @return array<string, array{0:string,1:int}>
	 */
	public function provide_funnel_methods(): array {
		return [
			'reader_lifecycle'         => [ 'get_reader_lifecycle_funnel', 5 ],
			'anonymous_to_registered'  => [ 'get_anonymous_to_registered_funnel', 3 ],
			'registered_to_subscriber' => [ 'get_registered_to_subscriber_funnel', 3 ],
			'registered_to_donor'      => [ 'get_registered_to_donor_funnel', 3 ],
			'subscriber_to_donor'      => [ 'get_subscriber_to_donor_funnel', 2 ],
		];
	}

	/**
	 * The visibility-gated Section 2.4 funnel is hidden in Phase 1, with the
	 * `insufficient_data` reason the React side reads for its empty state.
	 */
	public function test_cross_upsell_funnel_is_hidden_in_phase_1() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_subscriber_to_donor_funnel( $start, $end );

		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'insufficient_data', $result['visibility_reason'] );
	}

	/**
	 * Every Section 3 PieChart returns a pending envelope with a zero total
	 * and the three zeroed source slices (gate / prompt / direct).
	 *
	 * @dataProvider provide_source_mix_methods
	 * @param string $method Method on Conversion_Metric to call.
	 */
	public function test_source_mix_returns_zeroed_slices( string $method ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertSame( 0, $result['total'] );
		$this->assertCount( 3, $result['slices'] );
		$this->assertSame(
			[ 'gate', 'prompt', 'direct' ],
			array_column( $result['slices'], 'source' )
		);
		foreach ( $result['slices'] as $slice ) {
			$this->assertSame( 0, $slice['count'] );
			$this->assertSame( 0.0, $slice['pct'] );
		}
	}

	/**
	 * The three Section 3 PieCharts.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_source_mix_methods(): array {
		return [
			'registrations' => [ 'get_source_mix_registrations' ],
			'subscribers'   => [ 'get_source_mix_subscribers' ],
			'donors'        => [ 'get_source_mix_donors' ],
		];
	}

	/**
	 * The two single-series Section 4 distributions return a pending
	 * envelope with an empty `points` array.
	 *
	 * @dataProvider provide_single_series_distribution_methods
	 * @param string $method Method on Conversion_Metric to call.
	 */
	public function test_single_series_distribution_is_empty( string $method ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertArrayHasKey( 'points', $result );
		$this->assertSame( [], $result['points'] );
	}

	/**
	 * The single-series cumulative distributions (4.1 and 4.4).
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_single_series_distribution_methods(): array {
		return [
			'time_to_register'        => [ 'get_time_to_register_distribution' ],
			'subscriber_to_donor_lag' => [ 'get_subscriber_to_donor_lag_distribution' ],
		];
	}

	/**
	 * The two multi-series Section 4 distributions return a pending envelope
	 * with three empty per-source series (gate / prompt / direct).
	 *
	 * @dataProvider provide_multi_series_distribution_methods
	 * @param string $method Method on Conversion_Metric to call.
	 */
	public function test_multi_series_distribution_is_empty( string $method ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertCount( 3, $result['groups'] );
		$this->assertSame(
			[ 'gate', 'prompt', 'direct' ],
			array_column( $result['groups'], 'label' )
		);
		foreach ( $result['groups'] as $group ) {
			$this->assertSame( [], $group['points'] );
		}
	}

	/**
	 * The multi-series cumulative distributions (4.2 and 4.3).
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_multi_series_distribution_methods(): array {
		return [
			'time_to_subscribe' => [ 'get_time_to_subscribe_distribution' ],
			'time_to_donate'    => [ 'get_time_to_donate_distribution' ],
		];
	}

	/**
	 * The visibility-gated Section 4.4 lag distribution is hidden in Phase 1.
	 */
	public function test_lag_distribution_is_hidden_in_phase_1() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_subscriber_to_donor_lag_distribution( $start, $end );

		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'insufficient_data', $result['visibility_reason'] );
	}

	/**
	 * Each Section 5 cohort returns a pending envelope with empty `cohorts`
	 * and the spec's hardcoded reference line.
	 *
	 * @dataProvider provide_cohort_methods
	 * @param string $method         Method on Conversion_Metric to call.
	 * @param float  $expected_value Expected reference-line value.
	 */
	public function test_cohort_returns_empty_with_reference_line( string $method, float $expected_value ) {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->$method( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertSame( [], $result['cohorts'] );
		$this->assertArrayHasKey( 'reference_line', $result );
		$this->assertSame( $expected_value, $result['reference_line']['value'] );
		$this->assertNotSame( '', $result['reference_line']['label'] );
	}

	/**
	 * The two Section 5 cohorts and their hardcoded reference-line values.
	 *
	 * @return array<string, array{0:string,1:float}>
	 */
	public function provide_cohort_methods(): array {
		return [
			'registration_to_conversion' => [ 'get_registration_to_conversion_cohort', 0.15 ],
			'subscriber_retention'       => [ 'get_subscriber_retention_cohort', 0.70 ],
		];
	}

	/**
	 * The Section 6 weekly trends return a pending envelope with empty
	 * `weeks` and the two tracked series keys.
	 */
	public function test_weekly_trends_returns_empty_weeks_with_series() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_weekly_conversion_rates( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertSame( [], $result['weeks'] );
		$this->assertSame( [ 'registration_rate', 'subscription_attempt_rate' ], $result['series'] );
	}

	/**
	 * The Section 8.4 table returns a pending envelope with empty `rows` and
	 * the pageview threshold, so the React table renders its empty-state row.
	 */
	public function test_top_pages_table_returns_empty_rows_with_threshold() {
		[ $start, $end ] = $this->window();
		$result          = $this->metric->get_top_pages_no_conversion( $start, $end );

		$this->assertTrue( $result['pending'] );
		$this->assertSame( [], $result['rows'] );
		$this->assertSame( 100, $result['threshold_pageviews'] );
	}
}
