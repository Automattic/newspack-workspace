<?php
/**
 * CLI command to delete stale autosaves.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Autosave_Cleanup;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Autosaves CLI commands.
 */
final class Autosaves {

	/**
	 * Delete autosaves that have been stale (overtaken by a later save) for a number of days.
	 *
	 * Fresh autosaves and major revisions are never deleted.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would be deleted without deleting anything.
	 *
	 * [--post=<id>]
	 * : Only consider autosaves of this post.
	 *
	 * [--older-than=<days>]
	 * : Days an autosave must have been stale. Defaults to the cron setting (1). Use 0 for every stale autosave.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cmd_prune( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );

		$post_id = 0;
		if ( isset( $assoc_args['post'] ) ) {
			$post_id = absint( $assoc_args['post'] );
			if ( ! $post_id || ! get_post( $post_id ) ) {
				WP_CLI::error( sprintf( 'Post %s not found.', $assoc_args['post'] ) );
			}
		}

		$days = Autosave_Cleanup::get_days();
		if ( isset( $assoc_args['older-than'] ) ) {
			if ( ! ctype_digit( (string) $assoc_args['older-than'] ) ) {
				WP_CLI::error( '--older-than must be a whole number of days, 0 or more.' );
			}
			$days = (int) $assoc_args['older-than'];
		}

		$before_id = 0;
		$count    = 0;
		while ( true ) {
			$ids = Autosave_Cleanup::get_eligible_ids( $days, Autosave_Cleanup::BATCH_SIZE, $post_id, $before_id );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				$autosave = get_post( $id );
				// Already deleted, e.g. by the cron running at the same time.
				if ( ! $autosave ) {
					continue;
				}
				WP_CLI::log(
					sprintf(
						'%s autosave %d (post %d, user %d, saved %s GMT)',
						$dry_run ? 'Would delete' : 'Deleting',
						$id,
						$autosave->post_parent,
						$autosave->post_author,
						$autosave->post_modified_gmt
					)
				);
			}
			$count   += $dry_run ? count( $ids ) : count( Autosave_Cleanup::delete_autosaves( $ids ) );
			$before_id = end( $ids );

			// Pause between full batches of deletions to ease database load on a large backlog.
			if ( ! $dry_run && count( $ids ) === Autosave_Cleanup::BATCH_SIZE ) {
				usleep( 500000 );
			}
		}

		WP_CLI::success(
			$dry_run
				? sprintf( '%d stale autosaves would be deleted.', $count )
				: sprintf( 'Deleted %d stale autosaves.', $count )
		);
	}
}
