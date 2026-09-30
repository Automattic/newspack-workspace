<?php
/**
 * Newspack Network: rebuild the hub's copies of orders and subscriptions.
 *
 * @package Newspack
 */

namespace Newspack_Network\CLI;

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Hub\Database\Event_Log as Event_Log_Database;
use Newspack_Network\Hub\Node;
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
	 * it only replays events the hub already logged. Events from sites no longer in
	 * the network are skipped.
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

		$apply   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'apply', false );
		$last_id = self::get_last_event_id();
		$counts  = self::replay_latest( $last_id, $apply );

		if ( ! $apply ) {
			WP_CLI::line( sprintf( 'Would rewrite %d copies; %d events from sites no longer in the network would be skipped.', $counts['replayed'], $counts['skipped'] ) );
			WP_CLI::line( 'Dry run: pass --apply to rewrite them.' );
			return;
		}

		// Events logged while this ran were processed as they arrived, but the pass may
		// have replayed an older event for the same item afterwards. Replaying them
		// again, oldest first, leaves each such item on its newest event.
		$counts['replayed'] += self::replay_since( $last_id );

		WP_CLI::success( sprintf( 'Rewrote %d copies; skipped %d events from sites no longer in the network.', $counts['replayed'], $counts['skipped'] ) );
	}

	/**
	 * Replay the newest logged event for each copy, newest first.
	 *
	 * A group members event writes both a subscription's copy (when that copy predates
	 * the per-site fix) and its members, so it is replayed if either is still unwritten;
	 * a newer status event has by then rewritten the copy, and the members event only
	 * sets members.
	 *
	 * @param int  $last_id Newest event ID when the run started.
	 * @param bool $apply   Whether to replay, or only count.
	 * @return array{replayed:int, skipped:int}
	 */
	private static function replay_latest( $last_id, $apply ) {
		$seen   = [];
		$counts = [
			'replayed' => 0,
			'skipped'  => 0,
		];
		self::walk(
			'DESC',
			$last_id + 1,
			function ( $row ) use ( &$seen, &$counts, $apply ) {
				$item_id = (int) ( $row->data->id ?? 0 );
				if ( ! $item_id ) {
					return;
				}
				$site = self::get_site_url( (int) $row->node_id );
				if ( ! $site ) {
					++$counts['skipped'];
					return;
				}
				$keys = self::get_keys( $row->action_name, (int) $row->node_id, $item_id );
				if ( ! array_diff( $keys, array_keys( $seen ) ) ) {
					return;
				}
				foreach ( $keys as $key ) {
					$seen[ $key ] = true;
				}
				if ( ! $apply || self::replay( $row, $site ) ) {
					++$counts['replayed'];
				}
			}
		);
		return $counts;
	}

	/**
	 * Replay every event logged after the given ID, oldest first.
	 *
	 * @param int $last_id Newest event ID when the run started.
	 * @return int Number replayed.
	 */
	private static function replay_since( $last_id ) {
		$replayed = 0;
		self::walk(
			'ASC',
			$last_id,
			function ( $row ) use ( &$replayed ) {
				$site = self::get_site_url( (int) $row->node_id );
				if ( $site && self::replay( $row, $site ) ) {
					++$replayed;
				}
			}
		);
		return $replayed;
	}

	/**
	 * Process one logged event again.
	 *
	 * @param object $row  Event Log row.
	 * @param string $site The event's site URL.
	 * @return bool Whether it was replayed.
	 */
	private static function replay( $row, $site ) {
		$class = 'Newspack_Network\\Incoming_Events\\' . Accepted_Actions::ACTIONS[ $row->action_name ];
		$event = new $class( $site, $row->data, (int) $row->timestamp );
		// The copy is looked up by the site's URL; if that no longer leads back to the
		// logged site, the event would land on another site's copy.
		if ( (int) $event->get_node_id() !== (int) $row->node_id ) {
			return false;
		}
		$event->always_process_in_hub();
		return true;
	}

	/**
	 * The copies an event writes, as keys.
	 *
	 * @param string $action  Action name.
	 * @param int    $node_id Node ID, or 0 for the hub.
	 * @param int    $item_id Order or subscription ID on that site.
	 * @return string[]
	 */
	private static function get_keys( $action, $node_id, $item_id ) {
		$item = $node_id . '|' . $item_id;
		switch ( $action ) {
			case 'newspack_node_order_changed':
				return [ 'order|' . $item ];
			case 'newspack_node_group_members_changed':
				return [ 'subscription|' . $item, 'members|' . $item ];
			default:
				return [ 'subscription|' . $item ];
		}
	}

	/**
	 * The URL of a logged event's site, or '' for a node no longer in the network.
	 *
	 * @param int $node_id Node ID, or 0 for the hub.
	 * @return string
	 */
	private static function get_site_url( $node_id ) {
		static $urls = [];
		if ( ! $node_id ) {
			return get_bloginfo( 'url' );
		}
		if ( ! isset( $urls[ $node_id ] ) ) {
			$node              = new Node( $node_id );
			$urls[ $node_id ] = ( $node->get_id() && 'publish' === get_post_status( $node_id ) ) ? (string) $node->get_url() : '';
		}
		return $urls[ $node_id ];
	}

	/**
	 * The newest Event Log ID.
	 *
	 * @return int
	 */
	private static function get_last_event_id() {
		global $wpdb;
		$table = Event_Log_Database::get_table_name();
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM $table" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Call a function for each logged event that writes a copy, reading the Event Log
	 * table directly so each row keeps its logged node ID.
	 *
	 * @param string   $order    'ASC' for IDs above $boundary, oldest first; 'DESC' for IDs below it, newest first.
	 * @param int      $boundary Exclusive ID bound.
	 * @param callable $callback Receives each row, with `data` decoded.
	 * @return void
	 */
	private static function walk( $order, $boundary, $callback ) {
		global $wpdb;
		$table        = Event_Log_Database::get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( self::ACTIONS ), '%s' ) );
		$cursor       = (int) $boundary;
		do {
			$comparison = 'ASC' === $order ? '>' : '<';
			$sql_order  = 'ASC' === $order ? 'ASC' : 'DESC';
			$rows       = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The action names and the bounds are passed as one array.
					"SELECT id, node_id, action_name, data, timestamp FROM $table WHERE action_name IN ( $placeholders ) AND id $comparison %d ORDER BY id $sql_order LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					array_merge( self::ACTIONS, [ $cursor, self::PAGE_SIZE ] )
				)
			);
			foreach ( $rows as $row ) {
				$cursor    = (int) $row->id;
				$row->data = json_decode( $row->data );
				if ( is_object( $row->data ) && isset( Accepted_Actions::ACTIONS[ $row->action_name ] ) ) {
					$callback( $row );
				}
			}
			self::clear_memory();
			$is_full_page = self::PAGE_SIZE === count( $rows );
		} while ( $is_full_page );
	}

	/**
	 * Keep memory flat over a long log by dropping the in-request object cache.
	 *
	 * @return void
	 */
	private static function clear_memory() {
		if ( function_exists( '\WP_CLI\Utils\wp_clear_object_cache' ) ) {
			\WP_CLI\Utils\wp_clear_object_cache();
		} elseif ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}
