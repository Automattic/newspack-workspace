<?php
/**
 * Newspack Institutional Access.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Content_Gate\IP_Access_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Institution CPT and access rule evaluation.
 */
class Institution {

	const POST_TYPE     = 'np_institution';
	const META_PREFIX   = 'np_institution_';
	const TRANSIENT_KEY = 'newspack_institutions';
	const TRANSIENT_TTL = 300;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'invalidate_cache' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'maybe_invalidate_cache_on_delete' ] );
		add_filter( 'newspack_content_gate_check_ip', [ __CLASS__, 'check_ip' ] );
	}

	/**
	 * Register meta fields for the REST API.
	 */
	public static function register_meta() {
		$meta_keys = [ 'email_domain', 'ip_range', 'reader_data' ];
		foreach ( $meta_keys as $key ) {
			\register_post_meta(
				self::POST_TYPE,
				self::META_PREFIX . $key,
				[
					'show_in_rest'      => true,
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				]
			);
		}
	}

	/**
	 * Register the institution post type.
	 */
	public static function register_post_type() {
		$capabilities = array_fill_keys(
			[
				'edit_post',
				'read_post',
				'delete_post',
				'edit_posts',
				'edit_others_posts',
				'delete_posts',
				'publish_posts',
				'read_private_posts',
				'create_posts',
			],
			'manage_options'
		);

		\register_post_type(
			self::POST_TYPE,
			[
				'label'        => __( 'Institutions', 'newspack-plugin' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'supports'     => [ 'title', 'excerpt', 'thumbnail', 'custom-fields' ],
				/**
				 * Institutions effectively grant access, so restrict all CRUD operations
				 * (including via REST) to the `manage_options` user capability.
				 */
				'capabilities' => $capabilities,
			]
		);
	}

	/**
	 * Create an institution.
	 *
	 * @param string $name        Institution name.
	 * @param string $description Optional. Institution description.
	 * @param array  $rules {
	 *     Optional. Institution rules.
	 *
	 *     @type string $email_domain Comma-separated domains (e.g., 'university.edu,uni.ac.uk').
	 *     @type string $ip_range     Comma-separated IPs/CIDR (e.g., '192.168.1.0/24,10.0.0.5').
	 *     @type string $reader_data  Semicolon-delimited key=value pairs (e.g., 'org=uni;role=staff').
	 * }
	 *
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create( $name, $description = '', $rules = [] ) {
		$post_id = \wp_insert_post(
			[
				'post_type'    => self::POST_TYPE,
				'post_title'   => sanitize_text_field( $name ),
				'post_excerpt' => sanitize_text_field( $description ),
				'post_status'  => 'publish',
			],
			true
		);
		if ( \is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$allowed_keys = [ 'email_domain', 'ip_range', 'reader_data' ];
		foreach ( $rules as $key => $value ) {
			if ( in_array( $key, $allowed_keys, true ) && ! empty( $value ) ) {
				\update_post_meta( $post_id, self::META_PREFIX . $key, sanitize_text_field( $value ) );
			}
		}
		return $post_id;
	}

	/**
	 * Get institution options for the access rule multi-select.
	 *
	 * @return array Array of [ 'label' => string, 'value' => int ].
	 */
	public static function get_options() {
		$posts   = \get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
		$options = [];
		foreach ( $posts as $post ) {
			$options[] = [
				'label' => $post->post_title,
				'value' => $post->ID,
			];
		}
		return $options;
	}

	/**
	 * Get all cached institutions with their rules.
	 *
	 * @return array Keyed by post ID.
	 */
	public static function get_cached_institutions() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}
		return self::rebuild_cache();
	}

	/**
	 * Rebuild the institutions transient cache.
	 *
	 * @return array The rebuilt cache.
	 */
	public static function rebuild_cache() {
		$posts        = \get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			]
		);
		$institutions = [];
		foreach ( $posts as $post ) {
			$institutions[ $post->ID ] = [
				'email_domain' => get_post_meta( $post->ID, self::META_PREFIX . 'email_domain', true ),
				'ip_range'     => get_post_meta( $post->ID, self::META_PREFIX . 'ip_range', true ),
				'reader_data'  => get_post_meta( $post->ID, self::META_PREFIX . 'reader_data', true ),
			];
		}
		set_transient( self::TRANSIENT_KEY, $institutions, self::TRANSIENT_TTL );
		return $institutions;
	}

	/**
	 * Invalidate the institutions cache.
	 */
	public static function invalidate_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Invalidate cache on post deletion if it's an institution.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_invalidate_cache_on_delete( $post_id ) {
		if ( get_post_type( $post_id ) === self::POST_TYPE ) {
			self::invalidate_cache();
		}
	}

	/**
	 * Evaluate whether a user matches any of the selected institutions.
	 *
	 * @param int   $user_id         User ID.
	 * @param array $institution_ids Selected institution IDs.
	 *
	 * @return bool Whether the user matches any institution.
	 */
	public static function evaluate( $user_id, $institution_ids ) {
		if ( empty( $institution_ids ) || ! is_array( $institution_ids ) ) {
			return true;
		}

		$institutions = self::get_cached_institutions();

		foreach ( $institution_ids as $inst_id ) {
			$inst_id = absint( $inst_id );
			if ( ! isset( $institutions[ $inst_id ] ) ) {
				continue;
			}
			if ( self::user_matches_institution( $user_id, $institutions[ $inst_id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Per-request cache of [inst_id => decoded_name] maps.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $names_cache = [];

	/**
	 * Reset the per-request matching-names cache.
	 *
	 * Tests, CLI workers, and invalidation hooks call this to bust the memoization in
	 * `get_matching_names_for_user()` / `get_matching_ids_for_user()`. Distinct from
	 * `invalidate_cache()`, which clears the underlying institutions transient.
	 */
	public static function reset_matching_cache() {
		self::$names_cache = [];
	}

	/**
	 * Get the sorted, deduplicated names of institutions whose rules a user matches.
	 *
	 * Memoized per request via {@see self::get_matching_map_for_user()} — see that helper
	 * for cache scope, the IP/cookie context dependency, and invalidation.
	 *
	 * @param int        $user_id            User ID.
	 * @param array|null $institution_filter Optional list of institution post IDs. If non-empty, only
	 *                                       institutions whose ID is in the list are considered.
	 *                                       Pass null or an empty array to scan every cached institution.
	 *
	 * @return string[] Sorted, deduplicated institution names.
	 */
	public static function get_matching_names_for_user( $user_id, $institution_filter = null ) {
		$map   = self::get_matching_map_for_user( $user_id, $institution_filter );
		$names = array_values( array_unique( array_values( $map ) ) );
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		return $names;
	}

	/**
	 * Get the IDs of institutions whose rules a user matches.
	 *
	 * Returns institution post IDs. Shares the per-request cache with
	 * {@see self::get_matching_names_for_user()}. Suitable for callers that want a stable,
	 * non-PII identifier (e.g., GA4 anonymized labels) and don't need the display name.
	 *
	 * @param int        $user_id            User ID.
	 * @param array|null $institution_filter Optional list of institution post IDs (same semantics
	 *                                       as {@see self::get_matching_names_for_user()}).
	 *
	 * @return int[] Sorted institution post IDs.
	 */
	public static function get_matching_ids_for_user( $user_id, $institution_filter = null ) {
		$ids = array_keys( self::get_matching_map_for_user( $user_id, $institution_filter ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Build the [inst_id => decoded_name] map for the user, memoized per request.
	 *
	 * Cache key includes `get_current_user_id()` because `user_matches_institution()`'s IP
	 * branch is context-dependent on the current visitor (see the comment in that method).
	 * Without that the same `$user_id` could legitimately resolve to different sets across
	 * requests in a long-running worker that swaps the current user.
	 *
	 * IDs are read with `get_post_field( 'post_title', ... )` rather than `get_the_title( ... )`
	 * so the value going to GA4/ESP is a raw post title, not one that's been through
	 * `the_title` filters (texturization, third-party plugins) that can introduce entities
	 * or markup.
	 *
	 * @param int        $user_id            User ID.
	 * @param array|null $institution_filter Optional list of institution post IDs.
	 *
	 * @return array<int,string> Map of institution post ID to decoded post title.
	 */
	private static function get_matching_map_for_user( $user_id, $institution_filter = null ) {
		$user_id = (int) $user_id;

		// Normalize the filter so [], null, and unsorted/duplicate inputs share a cache key.
		$normalized_filter = is_array( $institution_filter ) && ! empty( $institution_filter )
			? array_values( array_unique( array_map( 'absint', $institution_filter ) ) )
			: null;
		if ( null !== $normalized_filter ) {
			sort( $normalized_filter, SORT_NUMERIC );
		}
		// Include the current user in the cache key because the IP-rule branch of
		// user_matches_institution() short-circuits when $user_id !== get_current_user_id().
		$cache_key = $user_id . '|' . get_current_user_id() . '|' . ( null === $normalized_filter ? '' : implode( ',', $normalized_filter ) );
		if ( isset( self::$names_cache[ $cache_key ] ) ) {
			return self::$names_cache[ $cache_key ];
		}

		$map = [];
		foreach ( self::get_cached_institutions() as $inst_id => $rules ) {
			$inst_id = (int) $inst_id;
			if ( null !== $normalized_filter && ! in_array( $inst_id, $normalized_filter, true ) ) {
				continue;
			}
			if ( self::user_matches_institution( $user_id, $rules ) ) {
				$map[ $inst_id ] = html_entity_decode( (string) \get_post_field( 'post_title', $inst_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		self::$names_cache[ $cache_key ] = $map;
		return $map;
	}

	/**
	 * Check if a user matches an institution's rules (OR logic).
	 *
	 * @param int   $user_id  User ID.
	 * @param array $rules    Institution rules with keys: email_domain, ip_range, reader_data.
	 * @param bool  $uncached Whether the request is known to be uncached (e.g., REST endpoint).
	 *                        When true, the IP check runs regardless of cookie/login state.
	 *
	 * @return bool Whether the user matches any rule.
	 */
	public static function user_matches_institution( $user_id, $rules, $uncached = false ) {
		if ( ! empty( $rules['email_domain'] ) ) {
			if ( Access_Rules::is_email_domain_whitelisted( $user_id, $rules['email_domain'] ) ) {
				return true;
			}
		}

		if ( ! empty( $rules['ip_range'] ) ) {
			// IP evaluation is page-cache-safe only when the response would be uncached anyway:
			// the caller flagged it as such, the *current visitor* is logged in (so the IP we
			// would read is theirs), or the visitor carries the IP-access bypass cookie. We
			// require $user_id === get_current_user_id() to avoid attributing the requestor's
			// IP to a different user during background metadata sync (admin/cron/webhook). A
			// first-time anonymous on-campus visitor landing directly on a gated post matches
			// none of these and will see the gate — they must first complete the IP check at
			// /institutional-access (or ?institutional-access=1) to set the cookie before
			// subsequent gated requests can evaluate their IP.
			//
			// Caveat: the IP_Access_Rule::is_cookie_set() disjunct accepts any non-current
			// $user_id when the *current visitor* carries the cache-bypass cookie, so a
			// cookie-bearing on-campus visitor can still cause the current request's IP to
			// be matched against another user's institution range. This is a narrow,
			// pre-existing edge case (the cookie is only set after the visitor has already
			// passed an institutional-access check on this site).
			$is_uncached = $uncached || ( ! empty( $user_id ) && (int) $user_id === get_current_user_id() ) || IP_Access_Rule::is_cookie_set();
			if ( $is_uncached && IP_Access_Rule::ip_matches_ranges( IP_Access_Rule::get_visitor_ip(), $rules['ip_range'] ) ) {
				return true;
			}
		}

		if ( ! empty( $rules['reader_data'] ) ) {
			if ( Access_Rules::has_reader_data( $user_id, $rules['reader_data'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check visitor's IP against all institutional IP ranges.
	 * Hooked to newspack_content_gate_check_ip filter.
	 *
	 * @param bool|int $valid_ip Current validation result.
	 *
	 * @return bool|int The matching institution post ID, or the original value.
	 */
	public static function check_ip( $valid_ip ) {
		if ( $valid_ip ) {
			return $valid_ip;
		}

		$visitor_ip = IP_Access_Rule::get_visitor_ip();
		if ( empty( $visitor_ip ) ) {
			return $valid_ip;
		}

		$institutions = self::get_cached_institutions();
		foreach ( $institutions as $inst_id => $rules ) {
			if ( ! empty( $rules['ip_range'] ) && IP_Access_Rule::ip_matches_ranges( $visitor_ip, $rules['ip_range'] ) ) {
				return $inst_id;
			}
		}

		return $valid_ip;
	}
}
Institution::init();
