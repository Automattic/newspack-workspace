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
use Newspack\Insights\BigQuery_Proxy_Client;

/**
 * Test \Newspack\Insights\Engagement_Metric.
 *
 * @group insights
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
	 * The per-window cache key incorporates the GA4 property ID (read from
	 * newspack_ga4_info after the B4 resolver swap), so a reconnect to a
	 * different property never serves the previous property's cache within
	 * the TTL.
	 */
	public function test_window_cache_key_varies_by_property() {
		$previous = get_option( 'newspack_ga4_info' );
		try {
			update_option( 'newspack_ga4_info', [ 'property_id' => '111111' ] );
			$key_a = $this->invoke( 'window_cache_key', [ '2026-01-01', '2026-01-31', true ] );

			update_option( 'newspack_ga4_info', [ 'property_id' => '222222' ] );
			$key_b = $this->invoke( 'window_cache_key', [ '2026-01-01', '2026-01-31', true ] );

			$this->assertNotSame( $key_a, $key_b, 'Different properties must produce different cache keys.' );

			// Same property + window is stable.
			update_option( 'newspack_ga4_info', [ 'property_id' => '111111' ] );
			$this->assertSame( $key_a, $this->invoke( 'window_cache_key', [ '2026-01-01', '2026-01-31', true ] ) );
		} finally {
			if ( false === $previous ) {
				delete_option( 'newspack_ga4_info' );
			} else {
				update_option( 'newspack_ga4_info', $previous );
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
	 * BQ path: metrics not yet wired in B4 still carry the not_implemented stub.
	 * (avg_pages_per_session and the other 3 quality scalars were wired in B4.)
	 */
	public function test_bq_stub_returns_not_implemented_for_unwired_metrics() {
		$payload = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		// most_read_articles is still stubbed for B5.
		$this->assertFalse( $payload['most_read_articles']['computable'] );
		$this->assertStringContainsString( 'NPPD-1630', $payload['most_read_articles']['error'] );
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
	 * When the GA4 report fails (no connection in tests), the traffic-source method
	 * must propagate the error/overlay payload and NOT run the bucketing helper —
	 * otherwise a failed report would render as a zeroed, needs-data comparison.
	 */
	public function test_traffic_source_short_circuits_on_failed_report() {
		$payload = $this->invoke( 'engagement_by_traffic_source_via_ga4', [ '123456', '2026-05-09', '2026-06-08' ] );
		// safe_run_report returns an error (or overlay) payload; it must pass through.
		$this->assertTrue( isset( $payload['error'] ) || isset( $payload['overlay'] ), 'Failed report should propagate error/overlay.' );
		$this->assertFalse( $payload['computable'] ?? true );
		// The bucketing helper never ran, so no rows / needs_data leaked in.
		$this->assertArrayNotHasKey( 'rows', $payload );
		$this->assertArrayNotHasKey( 'needs_data', $payload );
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

	/*
	===================================================================
	 * BQ proxy shaper tests (NPPD-1729 Task B4)
	 * ===================================================================
	 */

	/**
	 * Build a fake BigQuery_Proxy_Client that returns canned rows for a
	 * specific query name. Any other query name returns an empty array.
	 *
	 * @param string $expected_name  Catalog query name to intercept.
	 * @param array  $rows           Rows to return for that query.
	 * @return BigQuery_Proxy_Client
	 */
	private function makeProxyReturning( string $expected_name, array $rows ): BigQuery_Proxy_Client {
		return new class( $expected_name, $rows ) extends BigQuery_Proxy_Client {
			public function __construct( private string $expected_name, private array $rows ) {}
			public function query( string $query_name, \DateTimeInterface $start, \DateTimeInterface $end ) {
				return $query_name === $this->expected_name ? $this->rows : [];
			}
		};
	}

	/**
	 * bounce_rate_via_bq shapes a first-row 'bounce_rate' column into a rate payload.
	 */
	public function test_bounce_rate_via_bq_shapes_rate() {
		$proxy = $this->makeProxyReturning( 'engagement_bounce_rate', [ [ 'bounce_rate' => 0.62 ] ] );
		$out = Engagement_Metric::bounce_rate_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 0.62, $out['value'] );
		$this->assertSame( 'rate', $out['type'] );
	}

	/**
	 * avg_pages_per_session_via_bq shapes into a decimal scalar payload.
	 */
	public function test_avg_pages_per_session_via_bq_shapes_decimal() {
		$proxy = $this->makeProxyReturning( 'engagement_avg_pages_per_session', [ [ 'avg_pages_per_session' => 3.5 ] ] );
		$out   = Engagement_Metric::avg_pages_per_session_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 3.5, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'decimal', $out['type'] );
	}

	/**
	 * avg_engaged_session_duration_via_bq shapes into a decimal scalar payload.
	 */
	public function test_avg_engaged_session_duration_via_bq_shapes_decimal() {
		$proxy = $this->makeProxyReturning( 'engagement_avg_engaged_session_duration', [ [ 'avg_engaged_session_duration_sec' => 120.5 ] ] );
		$out   = Engagement_Metric::avg_engaged_session_duration_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 120.5, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'decimal', $out['type'] );
	}

	/**
	 * article_completion_rate_via_bq shapes the scroll_to_90_rate column into a rate payload.
	 */
	public function test_article_completion_rate_via_bq_shapes_rate() {
		$proxy = $this->makeProxyReturning( 'engagement_article_completion_rate', [ [ 'scroll_to_90_rate' => 0.45 ] ] );
		$out   = Engagement_Metric::article_completion_rate_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 0.45, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'rate', $out['type'] );
	}

	/**
	 * compute_via_bq wires the 4 quality scalars (no longer not_implemented stub).
	 * In the test environment the proxy is unconfigured (WP_Error), so each scalar
	 * returns computable=false — but the NPPD-1630 "not yet implemented" stub error
	 * must be gone, proving the method dispatches to the BQ shaper instead of the
	 * not_implemented_payload() fallback.
	 */
	public function test_compute_via_bq_quality_scalars_are_wired() {
		$payload = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		// These four should no longer carry 'NPPD-1630' not-implemented errors.
		$wired = [ 'avg_pages_per_session', 'avg_engaged_session_duration', 'bounce_rate', 'article_completion_rate' ];
		foreach ( $wired as $key ) {
			$this->assertArrayHasKey( $key, $payload, "$key present in BQ payload" );
			if ( isset( $payload[ $key ]['error'] ) ) {
				$this->assertStringNotContainsString( 'NPPD-1630', $payload[ $key ]['error'], "$key must not carry the not-implemented stub error" );
			}
		}
	}
}
