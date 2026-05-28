<?php
/**
 * Newspack Hub Event Log Store
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores;

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Debugger;
use Newspack_Network\Incoming_Events\Abstract_Incoming_Event;
use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Database\Event_Log as Database;

/**
 * Class to handle Event Log Store
 */
class Event_Log {

	/**
	 * Get event log items
	 *
	 * @param array  $args See {@see self::build_where_clause()} for supported arguments.
	 * @param int    $per_page Number of items to return per page.
	 * @param int    $page Page number to return.
	 * @param string $order Order to return the items in. ASC or DESC.
	 * @return Abstract_Event_Log_Item[]
	 */
	public static function get( $args, $per_page = 10, $page = 1, $order = 'DESC' ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		$table_name = Database::get_table_name();

		$order_string = "ORDER BY ID $order";

		$query = $wpdb->prepare( "SELECT * FROM $table_name WHERE 1=1 [args] $order_string LIMIT %d OFFSET %d", $per_page, $offset ); //phpcs:ignore

		$args = apply_filters( 'newspack_network_event_log_get_args', $args );
		$query = str_replace( '[args]', self::build_where_clause( $args ), $query );

		$db = $wpdb->get_results( $query ); //phpcs:ignore

		$results = [];

		foreach ( $db as $item ) {
			if ( empty( Accepted_Actions::ACTIONS[ $item->action_name ] ) ) {
				continue;
			}
			$class_name = 'Newspack_Network\\Hub\\Stores\\Event_Log_Items\\' . Accepted_Actions::ACTIONS[ $item->action_name ];
			if ( ! class_exists( $class_name ) ) {
				$class_name = 'Newspack_Network\\Hub\\Stores\\Event_Log_Items\\Generic';
			}
			$results[] = new $class_name(
				[
					'id'          => $item->id,
					'node'        => new Node( $item->node_id ),
					'action_name' => $item->action_name,
					'email'       => $item->email,
					'data'        => $item->data,
					'timestamp'   => $item->timestamp,
				]
			);
		}

		return $results;
	}

	/**
	 * Gets a list of all the emails in the event log
	 *
	 * @return array
	 */
	public static function get_all_emails() {
		global $wpdb;
		$table_name = Database::get_table_name();
		$query      = "SELECT DISTINCT email FROM $table_name ORDER BY email";
		return $wpdb->get_col( $query ); //phpcs:ignore
	}

	/**
	 * Get the total number of items for a query
	 *
	 * @param array $args See {@see self::build_where_clause()} for supported arguments.
	 * @return int
	 */
	public static function get_total_items( $args = [] ) {
		global $wpdb;
		$table_name = Database::get_table_name();
		$query      = "SELECT COUNT(*) FROM $table_name WHERE 1=1 [args]";
		$query      = str_replace( '[args]', self::build_where_clause( $args ), $query );
		$result     = $wpdb->get_var( $query ); //phpcs:ignore
		return $result;
	}

	/**
	 * Build the WHERE clause for the query
	 *
	 * @param array $args {
	 *      The query arguments. Supported arguments are below.
	 *
	 *      @type string $search Search string to search for in the event log. It will search in the email, action_name and data fields.
	 *      @type int $node_id The ID of the node to filter by.
	 *      @type int $excluded_node_id The ID of the node to exclude from results.
	 *      @type int $id_greater_than Retrieve events with ID greater than this value.
	 *      @type string $email The email of the event to filter by.
	 *      @type string $action_name The name of the action to filter by.
	 *      @type array $action_name_in List of action names to include in the results.
	 * }
	 * @return string The WHERE clause for the query.
	 */
	protected static function build_where_clause( $args ) {
		global $wpdb;
		$where = '';

		// remove empty values.
		$args = array_filter(
			$args,
			function( $v ) {
				return is_array( $v ) || strlen( (string) $v ); // Zero is a valid value for node id, thus we don't use empty().
			}
		);

		if ( isset( $args['node_id'] ) ) {
			$where .= $wpdb->prepare( ' AND node_id = %d', $args['node_id'] );
		}

		if ( isset( $args['excluded_node_id'] ) ) {
			$where .= $wpdb->prepare( ' AND node_id <> %d', $args['excluded_node_id'] );
		}

		if ( ! empty( $args['id_greater_than'] ) ) {
			$where .= $wpdb->prepare( ' AND id > %d', $args['id_greater_than'] );
		}

		if ( ! empty( $args['email'] ) ) {
			$where .= $wpdb->prepare( ' AND email = %s', $args['email'] );
		}

		if ( ! empty( $args['action_name'] ) ) {
			$where .= $wpdb->prepare( ' AND action_name = %s', $args['action_name'] );
		}

		if ( ! empty( $args['timestamp'] ) ) {
			// The logged event timestamp and the actual timestamp of the event can differ by a few seconds,
			// so we need to search for a range of timestamps. For example, when a user registered at time T,
			// the event will have a timestamp of e.g. T+10ms. Then, when a backfill script (or other code) is looking
			// for the event, it will miss it since it's looking for the exact timestamp T.
			$timestamp = $args['timestamp'];
			$range     = 10; // milliseconds.
			$where     .= $wpdb->prepare( ' AND timestamp >= %s AND timestamp <= %s', $timestamp - $range, $timestamp + $range );
		}

		if ( ! empty( $args['data'] ) ) {
			$where .= $wpdb->prepare( ' AND data = %s', $args['data'] );
		}

		if ( ! empty( $args['action_name_in'] ) && is_array( $args['action_name_in'] ) ) {
			$escaped_actions = array_map(
				function( $a ) {
					return "'" . esc_sql( $a ) . "'";
				},
				$args['action_name_in']
			);
			$where          .= ' AND action_name IN (' . implode( ',', $escaped_actions ) . ')';
		}

		if ( ! empty( $args['search'] ) ) {
			$where .= $wpdb->prepare( ' AND ( email LIKE %s ', '%' . $args['search'] . '%' );
			$where .= $wpdb->prepare( ' OR action_name LIKE %s ', '%' . $args['search'] . '%' );
			$where .= $wpdb->prepare( ' OR data LIKE %s ', '%' . $args['search'] . '%' );
			$where .= ')';
		}
		return $where;
	}

	/**
	 * Persists an event to the database
	 *
	 * @param Abstract_Incoming_Event $event The Incoming Event to be persisted.
	 * @return int|false The ID of the inserted row, or false on failure.
	 */
	public static function persist( Abstract_Incoming_Event $event ) {
		global $wpdb;

		$node    = Nodes::get_node_by_url( $event->get_site() );
		$node_id = 0;

		if ( $node instanceof Node ) {
			$node_id = $node->get_id();
		}

		$event_payload = [
			'node_id'     => $node_id,
			'action_name' => $event->get_action_name(),
			'email'       => $event->get_email(),
			'data'        => wp_json_encode( $event->get_data() ),
			'timestamp'   => $event->get_timestamp(),
		];

		// Check against duplicates.
		$maybe_event = self::get( $event_payload );
		if ( ! empty( $maybe_event ) ) {
			Debugger::log( 'Not persisting event, it\'s already in the log.' );
			return;
		}

		Debugger::log( 'Persisting Event' );
		$insert = $wpdb->insert( Database::get_table_name(), $event_payload ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		Debugger::log( $insert );
		if ( ! $insert ) {
			return false;
		}
		$event->is_persisted = true;
		return $wpdb->insert_id;
	}
}
