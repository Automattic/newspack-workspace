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
			// the caller flagged it as such, the visitor is logged in, or the visitor carries
			// the IP-access bypass cookie. A first-time anonymous on-campus visitor landing
			// directly on a gated post matches none of these and will see the gate — they must
			// first complete the IP check at /institutional-access (or ?institutional-access=1)
			// to set the cookie before subsequent gated requests can evaluate their IP.
			$is_uncached = $uncached || ! empty( $user_id ) || IP_Access_Rule::is_cookie_set();
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
