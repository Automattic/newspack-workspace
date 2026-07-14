<?php
/**
 * ActionScheduler utilities.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * General-purpose ActionScheduler helpers for Newspack.
 */
class Action_Scheduler {
	/**
	 * Default ActionScheduler group for Newspack actions.
	 */
	const DEFAULT_GROUP = 'newspack';

	/**
	 * Prefix for Newspack ActionScheduler groups.
	 */
	const GROUP_PREFIX = 'newspack-';

	/**
	 * Whether ActionScheduler is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'ActionScheduler' );
	}

	/**
	 * Get labels for known hooks.
	 *
	 * Returns an associative array of hook slug => human-readable label.
	 * Subsystems can extend this via the `newspack_action_scheduler_hook_labels` filter.
	 *
	 * @return array<string,string>
	 */
	public static function get_hook_labels() {
		/**
		 * Filters the human-readable labels for ActionScheduler hook names.
		 *
		 * @param array<string,string> $labels Hook slug => label pairs.
		 */
		return apply_filters( 'newspack_action_scheduler_hook_labels', [] );
	}

	/**
	 * Get labels for known groups.
	 *
	 * Returns an associative array of group slug => human-readable label.
	 * Subsystems can extend this via the `newspack_action_scheduler_group_labels` filter.
	 *
	 * @return array<string,string>
	 */
	public static function get_group_labels() {
		/**
		 * Filters the human-readable labels for ActionScheduler group slugs.
		 *
		 * @param array<string,string> $labels Group slug => label pairs.
		 */
		return apply_filters(
			'newspack_action_scheduler_group_labels',
			[
				'newspack' => 'Newspack',
			]
		);
	}

	/**
	 * Get all actions associated with a retry ID.
	 *
	 * Uses ActionScheduler's search parameter to match the retry_id
	 * against args or extended_args.
	 *
	 * @param string $retry_id The retry ID to search for.
	 * @param string $hook     Optional. Hook name to filter by.
	 *
	 * @return array ActionScheduler action objects keyed by action ID.
	 */
	public static function get_actions_by_retry_id( $retry_id, $hook = '' ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || empty( $retry_id ) ) {
			return [];
		}
		$args = [
			'search'   => $retry_id,
			'status'   => '',
			'per_page' => -1,
		];
		if ( ! empty( $hook ) ) {
			$args['hook'] = $hook;
		}
		return as_get_scheduled_actions( $args );
	}

	/**
	 * Get ActionScheduler group slugs matching a prefix.
	 *
	 * @param string $prefix The prefix to match (e.g. 'newspack-').
	 *
	 * @return string[] Array of group slug strings.
	 */
	public static function get_groups_by_prefix( $prefix ) {
		if ( ! self::is_available() ) {
			return [];
		}
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_groups';
		return $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT slug FROM {$table} WHERE slug LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
	}

	/**
	 * Get all known Newspack group slugs.
	 *
	 * @return string[] Array of group slug strings.
	 */
	public static function get_all_groups() {
		return array_merge(
			[ self::DEFAULT_GROUP ],
			self::get_groups_by_prefix( self::GROUP_PREFIX )
		);
	}

	/**
	 * Build the base query components shared by get/count methods.
	 *
	 * Resolves group slugs and builds the JOIN, WHERE clause, and prepare args.
	 *
	 * @param array $args Query arguments (groups, status, hook, search, date_op, date).
	 *
	 * @return array|null {
	 *     @var string $from         FROM + JOIN clause.
	 *     @var string $where        WHERE clause.
	 *     @var array  $prepare_args Prepare arguments for the query.
	 * } Null if no groups found.
	 */
	private static function build_query( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'groups' => [],
				'status' => '',
			]
		);

		$slugs = $args['groups'];
		if ( empty( $slugs ) ) {
			$slugs = array_merge(
				[ self::DEFAULT_GROUP ],
				self::get_groups_by_prefix( self::GROUP_PREFIX )
			);
		}
		if ( empty( $slugs ) ) {
			return null;
		}

		$actions_table     = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table      = $wpdb->prefix . 'actionscheduler_groups';
		$slug_placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$prepare_args      = $slugs;

		$where_clauses = '';
		if ( ! empty( $args['status'] ) ) {
			$where_clauses .= 'AND a.status = %s ';
			$prepare_args[] = $args['status'];
		}
		if ( ! empty( $args['hook'] ) ) {
			$where_clauses .= 'AND a.hook = %s ';
			$prepare_args[] = $args['hook'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like            = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where_clauses  .= 'AND (a.hook LIKE %s OR a.args LIKE %s OR a.extended_args LIKE %s) ';
			$prepare_args[]  = $like;
			$prepare_args[]  = $like;
			$prepare_args[]  = $like;
		}
		if ( ! empty( $args['date_op'] ) && ! empty( $args['date'] ) ) {
			list( $date_clause, $date_args ) = self::build_date_clause( $args['date_op'], $args['date'] );
			$where_clauses .= $date_clause;
			$prepare_args   = array_merge( $prepare_args, $date_args );
		}

		return [
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'from'         => "{$actions_table} a INNER JOIN {$groups_table} g ON a.group_id = g.group_id",
			'where'        => "WHERE g.slug IN ({$slug_placeholders}) {$where_clauses}",
			'prepare_args' => $prepare_args,
		];
	}

	/**
	 * Query ActionScheduler actions by group slugs.
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type string   $search   Search term to match against hook, args, or extended_args.
	 *     @type string[] $groups   Array of group slugs to query.
	 *     @type string   $status   ActionScheduler status (pending, complete, failed, canceled).
	 *     @type string   $hook     Hook name to filter by.
	 *     @type int      $per_page Number of actions to return. Default 20.
	 *     @type int      $offset   Offset for pagination. Default 0.
	 *     @type string   $date_op  Scheduled date filter operator.
	 *     @type mixed    $date     Scheduled date filter value.
	 *     @type string   $orderby  Column to order by. Default 'scheduled_date_gmt'.
	 *     @type string   $order    ASC or DESC. Default 'DESC'.
	 * }
	 *
	 * @return array Array of action row objects.
	 */
	public static function get_scheduled_actions( $args = [] ) {
		if ( ! self::is_available() ) {
			return [];
		}

		$args  = wp_parse_args(
			$args,
			[
				'per_page' => 20,
				'offset'   => 0,
				'orderby'  => 'scheduled_date_gmt',
				'order'    => 'DESC',
			]
		);
		$query = self::build_query( $args );
		if ( ! $query ) {
			return [];
		}

		global $wpdb;

		$allowed_orderby = [ 'scheduled_date_gmt', 'action_id', 'hook', 'status' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'scheduled_date_gmt';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$prepare_args   = $query['prepare_args'];
		$prepare_args[] = absint( $args['per_page'] );
		$prepare_args[] = absint( $args['offset'] );

		// Table names: $wpdb->prefix + hardcoded strings. $orderby/$order: allowlist/ternary validated.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT a.* FROM {$query['from']} {$query['where']}ORDER BY a.{$orderby} {$order} LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$prepared = $wpdb->prepare( $sql, ...$prepare_args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $prepared );
	}

	/**
	 * Count ActionScheduler actions matching the given query args.
	 *
	 * @param array $args Same as get_scheduled_actions() but per_page/offset/orderby/order are ignored.
	 *
	 * @return int Total count.
	 */
	public static function count_scheduled_actions( $args = [] ) {
		if ( ! self::is_available() ) {
			return 0;
		}

		$query = self::build_query( $args );
		if ( ! $query ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$query['from']} {$query['where']}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$prepared = $wpdb->prepare( $sql, ...$query['prepare_args'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $prepared );
	}

	/**
	 * Build SQL WHERE clause and prepare args for scheduled date filtering.
	 *
	 * Supports DataViews date filter operators: on, notOn, before, after,
	 * beforeInc, afterInc, inThePast, over, between.
	 *
	 * @param string $operator The filter operator.
	 * @param mixed  $value    The filter value (ISO date string, array for between, or object for relative).
	 *
	 * @return array{string, array} Tuple of [ SQL clause string, prepare args array ].
	 */
	private static function build_date_clause( $operator, $value ) {
		$clause       = '';
		$prepare_args = [];

		$allowed_ops = [ 'on', 'notOn', 'before', 'after', 'beforeInc', 'afterInc', 'inThePast', 'over', 'between' ];
		if ( ! in_array( $operator, $allowed_ops, true ) ) {
			return [ $clause, $prepare_args ];
		}

		switch ( $operator ) {
			case 'on':
				$clause         = 'AND DATE(a.scheduled_date_gmt) = %s ';
				$prepare_args[] = gmdate( 'Y-m-d', strtotime( $value ) );
				break;
			case 'notOn':
				$clause         = 'AND DATE(a.scheduled_date_gmt) != %s ';
				$prepare_args[] = gmdate( 'Y-m-d', strtotime( $value ) );
				break;
			case 'before':
				$clause         = 'AND a.scheduled_date_gmt < %s ';
				$prepare_args[] = gmdate( 'Y-m-d 00:00:00', strtotime( $value ) );
				break;
			case 'after':
				$clause         = 'AND a.scheduled_date_gmt > %s ';
				$prepare_args[] = gmdate( 'Y-m-d 23:59:59', strtotime( $value ) );
				break;
			case 'beforeInc':
				$clause         = 'AND a.scheduled_date_gmt <= %s ';
				$prepare_args[] = gmdate( 'Y-m-d 23:59:59', strtotime( $value ) );
				break;
			case 'afterInc':
				$clause         = 'AND a.scheduled_date_gmt >= %s ';
				$prepare_args[] = gmdate( 'Y-m-d 00:00:00', strtotime( $value ) );
				break;
			case 'inThePast':
				if ( is_array( $value ) && isset( $value['value'], $value['unit'] ) ) {
					$units          = [
						'days'   => 'DAY',
						'weeks'  => 'WEEK',
						'months' => 'MONTH',
						'years'  => 'YEAR',
					];
					$unit           = $units[ $value['unit'] ] ?? 'DAY';
					$clause         = "AND a.scheduled_date_gmt >= DATE_SUB(NOW(), INTERVAL %d {$unit}) "; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$prepare_args[] = absint( $value['value'] );
				}
				break;
			case 'over':
				if ( is_array( $value ) && isset( $value['value'], $value['unit'] ) ) {
					$units          = [
						'days'   => 'DAY',
						'weeks'  => 'WEEK',
						'months' => 'MONTH',
						'years'  => 'YEAR',
					];
					$unit           = $units[ $value['unit'] ] ?? 'DAY';
					$clause         = "AND a.scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL %d {$unit}) "; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$prepare_args[] = absint( $value['value'] );
				}
				break;
			case 'between':
				if ( is_array( $value ) && count( $value ) === 2 ) {
					$clause         = 'AND a.scheduled_date_gmt >= %s AND a.scheduled_date_gmt <= %s ';
					$prepare_args[] = gmdate( 'Y-m-d 00:00:00', strtotime( $value[0] ) );
					$prepare_args[] = gmdate( 'Y-m-d 23:59:59', strtotime( $value[1] ) );
				}
				break;
		}

		return [ $clause, $prepare_args ];
	}

	/**
	 * Get distinct hook names for Newspack ActionScheduler actions.
	 *
	 * @return string[] Array of hook name strings.
	 */
	public static function get_hooks() {
		if ( ! self::is_available() ) {
			return [];
		}

		$cache_key = 'newspack_as_hooks';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$query = self::build_query( [] );
		if ( ! $query ) {
			return [];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT DISTINCT a.hook FROM {$query['from']} {$query['where']}ORDER BY a.hook ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hooks = $wpdb->get_col( $wpdb->prepare( $sql, ...$query['prepare_args'] ) );

		set_transient( $cache_key, $hooks, 5 * MINUTE_IN_SECONDS );

		return $hooks;
	}

	/**
	 * Fetch a single ActionScheduler action by ID.
	 *
	 * Returns null when AS resolves the ID to a NullAction (action does not exist).
	 *
	 * @param int $action_id The action ID.
	 *
	 * @return \ActionScheduler_Action|null
	 */
	public static function get_action( $action_id ): ?\ActionScheduler_Action {
		if ( ! self::is_available() ) {
			return null;
		}
		$action = \ActionScheduler_Store::instance()->fetch_action( (int) $action_id );
		if ( ! $action || $action instanceof \ActionScheduler_NullAction ) {
			return null;
		}
		return $action;
	}

	/**
	 * Fetch per-action log entries for a single ActionScheduler action.
	 *
	 * Each entry is normalized to:
	 *   [ 'date_gmt' => string, 'message' => string ]
	 *
	 * NullLogEntry sentinels are filtered out. Entries are sorted ascending
	 * by date. ActionScheduler_LogEntry does not expose the log-row PK, so
	 * consumers use the array index as a stable key.
	 *
	 * @param int $action_id The action ID.
	 *
	 * @return array<int,array{date_gmt:string,message:string}>
	 */
	public static function get_action_logs( $action_id ): array {
		if ( ! self::is_available() || ! class_exists( '\ActionScheduler_Logger' ) ) {
			return [];
		}
		$entries = \ActionScheduler_Logger::instance()->get_logs( (int) $action_id );
		if ( empty( $entries ) ) {
			return [];
		}
		$normalized = [];
		foreach ( $entries as $entry ) {
			if ( $entry instanceof \ActionScheduler_NullLogEntry ) {
				continue;
			}
			$date         = $entry->get_date();
			$normalized[] = [
				'date_gmt' => $date ? $date->format( 'Y-m-d\TH:i:s' ) : '',
				'message'  => (string) $entry->get_message(),
			];
		}
		usort(
			$normalized,
			static function ( $a, $b ) {
				return strcmp( $a['date_gmt'], $b['date_gmt'] );
			}
		);
		return $normalized;
	}
}
