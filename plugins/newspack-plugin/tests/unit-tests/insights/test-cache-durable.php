<?php
/**
 * Tests for the Insights Cache durable-option store.
 *
 * @package Newspack
 */

use Newspack\Insights\Cache;

/**
 * Tests for Insights Cache durable-option store.
 *
 * @group insights
 */
class Test_Cache_Durable extends WP_UnitTestCase {

	/**
	 * Tab slug used in tests.
	 *
	 * @var string
	 */
	private $tab = 'gates';

	/**
	 * Data source constant used in tests.
	 *
	 * @var string
	 */
	private $source = Cache::SOURCE_BIGQUERY;

	/**
	 * Cache key tuple used in tests.
	 *
	 * @var array
	 */
	private $key = [ '2026-06-01', '2026-06-30', null, null ];

	/**
	 * Window array used in tests.
	 *
	 * @var array
	 */
	private $window = [
		'start' => '2026-06-01',
		'end'   => '2026-06-30',
	];

	/** Cleans up durable cache entries after each test. */
	public function tearDown(): void {
		Cache::prune_durable( $this->tab, [] );
		parent::tearDown();
	}

	/** Stored payload, source, and window are returned verbatim by peek. */
	public function test_store_then_peek_round_trips() {
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'a' => 1 ], $this->window );
		$got = Cache::peek_durable( $this->tab, $this->source, $this->key );
		$this->assertSame( [ 'a' => 1 ], $got['payload'] );
		$this->assertSame( $this->source, $got['source'] );
		$this->assertSame( $this->window, $got['window'] );
		$this->assertNotEmpty( $got['computed_at'] );
	}

	/** Peek returns null when the stored source does not match the requested source. */
	public function test_peek_returns_null_on_source_mismatch() {
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'a' => 1 ], $this->window );
		$this->assertNull( Cache::peek_durable( $this->tab, Cache::SOURCE_EXTERNAL, $this->key ) );
	}

	/** Peek returns null when no entry exists for the given key. */
	public function test_peek_returns_null_when_absent() {
		$this->assertNull( Cache::peek_durable( $this->tab, $this->source, $this->key ) );
	}

	/** Durable cache options are stored with autoload disabled. */
	public function test_durable_option_is_not_autoloaded() {
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'a' => 1 ], $this->window );
		global $wpdb;
		$name     = 'newspack_insights_warm_' . $this->tab . '_' . md5( (string) wp_json_encode( $this->key ) );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertContains( $autoload, [ 'no', 'off' ], 'Durable cache option must not autoload.' );
	}

	/** Is_fresh returns true within the TTL and false outside it. */
	public function test_is_fresh_boundary() {
		$fresh = gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS );
		$stale = gmdate( 'Y-m-d\TH:i:s\Z', time() - 26 * HOUR_IN_SECONDS );
		$this->assertTrue( Cache::is_fresh( $fresh ) );
		$this->assertFalse( Cache::is_fresh( $stale ) );
	}

	/** Prune_durable preserves listed keys and deletes unlisted ones. */
	public function test_prune_keeps_listed_deletes_others() {
		$keep = [ '2026-06-01', '2026-06-30', null, null ];
		$drop = [ '2026-05-01', '2026-05-31', null, null ];
		Cache::store_durable(
			$this->tab,
			$this->source,
			$keep,
			[ 'k' => 1 ],
			[
				'start' => '2026-06-01',
				'end'   => '2026-06-30',
			]
		);
		Cache::store_durable(
			$this->tab,
			$this->source,
			$drop,
			[ 'd' => 1 ],
			[
				'start' => '2026-05-01',
				'end'   => '2026-05-31',
			]
		);

		Cache::prune_durable( $this->tab, [ $keep ] );

		$this->assertNotNull( Cache::peek_durable( $this->tab, $this->source, $keep ) );
		$this->assertNull( Cache::peek_durable( $this->tab, $this->source, $drop ) );
	}

	/**
	 * Durable hit short-circuits compute and transient read.
	 *
	 * @covers Cache::store
	 */
	public function test_store_serves_durable_over_transient_and_compute() {
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'durable' => true ], $this->window );
		$called = false;
		$env    = Cache::store(
			$this->tab,
			$this->source,
			$this->key,
			function () use ( &$called ) {
				$called = true;
				return [ 'computed' => true ];
			}
		);
		$this->assertSame( [ 'durable' => true ], $env['payload'] );
		$this->assertFalse( $called, 'Durable hit must not invoke the compute closure.' );
	}

	/**
	 * Stale durable entry fires the SWR action and is served immediately.
	 *
	 * @covers Cache::store
	 */
	public function test_store_fires_stale_action_for_stale_durable() {
		// Seed a stale durable entry directly (computed_at 26h ago).
		$name = 'newspack_insights_warm_' . $this->tab . '_' . md5( (string) wp_json_encode( $this->key ) );
		update_option(
			$name,
			[
				'payload'     => [ 'stale' => true ],
				'computed_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 26 * HOUR_IN_SECONDS ),
				'source'      => $this->source,
				'window'      => $this->window,
			],
			false
		);

		$fired = [];
		add_action(
			'newspack_insights_durable_stale',
			function ( $tab, $start, $end ) use ( &$fired ) {
				$fired = [ $tab, $start, $end ];
			},
			10,
			3
		);

		$env = Cache::store( $this->tab, $this->source, $this->key, fn() => [ 'computed' => true ] );

		$this->assertSame( [ 'stale' => true ], $env['payload'], 'Stale durable is served immediately.' );
		$this->assertSame( [ $this->tab, '2026-06-01', '2026-06-30' ], $fired, 'Stale action fires with the window.' );
	}

	/**
	 * Fresh durable entry does not trigger the stale-while-revalidate action.
	 *
	 * @covers Cache::store
	 */
	public function test_store_does_not_fire_stale_action_for_fresh_durable() {
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'fresh' => true ], $this->window );
		$fired = false;
		add_action(
			'newspack_insights_durable_stale',
			function () use ( &$fired ) {
				$fired = true;
			},
			10,
			3
		);
		Cache::store( $this->tab, $this->source, $this->key, fn() => [ 'computed' => true ] );
		$this->assertFalse( $fired );
	}

	/**
	 * Manual refresh overwrites an existing durable entry so the next store()
	 * call returns the freshly-computed payload rather than the older pre-warmed
	 * one, and the compute closure is never invoked.
	 *
	 * @covers Cache::refresh
	 */
	public function test_refresh_overwrites_existing_durable_entry() {
		// Seed the durable store with a pre-warmed payload.
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'warm' => true ], $this->window );

		// Manually refresh — must overwrite the durable entry.
		Cache::refresh( $this->tab, $this->source, $this->key, fn() => [ 'fresh' => true ] );

		// Durable entry now holds the refreshed payload with the same window.
		$durable = Cache::peek_durable( $this->tab, $this->source, $this->key );
		$this->assertNotNull( $durable, 'Durable entry should still exist after refresh.' );
		$this->assertSame( [ 'fresh' => true ], $durable['payload'], 'Durable payload should be the refreshed data.' );
		$this->assertSame( $this->window, $durable['window'], 'Window metadata should be preserved.' );

		// A subsequent store() must serve the refreshed durable — compute must not be called.
		$called = false;
		$env    = Cache::store(
			$this->tab,
			$this->source,
			$this->key,
			function () use ( &$called ) {
				$called = true;
				return [ 'compute' => true ];
			}
		);
		$this->assertSame( [ 'fresh' => true ], $env['payload'], 'store() should serve the refreshed durable, not the older warm data.' );
		$this->assertFalse( $called, 'Compute closure must not be invoked when a fresh durable entry exists.' );
	}

	/**
	 * Manual refresh does not create a durable entry when none was pre-warmed
	 * for the requested window, keeping durable storage bounded to preset windows.
	 *
	 * @covers Cache::refresh
	 */
	public function test_refresh_does_not_create_durable_for_unwarmed_window() {
		// No durable entry exists — only a transient will be written by refresh.
		Cache::refresh( $this->tab, $this->source, $this->key, fn() => [ 'fresh' => true ] );

		// Durable store must remain empty for this window.
		$this->assertNull(
			Cache::peek_durable( $this->tab, $this->source, $this->key ),
			'refresh() must not fabricate a durable entry for a never-warmed window.'
		);
	}

	/**
	 * Cache is fully disabled when NEWSPACK_INSIGHTS_CACHE_DISABLED is true.
	 *
	 * Runs in a separate process so the define() does not leak into the parent
	 * and poison other tests (PHP constants cannot be unset).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_skips_write_and_read() {
		if ( ! defined( 'NEWSPACK_INSIGHTS_CACHE_DISABLED' ) ) {
			define( 'NEWSPACK_INSIGHTS_CACHE_DISABLED', true );
		}
		Cache::store_durable( $this->tab, $this->source, $this->key, [ 'a' => 1 ], $this->window );
		$this->assertNull( Cache::peek_durable( $this->tab, $this->source, $this->key ) );
	}
}
