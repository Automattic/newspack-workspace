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
}
