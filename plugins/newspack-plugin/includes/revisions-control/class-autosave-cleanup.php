<?php
/**
 * Deletes autosaves that were overtaken by a later save long enough ago that
 * nobody is likely to recover them. The editor never offers a stale autosave
 * back, but still renders every one on load.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Autosave Cleanup class.
 */
class Autosave_Cleanup {

	const DEFAULT_DAYS  = 7;
	const BATCH_SIZE    = 100;
	const MAX_PER_RUN   = 500;
	const CRON_HOOK     = 'newspack_autosave_cleanup';
	const LOGGER_HEADER = 'NEWSPACK-AUTOSAVE-CLEANUP';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'cron_init' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_cron' ] );
	}

	/**
	 * Schedule the daily cleanup, unless disabled with NEWSPACK_CRON_DISABLE.
	 */
	public static function cron_init() {
		register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'cron_deactivate' ] );

		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CRON_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			self::cron_deactivate();
		} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the daily cleanup.
	 */
	public static function cron_deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Delete up to $max eligible autosaves in batches. Autosaves whose deletion
	 * is vetoed by another filter are skipped rather than retried.
	 *
	 * @param int $max Maximum autosaves to delete in this run.
	 * @return int Number deleted.
	 */
	public static function run_cron( $max = self::MAX_PER_RUN ) {
		$days     = self::get_days();
		$after_id = 0;
		$deleted  = 0;

		while ( $deleted < $max ) {
			$ids = self::get_eligible_ids( $days, min( self::BATCH_SIZE, $max - $deleted ), 0, $after_id );
			if ( empty( $ids ) ) {
				break;
			}
			$deleted += count( self::delete_autosaves( $ids ) );
			$after_id = end( $ids );
		}

		if ( $deleted ) {
			Logger::log( sprintf( 'Deleted %d stale autosaves.', $deleted ), self::LOGGER_HEADER );
		}

		return $deleted;
	}

	/**
	 * Number of days an autosave must have been stale before it's deleted.
	 *
	 * @return int
	 */
	public static function get_days() {
		/**
		 * Days an autosave must have been stale (overtaken by a later save)
		 * before the daily cleanup deletes it.
		 *
		 * @constant NEWSPACK_AUTOSAVE_CLEANUP_DAYS
		 * @type     int
		 * @default  7
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_AUTOSAVE_CLEANUP_DAYS', 30 );
		 */
		if ( defined( 'NEWSPACK_AUTOSAVE_CLEANUP_DAYS' ) && is_int( NEWSPACK_AUTOSAVE_CLEANUP_DAYS ) && NEWSPACK_AUTOSAVE_CLEANUP_DAYS > 0 ) {
			return NEWSPACK_AUTOSAVE_CLEANUP_DAYS;
		}
		return self::DEFAULT_DAYS;
	}

	/**
	 * Get IDs of autosaves that have been stale for at least $days.
	 *
	 * Stale means the parent was saved after the autosave. The wait starts at
	 * the first regular revision after the autosave, so later saves don't
	 * reset it; the parent's modified date is the fallback when revisions are
	 * disabled or pruned. Autosaves marked as major are never returned.
	 *
	 * @param int $days     Minimum days stale.
	 * @param int $limit    Maximum IDs to return.
	 * @param int $post_id  Limit to one parent post, 0 for all.
	 * @param int $after_id Only return IDs greater than this, for paging.
	 * @return int[] Autosave IDs, ascending.
	 */
	public static function get_eligible_ids( $days, $limit, $post_id = 0, $after_id = 0 ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT a.ID
				FROM {$wpdb->posts} a
				JOIN {$wpdb->posts} p ON p.ID = a.post_parent
				WHERE a.post_type = 'revision'
					AND a.post_status = 'inherit'
					AND a.post_modified_gmt < %s
					AND a.post_name = CONCAT( p.ID, '-autosave-v1' )
					AND a.post_modified_gmt < p.post_modified_gmt
					AND (
						p.post_modified_gmt < %s
						OR EXISTS (
							SELECT 1 FROM {$wpdb->posts} r
							WHERE r.post_parent = p.ID
								AND r.post_type = 'revision'
								AND r.post_name NOT LIKE %s
								AND r.post_date_gmt > a.post_modified_gmt
								AND r.post_date_gmt < %s
						)
					)
					AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} m
						WHERE m.post_id = p.ID
							AND m.meta_key = '_major_revision'
							AND m.meta_value = CAST( a.ID AS CHAR )
					)
					AND ( 0 = %d OR p.ID = %d )
					AND a.ID > %d
				ORDER BY a.ID
				LIMIT %d",
				$cutoff,
				$cutoff,
				'%' . $wpdb->esc_like( '-autosave-v' ) . '%',
				$cutoff,
				$post_id,
				$post_id,
				$after_id,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Delete autosaves by ID. Anything that isn't an autosave is skipped.
	 *
	 * @param int[] $ids Autosave IDs.
	 * @return int[] IDs that were deleted.
	 */
	public static function delete_autosaves( $ids ) {
		$deleted = [];
		foreach ( $ids as $id ) {
			if ( ! wp_is_post_autosave( $id ) ) {
				continue;
			}
			// A pre_delete_post veto can return any non-null value, including true.
			if ( wp_delete_post_revision( $id ) instanceof \WP_Post ) {
				$deleted[] = (int) $id;
			}
		}
		return $deleted;
	}
}

Autosave_Cleanup::init();
