<?php
/**
 * CLI command to delete revisions over the revision limit.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Revision_Cleanup;
use Newspack\Revisions_Control;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Revisions CLI commands.
 */
final class Revisions {

	/**
	 * Delete the oldest revisions of posts that have more than the revision limit.
	 *
	 * Revisions newer than the limit's minimum age, major revisions and autosaves are never deleted.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would be deleted without deleting anything.
	 *
	 * [--post=<id>]
	 * : Only consider revisions of this post.
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

		if ( ! Revisions_Control::is_active() || Revisions_Control::get_number() < 1 ) {
			WP_CLI::warning( 'The Newspack revision limit is off, unlimited or 0, so there is nothing to delete.' );
		}

		$count    = 0;
		$after_id = 0;
		while ( true ) {
			$candidates = Revision_Cleanup::get_candidates( $after_id, $post_id );
			if ( empty( $candidates ) ) {
				break;
			}
			foreach ( $candidates as $candidate ) {
				$ids = Revision_Cleanup::get_excess_ids( $candidate, PHP_INT_MAX );
				if ( empty( $ids ) ) {
					continue;
				}
				WP_CLI::log(
					sprintf(
						'%s %d revisions of post %d',
						$dry_run ? 'Would delete' : 'Deleting',
						count( $ids ),
						$candidate
					)
				);
				if ( $dry_run ) {
					$count += count( $ids );
					continue;
				}
				$count += count( Revision_Cleanup::delete_revisions( $ids ) );

				// Pause between posts to ease database load on a large backlog.
				usleep( 500000 );
			}
			$after_id = end( $candidates );
		}

		WP_CLI::success(
			$dry_run
				? sprintf( '%d revisions would be deleted.', $count )
				: sprintf( 'Deleted %d revisions.', $count )
		);
	}
}
