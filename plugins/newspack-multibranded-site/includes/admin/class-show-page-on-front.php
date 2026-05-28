<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Admin;

use Newspack_Multibranded_Site\Customizations\Show_Page_On_Front as Show_Page_On_Front_Customization;
use Newspack_Multibranded_Site\Taxonomy;
use WP_Term;

/**
 * Class to handle the Show_Page_On_Front Admin tweaks
 */
class Show_Page_On_Front {

	/**
	 * Initializes
	 */
	public static function init() {
		add_filter( 'display_post_states', [ __CLASS__, 'add_display_post_states' ], 10, 2 );
	}

	/**
	 * Adds a posts state to the page listing if it is a front page for a brand
	 *
	 * @param array   $post_states The page post states.
	 * @param WP_Post $post The current post object.
	 * @return array
	 */
	public static function add_display_post_states( $post_states, $post ) {
		if ( 'page' !== $post->post_type ) {
			return $post_states;
		}

		$brand = Show_Page_On_Front_Customization::get_brand_page_is_cover_for( $post->ID );
		if ( ! $brand ) {
			return $post_states;
		}

		$brand = get_term( $brand, Taxonomy::SLUG );
		if ( $brand instanceof WP_Term ) {
			$post_states['newspack-front-page'] = sprintf(
				/* translators: %s: Brand name */
				__( 'Front page for %s', 'newspack-multibranded-site' ),
				$brand->name
			);
		}
		return $post_states;
	}
}
