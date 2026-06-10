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
	 * The per-window cache key incorporates the GA4 property ID, so a reconnect
	 * to a different property never serves the previous property's cache within
	 * the TTL.
	 */
	public function test_window_cache_key_varies_by_property() {
		$previous = get_option( 'googlesitekit_analytics-4_settings' );
		try {
			update_option( 'googlesitekit_analytics-4_settings', [ 'propertyID' => '111111' ] );
			$key_a = $this->invoke( 'window_cache_key', [ '2026-01-01', '2026-01-31', true ] );

			update_option( 'googlesitekit_analytics-4_settings', [ 'propertyID' => '222222' ] );
			$key_b = $this->invoke( 'window_cache_key', [ '2026-01-01', '2026-01-31', true ] );

			$this->assertNotSame( $key_a, $key_b, 'Different properties must produce different cache keys.' );

			// Same property + window is stable.
			update_option( 'googlesitekit_analytics-4_settings', [ 'propertyID' => '111111' ] );
			$this->assertSame( $key_a, $this->invoke( 'window_cache_key', [ '2026-01-01', '2026-01-31', true ] ) );
		} finally {
			if ( false === $previous ) {
				delete_option( 'googlesitekit_analytics-4_settings' );
			} else {
				update_option( 'googlesitekit_analytics-4_settings', $previous );
			}
		}
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
	 * Build a GA4 sessionMedium report row: medium dimension + userEngagementDuration, sessions.
	 *
	 * @param string $medium   Session medium dimension value.
	 * @param float  $eng       Total userEngagementDuration (seconds).
	 * @param int    $sessions  Session count.
	 * @return array
	 */
	private function medium_row( string $medium, float $eng, int $sessions ): array {
		return [
			'dimensionValues' => [ [ 'value' => $medium ] ],
			'metricValues'    => [ [ 'value' => (string) $eng ], [ 'value' => (string) $sessions ] ],
		];
	}

	/**
	 * Pull a cohort row out of a bucket_by_traffic_source payload by segment key.
	 *
	 * @param array  $payload bucket_by_traffic_source result.
	 * @param string $segment 'newsletter' or 'other'.
	 * @return array
	 */
	private function cohort( array $payload, string $segment ): array {
		foreach ( $payload['rows'] as $row ) {
			if ( $row['segment'] === $segment ) {
				return $row;
			}
		}
		return [];
	}

	/**
	 * Normal case: email + newsletter mediums aggregate into the newsletter cohort,
	 * everything else into other, and per-session averages are sum(eng)/sum(sessions).
	 */
	public function test_bucket_by_traffic_source_aggregates_cohorts() {
		$rows    = [
			$this->medium_row( 'email', 9760.0, 100 ),       // newsletter.
			$this->medium_row( 'newsletter', 1490.0, 20 ),   // newsletter.
			$this->medium_row( 'organic', 6870.0, 100 ),     // other.
			$this->medium_row( 'referral', 1330.0, 100 ),    // other.
		];
		$payload = $this->invoke( 'bucket_by_traffic_source', [ $rows ] );

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'table', $payload['type'] );

		$newsletter = $this->cohort( $payload, 'newsletter' );
		$other      = $this->cohort( $payload, 'other' );

		// Newsletter: (9760 + 1490) / (100 + 20) = 11250 / 120 = 93.75.
		$this->assertSame( 120, $newsletter['sessions'] );
		$this->assertEqualsWithDelta( 93.75, $newsletter['avg_engagement_seconds'], 0.001 );
		// Other: (6870 + 1330) / (100 + 100) = 8200 / 200 = 41.0.
		$this->assertSame( 200, $other['sessions'] );
		$this->assertEqualsWithDelta( 41.0, $other['avg_engagement_seconds'], 0.001 );

		// Above the 100-session floor → comparison renders.
		$this->assertFalse( $payload['needs_data'] );
		// avg_pages_per_session is no longer part of the contract.
		$this->assertArrayNotHasKey( 'avg_pages_per_session', $newsletter );
	}

	/**
	 * Inverted case: when other sources out-engage newsletter, the cohorts still
	 * compute correctly — the headline inversion lives in the React layer.
	 */
	public function test_bucket_by_traffic_source_inverted() {
		$rows    = [
			$this->medium_row( 'email', 4900.0, 100 ),   // newsletter: 49s/session.
			$this->medium_row( 'organic', 9800.0, 100 ), // other: 98s/session.
		];
		$payload = $this->invoke( 'bucket_by_traffic_source', [ $rows ] );

		$newsletter = $this->cohort( $payload, 'newsletter' );
		$other      = $this->cohort( $payload, 'other' );
		$this->assertEqualsWithDelta( 49.0, $newsletter['avg_engagement_seconds'], 0.001 );
		$this->assertEqualsWithDelta( 98.0, $other['avg_engagement_seconds'], 0.001 );
		$this->assertFalse( $payload['needs_data'] );
	}

	/**
	 * Empty case: zero newsletter sessions yields a 0 average (no divide-by-zero)
	 * and the needs-data floor trips.
	 */
	public function test_bucket_by_traffic_source_empty_newsletter() {
		$rows    = [
			$this->medium_row( 'organic', 6870.0, 100 ),
			$this->medium_row( '(none)', 5860.0, 100 ),
		];
		$payload = $this->invoke( 'bucket_by_traffic_source', [ $rows ] );

		$newsletter = $this->cohort( $payload, 'newsletter' );
		$this->assertSame( 0, $newsletter['sessions'] );
		$this->assertSame( 0.0, (float) $newsletter['avg_engagement_seconds'] );
		$this->assertTrue( $payload['needs_data'] );
	}

	/**
	 * Below-floor case: a newsletter cohort with some sessions but under
	 * NEWSLETTER_SESSION_FLOOR still trips needs_data while computing its average.
	 */
	public function test_bucket_by_traffic_source_below_floor() {
		$rows    = [
			$this->medium_row( 'email', 4500.0, 50 ),     // 50 < 100 floor
			$this->medium_row( 'organic', 9800.0, 1000 ),
		];
		$payload = $this->invoke( 'bucket_by_traffic_source', [ $rows ] );

		$newsletter = $this->cohort( $payload, 'newsletter' );
		$this->assertSame( 50, $newsletter['sessions'] );
		$this->assertEqualsWithDelta( 90.0, $newsletter['avg_engagement_seconds'], 0.001 );
		$this->assertTrue( $payload['needs_data'] );
	}

	/**
	 * The orchestrator exposes the metric under the traffic-source key, and the old
	 * newsletter-status key is gone from both paths.
	 */
	public function test_traffic_source_key_replaces_newsletter_status() {
		$ga4 = $this->invoke( 'compute_via_ga4', [ '2026-05-09', '2026-06-08' ] );
		$bq  = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		$this->assertArrayHasKey( 'engagement_by_traffic_source', $ga4 );
		$this->assertArrayHasKey( 'engagement_by_traffic_source', $bq );
		$this->assertArrayNotHasKey( 'engagement_by_newsletter_status', $ga4 );
		$this->assertArrayNotHasKey( 'engagement_by_newsletter_status', $bq );
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
