<?php
/**
 * Newspack Sponsors Core.
 *
 * Registers Sponsors custom post type and taxonomy, and creates a shadow
 * relationship between them.
 *
 * @package Newspack_Sponsors
 */

namespace Newspack_Sponsors;

use Newspack_Sponsors\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Main Core class.
 * Sets up Sponsors CPT and shadow taxonomy for posts.
 */
final class Core {

	const NEWSPACK_SPONSORS_CPT = 'newspack_spnsrs_cpt';
	const NEWSPACK_SPONSORS_TAX = 'newspack_spnsrs_tax';

	/**
	 * The single instance of the class.
	 *
	 * @var Core
	 */
	protected static $instance = null;

	/**
	 * Main Newspack_Sponsors instance.
	 * Ensures only one instance of Newspack_Sponsors is loaded or can be loaded.
	 *
	 * @return Core - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ __CLASS__, 'init' ] );
		add_filter( 'newspack_ads_should_show_ads', [ __CLASS__, 'suppress_ads' ], 10, 2 );
		add_filter( 'newspack_ads_ad_targeting', [ __CLASS__, 'ad_targeting' ], 10, 2 );
		add_action( 'pre_get_posts', [ __CLASS__, 'ensure_only_sponsors' ] );
	}

	/**
	 * Disable ads if the post is sponsored.
	 *
	 * @param bool $should_display Should ads be displayed on this post.
	 * @param int  $post_id Post ID.
	 */
	public static function suppress_ads( $should_display, $post_id ) {
		if ( ! is_single() ) {
			return $should_display;
		}

		$suppress_ads = Settings::get_settings( 'suppress' );
		$sponsors     = get_sponsors_for_post( $post_id );
		if ( boolval( $suppress_ads ) && $sponsors && count( $sponsors ) ) {
			return false;
		}
		return $should_display;
	}

	/**
	 * Set ad targeting for sponsored posts.
	 *
	 * @param array $targeting Ad targeting.
	 *
	 * @return array
	 */
	public static function ad_targeting( $targeting ) {
		$sponsors = [];
		if ( is_singular() ) {
			$sponsors = get_sponsors_for_post( get_the_ID() );
		} elseif ( is_archive() ) {
			$sponsors = get_sponsors_for_archive();
		}
		if ( ! is_wp_error( $sponsors ) && ! empty( $sponsors ) && ! isset( $targeting['sponsors'] ) ) {
			$targeting['sponsors'] = array_map(
				function( $sponsor ) {
					return $sponsor['sponsor_slug'];
				},
				$sponsors
			);
		}
		return $targeting;
	}

	/**
	 * After WP init.
	 */
	public static function init() {
		self::register_cpt();
		self::register_meta();
		self::register_tax();
		self::create_shadow_relationship();
	}

	/**
	 * Is the current post a sponsor?
	 *
	 * @return boolean True if a sponsor.
	 */
	public static function is_sponsor() {
		return self::NEWSPACK_SPONSORS_CPT === get_post_type();
	}

	/**
	 * Registers Sponsors custom post type.
	 */
	public static function register_cpt() {
		$labels = [
			'name'                  => _x( 'Sponsors', 'post type general name', 'newspack-sponsors' ),
			'singular_name'         => _x( 'Sponsor', 'post type singular name', 'newspack-sponsors' ),
			'menu_name'             => _x( 'Sponsors', 'admin menu', 'newspack-sponsors' ),
			'name_admin_bar'        => _x( 'Sponsor', 'add new on admin bar', 'newspack-sponsors' ),
			'add_new'               => _x( 'Add New', 'popup', 'newspack-sponsors' ),
			'add_new_item'          => __( 'Add New Sponsor', 'newspack-sponsors' ),
			'new_item'              => __( 'New Sponsor', 'newspack-sponsors' ),
			'edit_item'             => __( 'Edit Sponsor', 'newspack-sponsors' ),
			'view_item'             => __( 'View Sponsor', 'newspack-sponsors' ),
			'all_items'             => __( 'All Sponsors', 'newspack-sponsors' ),
			'search_items'          => __( 'Search Sponsors', 'newspack-sponsors' ),
			'parent_item_colon'     => __( 'Parent Sponsors:', 'newspack-sponsors' ),
			'not_found'             => __( 'No sponsors found.', 'newspack-sponsors' ),
			'not_found_in_trash'    => __( 'No sponsors found in Trash.', 'newspack-sponsors' ),
			'featured_image'        => __( 'Sponsor Logo', 'newspack-sponsors' ),
			'set_featured_image'    => __( 'Set sponsor logo', 'newspack-sponsors' ),
			'remove_featured_image' => __( 'Remove sponsor logo', 'newspack-sponsors' ),
			'use_featured_image'    => __( 'Use as sponsor logo', 'newspack-sponsors' ),
		];

		$cpt_args = [
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'supports'     => [ 'editor', 'title', 'custom-fields', 'thumbnail', 'newspack_blocks' ],
			'taxonomies'   => [ 'category', 'post_tag' ], // Regular post categories and tags.
			'menu_icon'    => 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgd2lkdGg9IjI0Ij48cGF0aCB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGQ9Ik0xMiAyQzYuNDggMiAyIDYuNDggMiAxMnM0LjQ4IDEwIDEwIDEwIDEwLTQuNDggMTAtMTBTMTcuNTIgMiAxMiAyem0xLjQxIDE2LjA5VjIwaC0yLjY3di0xLjkzYy0xLjcxLS4zNi0zLjE2LTEuNDYtMy4yNy0zLjRoMS45NmMuMSAxLjA1LjgyIDEuODcgMi42NSAxLjg3IDEuOTYgMCAyLjQtLjk4IDIuNC0xLjU5IDAtLjgzLS40NC0xLjYxLTIuNjctMi4xNC0yLjQ4LS42LTQuMTgtMS42Mi00LjE4LTMuNjcgMC0xLjcyIDEuMzktMi44NCAzLjExLTMuMjFWNGgyLjY3djEuOTVjMS44Ni40NSAyLjc5IDEuODYgMi44NSAzLjM5SDE0LjNjLS4wNS0xLjExLS42NC0xLjg3LTIuMjItMS44Ny0xLjUgMC0yLjQuNjgtMi40IDEuNjQgMCAuODQuNjUgMS4zOSAyLjY3IDEuOTFzNC4xOCAxLjM5IDQuMTggMy45MWMtLjAxIDEuODMtMS4zOCAyLjgzLTMuMTIgMy4xNnoiIGZpbGw9IiNhMGE1YWEiLz48L3N2Zz4K',
		];

		register_post_type( self::NEWSPACK_SPONSORS_CPT, $cpt_args );
	}

	/**
	 * Register custom fields.
	 */
	public static function register_meta() {
		register_meta(
			'post',
			'newspack_sponsor_url',
			[
				'object_subtype'    => self::NEWSPACK_SPONSORS_CPT,
				'description'       => __( 'A URL to link to when displaying this sponsor’s info.', 'newspack-sponsors' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
		register_meta(
			'post',
			'newspack_sponsor_flag_override',
			[
				'object_subtype'    => self::NEWSPACK_SPONSORS_CPT,
				'description'       => __( '(Optional) Text shown in category flag. This text will override site-wide default settings.', 'newspack-sponsors' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
		register_meta(
			'post',
			'newspack_sponsor_disclaimer_override',
			[
				'object_subtype'    => self::NEWSPACK_SPONSORS_CPT,
				'description'       => __( '(Optional) Text shown to explain sponsorship by this sponsor.', 'newspack-sponsors' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
		register_meta(
			'post',
			'newspack_sponsor_byline_prefix',
			[
				'object_subtype'    => self::NEWSPACK_SPONSORS_CPT,
				'description'       => __( '(Optional) Text shown in lieu of a byline on sponsored posts. This is combined with the Sponsor Name to form a full byline.', 'newspack-sponsors' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
		register_meta(
			'post',
			'newspack_sponsor_sponsorship_scope',
			[
				'description'       => __( 'Scope of sponsorship this sponsor offers (native content vs. underwritten).', 'newspack-sponsors' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
		register_meta(
			'post',
			'newspack_sponsor_native_byline_display',
			[
				'description'       => __( 'Display the sponsor only in the byline, or both the sponsor and post author.', 'newspack-sponsors' ),
				'type'              => 'string',
				'default'           => 'inherit',
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
		register_meta(
			'post',
			'newspack_sponsor_native_category_display',
			[
				'description'       => __( 'Display the sponsor only, or display categories alongside the sponsor.', 'newspack-sponsors' ),
				'type'              => 'string',
				'default'           => 'inherit',
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
		register_meta(
			'post',
			'newspack_sponsor_underwriter_style',
			[
				'description'       => __( 'Display the underwriter blurb in standard or simple-text format.', 'newspack-sponsors' ),
				'type'              => 'string',
				'default'           => 'inherit',
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
		register_meta(
			'post',
			'newspack_sponsor_underwriter_placement',
			[
				'description'       => __( 'Display the underwriter blurb at the top or bottom of the post.', 'newspack-sponsors' ),
				'type'              => 'string',
				'default'           => 'inherit',
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
		register_meta(
			'post',
			'newspack_sponsor_only_direct',
			[
				'object_subtype'    => self::NEWSPACK_SPONSORS_CPT,
				'description'       => __( 'If this value is true, this sponsor will not be shown on single posts unless directly assigned to a post. It will still appear on category/tag archive pages, if applicable.', 'newspack-sponsors' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * Registers Sponsors taxonomy which can be applied to posts.
	 * Terms in this taxonomy are not created or edited directly, but are linked to Sponsor posts.
	 */
	public static function register_tax() {
		$labels = [
			'name'                  => __( 'Sponsors', 'newspack-sponsors' ),
			'singular_name'         => __( 'Sponsors', 'newspack-sponsors' ),
			'search_items'          => __( 'Search Sponsors', 'newspack-sponsors' ),
			'all_items'             => __( 'Sponsors', 'newspack-sponsors' ),
			'parent_item'           => __( 'Parent Sponsor', 'newspack-sponsors' ),
			'parent_item_colon'     => __( 'Parent Sponsor:', 'newspack-sponsors' ),
			'edit_item'             => __( 'Edit Sponsor', 'newspack-sponsors' ),
			'view_item'             => __( 'View Sponsor', 'newspack-sponsors' ),
			'update_item'           => __( 'Update Sponsor', 'newspack-sponsors' ),
			'add_new_item'          => __( 'Add New Sponsor', 'newspack-sponsors' ),
			'new_item_name'         => __( 'New Sponsor Name', 'newspack-sponsors' ),
			'not_found'             => __( 'No sponsors found.', 'newspack-sponsors' ),
			'no_terms'              => __( 'No sponsors', 'newspack-sponsors' ),
			'items_list_navigation' => __( 'Sponsors list navigation', 'newspack-sponsors' ),
			'items_list'            => __( 'Sponsors list', 'newspack-sponsors' ),
			'back_to_items'         => __( '&larr; Back to Sponsors', 'newspack-sponsors' ),
			'menu_name'             => __( 'Sponsors', 'newspack-sponsors' ),
			'name_admin_bar'        => __( 'Sponsors', 'newspack-sponsors' ),
			'archives'              => __( 'Sponsors', 'newspack-sponsors' ),
		];

		$tax_args = [
			'capabilities'  => [
				'manage_terms' => '',
				'edit_terms'   => '',
				'delete_terms' => '',
			],
			'hierarchical'  => true,
			'labels'        => $labels,
			'public'        => true,
			'rewrite'       => [ 'slug' => self::NEWSPACK_SPONSORS_TAX ],
			'show_in_menu'  => false,
			'show_in_rest'  => true,
			'show_tagcloud' => false,
			'show_ui'       => true,
		];

		$post_types = apply_filters(
			'newspack_sponsors_post_types',
			[ 'post', 'page' ]
		);

		register_taxonomy( self::NEWSPACK_SPONSORS_TAX, $post_types, $tax_args );
	}

	/**
	 * Create a relationship between the Sponsors CPT and Sponsors tax.
	 */
	public static function create_shadow_relationship() {
		add_action( 'wp_insert_post', [ __CLASS__, 'update_or_delete_shadow_term' ], 10, 2 );
		add_action( 'before_delete_post', [ __CLASS__, 'delete_shadow_term' ] );
	}

	/**
	 * When a sponsor post changes status, add/update the shadow term if the status is `publish`, otherwise delete it.
	 *
	 * @param int   $post_id ID for the post being inserted or saved.
	 * @param array $post Post object for the post being inserted or saved.
	 * @return void
	 */
	public static function update_or_delete_shadow_term( $post_id, $post ) {
		// If the post is a valid post, update or create the shadow term. Otherwise, delete it.
		if ( self::should_update_shadow_term( $post ) ) {
			self::update_shadow_term( $post_id, $post );
		} else {
			self::delete_shadow_term( $post_id );
		}
	}

	/**
	 * Check whether a given post object should have a shadow term.
	 *
	 * @param object $post Post object to check.
	 * @return bool True if the post should have a shadow term, otherwise false.
	 */
	public static function should_update_shadow_term( $post ) {
		$should_update_shadow_term = true;

		// If post isn't published.
		if ( 'publish' !== $post->post_status ) {
			$should_update_shadow_term = false;
		}

		// If post lacks a valid title.
		if ( ! $post->post_title || 'Auto Draft' === $post->post_title ) {
			$should_update_shadow_term = false;
		}

		// If post lacks a valid slug.
		if ( ! $post->post_name ) {
			$should_update_shadow_term = false;
		}

		// If post isn't the right post type.
		if ( self::NEWSPACK_SPONSORS_CPT !== $post->post_type ) {
			return false;
		}

		return $should_update_shadow_term;
	}

	/**
	 * Creates a new taxonomy term, or updates an existing one.
	 *
	 * @param int   $post_id ID for the post being inserted or saved.
	 * @param array $post Post object for the post being inserted or saved.
	 * @return bool|void Nothing if successful, or false if not.
	 */
	public static function update_shadow_term( $post_id, $post ) {
		// Bail if we don't have a valid post or post type.
		if ( empty( $post ) ) {
			return false;
		}

		// Check for a shadow term associated with this post.
		$shadow_term = self::get_shadow_term( $post );

		// If there isn't already a shadow term, create it.
		if ( empty( $shadow_term ) ) {
			self::create_shadow_term( $post );
		} else {
			// Otherwise, update the existing term.
			wp_update_term(
				$shadow_term->term_id,
				self::NEWSPACK_SPONSORS_TAX,
				[
					'name' => $post->post_title,
					'slug' => $post->post_name,
				]
			);
		}
	}

	/**
	 * Deletes an existing shadow taxonomy term when the post is being deleted.
	 *
	 * @param int $post_id ID for the post being deleted.
	 * @return bool|void Nothing if successful, or false if not.
	 */
	public static function delete_shadow_term( $post_id ) {
		$post = get_post( $post_id );

		// Bail if we don't have a valid post or post type.
		if ( empty( $post ) || self::NEWSPACK_SPONSORS_CPT !== $post->post_type ) {
			return false;
		}

		// Check for a shadow term associated with this post.
		$shadow_term = self::get_shadow_term( $post );

		if ( empty( $shadow_term ) ) {
			return false;
		}

		wp_delete_term( $shadow_term->term_id, self::NEWSPACK_SPONSORS_TAX );
	}

	/**
	 * Looks up a shadow taxonomy term linked to a given post.
	 *
	 * @param array $post Post object to look up.
	 * @return array|bool Term object of the linked term, if any, or false.
	 */
	public static function get_shadow_term( $post ) {
		if ( empty( $post ) || empty( $post->post_title ) ) {
			return false;
		}

		// Try finding the shadow term by slug first.
		$shadow_term = get_term_by( 'slug', $post->post_name, self::NEWSPACK_SPONSORS_TAX );

		// If we can't find a term by slug, the post slug may have been updated. Try finding by title instead.
		if ( empty( $shadow_term ) ) {
			$shadow_term = get_term_by( 'name', $post->post_title, self::NEWSPACK_SPONSORS_TAX );
		}

		if ( empty( $shadow_term ) ) {
			return false;
		}

		return $shadow_term;
	}

	/**
	 * Creates a shadow taxonomy term linked to the given post.
	 *
	 * @param array $post Post object for which to create a shadow term.
	 * @return array|bool Term object if successful, false if not.
	 */
	public static function create_shadow_term( $post ) {
		$new_term = wp_insert_term(
			$post->post_title,
			self::NEWSPACK_SPONSORS_TAX,
			[
				'slug' => $post->post_name,
			]
		);

		if ( is_wp_error( $new_term ) ) {
			return false;
		}

		return $new_term;
	}

	/**
	 * Failsafe to ensure that sponsors queries only fetch sponsors post types.
	 * Other plugins may have query filters to add other post types to queries.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function ensure_only_sponsors( $query ) {
		if ( boolval( $query->get( 'is_sponsors' ) ) ) {
			$query->set( 'post_type', self::NEWSPACK_SPONSORS_CPT ); // phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts
		}
	}
}

Core::instance();
