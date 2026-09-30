<?php
/**
 * Newspack Network: rebuild the hub's copies of orders and subscriptions.
 *
 * @package Newspack
 */

namespace Newspack_Network\CLI;

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Hub\Stores\Event_Log;
use Newspack_Network\Site_Role;
use WP_CLI;

/**
 * Rewrites the hub's copies of the network's orders and subscriptions from the Event Log.
 *
 * Copies used to be matched on the item's ID alone, so two sites' items with the same
 * ID shared one copy: it kept the first site's customer and took whichever site wrote
 * last. Copies written that way aren't used to answer a node's reader access question
 * until something rewrites them. This rewrites every copy from its site's latest
 * logged event, which puts each collided item back on its own copy.
 */
class Rebuild_Hub_Copies {

	/**
	 * Logged actions that write the copies.
	 */
	const ACTIONS = [
		'newspack_node_subscription_changed',
		'newspack_node_order_changed',
		'newspack_node_group_members_changed',
	];

	/**
	 * Events read per page.
	 */
	const PAGE_SIZE = 500;

	/**
	 * Initialize this class and register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_commands' ] );
	}

	/**
	 * Register the WP-CLI command.
	 *
	 * @return void
	 */
	public static function register_commands() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'newspack-network rebuild-hub-copies', [ __CLASS__, 'rebuild' ] );
		}
	}

	/**
	 * Rewrite the hub's copies of orders and subscriptions from the Event Log.
	 *
	 * Run once on the hub after updating, so copies written before each site's items
	 * got their own copy can answer reader access questions again. Safe to run again:
	 * it only replays events the hub already logged.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Rewrite the copies. Without this flag the command only reports what it would rewrite.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network rebuild-hub-copies
	 *     wp newspack-network rebuild-hub-copies --apply
	 *
	 * @param array $args       Positional arguments ( unused ).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public static function rebuild( $args, $assoc_args ) {
		if ( ! Site_Role::is_hub() ) {
			WP_CLI::error( 'This command can only be run on the Hub.' );
		}

		$apply     = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'apply', false );
		$event_ids = self::find_latest_event_ids();

		WP_CLI::line( sprintf( 'Found %d orders and subscriptions to rewrite from the Event Log.', count( $event_ids ) ) );
		if ( ! $apply ) {
			WP_CLI::line( 'Dry run: pass --apply to rewrite them.' );
			return;
		}

		WP_CLI::success( sprintf( 'Rewrote %d copies.', self::replay( $event_ids ) ) );
	}

	/**
	 * The ID of the latest logged event for each action, site and item.
	 *
	 * @return array<int, true> Event IDs.
	 */
	private static function find_latest_event_ids() {
		$latest = [];
		self::walk(
			function ( $event ) use ( &$latest ) {
				$item_id = (int) ( $event->get_data()->id ?? 0 );
				if ( $item_id ) {
					$latest[ $event->get_action_name() . '|' . (int) $event->get_node_id() . '|' . $item_id ] = (int) $event->get_id();
				}
			}
		);
		return array_fill_keys( array_values( $latest ), true );
	}

	/**
	 * Process the given events again, oldest first, as the hub first did.
	 *
	 * @param array<int, true> $event_ids Event IDs to replay.
	 * @return int Number replayed.
	 */
	private static function replay( $event_ids ) {
		$replayed = 0;
		self::walk(
			function ( $event ) use ( $event_ids, &$replayed ) {
				if ( ! isset( $event_ids[ (int) $event->get_id() ] ) ) {
					return;
				}
				$class = 'Newspack_Network\\Incoming_Events\\' . Accepted_Actions::ACTIONS[ $event->get_action_name() ];
				( new $class( $event->get_node_url(), $event->get_data(), $event->get_timestamp() ) )->always_process_in_hub();
				++$replayed;
			}
		);
		return $replayed;
	}

	/**
	 * Call a function for every logged event that writes a copy, oldest first.
	 *
	 * @param callable $callback Receives each Event Log item.
	 * @return void
	 */
	private static function walk( $callback ) {
		$cursor = 0;
		do {
			$events = Event_Log::get(
				[
					'action_name_in'  => self::ACTIONS,
					'id_greater_than' => $cursor,
				],
				self::PAGE_SIZE,
				1,
				'ASC'
			);
			foreach ( $events as $event ) {
				$cursor = (int) $event->get_id();
				$callback( $event );
			}
			// Keep memory flat over a long log; only the in-request cache is dropped.
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			$is_full_page = self::PAGE_SIZE === count( $events );
		} while ( $is_full_page );
	}
}
