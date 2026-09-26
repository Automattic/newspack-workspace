<?php
/**
 * Trims posts with more revisions than the revision limit a few at a time:
 * each save deletes at most a handful, and an hourly cron works through the
 * rest. Deleting hundreds in one save makes that save take several seconds.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Revision Cleanup class.
 */
final class Revision_Cleanup {

	const MAX_PER_SAVE   = 10;
	const MAX_PER_RUN    = 500;
	const MAX_CANDIDATES = 200;
	const CRON_HOOK      = 'newspack_revision_cleanup';
	const CURSOR_OPTION  = 'newspack_revision_cleanup_cursor';
	const LOGGER_HEADER  = 'NEWSPACK-REVISION-CLEANUP';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'cron_init' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_cron' ] );
		add_filter( 'wp_save_post_revision_revisions_before_deletion', [ __CLASS__, 'cap_deletions_on_save' ], 10, 2 );
	}

	/**
	 * Schedule the hourly cleanup, unless disabled with NEWSPACK_CRON_DISABLE.
	 */
	public static function cron_init(): void {
		register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'cron_deactivate' ] );

		/**
		 * Array of cron hook names to disable. Use this to selectively
		 * disable Newspack cron jobs on specific environments.
		 *
		 * @constant NEWSPACK_CRON_DISABLE
		 * @type     array
		 * @default  All cron jobs enabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_CRON_DISABLE', [ 'newspack_revision_cleanup' ] );
		 */
		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CRON_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			self::cron_deactivate();
		} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the hourly cleanup.
	 */
	public static function cron_deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::CURSOR_OPTION );
	}

	/**
	 * Limit how many revisions one save deletes. WordPress deletes the oldest
	 * count( $revisions ) - $keep of the list this filter returns, so hand
	 * back the oldest deletable few followed by the ones it keeps.
	 *
	 * @param WP_Post[] $revisions Revisions of the post, oldest first.
	 * @param int       $post_id   Post ID.
	 * @return WP_Post[]
	 */
	public static function cap_deletions_on_save( $revisions, $post_id ) {
		if ( ! is_array( $revisions ) || ! Revisions_Control::is_active() ) {
			return $revisions;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $revisions;
		}
		$revisions = array_values( $revisions );
		$keep      = wp_revisions_to_keep( $post );
		$excess    = count( $revisions ) - $keep;
		if ( $keep < 0 || $excess <= self::MAX_PER_SAVE ) {
			return $revisions;
		}

		$delete = [];
		foreach ( array_slice( $revisions, 0, $excess ) as $revision ) {
			if ( self::is_deletable( $revision ) ) {
				$delete[] = $revision;
				if ( count( $delete ) === self::MAX_PER_SAVE ) {
					break;
				}
			}
		}
		return array_merge( $delete, array_slice( $revisions, $excess ) );
	}

	/**
	 * Delete up to $max revisions beyond the limit. Each run picks up from the
	 * post where the last one stopped and starts over once it reaches the end,
	 * so every post is reached in turn. Vetoed deletions are skipped.
	 *
	 * @param int $max Maximum revisions to delete in this run.
	 * @return int Number deleted.
	 */
	public static function run_cron( int $max = self::MAX_PER_RUN ): int {
		$cursor     = (int) get_option( self::CURSOR_OPTION, 0 );
		$candidates = self::get_candidates( $cursor );
		if ( empty( $candidates ) && $cursor ) {
			$cursor     = 0;
			$candidates = self::get_candidates( 0 );
		}

		$deleted = 0;
		// Past the last candidate, the next run starts over.
		$next = count( $candidates ) < self::MAX_CANDIDATES ? 0 : (int) end( $candidates );
		foreach ( $candidates as $post_id ) {
			$deleted += count( self::delete_revisions( self::get_excess_ids( $post_id, $max - $deleted ) ) );
			if ( $deleted >= $max ) {
				// This post may still be over the limit, so the next run starts with it.
				$next = $post_id - 1;
				break;
			}
		}
		update_option( self::CURSOR_OPTION, $next, false );

		if ( $deleted ) {
			Logger::log( sprintf( 'Deleted %d revisions over the limit.', $deleted ), self::LOGGER_HEADER );
		}

		return $deleted;
	}

	/**
	 * Get posts that may have more revisions than the site's revision limit.
	 * Nothing is returned when the limit is off or unlimited.
	 *
	 * This counts every child of a post (attachments and autosaves too), which
	 * the post_parent index answers without reading any rows; counting only
	 * revisions has to read every revision row, which takes seconds on a large
	 * site. get_excess_ids() does the exact count for each post.
	 *
	 * @param int $after_id Only return post IDs greater than this.
	 * @param int $post_id  Limit to one post, 0 for all.
	 * @return int[] Post IDs, ascending.
	 */
	public static function get_candidates( int $after_id = 0, int $post_id = 0 ): array {
		global $wpdb;

		$limit = Revisions_Control::get_number();
		if ( ! Revisions_Control::is_active() || $limit < 0 ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_parent
				FROM {$wpdb->posts}
				WHERE post_parent > %d
					AND ( 0 = %d OR post_parent = %d )
				GROUP BY post_parent
				HAVING COUNT(*) > %d
				ORDER BY post_parent ASC
				LIMIT %d",
				$after_id,
				$post_id,
				$post_id,
				(int) $limit,
				self::MAX_CANDIDATES
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Get the oldest regular revisions of a post beyond its limit, skipping
	 * any the limit protects (too recent, or marked as major).
	 *
	 * @param int $post_id Post ID.
	 * @param int $max     Maximum IDs to return.
	 * @return int[] Revision IDs, oldest first.
	 */
	public static function get_excess_ids( int $post_id, int $max ): array {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post || $max < 1 ) {
			return [];
		}
		$keep = wp_revisions_to_keep( $post );
		if ( $keep < 0 ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent, post_type, post_name, post_date
				FROM {$wpdb->posts}
				WHERE post_parent = %d
					AND post_type = 'revision'
					AND post_name NOT LIKE %s
				ORDER BY post_date ASC, ID ASC",
				$post_id,
				'%' . $wpdb->esc_like( 'autosave' ) . '%'
			)
		);

		$ids = [];
		foreach ( array_slice( $rows, 0, max( 0, count( $rows ) - $keep ) ) as $row ) {
			if ( self::is_deletable( new WP_Post( $row ) ) ) {
				$ids[] = (int) $row->ID;
				if ( count( $ids ) === $max ) {
					break;
				}
			}
		}
		return $ids;
	}

	/**
	 * Delete revisions by ID. Autosaves and anything that isn't a revision are skipped.
	 *
	 * @param int[] $ids Revision IDs.
	 * @return int[] IDs that were deleted.
	 */
	public static function delete_revisions( array $ids ): array {
		$deleted = [];
		foreach ( $ids as $id ) {
			$revision = get_post( $id );
			if ( ! $revision || 'revision' !== $revision->post_type || str_contains( $revision->post_name, 'autosave' ) ) {
				continue;
			}
			// A pre_delete_post veto can return any non-null value, including true.
			if ( wp_delete_post_revision( $id ) instanceof WP_Post ) {
				$deleted[] = (int) $id;
			}
		}
		return $deleted;
	}

	/**
	 * Whether the limit allows deleting a revision: not an autosave, and not
	 * protected by Revisions_Control (too recent, or marked as major).
	 *
	 * @param WP_Post $revision Revision.
	 * @return bool
	 */
	private static function is_deletable( WP_Post $revision ): bool {
		// Matches how WordPress spots autosaves when trimming revisions.
		if ( str_contains( $revision->post_name, 'autosave' ) ) {
			return false;
		}
		return null === Revisions_Control::pre_delete_revision( null, $revision );
	}
}

Revision_Cleanup::init();
