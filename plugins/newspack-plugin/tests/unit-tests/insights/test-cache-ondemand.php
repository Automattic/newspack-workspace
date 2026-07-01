<?php
/**
 * Tests for the Insights Cache on-demand durable pool.
 *
 * @package Newspack
 */

use Newspack\Insights\Cache;

/**
 * @group insights
 */
class Test_Cache_Ondemand extends WP_UnitTestCase {

	private $tab    = 'engagement';
	private $source = Cache::SOURCE_EXTERNAL;

	public function tearDown(): void {
		Cache::purge_ondemand( $this->tab );
		parent::tearDown();
	}

	private function key( string $start, string $end ): array {
		return [ 'v1', $start, $end, null, null ];
	}

	private function window( string $start, string $end ): array {
		return [ 'start' => $start, 'end' => $end ];
	}

	public function test_store_then_peek_round_trips() {
		$k = $this->key( '2026-06-01', '2026-06-10' );
		Cache::store_ondemand( $this->tab, $this->source, $k, [ 'a' => 1 ], $this->window( '2026-06-01', '2026-06-10' ) );
		$got = Cache::peek_ondemand( $this->tab, $this->source, $k );
		$this->assertSame( [ 'a' => 1 ], $got['payload'] );
		$this->assertSame( $this->source, $got['source'] );
		$this->assertSame( $this->window( '2026-06-01', '2026-06-10' ), $got['window'] );
	}

	public function test_peek_returns_null_on_source_mismatch() {
		$k = $this->key( '2026-06-01', '2026-06-10' );
		Cache::store_ondemand( $this->tab, $this->source, $k, [ 'a' => 1 ], $this->window( '2026-06-01', '2026-06-10' ) );
		$this->assertNull( Cache::peek_ondemand( $this->tab, Cache::SOURCE_BIGQUERY, $k ) );
	}

	public function test_peek_returns_null_when_absent() {
		$this->assertNull( Cache::peek_ondemand( $this->tab, $this->source, $this->key( '2000-01-01', '2000-01-02' ) ) );
	}

	public function test_fifo_eviction_beyond_cap() {
		for ( $i = 1; $i <= Cache::ONDEMAND_MAX_ENTRIES + 3; $i++ ) {
			$d = sprintf( '2026-06-%02d', $i );
			Cache::store_ondemand( $this->tab, $this->source, $this->key( $d, $d ), [ 'i' => $i ], $this->window( $d, $d ) );
		}
		// The three oldest (i = 1,2,3) are evicted; the cap-most-recent remain.
		$this->assertNull( Cache::peek_ondemand( $this->tab, $this->source, $this->key( '2026-06-01', '2026-06-01' ) ) );
		$this->assertNull( Cache::peek_ondemand( $this->tab, $this->source, $this->key( '2026-06-03', '2026-06-03' ) ) );
		$this->assertNotNull( Cache::peek_ondemand( $this->tab, $this->source, $this->key( '2026-06-04', '2026-06-04' ) ) );
		$newest = sprintf( '2026-06-%02d', Cache::ONDEMAND_MAX_ENTRIES + 3 );
		$this->assertNotNull( Cache::peek_ondemand( $this->tab, $this->source, $this->key( $newest, $newest ) ) );
	}

	public function test_rewrite_moves_entry_to_newest() {
		// Fill to cap.
		for ( $i = 1; $i <= Cache::ONDEMAND_MAX_ENTRIES; $i++ ) {
			$d = sprintf( '2026-06-%02d', $i );
			Cache::store_ondemand( $this->tab, $this->source, $this->key( $d, $d ), [ 'i' => $i ], $this->window( $d, $d ) );
		}
		// Touch the oldest (i = 1) so it becomes newest.
		Cache::store_ondemand( $this->tab, $this->source, $this->key( '2026-06-01', '2026-06-01' ), [ 'i' => 1 ], $this->window( '2026-06-01', '2026-06-01' ) );
		// Add one more: the now-oldest is i = 2, which should be evicted; i = 1 survives.
		Cache::store_ondemand( $this->tab, $this->source, $this->key( '2026-07-01', '2026-07-01' ), [ 'i' => 99 ], $this->window( '2026-07-01', '2026-07-01' ) );
		$this->assertNotNull( Cache::peek_ondemand( $this->tab, $this->source, $this->key( '2026-06-01', '2026-06-01' ) ) );
		$this->assertNull( Cache::peek_ondemand( $this->tab, $this->source, $this->key( '2026-06-02', '2026-06-02' ) ) );
	}

	public function test_store_writes_through_to_ondemand_on_windowed_compute_miss() {
		$k   = $this->key( '2026-05-01', '2026-05-10' );
		$win = $this->window( '2026-05-01', '2026-05-10' );
		$calls = 0;
		$compute = function () use ( &$calls ) {
			$calls++;
			return [ 'n' => 42 ];
		};
		$first  = Cache::store( $this->tab, $this->source, $k, $compute, $win );
		$this->assertSame( [ 'n' => 42 ], $first['payload'] );
		$this->assertSame( 1, $calls );
		// The window is now durable; a second store serves it without recomputing.
		$second = Cache::store( $this->tab, $this->source, $k, $compute, $win );
		$this->assertSame( [ 'n' => 42 ], $second['payload'] );
		$this->assertSame( 1, $calls, 'On-demand hit must not recompute.' );
		$this->assertNotNull( Cache::peek_ondemand( $this->tab, $this->source, $k ) );
	}

	public function test_store_does_not_write_ondemand_without_window() {
		$k = $this->key( '2026-05-11', '2026-05-20' );
		Cache::store( $this->tab, $this->source, $k, fn() => [ 'n' => 1 ] ); // no $window
		$this->assertNull( Cache::peek_ondemand( $this->tab, $this->source, $k ) );
	}

	public function test_store_does_not_write_ondemand_for_local_source() {
		$k   = $this->key( '2026-05-21', '2026-05-30' );
		$win = $this->window( '2026-05-21', '2026-05-30' );
		Cache::store( $this->tab, Cache::SOURCE_LOCAL, $k, fn() => [ 'n' => 1 ], $win );
		$this->assertNull( Cache::peek_ondemand( $this->tab, Cache::SOURCE_LOCAL, $k ) );
	}

	public function test_preset_durable_takes_precedence_over_ondemand() {
		$k   = $this->key( '2026-04-01', '2026-04-10' );
		$win = $this->window( '2026-04-01', '2026-04-10' );
		Cache::store_ondemand( $this->tab, $this->source, $k, [ 'from' => 'ondemand' ], $win );
		Cache::store_durable( $this->tab, $this->source, $k, [ 'from' => 'preset' ], $win );
		$got = Cache::store( $this->tab, $this->source, $k, fn() => [ 'from' => 'compute' ], $win );
		$this->assertSame( [ 'from' => 'preset' ], $got['payload'] );
		// Clean up the preset entry written here (ondemand handled by tearDown).
		Cache::prune_durable( $this->tab, [] );
	}

	public function test_refresh_syncs_existing_ondemand_entry() {
		$k   = $this->key( '2026-02-01', '2026-02-10' );
		$win = $this->window( '2026-02-01', '2026-02-10' );
		Cache::store_ondemand( $this->tab, $this->source, $k, [ 'n' => 1 ], $win );
		Cache::refresh( $this->tab, $this->source, $k, fn() => [ 'n' => 2 ], $win );
		$got = Cache::peek_ondemand( $this->tab, $this->source, $k );
		$this->assertSame( [ 'n' => 2 ], $got['payload'], 'Refresh must overwrite the on-demand entry.' );
	}

	public function test_refresh_write_through_creates_ondemand_entry() {
		$k   = $this->key( '2026-01-01', '2026-01-10' );
		$win = $this->window( '2026-01-01', '2026-01-10' );
		Cache::refresh( $this->tab, $this->source, $k, fn() => [ 'n' => 7 ], $win );
		$got = Cache::peek_ondemand( $this->tab, $this->source, $k );
		$this->assertNotNull( $got );
		$this->assertSame( [ 'n' => 7 ], $got['payload'] );
	}

	public function test_refresh_no_write_through_for_snapshot_source() {
		$k   = $this->key( '2026-05-01', '2026-05-10' );
		$win = $this->window( '2026-05-01', '2026-05-10' );
		Cache::refresh( $this->tab, Cache::SOURCE_SNAPSHOT, $k, fn() => [ 'n' => 9 ], $win );
		$this->assertNull( Cache::peek_ondemand( $this->tab, Cache::SOURCE_SNAPSHOT, $k ) );
	}

	public function test_refresh_no_write_through_when_durable_exists() {
		$k   = $this->key( '2026-06-01', '2026-06-10' );
		$win = $this->window( '2026-06-01', '2026-06-10' );
		Cache::store_durable( $this->tab, $this->source, $k, [ 'preset' => true ], $win );
		Cache::refresh( $this->tab, $this->source, $k, fn() => [ 'n' => 3 ], $win );
		$this->assertNull( Cache::peek_ondemand( $this->tab, $this->source, $k ) );
		Cache::prune_durable( $this->tab, [] );
	}

	public function test_stale_ondemand_is_recomputed_inline() {
		$k   = $this->key( '2026-03-01', '2026-03-10' );
		$win = $this->window( '2026-03-01', '2026-03-10' );
		// Seed a stale on-demand entry by hand (computed_at well beyond the freshness bound).
		$stale_ts = gmdate( 'Y-m-d\TH:i:s\Z', time() - ( Cache::TTL_DURABLE_FRESH + HOUR_IN_SECONDS ) );
		update_option(
			'newspack_insights_ondemand_' . $this->tab . '_' . md5( (string) wp_json_encode( $k ) ),
			[ 'payload' => [ 'n' => 1 ], 'computed_at' => $stale_ts, 'source' => $this->source, 'window' => $win ],
			false
		);
		$got = Cache::store( $this->tab, $this->source, $k, fn() => [ 'n' => 2 ], $win );
		$this->assertSame( [ 'n' => 2 ], $got['payload'], 'Stale on-demand entry must be recomputed inline.' );
		$refreshed = Cache::peek_ondemand( $this->tab, $this->source, $k );
		$this->assertSame( [ 'n' => 2 ], $refreshed['payload'] );
		$this->assertTrue( Cache::is_fresh( $refreshed['computed_at'] ) );
	}
}
