<?php
/**
 * Test Cross_System_Metrics (NPPD-1675).
 *
 * The first Insights metrics that join two orchestrators: RPM and average
 * impressions per session divide a GAM figure by GA4 sessions. The join math is
 * pure and tested directly; the sessions bridge is exercised through the
 * `newspack_insights_pre_total_sessions` short-circuit filter so no BigQuery call
 * is made.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Derived\Cross_System_Metrics;
use WP_UnitTestCase;

/**
 * Cross_System_Metrics test class.
 *
 * @group insights
 */
class Test_Cross_System_Metrics extends WP_UnitTestCase {

	/**
	 * A computable revenue payload for reuse.
	 *
	 * @param float $value Revenue.
	 * @return array
	 */
	private function revenue( float $value ): array {
		return [
			'value'      => $value,
			'computable' => true,
			'type'       => 'currency',
		];
	}

	/**
	 * A computable impressions payload for reuse.
	 *
	 * @param int $value Impressions.
	 * @return array
	 */
	private function impressions( int $value ): array {
		return [
			'value'      => $value,
			'computable' => true,
			'type'       => 'count',
		];
	}

	/**
	 * RPM is revenue per thousand sessions, with revenue/sessions carried through
	 * as numerator/denominator.
	 */
	public function test_rpm_computes() {
		$out = Cross_System_Metrics::rpm( $this->revenue( 4200.0 ), 800000 );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'currency', $out['type'] );
		$this->assertSame( ( 4200.0 / 800000 ) * 1000, $out['value'] );
		$this->assertSame( 4200.0, $out['numerator'] );
		$this->assertSame( 800000, $out['denominator'] );
	}

	/**
	 * Zero revenue is a genuine $0.00 RPM (not "unavailable") — the join succeeded,
	 * the numerator is simply zero.
	 */
	public function test_rpm_zero_revenue_is_computable_zero() {
		$out = Cross_System_Metrics::rpm( $this->revenue( 0.0 ), 800000 );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 0.0, $out['value'] );
	}

	/**
	 * Average impressions per session divides impressions by sessions. Typed as a
	 * whole-number `count` for display; the raw ratio is preserved as `value`.
	 */
	public function test_avg_impressions_per_session_computes() {
		$out = Cross_System_Metrics::avg_impressions_per_session( $this->impressions( 2400000 ), 800000 );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'count', $out['type'] );
		$this->assertSame( 2400000 / 800000, $out['value'] );
		$this->assertSame( 2400000, $out['numerator'] );
		$this->assertSame( 800000, $out['denominator'] );
	}

	/**
	 * Null sessions (Audience unavailable) → data-unavailable overlay on both, not a
	 * misleading zero.
	 *
	 * @dataProvider provide_unavailable_sessions
	 * @param int|null $sessions Session count under test.
	 */
	public function test_missing_sessions_is_data_unavailable( $sessions ) {
		$rpm = Cross_System_Metrics::rpm( $this->revenue( 4200.0 ), $sessions );
		$avg = Cross_System_Metrics::avg_impressions_per_session( $this->impressions( 2400000 ), $sessions );

		foreach ( [ $rpm, $avg ] as $out ) {
			$this->assertFalse( $out['computable'] );
			$this->assertNull( $out['value'] );
			$this->assertSame( 'data_unavailable', $out['overlay']['type'] );
		}
	}

	/**
	 * Null (outage), zero, and negative sessions all fail closed to the overlay:
	 * there is no meaningful per-session figure without a positive denominator.
	 *
	 * @return array
	 */
	public function provide_unavailable_sessions(): array {
		return [
			'null'     => [ null ],
			'zero'     => [ 0 ],
			'negative' => [ -5 ],
		];
	}

	/**
	 * A non-computable / errored / overlaid source metric can't seed a derived
	 * value — the derived metric is data-unavailable rather than dividing garbage.
	 *
	 * @dataProvider provide_unusable_sources
	 * @param array $source A source payload that shouldn't contribute a value.
	 */
	public function test_unusable_source_is_data_unavailable( array $source ) {
		$out = Cross_System_Metrics::rpm( $source, 800000 );
		$this->assertFalse( $out['computable'] );
		$this->assertSame( 'data_unavailable', $out['overlay']['type'] );
	}

	/**
	 * Sources the join must refuse: not computable, carrying an error, carrying an
	 * overlay, or a non-numeric value.
	 *
	 * @return array
	 */
	public function provide_unusable_sources(): array {
		return [
			'not computable' => [
				[
					'value'      => 4200.0,
					'computable' => false,
				],
			],
			'errored'        => [
				[
					'value'      => null,
					'computable' => false,
					'error'      => 'boom',
				],
			],
			'overlaid'       => [
				[
					'value'      => null,
					'computable' => false,
					'overlay'    => [ 'type' => 'data_unavailable' ],
				],
			],
			'non-numeric'    => [
				[
					'value'      => null,
					'computable' => true,
				],
			],
		];
	}

	/**
	 * The sessions bridge delegates to the Audience orchestrator; the short-circuit
	 * filter proves it is wired without touching BigQuery.
	 */
	public function test_sessions_for_window_bridges_to_audience() {
		add_filter( 'newspack_insights_pre_total_sessions', [ $this, 'return_fixed_sessions' ], 10, 3 );
		$sessions = Cross_System_Metrics::sessions_for_window( '2026-01-01', '2026-01-31' );
		remove_filter( 'newspack_insights_pre_total_sessions', [ $this, 'return_fixed_sessions' ], 10 );

		$this->assertSame( 800000, $sessions );
	}

	/**
	 * Filter callback: a known session count for the bridge test.
	 *
	 * @param int|null $pre        Incoming value.
	 * @param string   $start_date Window start.
	 * @param string   $end_date   Window end.
	 * @return int
	 */
	public function return_fixed_sessions( $pre, $start_date, $end_date ): int {
		return 800000;
	}
}
