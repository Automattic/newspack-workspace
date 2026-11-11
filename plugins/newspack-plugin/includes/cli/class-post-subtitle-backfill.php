<?php
/**
 * WP-CLI command to re-sanitize `newspack_post_subtitle` values stored before the
 * theme's sanitize_callback shipped (newspack-theme's
 * `newspack_sanitize_post_subtitle()` in inc/template-tags.php).
 *
 * `register_post_meta()`'s sanitize_callback only runs on a write, so a value saved
 * before the callback existed sits in the database exactly as submitted. This command
 * is the one-time pass that catches those rows up by re-saving the sanitized value
 * through the normal meta path, rather than filtering the raw value on every read.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `newspack_post_subtitle` backfill CLI command.
 */
class Post_Subtitle_Backfill {

	/**
	 * Re-sanitizes every stored `newspack_post_subtitle` value.
	 *
	 * Reads each stored value and re-saves it through the theme's own sanitizer — the
	 * same function `register_post_meta()` already runs on every new write. A value
	 * already sanitized comes back identical and is left alone, which is what makes a
	 * second run report nothing left to change.
	 *
	 * Skips trashed posts: a trashed post keeps its raw value until it is restored or
	 * permanently deleted, and restoring it returns it to this command's scope on the
	 * next run.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack backfill-post-subtitles
	 *     wp newspack backfill-post-subtitles --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function backfill( $args, $assoc_args ) {
		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		$sanitizer = self::resolve_sanitizer();
		if ( null === $sanitizer ) {
			WP_CLI::error( 'Neither newspack_sanitize_post_subtitle() nor Newspack_Blocks::sanitize_post_subtitle() is available. Activate newspack-theme or newspack-blocks and try again.' );
		}

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to write. ***' );
			WP_CLI::line( '' );
		}

		$post_ids = self::get_subtitle_post_ids();
		if ( null === $post_ids ) {
			WP_CLI::error( 'Could not read the stored subtitle values. Aborting rather than reporting an empty set.' );
		}

		if ( empty( $post_ids ) ) {
			WP_CLI::success( 'No posts carry a non-empty newspack_post_subtitle value. Nothing to do.' );
			return;
		}

		$changed = [];
		$failed  = [];
		$scanned = 0;

		// Read and write a chunk at a time, so the meta cache neither grows with the site
		// nor is thrown away between reading a post and writing it.
		foreach ( array_chunk( $post_ids, 200 ) as $chunk ) {
			\update_meta_cache( 'post', $chunk );
			foreach ( $chunk as $post_id ) {
				++$scanned;
				$raw = \get_post_meta( $post_id, 'newspack_post_subtitle', true );
				if ( '' === $raw ) {
					continue;
				}
				$sanitized = $sanitizer( $raw );
				if ( $sanitized === $raw ) {
					continue;
				}
				if ( ! $dry_run && ! \update_post_meta( $post_id, 'newspack_post_subtitle', $sanitized ) ) {
					$failed[] = $post_id;
					continue;
				}
				$changed[] = $post_id;
			}
			\WP_CLI\Utils\wp_clear_object_cache();
		}

		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== BACKFILL SUMMARY ===' );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Scanned %d post(s) with a non-empty subtitle.', $scanned ) );

		if ( empty( $changed ) && empty( $failed ) ) {
			WP_CLI::success( 'Every stored subtitle already matches what the sanitizer produces. Nothing to change.' );
			return;
		}

		if ( ! empty( $changed ) ) {
			WP_CLI::line(
				sprintf(
					'Subtitle %s for: %s',
					$dry_run ? 'would be sanitized' : 'sanitized',
					self::format_post_id_list( $changed )
				)
			);
		}

		if ( ! empty( $failed ) ) {
			WP_CLI::warning( sprintf( 'update_post_meta() failed for: %s', self::format_post_id_list( $failed ) ) );
		}

		WP_CLI::line( '' );
		if ( $dry_run ) {
			WP_CLI::line( sprintf( '%d row(s) would change. Pass --live to write.', count( $changed ) ) );
		} else {
			WP_CLI::success( sprintf( '%d row(s) updated.', count( $changed ) ) );
		}
	}

	/**
	 * The sanitizer to backfill against.
	 *
	 * Prefers the theme's own function — it is the registered sanitize_callback, so it
	 * is the ground truth for what a new write already produces. Falls back to
	 * Newspack_Blocks' copy (kept in sync with the theme's by the same change that added
	 * both) for a site running newspack-block-theme, whose own meta registration carries
	 * no sanitize_callback at all.
	 *
	 * @return callable|null A callable taking the raw value and returning the sanitized
	 *                        one, or null when neither is available.
	 */
	private static function resolve_sanitizer() {
		if ( function_exists( 'newspack_sanitize_post_subtitle' ) ) {
			return 'newspack_sanitize_post_subtitle';
		}
		if ( class_exists( 'Newspack_Blocks' ) && method_exists( 'Newspack_Blocks', 'sanitize_post_subtitle' ) ) {
			return [ 'Newspack_Blocks', 'sanitize_post_subtitle' ];
		}
		return null;
	}

	/**
	 * IDs of every non-trashed post carrying a non-empty `newspack_post_subtitle`.
	 *
	 * A direct query rather than `WP_Query`/`get_posts()` with a `meta_query`: the
	 * command's whole job is "every row with this meta key", which a meta_query would
	 * express as a join anyway, and here the join can also filter out empty values and
	 * trashed posts in one pass.
	 *
	 * @return int[]|null Post IDs, or null when the query itself failed (reported by
	 *                     $wpdb->last_error), so an operator never mistakes a failed read
	 *                     for a store with nothing to sanitize.
	 */
	private static function get_subtitle_post_ids(): ?array {
		global $wpdb;

		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE pm.meta_key = 'newspack_post_subtitle' AND pm.meta_value != '' AND p.post_status != 'trash'
			ORDER BY p.ID"
		);

		return $wpdb->last_error ? null : array_map( 'intval', (array) $post_ids );
	}

	/**
	 * Formats a post ID list for a report line, truncating a long one.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @param int   $limit    Maximum IDs to print before truncating.
	 *
	 * @return string
	 */
	private static function format_post_id_list( array $post_ids, int $limit = 100 ): string {
		if ( count( $post_ids ) <= $limit ) {
			return implode( ', ', $post_ids );
		}
		return sprintf(
			'%s (and %d more)',
			implode( ', ', array_slice( $post_ids, 0, $limit ) ),
			count( $post_ids ) - $limit
		);
	}
}
