<?php
/**
 * Rollback — capture before-state of agent writes and provide one-click restore.
 *
 * How it works:
 *   1. rest_pre_dispatch (priority 8) intercepts agent PUT/PATCH/DELETE on
 *      /wp/v2/posts/* and /wp/v2/media/*.
 *   2. Before the request executes, the current post/attachment state is
 *      snapshotted into wp_agent_access_snapshots.
 *   3. The Revisions tab in the Agent Access Tools page lists recent snapshots
 *      with a "Restore" button.
 *   4. Restore calls wp_update_post() with the saved fields and logs an audit event.
 *
 * Snapshot fields captured per post: title, content, excerpt, status, date.
 * Snapshot fields captured per attachment: title, caption (excerpt), alt text (meta).
 *
 * Retention: snapshots older than RETAIN_DAYS are pruned on each capture (opportunistic).
 *
 * @package BotCreds Agent Access
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Rollback {

	const TABLE_SUFFIX = 'agent_access_snapshots';
	const RETAIN_DAYS  = 30;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'capture' ), 8, 3 );
		add_action( 'wp_ajax_agent_access_restore', array( __CLASS__, 'handle_restore_ajax' ) );
	}

	// ──────────────────────────────────────────────
	// Capture (before-state snapshot)
	// ──────────────────────────────────────────────

	/**
	 * Snapshot a post/attachment before an agent modifies or deletes it.
	 *
	 * Only fires on PUT / PATCH / DELETE targeting /wp/v2/posts/{id}
	 * or /wp/v2/media/{id}.  POST (create) has no before-state to capture.
	 *
	 * @param mixed            $result
	 * @param WP_REST_Server   $server
	 * @param WP_REST_Request  $request
	 * @return mixed  Always returns $result unchanged.
	 */
	public static function capture( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result; // Upstream short-circuited.
		}

		$method = strtoupper( $request->get_method() );
		if ( ! in_array( $method, array( 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return $result; // POST (create) has no before-state.
		}

		// Only govern Agent Access credentials.
		$source = Agent_Access_Activity_Log::detect_source();
		if ( null === $source || Agent_Access_Activity_Log::SOURCE_AA !== $source['source'] ) {
			return $result;
		}

		$uuid = function_exists( 'rest_get_authenticated_app_password' )
			? rest_get_authenticated_app_password()
			: null;

		if ( ! $uuid ) {
			return $result;
		}

		// Match /wp/v2/posts/{id} or /wp/v2/media/{id}.
		$route = $request->get_route();
		if ( ! preg_match( '#^/wp/v2/(posts|media)/(\d+)$#', $route, $m ) ) {
			return $result;
		}

		$kind    = $m[1]; // 'posts' or 'media'
		$post_id = (int) $m[2];

		if ( $post_id < 1 ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}

		self::save_snapshot( $post, $uuid, $kind );
		self::prune_old_snapshots(); // Opportunistic cleanup.

		return $result;
	}

	// ──────────────────────────────────────────────
	// Snapshot storage
	// ──────────────────────────────────────────────

	/**
	 * Write a snapshot row for a post.
	 *
	 * @param WP_Post $post
	 * @param string  $uuid  App password UUID.
	 * @param string  $kind  'posts' | 'media'
	 */
	private static function save_snapshot( $post, $uuid, $kind ) {
		global $wpdb;

		$alt_text = ( 'media' === $kind )
			? (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true )
			: '';

		$payload = wp_json_encode( array(
			'kind'         => $kind,
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'post_date'    => $post->post_date,
			'alt_text'     => $alt_text,
		) );

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'post_id'      => $post->ID,
				'user_id'      => get_current_user_id(),
				'uuid_hash'    => substr( md5( $uuid ), 0, 16 ),
				'method'       => strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'before_state' => $payload,
				'captured_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Delete snapshots older than RETAIN_DAYS.
	 */
	private static function prune_old_snapshots() {
		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE_SUFFIX;
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::RETAIN_DAYS . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$table}` WHERE captured_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cutoff
		) );
	}

	// ──────────────────────────────────────────────
	// Restore (AJAX)
	// ──────────────────────────────────────────────

	/**
	 * Restore a post to a snapshotted before-state.
	 * Expects: snapshot_id, nonce (agent_access_restore_{id}).
	 */
	public static function handle_restore_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'botcreds-agent-access' ) );
		}

		$snapshot_id = isset( $_POST['snapshot_id'] ) ? (int) $_POST['snapshot_id'] : 0;
		check_ajax_referer( 'agent_access_restore_' . $snapshot_id, 'nonce' );

		if ( ! $snapshot_id ) {
			wp_send_json_error( __( 'Invalid snapshot.', 'botcreds-agent-access' ) );
		}

		$snapshot = self::get_snapshot( $snapshot_id );
		if ( ! $snapshot ) {
			wp_send_json_error( __( 'Snapshot not found.', 'botcreds-agent-access' ) );
		}

		$state = json_decode( $snapshot->before_state, true );
		if ( ! $state ) {
			wp_send_json_error( __( 'Snapshot data is corrupt.', 'botcreds-agent-access' ) );
		}

		$post = get_post( (int) $snapshot->post_id );
		if ( ! $post ) {
			wp_send_json_error( __( 'The post no longer exists.', 'botcreds-agent-access' ) );
		}

		// Restore the post fields.
		$update = array(
			'ID'           => $post->ID,
			'post_title'   => $state['post_title'],
			'post_content' => $state['post_content'],
			'post_excerpt' => $state['post_excerpt'],
			'post_status'  => $state['post_status'],
			'post_date'    => $state['post_date'],
		);

		$result = wp_update_post( $update, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// Restore alt text for attachments.
		if ( 'media' === $state['kind'] && isset( $state['alt_text'] ) ) {
			update_post_meta( $post->ID, '_wp_attachment_image_alt', $state['alt_text'] );
		}

		do_action( 'agent_access_audit', 'content_restored', array(
			'post_id'     => $post->ID,
			'post_title'  => $state['post_title'],
			'restored_by' => wp_get_current_user()->user_login,
			'snapshot_id' => $snapshot_id,
		) );

		wp_send_json_success( array(
			'message'  => __( 'Content restored successfully.', 'botcreds-agent-access' ),
			'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
		) );
	}

	// ──────────────────────────────────────────────
	// Queries
	// ──────────────────────────────────────────────

	/**
	 * Get a single snapshot by ID.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function get_snapshot( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id
		) );
	}

	/**
	 * Get recent snapshots for the admin UI.
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function get_recent_snapshots( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.*, u.user_login FROM `{$table}` s // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			 LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
			 ORDER BY s.captured_at DESC
			 LIMIT %d",
			$limit
		) );

		return $rows ?: array();
	}

	// ──────────────────────────────────────────────
	// DB lifecycle
	// ──────────────────────────────────────────────

	/**
	 * Create the snapshots table on plugin activation.
	 */
	public static function install_table() {
		global $wpdb;
		$table           = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			uuid_hash varchar(16) NOT NULL,
			method varchar(10) NOT NULL DEFAULT 'PATCH',
			before_state longtext NOT NULL,
			captured_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY captured_at (captured_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the snapshots table on plugin uninstall.
	 */
	public static function uninstall_table() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
