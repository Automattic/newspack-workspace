<?php
/**
 * ClawPress content tracker — tags posts and media created via OpenClaw.
 *
 * @package ClawPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClawPress_Tracker {

	const META_KEY = '_clawpress_created';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'rest_after_insert_post', array( $this, 'maybe_tag_post' ), 10, 1 );
		add_action( 'rest_after_insert_page', array( $this, 'maybe_tag_post' ), 10, 1 );
		add_action( 'add_attachment', array( $this, 'maybe_tag_attachment' ), 10, 1 );
	}

	/**
	 * Tag a post/page if created via OpenClaw Application Password.
	 *
	 * @param WP_Post $post The inserted post.
	 */
	public function maybe_tag_post( $post ) {
		if ( $this->is_openclaw_request() ) {
			update_post_meta( $post->ID, self::META_KEY, time() );
		}
	}

	/**
	 * Tag an attachment if uploaded via OpenClaw Application Password.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public function maybe_tag_attachment( $attachment_id ) {
		if ( $this->is_openclaw_request() ) {
			update_post_meta( $attachment_id, self::META_KEY, time() );
		}
	}

	/**
	 * Check if the current request is authenticated via the OpenClaw Application Password.
	 *
	 * @return bool
	 */
	private function is_openclaw_request() {
		// Must be a REST API request
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		// Must be authenticated
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		// Check if authenticated via Application Password
		$app_password_uuid = rest_get_authenticated_app_password();
		if ( empty( $app_password_uuid ) ) {
			return false;
		}

		// Verify it's the OpenClaw app password specifically
		$passwords = WP_Application_Passwords::get_user_application_passwords( $user_id );
		foreach ( $passwords as $item ) {
			if ( $item['uuid'] === $app_password_uuid ) {
				return $item['name'] === CLAWPRESS_APP_PASSWORD_NAME;
			}
		}

		return false;
	}

	/**
	 * Get stats for content created via OpenClaw for a specific user.
	 *
	 * @param int $user_id The user ID.
	 * @return array{post_count: int, media_count: int, recent_posts: array}
	 */
	public static function get_stats( $user_id ) {
		global $wpdb;

		// Count posts (not attachments) tagged by ClawPress
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

		// Count media tagged by ClawPress
		$media_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_author = %d
			 AND pm.meta_key = %s
			 AND p.post_type = 'attachment'",
			$user_id,
			self::META_KEY
		) );

		// Recent posts (last 5)
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

		return array(
			'post_count'   => $post_count,
			'media_count'  => $media_count,
			'recent_posts' => $recent_posts,
		);
	}
}
