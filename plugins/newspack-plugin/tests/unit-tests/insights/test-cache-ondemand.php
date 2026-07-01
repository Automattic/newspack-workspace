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
}
