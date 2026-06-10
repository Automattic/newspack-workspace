<?php
/**
 * Tests for the Insights Audience metric orchestrator (Tab 1, NPPD-1648).
 *
 * Covers the deterministic surface without a live GA4 connection: the
 * tab-level OAuth short-circuit, the BQ stub path, hidden-in-v1 metrics, and
 * the GA4 response → payload transform helpers (success, overlay and error
 * propagation). Full GA4 round-trips are covered by manual verification.
 *
 * @package Newspack\Tests
 */

use Newspack\Insights\Audience_Metric;

/**
 * Test \Newspack\Insights\Audience_Metric.
 */
class Newspack_Test_Insights_Audience_Metric extends WP_UnitTestCase {

	/**
	 * Invoke a private static method via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke( $method, array $args = [] ) {
		$ref = new ReflectionMethod( Audience_Metric::class, $method );
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
	 * With the GA4 path active (default) and no Google connection in the test
	 * environment, get_all() short-circuits to a tab-level connection error.
	 */
	public function test_get_all_returns_tab_error_when_oauth_missing() {
		$payload = Audience_Metric::get_all( '2026-05-09', '2026-06-08', false );
		$this->assertArrayHasKey( 'tab_error', $payload );
		$this->assertSame( 'oauth_not_connected', $payload['tab_error'] );
		$this->assertArrayHasKey( 'banner_text', $payload );
	}

	/**
	 * The BQ stub path returns a not_implemented error for every standard
	 * metric, keyed identically to the GA4 path.
	 */
	public function test_bq_stub_returns_not_implemented() {
		$payload = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		$this->assertArrayHasKey( 'active_readers', $payload );
		$this->assertFalse( $payload['active_readers']['computable'] );
		$this->assertArrayHasKey( 'error', $payload['active_readers'] );
		$this->assertStringContainsString( 'NPPD-1630', $payload['active_readers']['error'] );
	}

	/**
	 * The strict returning-reader metric is BQ-only: hidden in v1 under both
	 * the GA4 and BQ paths.
	 */
	public function test_returning_reader_rate_strict_hidden_in_both_paths() {
		$bq  = $this->invoke( 'compute_via_bq', [ '2026-05-09', '2026-06-08' ] );
		$ga4 = $this->invoke( 'compute_via_ga4', [ '2026-05-09', '2026-06-08' ] );
		$this->assertTrue( $bq['returning_reader_rate_strict']['hidden_in_v1'] );
		$this->assertTrue( $ga4['returning_reader_rate_strict']['hidden_in_v1'] );
	}

	/**
	 * The scalar() helper transforms a GA4 single-value response into a count payload.
	 */
	public function test_scalar_transform_success() {
		$raw     = [ 'raw' => [ 'rows' => [ [ 'metricValues' => [ [ 'value' => '1234' ] ] ] ] ] ];
		$payload = $this->invoke( 'scalar', [ $raw, 'count' ] );
		$this->assertSame( 1234, $payload['value'] );
		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'count', $payload['type'] );
	}

	/**
	 * A custom_dimension_missing overlay propagates unchanged through transforms.
	 */
	public function test_overlay_propagates_through_transform() {
		$overlay = [
			'value'      => null,
			'computable' => false,
			'overlay'    => [
				'type'       => 'custom_dimension_missing',
				'dimensions' => [ 'is_newsletter_subscriber' ],
			],
		];
		$payload = $this->invoke( 'yes_composition', [ $overlay, 'Subscribed', 'Not subscribed' ] );
		$this->assertSame( 'custom_dimension_missing', $payload['overlay']['type'] );
		$this->assertSame( [ 'is_newsletter_subscriber' ], $payload['overlay']['dimensions'] );
		$this->assertFalse( $payload['computable'] );
	}

	/**
	 * A generic error payload propagates unchanged through transforms.
	 */
	public function test_error_propagates_through_transform() {
		$error   = [
			'value'      => null,
			'computable' => false,
			'error'      => 'HTTP 500',
		];
		$payload = $this->invoke( 'scalar', [ $error, 'count' ] );
		$this->assertSame( 'HTTP 500', $payload['error'] );
		$this->assertFalse( $payload['computable'] );
	}

	/**
	 * The yes_composition() helper splits a yes/no dimension into two pie slices.
	 */
	public function test_yes_composition_splits_into_slices() {
		$raw     = [
			'raw' => [
				'rows' => [
					[
						'dimensionValues' => [ [ 'value' => 'yes' ] ],
						'metricValues'    => [ [ 'value' => '30' ] ],
					],
					[
						'dimensionValues' => [ [ 'value' => 'no' ] ],
						'metricValues'    => [ [ 'value' => '70' ] ],
					],
				],
			],
		];
		$payload = $this->invoke( 'yes_composition', [ $raw, 'Subscribed', 'Not subscribed' ] );
		$this->assertSame( 'breakdown', $payload['type'] );
		$this->assertTrue( $payload['computable'] );
		$this->assertSame( 'Subscribed', $payload['rows'][0]['label'] );
		$this->assertSame( 30, $payload['rows'][0]['value'] );
		$this->assertSame( 'Not subscribed', $payload['rows'][1]['label'] );
		$this->assertSame( 70, $payload['rows'][1]['value'] );
	}
}
