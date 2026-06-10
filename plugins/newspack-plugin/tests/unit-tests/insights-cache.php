<?php
/**
 * Tests for Newspack\Insights\Cache.
 *
 * @package Newspack
 */

use Newspack\Insights\Cache;

class Newspack_Test_Insights_Cache extends WP_UnitTestCase {

	public function tear_down() {
		// Clean transients between tests.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_newspack_insights_%' OR option_name LIKE 'newspack_insights_%'" );
		parent::tear_down();
	}

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

	public function test_is_disabled_returns_false_without_constant(): void {
		$this->assertFalse( Cache::is_disabled() );
	}

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

	public function test_bigquery_source_uses_24h_ttl(): void {
		Cache::store( 'gates', Cache::SOURCE_BIGQUERY, [ '2026-01-01', '2026-01-31', null, null ], function () {
			return [ 'value' => 1 ];
		} );

		$key       = self::_transient_key_for( 'gates', [ '2026-01-01', '2026-01-31', null, null ] );
		$timeout   = get_option( '_transient_timeout_' . $key );
		$remaining = $timeout - time();

		$this->assertGreaterThan( 23 * HOUR_IN_SECONDS, $remaining, 'BQ TTL should be ~24h.' );
		$this->assertLessThanOrEqual( DAY_IN_SECONDS, $remaining );
	}

	public function test_external_source_uses_10m_ttl(): void {
		Cache::store( 'audience', Cache::SOURCE_EXTERNAL, [ '2026-01-01', '2026-01-31', null, null ], function () {
			return [ 'value' => 1 ];
		} );

		$key       = self::_transient_key_for( 'audience', [ '2026-01-01', '2026-01-31', null, null ] );
		$timeout   = get_option( '_transient_timeout_' . $key );
		$remaining = $timeout - time();

		$this->assertGreaterThan( 9 * MINUTE_IN_SECONDS, $remaining, 'External TTL should be ~10m.' );
		$this->assertLessThanOrEqual( 10 * MINUTE_IN_SECONDS, $remaining );
	}

	/**
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
		$key = self::_transient_key_for( 'audience', $key_parts );
		$this->assertFalse( get_transient( $key ), 'Disabled mode must not write transients.' );
	}

	/**
	 * Mirror the production transient-key formula so tests can reach into storage.
	 */
	private static function _transient_key_for( string $tab, array $key_parts ): string {
		return 'newspack_insights_' . $tab . '_' . md5( wp_json_encode( $key_parts ) );
	}
}
