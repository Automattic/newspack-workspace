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
	 * Re-sanitizes every stored `newspack_post_subtitle` row.
	 *
	 * Reads each stored row and re-saves it through the theme's own sanitizer — the
	 * same function `register_post_meta()` already runs on every new write. A value
	 * already sanitized comes back identical and is left alone, which is what makes a
	 * second run report nothing left to change.
	 *
	 * Skips trashed posts: a trashed post keeps its raw value until it is restored or
	 * permanently deleted, and restoring it returns it to this command's scope on the
	 * next run.
	 *
	 * Dry-run by default; pass --live to write. A live run with any write failure exits
	 * non-zero after reporting, so a caller chaining this command notices rather than
	 * treating a partial cleanup as done.
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

		$rows = self::get_subtitle_meta_rows();
		if ( null === $rows ) {
			WP_CLI::error( 'Could not read the stored subtitle values. Aborting rather than reporting an empty set.' );
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( 'No posts carry a non-empty newspack_post_subtitle value. Nothing to do.' );
			return;
		}

		$changed = [];
		$failed  = [];
		$scanned = 0;

		// Chunk only to bound memory on a very large scan; each row is read and written
		// straight from the query result, so there is no cache to warm first.
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			foreach ( $chunk as $row ) {
				++$scanned;
				// get_post_meta() would maybe_unserialize() this for us; reading the
				// column directly skips that, so a value stored as a serialized array
				// needs the same unserialize before the is_string() guard can see it.
				$raw = \maybe_unserialize( $row->meta_value );
				if ( ! is_string( $raw ) || '' === $raw ) {
					continue;
				}
				$sanitized = $sanitizer( $raw );
				if ( $sanitized === $raw ) {
					continue;
				}
				// update_metadata_by_mid() targets one row by its own meta_id, unlike
				// update_post_meta(), which replaces every row sharing this post and key —
				// the only way to fix one duplicate row without clobbering the others. It
				// also never unslashes its value (update_post_meta() does), so, unlike that
				// call, this one must NOT wp_slash() first.
				if ( ! $dry_run && ! \update_metadata_by_mid( 'post', $row->meta_id, $sanitized ) ) {
					$failed[] = $row->post_id;
					continue;
				}
				$changed[] = $row->post_id;
			}
			\WP_CLI\Utils\wp_clear_object_cache();
		}

		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== BACKFILL SUMMARY ===' );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Scanned %d subtitle row(s).', $scanned ) );

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
			WP_CLI::warning( sprintf( 'update_metadata_by_mid() failed for: %s', self::format_post_id_list( $failed ) ) );
		}

		WP_CLI::line( '' );
		if ( $dry_run ) {
			WP_CLI::line( sprintf( '%d row(s) would change. Pass --live to write.', count( $changed ) ) );
			return;
		}

		WP_CLI::success( sprintf( '%d row(s) updated.', count( $changed ) ) );

		// A warning line is easy to miss, and a row left unsanitized can't be told apart
		// from one this pass already cleaned. Exit non-zero so a caller chaining this
		// command notices an incomplete run instead of treating it as done.
		if ( ! empty( $failed ) ) {
			WP_CLI::halt( 1 );
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
	private static function resolve_sanitizer(): ?callable {
		if ( function_exists( 'newspack_sanitize_post_subtitle' ) ) {
			return 'newspack_sanitize_post_subtitle';
		}
		if ( class_exists( 'Newspack_Blocks' ) && method_exists( 'Newspack_Blocks', 'sanitize_post_subtitle' ) ) {
			return [ 'Newspack_Blocks', 'sanitize_post_subtitle' ];
		}
		return null;
	}

	/**
	 * Every non-empty `newspack_post_subtitle` row on a non-trashed, non-revision post.
	 *
	 * A direct query rather than `WP_Query`/`get_posts()` with a `meta_query`: the
	 * command's whole job is "every row with this meta key", which a meta_query would
	 * express as a join anyway, and here the join can also filter out empty values and
	 * trashed posts in one pass.
	 *
	 * Every row, not one per post: WordPress allows more than one `newspack_post_subtitle`
	 * row on the same post (a plugin conflict, a direct database edit, or any other write
	 * that bypassed the `single => true` registration meant to prevent it). Collapsing to
	 * one row per post, the way `get_post_meta( $id, $key, true )` or a `DISTINCT p.ID`
	 * query would, hides a second unsanitized row behind a first clean one, or — worse,
	 * once written — collapses both rows into whichever one was read. Returning every row
	 * with its own `meta_id` lets the caller read, sanitize, and write each one on its own.
	 *
	 * Revisions are excluded: `update_post_meta()` on a revision ID redirects the write
	 * to its parent post, which would overwrite the parent's current value with the
	 * revision's (possibly older) one.
	 *
	 * Run once per site on a multisite install — `$wpdb->posts`/`$wpdb->postmeta`
	 * resolve to the current blog's tables, not the whole network.
	 *
	 * @return object[]|null Rows with `meta_id`, `post_id`, and the raw `meta_value`, or
	 *                        null when the query itself failed (reported by
	 *                        $wpdb->last_error), so an operator never mistakes a failed
	 *                        read for a store with nothing to sanitize.
	 */
	private static function get_subtitle_meta_rows(): ?array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT pm.meta_id, pm.post_id, pm.meta_value
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE pm.meta_key = 'newspack_post_subtitle' AND pm.meta_value != ''
			AND p.post_status != 'trash' AND p.post_type != 'revision'
			ORDER BY p.ID, pm.meta_id"
		);

		if ( $wpdb->last_error ) {
			return null;
		}

		foreach ( (array) $rows as $row ) {
			$row->meta_id = (int) $row->meta_id;
			$row->post_id = (int) $row->post_id;
		}

		return (array) $rows;
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
