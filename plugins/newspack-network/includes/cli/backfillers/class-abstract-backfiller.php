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
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	abstract public function get_events();

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
