<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Meta\Menus as Menus_Meta;
use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Menu Customization
 */
class Menus {

	/**
	 * Initializes
	 */
	public static function init() {
		add_filter( 'theme_mod_nav_menu_locations', [ __CLASS__, 'filter_nav_menu_locations' ] );
	}

	/**
	 * Filters the nav_menu_locations theme mod
	 *
	 * @param int $nav_menu_locations The nav_menu_locations ID.
	 * @return int
	 */
	public static function filter_nav_menu_locations( $nav_menu_locations ) {
		$brand = Taxonomy::get_current();
		if ( ! $brand || ! is_array( $nav_menu_locations ) ) {
			return $nav_menu_locations;
		}

		$custom_menus = get_term_meta( $brand->term_id, Menus_Meta::get_key(), true );
		if ( empty( $custom_menus ) ) {
			return $nav_menu_locations;
		}
		foreach ( $custom_menus as $custom_menu ) {
			$custom_menu = (array) $custom_menu;
			if ( empty( $custom_menu['menu'] ) ) {
				continue;
			}
			$nav_menu_locations[ $custom_menu['location'] ] = $custom_menu['menu'];
		}

		return $nav_menu_locations;
	}
}
