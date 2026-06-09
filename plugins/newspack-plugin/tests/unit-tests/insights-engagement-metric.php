<?php
/**
 * Tests for the Insights Engagement metric orchestrator (Tab 2, NPPD-1648).
 *
 * Covers the deterministic surface without a live GA4 connection: the
 * tab-level OAuth short-circuit, the BQ stub path, the four BQ-only hidden
 * metrics, and the GA4 response → payload transform helpers. Full GA4
 * round-trips are covered by manual verification.
 *
 * @package Newspack\Tests
 */

use Newspack\Insights\Engagement_Metric;

/**
 * Test \Newspack\Insights\Engagement_Metric.
 */
class Newspack_Test_Insights_Engagement_Metric extends WP_UnitTestCase {

	/**
	 * Invoke a private static method via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke( $method, array $args = [] ) {
		$ref = new ReflectionMethod( Engagement_Metric::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	/**
	 * No Google connection in the test environment → tab-level error.
	 */
	public function test_get_all_returns_tab_error_when_oauth_missing() {
		$payload = Engagement_Metric::get_all( '2026-05-09', '2026-06-08', false );
		$this->assertArrayHasKey( 'tab_error', $payload );
		$this->assertSame( 'oauth_not_connected', $payload['tab_error'] );
	}

	/**
	 * BQ stub returns not_implemented for every standard metric.
	 */
	public function test_bq_stub_returns_not_implemented() {
		$payload = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		$this->assertFalse( $payload['avg_pages_per_session']['computable'] );
		$this->assertStringContainsString( 'NPPD-1630', $payload['avg_pages_per_session']['error'] );
	}

	/**
	 * The four BQ-only metrics are hidden in v1 under both paths.
	 */
	public function test_bq_only_metrics_hidden_in_both_paths() {
		$bq_only = [
			'top_categories_by_engagement',
			'mobile_vs_desktop_content_preferences',
			'top_authors_by_repeat_reader_rate',
			'article_freshness_vs_engagement',
		];
		$bq  = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		$ga4 = $this->invoke( 'compute_via_ga4', [ '2026-05-09', '2026-06-08' ] );
		foreach ( $bq_only as $key ) {
			$this->assertTrue( $bq[ $key ]['hidden_in_v1'], "$key hidden in BQ path" );
			$this->assertTrue( $ga4[ $key ]['hidden_in_v1'], "$key hidden in GA4 path" );
		}
	}

	/**
	 * The cut box-plot metrics never appear in the orchestrator output.
	 */
	public function test_cut_box_plots_absent() {
		$ga4 = $this->invoke( 'compute_via_ga4', [ '2026-05-09', '2026-06-08' ] );
		$this->assertArrayNotHasKey( 'pages_per_session_distribution', $ga4 );
		$this->assertArrayNotHasKey( 'scroll_depth_distribution', $ga4 );
		$this->assertArrayNotHasKey( 'reader_author_affinity', $ga4 );
	}

	/**
	 * The scalar() helper casts a decimal metric to float.
	 */
	public function test_scalar_decimal_transform() {
		$raw     = [ 'raw' => [ 'rows' => [ [ 'metricValues' => [ [ 'value' => '2.5' ] ] ] ] ] ];
		$payload = $this->invoke( 'scalar', [ $raw, 'decimal' ] );
		$this->assertSame( 2.5, $payload['value'] );
		$this->assertSame( 'decimal', $payload['type'] );
		$this->assertTrue( $payload['computable'] );
	}

	/**
	 * Overlay propagation through a transform helper.
	 */
	public function test_overlay_propagates_through_transform() {
		$overlay = [
			'value'      => null,
			'computable' => false,
			'overlay'    => [
				'type'       => 'custom_dimension_missing',
				'dimensions' => [ 'post_id' ],
			],
		];
		$payload = $this->invoke( 'rows', [ $overlay, [ 'post_id' ], [ 'readers' ], 'table' ] );
		$this->assertSame( 'custom_dimension_missing', $payload['overlay']['type'] );
		$this->assertSame( [ 'post_id' ], $payload['overlay']['dimensions'] );
	}
}
