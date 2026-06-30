<?php
/**
 * Tests for the Insights Engagement metric orchestrator (Tab 2, NPPD-1648).
 *
 * Covers the deterministic surface without a live BQ connection: the
 * tab-level BQ path, hidden metrics, and the BQ response → payload transform
 * helpers. The GA4 path has been removed (NPPD-1729 Task B5).
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
	 * newspack_ga4_info), so a reconnect to a different property never serves
	 * the previous property's cache within the TTL.
	 */
	public function test_window_cache_key_varies_by_property() {
		$previous = get_option( 'newspack_ga4_info' );
		try {
			update_option( 'newspack_ga4_info', [ 'property_id' => '111111' ] );
			$key_a = $this->invoke( 'window_cache_key', [ '2026-01-01', '2026-01-31' ] );

			update_option( 'newspack_ga4_info', [ 'property_id' => '222222' ] );
			$key_b = $this->invoke( 'window_cache_key', [ '2026-01-01', '2026-01-31' ] );

			$this->assertNotSame( $key_a, $key_b, 'Different properties must produce different cache keys.' );

			// Same property + window is stable.
			update_option( 'newspack_ga4_info', [ 'property_id' => '111111' ] );
			$this->assertSame( $key_a, $this->invoke( 'window_cache_key', [ '2026-01-01', '2026-01-31' ] ) );
		} finally {
			if ( false === $previous ) {
				delete_option( 'newspack_ga4_info' );
			} else {
				update_option( 'newspack_ga4_info', $previous );
			}
		}
	}

	/**
	 * No BQ connection in the test environment → get_all returns a payload with a
	 * window key (connection_error always returns null on the BQ path).
	 */
	public function test_get_all_returns_payload_on_bq_path() {
		$payload = Engagement_Metric::get_all( '2026-05-09', '2026-06-08', false );
		// BQ path has no OAuth gate; it always proceeds. The window key must be present.
		$this->assertArrayHasKey( 'window', $payload );
		$this->assertArrayNotHasKey( 'tab_error', $payload );
	}

	/**
	 * Verifies only article_freshness_vs_engagement is hidden in v1 in the BQ path.
	 * The three previously-hidden metrics are now enabled.
	 */
	public function test_only_article_freshness_hidden_in_bq_path() {
		$bq = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		// article_freshness still hidden.
		$this->assertTrue( $bq['article_freshness_vs_engagement']['hidden_in_v1'], 'article_freshness must be hidden' );
		// The three previously-hidden metrics are now wired (not hidden).
		$now_enabled = [
			'top_categories_by_engagement',
			'mobile_vs_desktop_content_preferences',
			'top_authors_by_repeat_reader_rate',
		];
		foreach ( $now_enabled as $key ) {
			$this->assertArrayNotHasKey( 'hidden_in_v1', $bq[ $key ], "$key must NOT be hidden_in_v1 any more" );
		}
	}

	/**
	 * The cut box-plot metrics never appear in the orchestrator output.
	 */
	public function test_cut_box_plots_absent() {
		$bq = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		$this->assertArrayNotHasKey( 'pages_per_session_distribution', $bq );
		$this->assertArrayNotHasKey( 'scroll_depth_distribution', $bq );
		$this->assertArrayNotHasKey( 'reader_author_affinity', $bq );
	}

	/**
	 * Pull a cohort row out of a bucketed payload by segment key.
	 *
	 * @param array  $payload bucket_bq_by_traffic_source result.
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
	 * Build a BQ per-channel row.
	 *
	 * @param string $channel                 Channel label (e.g. 'Email', 'Organic Search').
	 * @param int    $sessions                Session count.
	 * @param float  $avg_engagement_seconds  Pre-computed per-session average.
	 * @return array
	 */
	private function bq_channel_row( string $channel, int $sessions, float $avg_engagement_seconds ): array {
		return [
			'channel'                => $channel,
			'sessions'               => $sessions,
			'avg_pages_per_session'  => 2.0,
			'avg_engagement_seconds' => $avg_engagement_seconds,
		];
	}

	/**
	 * Normal case: Email/Newsletter channels aggregate into the newsletter cohort,
	 * everything else into other, weighted average is sessions×avg / total_sessions.
	 */
	public function test_bucket_bq_by_traffic_source_aggregates_cohorts() {
		$rows = [
			$this->bq_channel_row( 'Email', 100, 97.6 ),        // newsletter: 100 × 97.6 = 9760.
			$this->bq_channel_row( 'Newsletter', 20, 74.5 ),    // newsletter: 20 × 74.5 = 1490.
			$this->bq_channel_row( 'Organic Search', 100, 68.7 ), // other: 100 × 68.7 = 6870.
			$this->bq_channel_row( 'Referral', 100, 13.3 ),     // other: 100 × 13.3 = 1330.
		];
		$payload = $this->invoke( 'bucket_bq_by_traffic_source', [ $rows ] );

		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'table', $payload['type'] );

		$newsletter = $this->cohort( $payload, 'newsletter' );
		$other      = $this->cohort( $payload, 'other' );

		// Newsletter: (9760 + 1490) / (100 + 20) = 11250 / 120 = 93.75.
		$this->assertSame( 120, $newsletter['sessions'] );
		$this->assertEqualsWithDelta( 93.75, $newsletter['avg_engagement_seconds'], 0.001 );
		// Other: (6870 + 1330) / 200 = 41.0.
		$this->assertSame( 200, $other['sessions'] );
		$this->assertEqualsWithDelta( 41.0, $other['avg_engagement_seconds'], 0.001 );

		// Above the 100-session floor → comparison renders.
		$this->assertFalse( $payload['needs_data'] );
	}

	/**
	 * Inverted case: when other sources out-engage newsletter, the cohorts still
	 * compute correctly — the headline inversion lives in the React layer.
	 */
	public function test_bucket_bq_by_traffic_source_inverted() {
		$rows = [
			$this->bq_channel_row( 'Email', 100, 49.0 ),
			$this->bq_channel_row( 'Organic Search', 100, 98.0 ),
		];
		$payload = $this->invoke( 'bucket_bq_by_traffic_source', [ $rows ] );

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
	public function test_bucket_bq_by_traffic_source_empty_newsletter() {
		$rows = [
			$this->bq_channel_row( 'Organic Search', 100, 68.7 ),
			$this->bq_channel_row( '(none)', 100, 58.6 ),
		];
		$payload = $this->invoke( 'bucket_bq_by_traffic_source', [ $rows ] );

		$newsletter = $this->cohort( $payload, 'newsletter' );
		$this->assertSame( 0, $newsletter['sessions'] );
		$this->assertSame( 0.0, (float) $newsletter['avg_engagement_seconds'] );
		$this->assertTrue( $payload['needs_data'] );
	}

	/**
	 * Below-floor case: a newsletter cohort with some sessions but under
	 * NEWSLETTER_SESSION_FLOOR still trips needs_data while computing its average.
	 */
	public function test_bucket_bq_by_traffic_source_below_floor() {
		$rows = [
			$this->bq_channel_row( 'Email', 50, 90.0 ),          // 50 < 100 floor.
			$this->bq_channel_row( 'Organic Search', 1000, 9.8 ),
		];
		$payload = $this->invoke( 'bucket_bq_by_traffic_source', [ $rows ] );

		$newsletter = $this->cohort( $payload, 'newsletter' );
		$this->assertSame( 50, $newsletter['sessions'] );
		$this->assertEqualsWithDelta( 90.0, $newsletter['avg_engagement_seconds'], 0.001 );
		$this->assertTrue( $payload['needs_data'] );
	}

	/**
	 * The orchestrator exposes the metric under the traffic-source key, and the old
	 * newsletter-status key is gone.
	 */
	public function test_traffic_source_key_present_in_bq_path() {
		$bq = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		$this->assertArrayHasKey( 'engagement_by_traffic_source', $bq );
		$this->assertArrayNotHasKey( 'engagement_by_newsletter_status', $bq );
	}

	/**
	 * When the BQ proxy returns a WP_Error, the traffic-source method returns
	 * a computable=false payload and does NOT run the bucketing helper.
	 */
	public function test_traffic_source_bq_wp_error_returns_not_computable() {
		$proxy = new class() extends BigQuery_Proxy_Client {
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function __construct() {}
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function query( string $query_name, \DateTimeInterface $start, \DateTimeInterface $end ) {
				return new \WP_Error( 'bq_error', 'BQ unavailable' );
			}
		};
		$out = Engagement_Metric::engagement_by_traffic_source_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertFalse( $out['computable'] );
		$this->assertArrayNotHasKey( 'needs_data', $out, 'WP_Error path must not produce a needs_data key' );
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
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function __construct( private string $expected_name, private array $rows ) {}
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function query( string $query_name, \DateTimeInterface $start, \DateTimeInterface $end ) {
				return $query_name === $this->expected_name ? $this->rows : [];
			}
		};
	}

	/**
	 * Tests that bounce_rate_via_bq shapes a first-row 'bounce_rate' column into a rate payload.
	 */
	public function test_bounce_rate_via_bq_shapes_rate() {
		$proxy = $this->makeProxyReturning( 'engagement_bounce_rate', [ [ 'bounce_rate' => 0.62 ] ] );
		$out = Engagement_Metric::bounce_rate_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 0.62, $out['value'] );
		$this->assertSame( 'rate', $out['type'] );
	}

	/**
	 * Tests that avg_pages_per_session_via_bq shapes into a decimal scalar payload.
	 */
	public function test_avg_pages_per_session_via_bq_shapes_decimal() {
		$proxy = $this->makeProxyReturning( 'engagement_avg_pages_per_session', [ [ 'avg_pages_per_session' => 3.5 ] ] );
		$out   = Engagement_Metric::avg_pages_per_session_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 3.5, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'decimal', $out['type'] );
	}

	/**
	 * Tests that avg_engaged_session_duration_via_bq shapes into a duration scalar payload
	 * (matches the GA4 path's 'duration' type so the frontend formats it as a
	 * time, not a raw number — zero-UI-change parity).
	 */
	public function test_avg_engaged_session_duration_via_bq_shapes_duration() {
		$proxy = $this->makeProxyReturning( 'engagement_avg_engaged_session_duration', [ [ 'avg_engaged_session_duration_sec' => 120.5 ] ] );
		$out   = Engagement_Metric::avg_engaged_session_duration_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 120.5, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'duration', $out['type'] );
	}

	/**
	 * Tests that article_completion_rate_via_bq shapes the scroll_to_90_rate column into a rate payload.
	 */
	public function test_article_completion_rate_via_bq_shapes_rate() {
		$proxy = $this->makeProxyReturning( 'engagement_article_completion_rate', [ [ 'scroll_to_90_rate' => 0.45 ] ] );
		$out   = Engagement_Metric::article_completion_rate_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 0.45, $out['value'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'rate', $out['type'] );
	}

	/**
	 * Tests that compute_via_bq wires the 4 quality scalars (no longer not_implemented stub).
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

	/*
	===================================================================
	 * BQ proxy shaper tests (NPPD-1729 Task B5)
	 * ===================================================================
	 */

	/**
	 * Tests that most_read_articles_via_bq passes rows through with type 'table'.
	 */
	public function test_most_read_articles_via_bq_passes_rows() {
		$proxy = $this->makeProxyReturning(
			'engagement_most_read_articles',
			[
				[
					'page_title'             => 'Breaking News',
					'unique_readers'         => 500,
					'avg_engagement_seconds' => 120.0,
				],
			]
		);
		$out = Engagement_Metric::most_read_articles_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'Breaking News', $out['rows'][0]['page_title'] );
	}

	/**
	 * Tests that articles_by_completion_rate_via_bq passes rows through with type 'table'.
	 */
	public function test_articles_by_completion_rate_via_bq_passes_rows() {
		$proxy = $this->makeProxyReturning(
			'engagement_articles_by_completion_rate',
			[
				[
					'page_title'      => 'Top Story',
					'readers'         => 200,
					'completion_rate' => 0.72,
				],
			]
		);
		$out = Engagement_Metric::articles_by_completion_rate_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'Top Story', $out['rows'][0]['page_title'] );
	}

	/**
	 * Tests that top_authors_by_avg_engagement_time_via_bq passes rows through with type 'table'.
	 */
	public function test_top_authors_by_avg_engagement_time_via_bq_passes_rows() {
		$proxy = $this->makeProxyReturning(
			'engagement_top_authors_by_avg_engagement_time',
			[
				[
					'author'                 => 'Jane Doe',
					'unique_readers'         => 300,
					'avg_engagement_seconds' => 95.5,
				],
			]
		);
		$out = Engagement_Metric::top_authors_by_avg_engagement_time_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'Jane Doe', $out['rows'][0]['author'] );
	}

	/**
	 * Tests that engagement_by_device_type_via_bq passes rows through with type 'table'.
	 */
	public function test_engagement_by_device_type_via_bq_passes_rows() {
		$proxy = $this->makeProxyReturning(
			'engagement_by_device_type',
			[
				[
					'device'                 => 'mobile',
					'sessions'               => 1200,
					'avg_pages_per_session'  => 2.5,
					'avg_engagement_seconds' => 65.0,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_device_type_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'mobile', $out['rows'][0]['device'] );
	}

	/**
	 * Tests that engagement_by_returning_vs_new_via_bq passes rows through with type 'table'.
	 */
	public function test_engagement_by_returning_vs_new_via_bq_passes_rows() {
		$proxy = $this->makeProxyReturning(
			'engagement_by_returning_vs_new',
			[
				[
					'reader_type'            => 'returning',
					'sessions'               => 800,
					'avg_pages_per_session'  => 3.2,
					'avg_engagement_seconds' => 88.0,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_returning_vs_new_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertTrue( $out['computable'] );
		$this->assertSame( 'returning', $out['rows'][0]['reader_type'] );
	}

	/**
	 * Tests engagement_by_traffic_source_via_bq: Email/Newsletter channel rows are bucketed
	 * as "newsletter" cohort, all others as "other". avg_engagement_seconds for each
	 * cohort is computed as weighted average (sessions * avg_seconds / total_sessions).
	 * Above the 100-session floor → needs_data is false.
	 */
	public function test_engagement_by_traffic_source_via_bq_buckets_cohorts() {
		$proxy = $this->makeProxyReturning(
			'engagement_by_traffic_source',
			[
				[
					'channel'                => 'Email',
					'sessions'               => 120,
					'avg_pages_per_session'  => 3.0,
					'avg_engagement_seconds' => 90.0,
				],
				[
					'channel'                => 'Organic Search',
					'sessions'               => 200,
					'avg_pages_per_session'  => 2.5,
					'avg_engagement_seconds' => 45.0,
				],
				[
					'channel'                => 'Direct',
					'sessions'               => 100,
					'avg_pages_per_session'  => 2.0,
					'avg_engagement_seconds' => 35.0,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_traffic_source_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );

		$this->assertSame( 'table', $out['type'] );
		$this->assertTrue( $out['computable'] );
		$this->assertFalse( $out['needs_data'], 'Above floor: needs_data must be false' );

		// Build segment → row map.
		$by_segment = [];
		foreach ( $out['rows'] as $row ) {
			$by_segment[ $row['segment'] ] = $row;
		}

		$this->assertArrayHasKey( 'newsletter', $by_segment );
		$this->assertArrayHasKey( 'other', $by_segment );

		// Newsletter: Email row contributes 120 sessions × 90s = 10800 total eng.
		$this->assertSame( 120, $by_segment['newsletter']['sessions'] );
		$this->assertEqualsWithDelta( 90.0, $by_segment['newsletter']['avg_engagement_seconds'], 0.001 );

		// Other: Organic (200 × 45 = 9000) + Direct (100 × 35 = 3500) = 12500 / 300 = 41.667.
		$this->assertSame( 300, $by_segment['other']['sessions'] );
		$this->assertEqualsWithDelta( 41.667, $by_segment['other']['avg_engagement_seconds'], 0.01 );
	}

	/**
	 * Tests engagement_by_traffic_source_via_bq: below-floor newsletter sessions
	 * (< NEWSLETTER_SESSION_FLOOR = 100) → needs_data is true.
	 */
	public function test_engagement_by_traffic_source_via_bq_below_floor_sets_needs_data() {
		$proxy = $this->makeProxyReturning(
			'engagement_by_traffic_source',
			[
				[
					'channel'                => 'Email',
					'sessions'               => 50,
					'avg_pages_per_session'  => 3.0,
					'avg_engagement_seconds' => 80.0,
				],
				[
					'channel'                => 'Organic Search',
					'sessions'               => 500,
					'avg_pages_per_session'  => 2.0,
					'avg_engagement_seconds' => 40.0,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_traffic_source_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );

		$this->assertTrue( $out['needs_data'], 'Below floor (50 < 100): needs_data must be true' );
		// avg still computed even when below floor.
		$by_segment = array_column( $out['rows'], null, 'segment' );
		$this->assertEqualsWithDelta( 80.0, $by_segment['newsletter']['avg_engagement_seconds'], 0.001 );
	}

	/**
	 * Tests engagement_by_traffic_source_via_bq: zero newsletter sessions → needs_data true,
	 * no divide-by-zero.
	 */
	public function test_engagement_by_traffic_source_via_bq_zero_newsletter_needs_data() {
		$proxy = $this->makeProxyReturning(
			'engagement_by_traffic_source',
			[
				[
					'channel'                => 'Organic Search',
					'sessions'               => 300,
					'avg_pages_per_session'  => 2.0,
					'avg_engagement_seconds' => 50.0,
				],
			]
		);
		$out = Engagement_Metric::engagement_by_traffic_source_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );

		$this->assertTrue( $out['needs_data'] );
		$by_segment = array_column( $out['rows'], null, 'segment' );
		$this->assertSame( 0, $by_segment['newsletter']['sessions'] );
		$this->assertSame( 0.0, (float) $by_segment['newsletter']['avg_engagement_seconds'] );
	}

	/**
	 * Tests top_categories_by_engagement_via_bq: enabled metric returns rows with type 'table'.
	 */
	public function test_top_categories_by_engagement_via_bq_enabled() {
		$proxy = $this->makeProxyReturning(
			'engagement_top_categories_by_engagement',
			[
				[
					'category'               => 'Politics',
					'pageviews'              => 500,
					'avg_engagement_seconds' => 75.0,
				],
			]
		);
		$out = Engagement_Metric::top_categories_by_engagement_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertSame( 'Politics', $out['rows'][0]['category'] );
	}

	/**
	 * Tests mobile_vs_desktop_content_preferences_via_bq: enabled metric returns rows with type 'table'.
	 */
	public function test_mobile_vs_desktop_via_bq_enabled() {
		$proxy = $this->makeProxyReturning(
			'engagement_mobile_vs_desktop_content_preferences',
			[
				[
					'category'     => 'News',
					'mobile_share' => 0.7,
					'total_reads'  => 200,
				],
			]
		);
		$out = Engagement_Metric::mobile_vs_desktop_content_preferences_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertSame( 'News', $out['rows'][0]['category'] );
	}

	/**
	 * Tests top_authors_by_repeat_reader_rate_via_bq: enabled metric returns rows with type 'table'.
	 */
	public function test_top_authors_by_repeat_reader_rate_via_bq_enabled() {
		$proxy = $this->makeProxyReturning(
			'engagement_top_authors_by_repeat_reader_rate',
			[
				[
					'author'             => 'Alice Smith',
					'repeat_reader_rate' => 0.65,
					'unique_readers'     => 150,
				],
			]
		);
		$out = Engagement_Metric::top_authors_by_repeat_reader_rate_via_bq( $proxy, new \DateTimeImmutable( '2026-01-01' ), new \DateTimeImmutable( '2026-01-31' ) );
		$this->assertSame( 'table', $out['type'] );
		$this->assertSame( 'Alice Smith', $out['rows'][0]['author'] );
	}

	/**
	 * Verifies article_freshness_vs_engagement is still hidden in v1 in the BQ compute path.
	 */
	public function test_article_freshness_stays_hidden() {
		$payload = $this->invoke( 'compute_via_bq', [ '2026-01-01', '2026-01-31' ] );
		$this->assertTrue( $payload['article_freshness_vs_engagement']['hidden_in_v1'], 'article_freshness_vs_engagement must remain hidden_in_v1' );
	}

	/**
	 * Verifies the three newly-enabled hidden metrics are now wired (not hidden_in_v1) in the BQ path.
	 */
	public function test_previously_hidden_metrics_now_wired_in_bq() {
		$payload = $this->invoke( 'compute_via_bq', [ '2026-01-01', '2026-01-31' ] );
		$newly_enabled = [
			'top_categories_by_engagement',
			'mobile_vs_desktop_content_preferences',
			'top_authors_by_repeat_reader_rate',
		];
		foreach ( $newly_enabled as $key ) {
			$this->assertArrayHasKey( $key, $payload, "$key must be present" );
			$this->assertArrayNotHasKey( 'hidden_in_v1', $payload[ $key ], "$key must NOT be hidden_in_v1 any more" );
		}
	}

	/**
	 * Guard: no GA4 constant, no _via_ga4 methods, no safe_run_report remain in
	 * class-engagement-metric.php after the GA4 path has been deleted.
	 */
	public function test_engagement_no_ga4_remains() {
		$src = file_get_contents( NEWSPACK_ABSPATH . 'includes/wizards/insights/metrics/class-engagement-metric.php' );
		$this->assertStringNotContainsString( 'NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4', $src );
		$this->assertStringNotContainsString( '_via_ga4', $src );
		$this->assertStringNotContainsString( 'safe_run_report', $src );
	}
}
