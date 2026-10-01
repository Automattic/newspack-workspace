<?php
/**
 * Data Backfiller abstract class.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Data_Backfill;
use Newspack_Network\Site_Role;
use WP_CLI;

/**
 * Abstract class for backfillers.
 */
abstract class Abstract_Backfiller {

	/**
	 * How many items load_in_batches() loads between frees of the object cache's in-memory copy.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 100;

	/**
	 * Whether to run the backfiller in live mode.
	 *
	 * @var bool
	 */
	protected $live;

	/**
	 * Whether to run the backfiller in verbose mode.
	 *
	 * @var bool
	 */
	protected $verbose;

	/**
	 * The progress bar object if not in verbose mode.
	 *
	 * @var ?object
	 */
	protected $progress = null;

	/**
	 * The start date to process data from
	 *
	 * @var string
	 */
	protected $start;

	/**
	 * The end date to process data to
	 *
	 * @var string
	 */
	protected $end;

	/**
	 * Object contructor
	 *
	 * @param string $start The start date.
	 * @param string $end The end date.
	 * @param bool   $live Whether to run the backfiller in live mode.
	 * @param bool   $verbose Whether to run the backfiller in verbose mode.
	 */
	public function __construct( $start, $end, $live, $verbose ) {
		$this->start = $start;
		$this->end = $end;
		$this->live = $live;
		$this->verbose = $verbose;
	}

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	abstract protected function get_processed_item_output( $event );

	/**
	 * Gets the events to be processed
	 *
	 * Return a generator to build events one at a time, when holding every event in memory could exhaust it.
	 *
	 * @return iterable<\Newspack_Network\Incoming_Events\Abstract_Incoming_Event> $events An array or generator of events.
	 */
	abstract public function get_events();

	/**
	 * Loads items one at a time, so a backfill never holds every item in memory at once.
	 *
	 * Each loaded item stays in the object cache's in-memory copy for the rest of the run, so that
	 * copy is freed after every batch where the cache allows it. Where it doesn't, the copy keeps
	 * growing, and the run warns once so it can be split by date range instead.
	 *
	 * @param int[]    $ids  IDs of the items to load.
	 * @param callable $load Loads one item by ID; returns a falsy value when the item no longer exists.
	 *
	 * @return \Generator Each item that loaded.
	 */
	protected function load_in_batches( $ids, $load ) {
		if ( ! $this->can_flush_runtime_cache() ) {
			WP_CLI::warning( "This site's object cache can't free its in-memory copy during the run, so memory grows with each item. On a large site, split the backfill into date ranges with --start and --end." );
		}

		foreach ( array_chunk( $ids, static::BATCH_SIZE ) as $batch ) {
			foreach ( $batch as $id ) {
				$item = $load( $id );
				if ( $item ) {
					yield $item;
				}
			}
			$this->free_runtime_cache();
		}
	}

	/**
	 * Frees this process's in-memory copy of the object cache.
	 *
	 * Never falls back to wp_cache_flush(): on a persistent cache that empties the cache for the
	 * whole site, not just this process. Where the cache can't flush its in-memory copy alone,
	 * this frees nothing.
	 */
	protected function free_runtime_cache() {
		if ( $this->can_flush_runtime_cache() ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Whether the object cache can flush its in-memory copy without touching persistent storage.
	 *
	 * @return bool
	 */
	protected function can_flush_runtime_cache() {
		return wp_cache_supports( 'flush_runtime' );
	}

	/**
	 * Initializes the WP CLI progress bar if in verbose mode
	 *
	 * @param string $label The progress bar label.
	 * @param int    $total The total number of items to be processed.
	 * @return void
	 */
	protected function maybe_initialize_progress_bar( $label, $total ) {
		if ( ! $this->verbose ) {
			Data_Backfill::$progress = \WP_CLI\Utils\make_progress_bar( $label, $total );
		}
	}

	/**
	 * Process the events.
	 */
	public function process_events() {
		$events = $this->get_events();

		foreach ( $events as $event ) {

			if ( Site_Role::is_node() ) {
				$requests = $this->find_webhook_requests( $event->get_action_name(), $event->get_timestamp(), $event->get_data() );
				if ( count( $requests ) > 0 ) {
					Data_Backfill::increment_results_counter( $event->get_action_name(), 'duplicate' );
					return;
				}
			}

			if ( $this->live ) {
				if ( Site_Role::is_hub() ) {
					$event->process_in_hub();
					Data_Backfill::increment_results_counter( $event->get_action_name(), $event->is_persisted ? 'processed' : 'duplicate' );
				} else {
					\Newspack\Data_Events\Webhooks::handle_dispatch( $event->get_action_name(), $event->get_timestamp(), $event->get_data() );
					Data_Backfill::increment_results_counter( $event->get_action_name(), 'processed' );
				}
			}

			if ( $this->verbose ) {
				WP_CLI::line( '👉 ' . $this->get_processed_item_output( $event ) );
			}

			if ( ! $this->verbose ) {
				Data_Backfill::$progress->tick();
			}
		}
	}

	/**
	 * Find existing webhook requests for a given action and data.
	 *
	 * @param string $action The action name.
	 * @param int    $timestamp The timestamp.
	 * @param array  $data The data.
	 */
	private function find_webhook_requests( $action, $timestamp, $data ) {
		return get_posts(
			[
				'post_type'   => \Newspack\Data_Events\Webhooks::REQUEST_POST_TYPE,
				'post_title'  => $action,
				'post_status' => 'any',
				'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					[
						'key'     => 'timestamp',
						'value'   => $timestamp,
						'compare' => '=',
					],
					[
						'key'     => 'action_name',
						'value'   => $action,
						'compare' => '=',
					],
					[
						'key'     => 'data',
						'value'   => wp_json_encode( $data ),
						'compare' => '=',
					],
				],
			]
		);
	}
}
