<?php
/**
 * Newspack Story Budget Budgets
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * Budgets Class.
 */
class Budgets {

	/**
	 * Taxonomy name.
	 */
	const TAXONOMY = 'newspack_story_budget';

	/**
	 * Cron hook name for auto-archiving budgets.
	 */
	const AUTO_ARCHIVE_CRON_HOOK = 'newspack_story_budget_auto_archive_budgets';

	/**
	 * Stories query object.
	 *
	 * @var \WP_Query
	 */
	public static $stories_query;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ], 5 ); // Before the fields are initialized.

		// Auto-archive cron.
		add_action( 'init', [ __CLASS__, 'register_cron_jobs' ] );
		add_action( self::AUTO_ARCHIVE_CRON_HOOK, [ __CLASS__, 'process_auto_archive_budgets' ] );
		register_deactivation_hook( NEWSPACK_STORY_BUDGET_PLUGIN_FILE, [ __CLASS__, 'clear_cron_jobs' ] );
	}

	/**
	 * Register taxonomy.
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			self::get_post_types(),
			[
				'labels' => [
					'name'          => __( 'Story Budgets', 'newspack-story-budget' ),
					'singular_name' => __( 'Story Budget', 'newspack-story-budget' ),
					'edit_item'     => __( 'Edit Story Budget', 'newspack-story-budget' ),
					'add_new_item'  => __( 'Add New Story Budget', 'newspack-story-budget' ),
					'all_items'     => __( 'All Budgets', 'newspack-story-budget' ),
					'no_items'      => __( 'No Budgets', 'newspack-story-budget' ),
				],
				'public' => false,
			]
		);
	}

	/**
	 * Get post types allowed to be stories in a budget.
	 *
	 * @return string[]
	 */
	public static function get_post_types() {
		/**
		 * Filters the post types allowed to be stories in a budget.
		 */
		return apply_filters( 'newspack_story_budget_post_types', [ 'post' ] );
	}

	/**
	 * Get budgets.
	 *
	 * @param bool $include_archived Whether to include archived budgets.
	 *
	 * @return Budget[]
	 */
	public static function get_budgets( $include_archived = false ) {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			]
		);

		$budgets = array_map(
			function( $term ) {
				return new Budget( $term );
			},
			$terms
		);

		// Sort budgets by the `order` term meta.
		usort(
			$budgets,
			function( $a, $b ) {
				// If both have no order, maintain original order.
				if ( empty( $a->order ) && empty( $b->order ) ) {
					return 0;
				}

				// If one has no order, it goes to the bottom.
				if ( empty( $a->order ) ) {
					return 1;
				}
				if ( empty( $b->order ) ) {
					return -1;
				}

				return absint( $a->order ) - absint( $b->order );
			}
		);

		if ( ! $include_archived ) {
			$budgets = array_filter(
				$budgets,
				function( $budget ) {
					return ! $budget->archived;
				}
			);
		}

		return array_values( $budgets );
	}

	/**
	 * Get stories from all active budgets.
	 *
	 * @param array $query_args WP_Query arguments.
	 * @param int   $budget_id  Optional. Budget ID to limit stories to.
	 *
	 * @return Stories|int[] Array of Story objects or post IDs.
	 */
	public static function get_stories( $query_args = [], $budget_id = null ) {
		$budget_ids = $budget_id ? [ $budget_id ] : array_map(
			function( $budget ) {
				return $budget->id;
			},
			self::get_budgets()
		);

		$tax_param = [
			'taxonomy' => self::TAXONOMY,
			'field'    => 'term_id',
			'terms'    => $budget_ids,
			'operator' => 'IN',
		];

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		if ( isset( $query_args['tax_query'] ) ) {
			$query_args['tax_query'][] = $tax_param;

			// Enforce "AND" relation to not query stories outside the budget.
			if ( ! empty( $query_args['tax_query']['relation'] ) && 'AND' !== $query_args['tax_query']['relation'] ) {
				_doing_it_wrong( __FUNCTION__, 'Stories only support tax query with "AND" relation.', '0.0.0' );
			}
			$query_args['tax_query']['relation'] = 'AND';
		} else {
			$query_args['tax_query'] = [ $tax_param ];
		}
		// phpcs:enable

		$query_args = wp_parse_args(
			$query_args,
			[
				'post_type'      => self::get_post_types(),
				'post_status'    => [ 'publish', 'pending', 'draft', 'future', 'private' ],
				'posts_per_page' => -1,
			]
		);

		self::$stories_query = new \WP_Query( $query_args );

		if ( ! empty( $query_args['fields'] ) && $query_args['fields'] === 'ids' ) {
			return self::$stories_query->posts;
		}

		return array_map(
			function( $post ) {
				return new Story( $post );
			},
			self::$stories_query->posts
		);
	}

	/**
	 * Whether a story can be assigned to multiple budgets.
	 *
	 * @return bool
	 */
	public static function is_multiple_budgets_enabled() {
		/**
		 * Filters whether a story can be assigned to multiple budgets.
		 */
		return apply_filters( 'newspack_story_budget_multiple_budgets_enabled', false );
	}

	/**
	 * Update the order of active budgets.
	 *
	 * @param int[] $budget_ids Ordered list of budget IDs.
	 */
	public static function update_budgets_order( $budget_ids ) {
		foreach ( $budget_ids as $index => $budget_id ) {
			$order = $index + 1;
			update_term_meta( $budget_id, Budget::ORDER_META_KEY, $order );

			$budget        = new Budget( $budget_id );
			$budget->order = $order;
		}
	}

	/**
	 * Register the daily cron job for auto-archiving budgets.
	 */
	public static function register_cron_jobs() {
		if ( ! wp_next_scheduled( self::AUTO_ARCHIVE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::AUTO_ARCHIVE_CRON_HOOK );
		}
	}

	/**
	 * Clear the scheduled cron event.
	 */
	public static function clear_cron_jobs() {
		$timestamp = wp_next_scheduled( self::AUTO_ARCHIVE_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::AUTO_ARCHIVE_CRON_HOOK );
		}
	}

	/**
	 * Process auto-archiving of budgets.
	 * This method is called by the cron job.
	 */
	public static function process_auto_archive_budgets() {
		$args = array(
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'     => Budget::ARCHIVE_META_KEY,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Budget::ARCHIVE_DATE_META_KEY,
					'compare' => '<=',
					'value'   => gmdate( 'c' ),
					'type'    => 'DATE',
				),
			),
		);

		$budget_terms = get_terms( $args );

		if ( is_wp_error( $budget_terms ) || empty( $budget_terms ) ) {
			return;
		}

		$archived_count = 0;
		foreach ( $budget_terms as $term ) {
			$budget = new Budget( $term );
			if ( $budget->is_valid() ) {
				$budget->archive();
				$archived_count++;
			}
		}

		return $archived_count;
	}
}
