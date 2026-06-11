<?php
/**
 * Tests for Newspack\Insights\Cache.
 *
 * @package Newspack
 */

use Newspack\Insights\Cache;

/**
 * Tests for Newspack\Insights\Cache.
 */
class Newspack_Test_Insights_Cache extends WP_UnitTestCase {

	/**
	 * Clean transients between tests.
	 */
	public function tear_down() {
		// Clean transients between tests.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_newspack_insights_%'
				OR option_name LIKE '_transient_timeout_newspack_insights_%'
				OR option_name LIKE 'newspack_insights_index_%'
				OR option_name LIKE 'newspack_insights_bq_last_manual_refresh_%'"
		);
		parent::tear_down();
	}

	/**
	 * Test local source skips transient and recomputes each call.
	 */
	public function test_local_source_skips_transient_and_recomputes_each_call(): void {
		$calls = 0;
		$compute = function () use ( &$calls ) {
			$calls++;
			return [ 'value' => $calls ];
		};
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		$first  = Cache::store( 'engagement', Cache::SOURCE_LOCAL, $key_parts, $compute );
		$second = Cache::store( 'engagement', Cache::SOURCE_LOCAL, $key_parts, $compute );

		$this->assertSame( 2, $calls, 'Local source must call compute on every invocation.' );
		$this->assertSame( [ 'value' => 1 ], $first['payload'] );
		$this->assertSame( [ 'value' => 2 ], $second['payload'] );
		$this->assertSame( Cache::SOURCE_LOCAL, $first['source'] );
		$this->assertNull( $first['cooldown_until'] );
		$this->assertNotEmpty( $first['computed_at'] );
	}

	/**
	 * Test is_disabled returns false without constant.
	 */
	public function test_is_disabled_returns_false_without_constant(): void {
		$this->assertFalse( Cache::is_disabled() );
	}

	/**
	 * Test external source caches payload and reuses on second call.
	 */
	public function test_external_source_caches_payload_and_reuses_on_second_call(): void {
		$calls = 0;
		$compute = function () use ( &$calls ) {
			$calls++;
			return [ 'value' => $calls ];
		};
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		$first  = Cache::store( 'audience', Cache::SOURCE_EXTERNAL, $key_parts, $compute );
		$second = Cache::store( 'audience', Cache::SOURCE_EXTERNAL, $key_parts, $compute );

		$this->assertSame( 1, $calls, 'Compute must only run once for cached payloads.' );
		$this->assertSame( [ 'value' => 1 ], $second['payload'] );
		$this->assertSame( $first['computed_at'], $second['computed_at'] );
		$this->assertSame( Cache::SOURCE_EXTERNAL, $second['source'] );
	}

	/**
	 * Test different key parts produce distinct cache entries.
	 */
	public function test_different_key_parts_produce_distinct_cache_entries(): void {
		$calls = 0;
		$compute = function () use ( &$calls ) {
			$calls++;
			return [ 'value' => $calls ];
		};

		Cache::store( 'audience', Cache::SOURCE_EXTERNAL, [ '2026-01-01', '2026-01-31', null, null ], $compute );
		Cache::store( 'audience', Cache::SOURCE_EXTERNAL, [ '2026-02-01', '2026-02-28', null, null ], $compute );

		$this->assertSame( 2, $calls, 'Distinct windows must produce distinct cache keys.' );
	}

	/**
	 * Test BigQuery source uses 24h TTL.
	 */
	public function test_bigquery_source_uses_24h_ttl(): void {
		Cache::store(
			'gates',
			Cache::SOURCE_BIGQUERY,
			[ '2026-01-01', '2026-01-31', null, null ],
			function () {
				return [ 'value' => 1 ];
			}
		);

		$key       = self::transient_key_for_test( 'gates', [ '2026-01-01', '2026-01-31', null, null ] );
		$timeout   = get_option( '_transient_timeout_' . $key );
		$remaining = $timeout - time();

		$this->assertGreaterThan( 23 * HOUR_IN_SECONDS, $remaining, 'BQ TTL should be ~24h.' );
		$this->assertLessThanOrEqual( DAY_IN_SECONDS, $remaining );
	}

	/**
	 * Test external source uses 10m TTL.
	 */
	public function test_external_source_uses_10m_ttl(): void {
		Cache::store(
			'audience',
			Cache::SOURCE_EXTERNAL,
			[ '2026-01-01', '2026-01-31', null, null ],
			function () {
				return [ 'value' => 1 ];
			}
		);

		$key       = self::transient_key_for_test( 'audience', [ '2026-01-01', '2026-01-31', null, null ] );
		$timeout   = get_option( '_transient_timeout_' . $key );
		$remaining = $timeout - time();

		$this->assertGreaterThan( 9 * MINUTE_IN_SECONDS, $remaining, 'External TTL should be ~10m.' );
		$this->assertLessThanOrEqual( 10 * MINUTE_IN_SECONDS, $remaining );
	}

	/**
	 * Test disabled constant bypasses transient IO.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_constant_bypasses_transient_io(): void {
		if ( ! defined( 'NEWSPACK_INSIGHTS_CACHE_DISABLED' ) ) {
			define( 'NEWSPACK_INSIGHTS_CACHE_DISABLED', true );
		}

		$calls = 0;
		$compute = function () use ( &$calls ) {
			$calls++;
			return [ 'value' => $calls ];
		};
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		Cache::store( 'audience', Cache::SOURCE_EXTERNAL, $key_parts, $compute );
		Cache::store( 'audience', Cache::SOURCE_EXTERNAL, $key_parts, $compute );

		$this->assertSame( 2, $calls, 'Disabled mode must recompute every call.' );
		$key = self::transient_key_for_test( 'audience', $key_parts );
		$this->assertFalse( get_transient( $key ), 'Disabled mode must not write transients.' );
	}

	/**
	 * Refreshing recomputes and replaces the cached payload.
	 */
	public function test_refresh_deletes_transient_and_recomputes(): void {
		$calls = 0;
		$compute = function () use ( &$calls ) {
			$calls++;
			return [ 'value' => $calls ];
		};
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		Cache::store( 'audience', Cache::SOURCE_EXTERNAL, $key_parts, $compute );
		$refreshed = Cache::refresh( 'audience', Cache::SOURCE_EXTERNAL, $key_parts, $compute );

		$this->assertSame( 2, $calls );
		$this->assertSame( [ 'value' => 2 ], $refreshed['payload'] );
	}

	/**
	 * A successful BQ refresh writes the cooldown timestamp.
	 */
	public function test_refresh_for_bigquery_writes_cooldown_marker(): void {
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		Cache::refresh(
			'gates',
			Cache::SOURCE_BIGQUERY,
			$key_parts,
			function () {
				return [ 'value' => 1 ];
			}
		);

		$until = Cache::bq_cooldown_until( 'gates' );
		$this->assertIsString( $until );
		$this->assertGreaterThan( time(), (int) ( new DateTimeImmutable( $until ) )->format( 'U' ) );
	}

	/**
	 * Cooldown rejection returns the cached envelope (no WP_Error) with
	 * cooldown_until populated, so the response transport stays 2xx.
	 */
	public function test_refresh_during_bq_cooldown_returns_cached_envelope_with_cooldown_until(): void {
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		$first = Cache::refresh(
			'gates',
			Cache::SOURCE_BIGQUERY,
			$key_parts,
			function () {
				return [ 'value' => 1 ];
			}
		);

		$second = Cache::refresh(
			'gates',
			Cache::SOURCE_BIGQUERY,
			$key_parts,
			function () {
				return [ 'value' => 'should-not-run' ];
			}
		);

		$this->assertIsArray( $second );
		$this->assertSame( [ 'value' => 1 ], $second['payload'], 'Cooldown response replays the cached payload.' );
		$this->assertSame( $first['computed_at'], $second['computed_at'], 'Cooldown response preserves computed_at.' );
		$this->assertSame( Cache::SOURCE_BIGQUERY, $second['source'] );
		$this->assertNotEmpty( $second['cooldown_until'] );
	}

	/**
	 * When BQ is in cooldown and the requested window has no cached envelope,
	 * refresh() returns null payload + cooldown_until so the React client can
	 * preserve any prior slot data and render the throttle UI.
	 */
	public function test_refresh_during_cooldown_with_no_prior_cache_returns_null_payload(): void {
		// Trigger the cooldown via window A.
		Cache::refresh(
			'gates',
			Cache::SOURCE_BIGQUERY,
			[ '2026-01-01', '2026-01-31', null, null ],
			function () {
				return [ 'value' => 1 ];
			}
		);

		// Refresh on window B (no cached envelope) during the cooldown.
		$envelope = Cache::refresh(
			'gates',
			Cache::SOURCE_BIGQUERY,
			[ '2026-02-01', '2026-02-28', null, null ],
			function () {
				return [ 'value' => 'should-not-run' ];
			}
		);

		$this->assertNull( $envelope['payload'] );
		$this->assertNotEmpty( $envelope['cooldown_until'] );
		$this->assertSame( Cache::SOURCE_BIGQUERY, $envelope['source'] );
	}

	/**
	 * External-source refresh has no cooldown.
	 */
	public function test_refresh_for_external_has_no_cooldown(): void {
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		Cache::refresh(
			'audience',
			Cache::SOURCE_EXTERNAL,
			$key_parts,
			function () {
				return [ 'value' => 1 ];
			}
		);
		$second = Cache::refresh(
			'audience',
			Cache::SOURCE_EXTERNAL,
			$key_parts,
			function () {
				return [ 'value' => 2 ];
			}
		);

		$this->assertSame( [ 'value' => 2 ], $second['payload'] );
		$this->assertNull( $second['cooldown_until'] );
		$this->assertNull( Cache::bq_cooldown_until( 'audience' ) );
	}

	/**
	 * Purge() clears every cached window for a tab and resets cooldown.
	 */
	public function test_purge_clears_every_window_for_a_tab(): void {
		Cache::store(
			'audience',
			Cache::SOURCE_EXTERNAL,
			[ '2026-01-01', '2026-01-31', null, null ],
			function () {
				return [ 'a' => 1 ];
			}
		);
		Cache::store(
			'audience',
			Cache::SOURCE_EXTERNAL,
			[ '2026-02-01', '2026-02-28', null, null ],
			function () {
				return [ 'b' => 1 ];
			}
		);

		Cache::purge( 'audience' );

		$key_a = 'newspack_insights_audience_' . md5( (string) wp_json_encode( [ '2026-01-01', '2026-01-31', null, null ] ) );
		$key_b = 'newspack_insights_audience_' . md5( (string) wp_json_encode( [ '2026-02-01', '2026-02-28', null, null ] ) );
		$this->assertFalse( get_transient( $key_a ) );
		$this->assertFalse( get_transient( $key_b ) );
		$this->assertSame( [], get_option( 'newspack_insights_index_audience', [] ) );
	}

	/**
	 * Disabled-mode refresh skips the cooldown gate.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_refresh_skips_cooldown(): void {
		if ( ! defined( 'NEWSPACK_INSIGHTS_CACHE_DISABLED' ) ) {
			define( 'NEWSPACK_INSIGHTS_CACHE_DISABLED', true );
		}
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		$first  = Cache::refresh(
			'gates',
			Cache::SOURCE_BIGQUERY,
			$key_parts,
			function () {
				return [ 'value' => 1 ];
			}
		);
		$second = Cache::refresh(
			'gates',
			Cache::SOURCE_BIGQUERY,
			$key_parts,
			function () {
				return [ 'value' => 2 ];
			}
		);

		$this->assertSame( [ 'value' => 1 ], $first['payload'] );
		$this->assertSame( [ 'value' => 2 ], $second['payload'] );
		$this->assertNull( Cache::bq_cooldown_until( 'gates' ) );
	}

	/**
	 * After a successful BQ refresh, the envelope's cooldown_until is
	 * populated so the React layer can render the throttle UI immediately
	 * (not only on a second click).
	 */
	public function test_successful_bq_refresh_reports_cooldown_until(): void {
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		$envelope = Cache::refresh(
			'gates',
			Cache::SOURCE_BIGQUERY,
			$key_parts,
			function () {
				return [ 'value' => 1 ];
			}
		);

		$this->assertSame( [ 'value' => 1 ], $envelope['payload'] );
		$this->assertNotEmpty( $envelope['cooldown_until'] );
		$this->assertGreaterThan(
			time(),
			( new DateTimeImmutable( $envelope['cooldown_until'] ) )->format( 'U' )
		);
	}

	/**
	 * A BQ GET response that hits the transient cache still reports the
	 * active cooldown so a page reload during the throttle window restores
	 * the notice instead of clearing it.
	 */
	public function test_store_for_bigquery_reports_active_cooldown(): void {
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		// Seed the cache + cooldown via a successful refresh.
		Cache::refresh(
			'gates',
			Cache::SOURCE_BIGQUERY,
			$key_parts,
			function () {
				return [ 'value' => 1 ];
			}
		);

		// Subsequent GET hits the cache and must still surface the cooldown.
		$envelope = Cache::store(
			'gates',
			Cache::SOURCE_BIGQUERY,
			$key_parts,
			function () {
				return [ 'value' => 'should-not-run' ];
			}
		);

		$this->assertSame( [ 'value' => 1 ], $envelope['payload'] );
		$this->assertNotEmpty( $envelope['cooldown_until'] );
	}

	/**
	 * External-source store never reports a cooldown (cooldowns are
	 * BigQuery-only).
	 */
	public function test_store_for_external_reports_null_cooldown(): void {
		$key_parts = [ '2026-01-01', '2026-01-31', null, null ];

		$envelope = Cache::store(
			'audience',
			Cache::SOURCE_EXTERNAL,
			$key_parts,
			function () {
				return [ 'value' => 1 ];
			}
		);

		$this->assertNull( $envelope['cooldown_until'] );
	}

	/**
	 * Mirror the production transient-key formula so tests can reach into storage.
	 *
	 * @param string $tab Tab slug.
	 * @param array  $key_parts Canonicalized window components.
	 * @return string Transient key.
	 */
	private static function transient_key_for_test( string $tab, array $key_parts ): string {
		return 'newspack_insights_' . $tab . '_' . md5( wp_json_encode( $key_parts ) );
	}
}
