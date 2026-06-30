<?php
/**
 * Tests for the Insights Cache durable-option store.
 *
 * @package Newspack
 */

use Newspack\Insights\Cache;

/**
 * @group insights
 */
class Test_Cache_Durable extends WP_UnitTestCase {

	private $tab    = 'gates';
	private $source = Cache::SOURCE_BIGQUERY;
	private $key    = [ '2026-06-01', '2026-06-30', null, null ];
	private $window = [ 'start' => '2026-06-01', 'end' => '2026-06-30' ];

	public function tearDown(): void {
		Cache::prune_durable( $this->tab, [] );
		parent::tearDown();
	}

	public function test_store_then_peek_round_trips() {
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'a' => 1 ], $this->window );
		$got = Cache::peek_durable( $this->tab, $this->source, $this->key );
		$this->assertSame( [ 'a' => 1 ], $got['payload'] );
		$this->assertSame( $this->source, $got['source'] );
		$this->assertSame( $this->window, $got['window'] );
		$this->assertNotEmpty( $got['computed_at'] );
	}

	public function test_peek_returns_null_on_source_mismatch() {
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'a' => 1 ], $this->window );
		$this->assertNull( Cache::peek_durable( $this->tab, Cache::SOURCE_EXTERNAL, $this->key ) );
	}

	public function test_peek_returns_null_when_absent() {
		$this->assertNull( Cache::peek_durable( $this->tab, $this->source, $this->key ) );
	}

	public function test_durable_option_is_not_autoloaded() {
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'a' => 1 ], $this->window );
		global $wpdb;
		$name     = 'newspack_insights_warm_' . $this->tab . '_' . md5( (string) wp_json_encode( $this->key ) );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$this->assertContains( $autoload, [ 'no', 'off' ], 'Durable cache option must not autoload.' );
	}

	public function test_is_fresh_boundary() {
		$fresh = gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS );
		$stale = gmdate( 'Y-m-d\TH:i:s\Z', time() - 26 * HOUR_IN_SECONDS );
		$this->assertTrue( Cache::is_fresh( $fresh ) );
		$this->assertFalse( Cache::is_fresh( $stale ) );
	}

	public function test_prune_keeps_listed_deletes_others() {
		$keep = [ '2026-06-01', '2026-06-30', null, null ];
		$drop = [ '2026-05-01', '2026-05-31', null, null ];
		Cache::store_durable( $this->tab, $this->source, $keep, [ 'k' => 1 ], [ 'start' => '2026-06-01', 'end' => '2026-06-30' ] );
		Cache::store_durable( $this->tab, $this->source, $drop, [ 'd' => 1 ], [ 'start' => '2026-05-01', 'end' => '2026-05-31' ] );

		Cache::prune_durable( $this->tab, [ $keep ] );

		$this->assertNotNull( Cache::peek_durable( $this->tab, $this->source, $keep ) );
		$this->assertNull( Cache::peek_durable( $this->tab, $this->source, $drop ) );
	}

	public function test_disabled_skips_write_and_read() {
		if ( ! defined( 'NEWSPACK_INSIGHTS_CACHE_DISABLED' ) ) {
			define( 'NEWSPACK_INSIGHTS_CACHE_DISABLED', true );
		}
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'a' => 1 ], $this->window );
		$this->assertNull( Cache::peek_durable( $this->tab, $this->source, $this->key ) );
	}
}
