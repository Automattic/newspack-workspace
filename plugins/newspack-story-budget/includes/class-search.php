<?php
/**
 * Newspack Story Budget - Search functionality.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * Story budget search functionality.
 */
class Search {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Expand search on WP Admin Posts screen.
		add_filter( 'posts_join', [ __CLASS__, 'wp_admin_search_join' ], 10, 2 );
		add_filter( 'posts_where', [ __CLASS__, 'wp_admin_search_where' ], 10, 2 );
		add_filter( 'posts_distinct', [ __CLASS__, 'wp_admin_search_distinct' ], 10, 2 );
	}

	/**
	 * Whether we should apply search fields to the query.
	 *
	 * @param \WP_Query $query The WP_Query object.
	 */
	protected static function should_add_fields_to_wp_admin_search( $query ) {
		global $pagenow;
		$is_story_budget_search = ! empty( $query->query_vars['story_budget_search'] );
		$is_wp_admin_search = is_admin() && 'edit.php' === $pagenow && $query->is_main_query();

		/**
		 * Enables search on custom fields in the WP Admin posts screen.
		 *
		 * This can slow down sites with too many posts.
		 */
		if ( ( ! defined( 'NEWSPACK_STORY_BUDGET_ENABLE_SEARCH_META' ) || ! NEWSPACK_STORY_BUDGET_ENABLE_SEARCH_META ) && $is_wp_admin_search ) {
			return false;
		}

		return ! empty( $query->query_vars['s'] ) && ( $is_story_budget_search || $is_wp_admin_search );
	}

	/**
	 * Filters the JOIN clause to add search fields to the query.
	 *
	 * @param string    $join The JOIN clause.
	 * @param \WP_Query $query The WP_Query object.
	 */
	public static function wp_admin_search_join( $join, $query ) {
		global $wpdb;
		if ( self::should_add_fields_to_wp_admin_search( $query ) ) {
			$join .= " LEFT JOIN $wpdb->postmeta ON $wpdb->posts.ID = $wpdb->postmeta.post_id ";
		}
		return $join;
	}

	/**
	 * Filters the WHERE clause to add search fields to the query.
	 *
	 * @param string    $where The WHERE clause.
	 * @param \WP_Query $query The WP_Query object.
	 */
	public static function wp_admin_search_where( $where, $query ) {
		global $wpdb;
		if ( ! self::should_add_fields_to_wp_admin_search( $query ) ) {
			return $where;
		}

		$fields = Fields::get_all_fields();
		$meta_keys = [];
		foreach ( $fields as $field ) {
			if ( ! $field->is_searchable() ) {
				continue;
			}
			$meta_keys[] = "'" . $field->get_post_meta_name() . "'";
		}

		if ( empty( $meta_keys ) ) {
			return $where;
		}

		$meta_search = "($wpdb->postmeta.meta_key IN (" . implode( ',', $meta_keys ) .
						") AND $wpdb->postmeta.meta_value LIKE '%" . esc_sql( $query->query_vars['s'] ) . "%') OR ";

		// Insert our condition just before the post_title LIKE condition.
		$pattern = '/\(\s*(' . preg_quote( $wpdb->posts, '/' ) . '\.post_title LIKE)/';
		$replacement = '(' . $meta_search . '$1';

		return preg_replace( $pattern, $replacement, $where );
	}

	/**
	 * Filters the DISTINCT clause to add search fields to the query.
	 *
	 * @param string    $distinct The DISTINCT clause.
	 * @param \WP_Query $query The WP_Query object.
	 */
	public static function wp_admin_search_distinct( $distinct, $query ) {
		if ( self::should_add_fields_to_wp_admin_search( $query ) ) {
			return 'DISTINCT';
		}
		return $distinct;
	}
}
