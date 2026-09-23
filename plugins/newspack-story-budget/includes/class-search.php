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
			$meta_keys[] = $field->get_post_meta_name();
		}

		if ( empty( $meta_keys ) ) {
			return $where;
		}

		// esc_like() so % and _ in the term match literally, as they do in core's title search.
		$meta_search = $wpdb->prepare(
			"($wpdb->postmeta.meta_key IN (" . implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) ) . ") AND $wpdb->postmeta.meta_value LIKE %s) OR ",
			array_merge( $meta_keys, [ '%' . $wpdb->esc_like( $query->query_vars['s'] ) . '%' ] )
		);

		// Insert our condition just before the post_title LIKE condition. A callback, because
		// preg_replace() would treat backslashes and $n in the search term as replacement syntax.
		$pattern = '/\(\s*(' . preg_quote( $wpdb->posts, '/' ) . '\.post_title LIKE)/';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $meta_search ) {
				return '(' . $meta_search . $matches[1];
			},
			$where
		);
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
