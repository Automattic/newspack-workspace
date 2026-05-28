<?php
/**
 * Agent Access content tracker — tags posts and media created via Agent Access.
 *
 * @package Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Tracker {

	const META_KEY = '_agent_access_created';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_after_insert_post', array( $this, 'maybe_tag_post' ), 10, 2 );
		add_action( 'add_attachment', array( $this, 'maybe_tag_attachment' ), 10, 1 );
	}

	/**
	 * Tag a post/page if created via Agent Access Application Password.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 */
	public function maybe_tag_post( $post_id, $post ) {
		// Skip revisions and attachments (attachments handled separately)
		if ( wp_is_post_revision( $post_id ) || 'attachment' === $post->post_type ) {
			return;
		}

		if ( null !== Agent_Access_Activity_Log::detect_source() ) {
			update_post_meta( $post_id, self::META_KEY, time() );
			wp_cache_delete( 'agent_access_stats_' . get_current_user_id(), 'agent_access' );
		}
	}

	/**
	 * Tag an attachment if uploaded via a tracked agent source.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public function maybe_tag_attachment( $attachment_id ) {
		if ( null !== Agent_Access_Activity_Log::detect_source() ) {
			update_post_meta( $attachment_id, self::META_KEY, time() );
			wp_cache_delete( 'agent_access_stats_' . get_current_user_id(), 'agent_access' );
		}
	}

	/**
	 * Get stats for content created via Agent Access for a specific user.
	 *
	 * @param int $user_id The user ID.
	 * @return array{post_count: int, media_count: int, recent_posts: array}
	 */
	public static function get_stats( $user_id ) {
		$cache_key = 'agent_access_stats_' . $user_id;
		$cached    = wp_cache_get( $cache_key, 'agent_access' );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// Count posts (not attachments) tagged by Agent Access.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$post_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_author = %d
			 AND pm.meta_key = %s
			 AND p.post_type IN ('post', 'page')
			 AND p.post_status != 'trash'",
			$user_id,
			self::META_KEY
		) );

		// Count media tagged by Agent Access.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$media_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_author = %d
			 AND pm.meta_key = %s
			 AND p.post_type = 'attachment'",
			$user_id,
			self::META_KEY
		) );

		// Recent posts (last 5).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$recent_posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_date, p.post_status, p.post_type
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_author = %d
			 AND pm.meta_key = %s
			 AND p.post_type IN ('post', 'page')
			 AND p.post_status != 'trash'
			 ORDER BY p.post_date DESC
			 LIMIT 5",
			$user_id,
			self::META_KEY
		) );

		$stats = array(
			'post_count'   => $post_count,
			'media_count'  => $media_count,
			'recent_posts' => $recent_posts,
		);

		wp_cache_set( $cache_key, $stats, 'agent_access', 300 );

		return $stats;
	}

	/**
	 * Get paginated content created via Agent Access for a specific user.
	 *
	 * @param int   $user_id  The user ID.
	 * @param int   $limit    Results per page.
	 * @param int   $offset   Offset.
	 * @param string $post_type Optional post type filter ('post', 'page', 'attachment', or '' for all).
	 * @return array{items: array, total: int}
	 */
	/**
	 * Get paginated content created via Agent Access for a specific user.
	 *
	 * @param int    $user_id   The user ID.
	 * @param int    $limit     Results per page.
	 * @param int    $offset    Offset.
	 * @param string $post_type Optional post type filter ('post', 'page', 'attachment', or '' for all).
	 * @return array{items: array, total: int}
	 */
	public static function get_user_content_paged( $user_id, $limit = 10, $offset = 0, $post_type = '' ) {
		global $wpdb;

		// Build complete SQL with all placeholders before calling prepare().
		$type_sql      = $post_type ? ' AND p.post_type = %s' : " AND p.post_type IN ('post', 'page', 'attachment')";
		$count_sql     = "SELECT COUNT(*) FROM {$wpdb->posts} p
		                  INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
		                  WHERE p.post_author = %d AND pm.meta_key = %s AND p.post_status != 'trash'" . $type_sql;
		$select_sql    = "SELECT p.ID, p.post_title, p.post_date, p.post_status, p.post_type
		                  FROM {$wpdb->posts} p
		                  INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
		                  WHERE p.post_author = %d AND pm.meta_key = %s AND p.post_status != 'trash'" . $type_sql . '
		                  ORDER BY p.post_date DESC LIMIT %d OFFSET %d';

		$base_params   = array( (int) $user_id, self::META_KEY );
		$type_params   = $post_type ? array( $post_type ) : array();
		$count_params  = array_merge( $base_params, $type_params );
		$select_params = array_merge( $base_params, $type_params, array( $limit, $offset ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) );
		$items = $wpdb->get_results( $wpdb->prepare( $select_sql, $select_params ) ) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Count all Agent Access content across all users.
	 *
	 * @param array $args Optional filters: user_id, post_type.
	 * @return int
	 */
	public static function count_all_content( $args = array() ) {
		global $wpdb;

		$cache_key = 'agent_access_content_count_' . md5( wp_json_encode( $args ) );
		$cached    = wp_cache_get( $cache_key, 'agent_access' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		list( $sql, $params ) = self::build_all_content_query( 'COUNT(*)', $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		wp_cache_set( $cache_key, $count, 'agent_access', 300 );

		return $count;
	}

	/**
	 * Get all Agent Access content across all users (paginated).
	 *
	 * @param array $args Optional: user_id, post_type, limit, offset.
	 * @return array
	 */
	public static function get_all_content( $args = array() ) {
		global $wpdb;

		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

		$select = 'p.ID, p.post_title, p.post_date, p.post_status, p.post_type, u.ID as user_id, u.display_name, u.user_login';
		list( $base_sql, $params ) = self::build_all_content_query( $select, $args );

		$sql      = $base_sql . ' ORDER BY p.post_date DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: array();
	}

	/**
	 * Build a complete SELECT/COUNT SQL string and params array for all-content queries.
	 * All dynamic values use %d/%s placeholders — safe for $wpdb->prepare().
	 *
	 * @param string $select_expr Column list or COUNT(*).
	 * @param array  $args        Filter args: user_id, post_type.
	 * @return array{0: string, 1: array} [$sql, $params]
	 */
	private static function build_all_content_query( $select_expr, $args ) {
		global $wpdb;

		$sql    = "SELECT {$select_expr}
		           FROM {$wpdb->posts} p
		           INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
		           INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
		           WHERE pm.meta_key = %s AND p.post_status != 'trash'";
		$params = array( self::META_KEY );

		if ( ! empty( $args['user_id'] ) ) {
			$sql     .= ' AND p.post_author = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( ! empty( $args['post_type'] ) ) {
			$sql     .= ' AND p.post_type = %s';
			$params[] = $args['post_type'];
		} else {
			$sql .= " AND p.post_type IN ('post', 'page', 'attachment')";
		}

		return array( $sql, $params );
	}
}
