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
	const PASS_OPTION    = 'newspack_revision_cleanup_pass_deleted';
	const LOGGER_HEADER  = 'NEWSPACK-REVISION-CLEANUP';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'cron_init' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_cron' ] );
		add_filter( 'wp_save_post_revision_revisions_before_deletion', [ __CLASS__, 'cap_deletions_on_save' ], 10, 2 );
		// A new or lowered limit can create a backlog, so go back to hourly runs.
		add_action( 'add_option_newspack_revisions_control', [ __CLASS__, 'speed_up' ] );
		add_action( 'update_option_newspack_revisions_control', [ __CLASS__, 'speed_up' ] );
	}

	/**
	 * Schedule the cleanup, hourly to start, unless disabled with NEWSPACK_CRON_DISABLE.
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
		if ( self::is_disabled() ) {
			self::cron_deactivate();
		} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Whether the cleanup is disabled with NEWSPACK_CRON_DISABLE.
	 *
	 * @return bool
	 */
	private static function is_disabled(): bool {
		return defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CRON_HOOK, NEWSPACK_CRON_DISABLE, true );
	}

	/**
	 * Unschedule the cleanup.
	 */
	public static function cron_deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		// This runs on every request while the cron is disabled; get_option() caches a missing option, delete_option() doesn't.
		foreach ( [ self::CURSOR_OPTION, self::PASS_OPTION ] as $option ) {
			if ( false !== get_option( $option ) ) {
				delete_option( $option );
			}
		}
	}

	/**
	 * Run the cleanup hourly.
	 */
	public static function speed_up(): void {
		self::set_recurrence( 'hourly' );
	}

	/**
	 * Reschedule the cleanup if its recurrence changes. The first run waits one interval.
	 *
	 * @param string $recurrence 'hourly' or 'daily'.
	 */
	private static function set_recurrence( string $recurrence ): void {
		if ( self::is_disabled() || wp_get_schedule( self::CRON_HOOK ) === $recurrence ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( time() + ( 'daily' === $recurrence ? DAY_IN_SECONDS : HOUR_IN_SECONDS ), $recurrence, self::CRON_HOOK );
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
	 * Runs hourly while there's a backlog. Once a full pass over the posts
	 * deletes nothing, it runs daily, until a run deletes something again.
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
		// Past the last candidate, the pass is done and the next run starts over.
		$pass_done = count( $candidates ) < self::MAX_CANDIDATES;
		$next      = $pass_done ? 0 : (int) end( $candidates );
		foreach ( $candidates as $post_id ) {
			$deleted += count( self::delete_revisions( self::get_excess_ids( $post_id, $max - $deleted ) ) );
			if ( $deleted >= $max ) {
				// This post may still be over the limit, so the next run starts with it.
				$pass_done = false;
				$next      = $post_id - 1;
				break;
			}
		}
		update_option( self::CURSOR_OPTION, $next, false );

		$pass_deleted = (int) get_option( self::PASS_OPTION, 0 ) + $deleted;
		if ( $pass_done ) {
			self::set_recurrence( $pass_deleted ? 'hourly' : 'daily' );
			$pass_deleted = 0;
		} elseif ( $deleted ) {
			self::set_recurrence( 'hourly' );
		}
		update_option( self::PASS_OPTION, $pass_deleted, false );

		if ( $deleted ) {
			Logger::log( sprintf( 'Deleted %d revisions over the limit.', $deleted ), self::LOGGER_HEADER );
		}

		return $deleted;
	}

	/**
	 * Get posts that may have more revisions than the site's revision limit.
	 * Nothing is returned when the limit is off, unlimited or 0.
	 *
	 * This counts every child of a post (attachments and autosaves too), which
	 * the post_parent index answers without reading any rows; counting only
	 * revisions has to read every revision row, which takes seconds on a large
	 * site. get_excess_ids() does the exact count for each post.
	 *
	 * The threshold is the site-wide limit. A post type filtered to a higher
	 * limit is still trimmed only to its own limit; one filtered lower is only
	 * trimmed below the site-wide limit when it's saved.
	 *
	 * @param int $after_id Only return post IDs greater than this.
	 * @param int $post_id  Limit to one post, 0 for all.
	 * @return int[] Post IDs, ascending.
	 */
	public static function get_candidates( int $after_id = 0, int $post_id = 0 ): array {
		global $wpdb;

		$limit = Revisions_Control::get_number();
		if ( ! Revisions_Control::is_active() || $limit < 1 ) {
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
		// WordPress treats a limit of 0 as revisions disabled and never trims, so neither does the cron.
		if ( $keep < 1 ) {
			return [];
		}

		// Unlike a save, autosaves don't count toward the limit here; this keeps at most one more regular revision, so the two never fight.
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
			// Mark the partial row as raw so get_post() uses it instead of loading the full row.
			// The SELECT has every field is_deletable() reads; unselected fields fall back to WP_Post defaults.
			$row->filter = 'raw';
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
