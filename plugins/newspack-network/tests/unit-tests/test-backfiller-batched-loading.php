<?php
/**
 * Class TestBackfillerBatchedLoading
 *
 * @package Newspack_Network
 */

namespace Newspack_Network\Tests;

use Newspack_Network\Backfillers\Abstract_Backfiller;
use Newspack_Network\Incoming_Events\Reader_Registered;
use WP_CLI;

/**
 * How backfillers load items when a site has more of them than fit in memory at once.
 */
class TestBackfillerBatchedLoading extends \WP_UnitTestCase {

	/**
	 * IDs passed to the loader, in the order it was called.
	 *
	 * @var int[]
	 */
	private $loaded = [];

	/**
	 * For each ID, whether the cached sentinel survived from the previous load.
	 *
	 * @var bool[]
	 */
	private $sentinel_survived = [];

	/**
	 * Clear captured CLI output and loads.
	 */
	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		$this->loaded            = [];
		$this->sentinel_survived = [];
	}

	/**
	 * A backfiller that exposes load_in_batches() and builds a reader event per loaded item.
	 *
	 * @param bool|null $can_flush_runtime Whether the object cache can flush its in-memory copy; null keeps the real check.
	 * @return Abstract_Backfiller
	 */
	private function backfiller( $can_flush_runtime = null ) {
		return new class( $can_flush_runtime, $this ) extends Abstract_Backfiller {

			const BATCH_SIZE = 2;

			/**
			 * Constructor: a dry, verbose run.
			 *
			 * @param bool|null                    $can_flush_runtime Forced cache capability, or null for the real one.
			 * @param TestBackfillerBatchedLoading $test              The test, which records loads.
			 */
			public function __construct( private $can_flush_runtime, private $test ) {
				parent::__construct( null, null, false, true );
			}

			/**
			 * Load the given IDs in batches.
			 *
			 * @param int[]    $ids  IDs.
			 * @param callable $load Loader.
			 * @return \Generator
			 */
			public function load( $ids, $load ) {
				return $this->load_in_batches( $ids, $load );
			}

			/**
			 * One event per item from IDs 1 to 5, loaded in batches.
			 *
			 * @return \Generator
			 */
			public function get_events() {
				foreach ( $this->load_in_batches( range( 1, 5 ), [ $this->test, 'load_item' ] ) as $item ) {
					yield new Reader_Registered( get_bloginfo( 'url' ), [ 'email' => $item ], 1000 );
				}
			}

			/**
			 * Reports the event and how many items were loaded by the time it was processed.
			 *
			 * @param Reader_Registered $event Event.
			 * @return string
			 */
			protected function get_processed_item_output( $event ) {
				return $event->get_email() . ' after ' . count( $this->test->loaded() ) . ' loads';
			}

			/**
			 * Forced cache capability, or the real one.
			 *
			 * @return bool
			 */
			protected function can_flush_runtime_cache() {
				return null === $this->can_flush_runtime ? parent::can_flush_runtime_cache() : $this->can_flush_runtime;
			}
		};
	}

	/**
	 * Loader that records each ID and returns an item named after it.
	 *
	 * @param int $id ID.
	 * @return string
	 */
	public function load_item( $id ) {
		$this->loaded[] = $id;
		return "item-$id@example.test";
	}

	/**
	 * IDs loaded so far.
	 *
	 * @return int[]
	 */
	public function loaded() {
		return $this->loaded;
	}

	/**
	 * Loader that records whether a cached sentinel survived since the previous load, then sets it again.
	 *
	 * @param int $id ID.
	 * @return int
	 */
	public function load_and_check_sentinel( $id ) {
		$this->sentinel_survived[ $id ] = false !== wp_cache_get( 'sentinel', 'backfill_test' );
		wp_cache_set( 'sentinel', $id, 'backfill_test' );
		return $id;
	}

	/**
	 * Nothing loads before it's asked for, so memory holds one item rather than all of them.
	 */
	public function test_loads_each_item_only_when_asked_for() {
		$items = $this->backfiller()->load( range( 1, 5 ), [ $this, 'load_item' ] );

		$this->assertSame( [], $this->loaded, 'Nothing loads before iteration starts.' );

		$items->current();
		$this->assertSame( [ 1 ], $this->loaded, 'Taking the first item loads only that item.' );

		$this->assertSame(
			[ 'item-1@example.test', 'item-2@example.test', 'item-3@example.test', 'item-4@example.test', 'item-5@example.test' ],
			iterator_to_array( $items, false ),
			'Every item still arrives, in ID order.'
		);
	}

	/**
	 * An item deleted after the ID query is skipped instead of ending or breaking the run.
	 */
	public function test_skips_items_that_no_longer_load() {
		$items = $this->backfiller()->load(
			[ 1, 2, 3 ],
			function ( $id ) {
				return 2 === $id ? false : $id;
			}
		);

		$this->assertSame( [ 1, 3 ], iterator_to_array( $items, false ) );
	}

	/**
	 * The in-memory cache is freed between batches, not after every item.
	 */
	public function test_frees_in_memory_cache_after_each_batch() {
		wp_cache_set( 'sentinel', 0, 'backfill_test' );

		iterator_to_array( $this->backfiller()->load( [ 1, 2, 3 ], [ $this, 'load_and_check_sentinel' ] ) );

		$this->assertSame(
			[
				1 => true,
				2 => true,
				3 => false,
			],
			$this->sentinel_survived,
			'The cache survives within a batch of two and is freed before the next one.'
		);
	}

	/**
	 * A cache that can't flush its in-memory copy alone is left untouched, rather than flushed for the whole site.
	 */
	public function test_leaves_cache_alone_when_it_cannot_flush_in_memory_copy() {
		wp_cache_set( 'sentinel', 0, 'backfill_test' );

		iterator_to_array( $this->backfiller( false )->load( [ 1, 2, 3 ], [ $this, 'load_and_check_sentinel' ] ) );

		$this->assertSame(
			[
				1 => true,
				2 => true,
				3 => true,
			],
			$this->sentinel_survived
		);
	}

	/**
	 * Processing a generator of events loads each item just before its event is processed.
	 */
	public function test_process_events_consumes_generator_one_item_at_a_time() {
		$this->backfiller()->process_events();

		$this->assertSame(
			[
				'👉 item-1@example.test after 1 loads',
				'👉 item-2@example.test after 2 loads',
				'👉 item-3@example.test after 3 loads',
				'👉 item-4@example.test after 4 loads',
				'👉 item-5@example.test after 5 loads',
			],
			WP_CLI::$output
		);
	}
}
