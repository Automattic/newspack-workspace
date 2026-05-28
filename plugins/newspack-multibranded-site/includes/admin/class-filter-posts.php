<?php
/**
 * Newspack Authors Primary Brand.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Admin;

use Newspack_Multibranded_Site\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Authors Primary Brand.
 */
class Filter_Posts {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'restrict_manage_posts', [ __CLASS__, 'add_brands_dropdown' ] );
	}

	/**
	 * Adds a dropdown to filter posts by brand
	 *
	 * @param string $post_type The post type of the current list.
	 * @return void
	 */
	public static function add_brands_dropdown( $post_type ) {
		if ( ! in_array( $post_type, Taxonomy::get_post_types(), true ) ) {
			return;
		}

		$num_posts_with_brand = wp_count_terms(
			[
				'taxonomy'   => Taxonomy::SLUG,
				'hide_empty' => true,
			]
		);
		// If we have no brands or no posts with a brand, then don't show the dropdown.
		if ( $num_posts_with_brand < 1 ) {
			return;
		}

		$taxonomy_object = get_taxonomy( Taxonomy::SLUG );
		$selected        = isset( $_GET[ Taxonomy::SLUG ] ) ? sanitize_text_field( wp_unslash( $_GET[ Taxonomy::SLUG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_dropdown_categories(
			array(
				'show_option_all' => $taxonomy_object->labels->all_items,
				'taxonomy'        => Taxonomy::SLUG,
				'name'            => Taxonomy::SLUG,
				'orderby'         => 'name',
				'value_field'     => 'slug',
				'selected'        => $selected,
				'hierarchical'    => false,
			)
		);
	}
}
