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
	 * Rows read and written per database round trip.
	 *
	 * Bounds how much of the result set sits in PHP memory at once: get_subtitle_meta_rows()
	 * is paginated by this value rather than fetching every row up front.
	 */
	private const BATCH_SIZE = 200;

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
	public function backfill( array $args, array $assoc_args ): void {
		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		$sanitizer = self::resolve_sanitizer();
		if ( null === $sanitizer ) {
			WP_CLI::error( "newspack_sanitize_post_subtitle() is not available. Activate newspack-theme and try again — this command targets what that theme's own sanitize_callback already produces on a new write." );
		}

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to write. ***' );
			WP_CLI::line( '' );
		}

		$changed     = [];
		$failed      = [];
		$concurrent  = [];
		$scanned     = 0;
		$after       = 0;
		$found_a_row = false;

		do {
			$rows = self::get_subtitle_meta_rows( $after );
			if ( null === $rows ) {
				WP_CLI::error( 'Could not read the stored subtitle values. Aborting rather than reporting an empty set.' );
			}
			$rows_in_batch = count( $rows );

			foreach ( $rows as $row ) {
				$found_a_row = true;
				$after       = $row->meta_id;
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

				if ( $dry_run ) {
					$changed[] = $row->post_id;
					continue;
				}

				switch ( self::write_sanitized_value( $row, $sanitized ) ) {
					case 'changed':
						$changed[] = $row->post_id;
						break;
					case 'concurrent-change':
						$concurrent[] = $row->post_id;
						break;
					default:
						$failed[] = $row->post_id;
				}
			}

			\WP_CLI\Utils\wp_clear_object_cache();
		} while ( $rows_in_batch === self::BATCH_SIZE );

		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== BACKFILL SUMMARY ===' );
		WP_CLI::line( '' );

		if ( ! $found_a_row ) {
			WP_CLI::success( 'No posts carry a non-empty newspack_post_subtitle value. Nothing to do.' );
			return;
		}

		WP_CLI::line( sprintf( 'Scanned %d subtitle row(s).', $scanned ) );

		if ( empty( $changed ) && empty( $failed ) && empty( $concurrent ) ) {
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

		if ( ! empty( $concurrent ) ) {
			WP_CLI::warning( sprintf( 'Skipped — the stored value changed after this pass read it, so writing the old read would have clobbered a newer save. Rerun to catch these: %s', self::format_post_id_list( $concurrent ) ) );
		}

		WP_CLI::line( '' );
		if ( $dry_run ) {
			WP_CLI::line( sprintf( '%d row(s) would change. Pass --live to write.', count( $changed ) ) );
			return;
		}

		$incomplete = count( $failed ) + count( $concurrent );
		if ( $incomplete > 0 ) {
			WP_CLI::success( sprintf( '%d row(s) updated; %d left unsanitized (see above).', count( $changed ), $incomplete ) );
			// A warning line is easy to miss, and a row left unsanitized can't be told
			// apart from one this pass already cleaned. Exit non-zero so a caller
			// chaining this command notices an incomplete run instead of treating it as
			// done. The mock halt() used in tests doesn't actually exit, so return
			// explicitly rather than falling through to the all-clear success() below.
			WP_CLI::halt( 1 );
			return;
		}

		WP_CLI::success( sprintf( '%d row(s) updated.', count( $changed ) ) );
	}

	/**
	 * The sanitizer to backfill against.
	 *
	 * Only the theme's own function qualifies — it is the registered sanitize_callback
	 * for this meta key, so it is the ground truth for what a new write already
	 * produces. newspack-block-theme's subtitle block registers no sanitize_callback at
	 * all for this key and renders the stored value through esc_html(), so there is no
	 * "already sanitized" form to backfill toward there; applying a different sanitizer
	 * would strip content the site's own render path never touches.
	 *
	 * @return callable|null newspack_sanitize_post_subtitle(), or null when it isn't
	 *                        available.
	 */
	private static function resolve_sanitizer(): ?callable {
		if ( function_exists( 'newspack_sanitize_post_subtitle' ) ) {
			return 'newspack_sanitize_post_subtitle';
		}
		return null;
	}

	/**
	 * Writes a sanitized value for one row, guarding against a save that landed between
	 * the upfront batch read and this write.
	 *
	 * The batch read that produced $row covers many rows at once; a save landing in the
	 * gap before this specific row's turn — a reader's own edit, or another run of this
	 * command overlapping — must not be silently overwritten by the stale value that
	 * read saw. Re-reading the row immediately before writing shrinks that gap to the
	 * width of this one function, rather than the width of the whole batch.
	 *
	 * @param object $row       A row from get_subtitle_meta_rows(), with `meta_id` and
	 *                           the `meta_value` that batch read saw.
	 * @param string $sanitized The sanitizer's output for that `meta_value`.
	 *
	 * @return string One of 'changed', 'concurrent-change', or 'failed'.
	 */
	private static function write_sanitized_value( $row, string $sanitized ): string {
		$current = \get_metadata_by_mid( 'post', $row->meta_id );
		if ( false === $current || $current->meta_value !== $row->meta_value ) {
			return 'concurrent-change';
		}

		// update_metadata_by_mid() targets one row by its own meta_id, unlike
		// update_post_meta(), which replaces every row sharing this post and key — the
		// only way to fix one duplicate row without clobbering the others. It also never
		// unslashes its value (update_post_meta() does), so, unlike that call, this one
		// must NOT wp_slash() first.
		//
		// This still runs $sanitized through sanitize_meta() a second time — core applies
		// the registered sanitize_callback to every write, this one included. Verified
		// harmless: the sanitizer is wp_kses() against a fixed inline allow-list, and
		// re-sanitizing its own output is idempotent.
		if ( ! \update_metadata_by_mid( 'post', $row->meta_id, $sanitized ) ) {
			return 'failed';
		}

		return 'changed';
	}

	/**
	 * One batch of non-empty `newspack_post_subtitle` rows on a non-trashed,
	 * non-revision post, keyed past `$after_meta_id`.
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
	 * No `post_type` filter beyond excluding revisions: the same plugin conflict or direct
	 * edit that can leave a stray duplicate row (above) can just as easily leave one on a
	 * post type other than `post`, and this query's job is to find every row with this
	 * meta key regardless.
	 *
	 * Revisions are excluded: this meta key is registered without `revisions_enabled`, so
	 * nothing in core's revision machinery ever reads or restores a revision's own copy —
	 * a stray row there is inert, and update_metadata_by_mid() on its meta_id would still
	 * write the revision's own row rather than the parent's.
	 *
	 * Paginated by `meta_id`, the same column every row is written back by: a batch is
	 * read, processed, and released before the next is fetched, so a very large scan
	 * holds one batch in memory rather than the whole result set.
	 *
	 * Run once per site on a multisite install — `$wpdb->posts`/`$wpdb->postmeta`
	 * resolve to the current blog's tables, not the whole network.
	 *
	 * @param int $after_meta_id Only rows with a greater `meta_id`; 0 for the first batch.
	 *
	 * @return object[]|null Up to self::BATCH_SIZE rows with `meta_id`, `post_id`, and the
	 *                        raw `meta_value`, or null when the query itself failed
	 *                        (reported by $wpdb->last_error), so an operator never mistakes
	 *                        a failed read for a store with nothing left to sanitize.
	 */
	private static function get_subtitle_meta_rows( int $after_meta_id ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT pm.meta_id, pm.post_id, pm.meta_value
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE pm.meta_key = 'newspack_post_subtitle' AND pm.meta_value != ''
				AND p.post_status != 'trash' AND p.post_type != 'revision'
				AND pm.meta_id > %d
				ORDER BY pm.meta_id
				LIMIT %d",
				$after_meta_id,
				self::BATCH_SIZE
			)
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
