<?php
/**
 * Co-Authors Plus count_user_posts dedup integration.
 *
 * CAP's filter_count_user_posts adds a user's own post_author count to the
 * count of posts attached to a linked guest author's taxonomy term without
 * deduplication. On sites where the editorial workflow tags the same posts
 * with both attribution paths (e.g. MinnPost), this inflates the wp-admin
 * Users-list "Posts" column.
 *
 * This filter recomputes count_user_posts as COUNT(DISTINCT) over the union
 * of (post_author = user) and (attached to GA term), so the same post is
 * counted once regardless of how it's attributed.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Deduplicates count_user_posts for WP users linked to a CAP guest author.
 */
class Co_Authors_Plus_Count_User_Posts_Fix {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Priority 11 runs after CAP's own filter_count_user_posts at priority 10.
		add_filter( 'get_usernumposts', [ __CLASS__, 'dedup_linked_author_count' ], 11, 4 );
	}

	/**
	 * Replace the count CAP produced with a deduplicated union count.
	 *
	 * @param int|string      $count       The count value from previous filters (CAP at priority 10).
	 * @param int             $user_id     The user ID.
	 * @param string|string[] $post_type   The post type(s) to count. WP's count_user_posts accepts
	 *                                     a string or an array; the WP_Query 'any' sentinel is honored.
	 * @param bool            $public_only Whether to restrict the count to public-status posts.
	 * @return int|string The deduplicated count, or the original $count if no linked GA.
	 */
	public static function dedup_linked_author_count( $count, $user_id, $post_type, $public_only ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return $count;
		}

		$ga_term_taxonomy_ids = self::get_linked_guest_author_term_taxonomy_ids( $user->user_login );
		if ( empty( $ga_term_taxonomy_ids ) ) {
			return $count;
		}

		return self::count_distinct_attributed_posts( $count, $user_id, $ga_term_taxonomy_ids, $post_type, (bool) $public_only );
	}

	/**
	 * Find every author term_taxonomy_id belonging to guest authors whose
	 * `cap-linked_account` meta matches the given user_login.
	 *
	 * CAP's data model treats this as a 1:1 link, but data corruption, manual
	 * term edits, or in-progress migrations can leave a user pointed at by more
	 * than one GA (and a GA can carry more than one author term). Collecting
	 * all of them prevents silent undercount in the dedup query.
	 *
	 * @param string $user_login The WP user's user_login value.
	 * @return int[] Unique term_taxonomy_ids across all linked GAs (empty if none).
	 */
	private static function get_linked_guest_author_term_taxonomy_ids( $user_login ) {
		if ( ! post_type_exists( 'guest-author' ) || ! taxonomy_exists( 'author' ) ) {
			return [];
		}

		global $wpdb;

		// Find every GA CPT post whose cap-linked_account meta matches the user's login.
		// SELECT DISTINCT guards against duplicate cap-linked_account meta rows on a single
		// GA post returning the same ID twice, which would cause redundant term lookups below.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- WP_Query + meta_query would be markedly slower; this fires per count_user_posts call.
		$ga_post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'cap-linked_account'
				WHERE p.post_type = 'guest-author'
				AND p.post_status != 'trash'
				AND pm.meta_value = %s",
				$user_login
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( empty( $ga_post_ids ) ) {
			return [];
		}

		$tt_ids = [];
		foreach ( $ga_post_ids as $ga_post_id ) {
			$terms = wp_get_object_terms( (int) $ga_post_id, 'author', [ 'fields' => 'tt_ids' ] );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $tt_id ) {
				$tt_ids[] = (int) $tt_id;
			}
		}

		return array_values( array_unique( $tt_ids ) );
	}

	/**
	 * Run a COUNT(DISTINCT) over the union of (post_author = user) and
	 * (attached to any of the GA's author terms), honoring post type and
	 * public-only filters.
	 *
	 * @param int|string      $count                 The upstream count, returned cast to int when no post types resolve.
	 * @param int             $user_id               The user ID.
	 * @param int[]           $ga_term_taxonomy_ids  Author term_taxonomy_ids for all linked GAs.
	 * @param string|string[] $post_type             The post type(s) to count (WP's count_user_posts accepts either).
	 * @param bool            $public_only           Restrict to public-status posts.
	 * @return int The deduplicated count.
	 */
	private static function count_distinct_attributed_posts( $count, $user_id, $ga_term_taxonomy_ids, $post_type, $public_only ) {
		global $wpdb;

		$types = self::resolve_post_types( $post_type );
		// Guard: an empty post-type set (e.g. a publisher filtering
		// `coauthors_count_published_post_types` down to []) would build a
		// malformed `post_type IN ()`. Fall back to the upstream count rather
		// than running a broken query and silently returning 0.
		if ( empty( $types ) ) {
			return (int) $count;
		}

		$statuses            = self::resolve_post_statuses( $public_only, $types );
		$tt_placeholders     = implode( ',', array_fill( 0, count( $ga_term_taxonomy_ids ), '%d' ) );
		$type_placeholders   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$prepare_args        = array_merge( $ga_term_taxonomy_ids, $types, $statuses, [ $user_id ] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $tt_placeholders / $type_placeholders / $status_placeholders are %d/%s-only fragments built from count() of the resolved arrays; the actual values flow through prepare() in $prepare_args. Direct query needed for COUNT(DISTINCT) over the union (loses index-friendly form as WP_Query meta/tax_query).
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->term_relationships} tr
					ON tr.object_id = p.ID AND tr.term_taxonomy_id IN ({$tt_placeholders})
				WHERE p.post_type IN ({$type_placeholders})
				AND p.post_status IN ({$status_placeholders})
				AND ( p.post_author = %d OR tr.object_id IS NOT NULL )",
				$prepare_args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return (int) $count;
	}

	/**
	 * Resolve the post statuses to count over, matching CAP's `get_post_count_for_author_term`
	 * and WP core's `count_user_posts` behavior: `publish` only when restricted to public,
	 * `publish` + `private` otherwise — but `private` is only included when the current viewer
	 * may actually read private posts, mirroring WP core's `get_posts_by_author_sql()` gate.
	 * Notably excludes drafts, pending, trash, auto-draft, and other transient/internal statuses
	 * that would inflate the count over time.
	 *
	 * @param bool     $public_only Restrict to public statuses when true.
	 * @param string[] $types       Resolved post types, used to gate the private-status capability.
	 * @return string[] Post status names.
	 */
	private static function resolve_post_statuses( $public_only, $types ) {
		if ( $public_only ) {
			return [ 'publish' ];
		}
		return self::viewer_can_read_private_posts( $types )
			? [ 'publish', 'private' ]
			: [ 'publish' ];
	}

	/**
	 * Whether the current viewer may read private posts for every resolved post type.
	 *
	 * Mirrors WP core's `get_posts_by_author_sql()` capability gate so a low-cap or
	 * unauthenticated viewer (e.g. the public REST `users` endpoint) never sees a
	 * private-inflated count. Conservative by design: `private` is counted only when
	 * the viewer can read private posts for ALL resolved types — for the common
	 * single-`post` case this is identical to core. Core's narrower "own private posts
	 * when logged in" branch is intentionally not replicated; this filter only runs for
	 * linked-guest-author users, where that edge does not meaningfully apply.
	 *
	 * @param string[] $types Resolved post type names.
	 * @return bool True if the viewer can read private posts for every type.
	 */
	private static function viewer_can_read_private_posts( $types ) {
		foreach ( $types as $type ) {
			$post_type_object = get_post_type_object( $type );
			if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->read_private_posts ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve the post types to count over, mirroring the upstream behaviors this filter
	 * runs after:
	 *
	 * - WP_Query's `'any'` sentinel expands to every post type whose `exclude_from_search`
	 *   is `false`. Without this, `IN ('any')` would match zero rows — a real regression
	 *   for callers like newspack-blocks' Author List controller which passes `['any']`.
	 * - When the default `'post'` is passed (string or `['post']`), CAP's
	 *   `coauthors_count_published_post_types` filter lets publishers expand the set
	 *   (e.g. include `newsletter`). CAP applies this at priority 10; we mirror it here
	 *   so the dedup'd count matches the surface area CAP counted.
	 *
	 * @param string|string[] $post_type The post type argument passed to count_user_posts.
	 * @return string[] Resolved list of post type names.
	 */
	private static function resolve_post_types( $post_type ) {
		if ( 'any' === $post_type || ( is_array( $post_type ) && in_array( 'any', $post_type, true ) ) ) {
			return array_values( get_post_types( [ 'exclude_from_search' => false ], 'names' ) );
		}

		$is_default = ( 'post' === $post_type || ( is_array( $post_type ) && [ 'post' ] === $post_type ) );
		if ( $is_default ) {
			return (array) apply_filters( 'coauthors_count_published_post_types', [ 'post' ] );
		}

		return is_array( $post_type ) ? $post_type : [ $post_type ];
	}
}
Co_Authors_Plus_Count_User_Posts_Fix::init();
