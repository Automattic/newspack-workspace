<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Meta\Theme_Colors as Theme_Colors_Meta;
use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Theme_Colors Customization
 */
class Theme_Colors {

	/**
	 * Initializes
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_theme_colors_filters' ] );
	}

	/**
	 * Registers the filters that will be used to filter the theme colors
	 *
	 * Running on init so themes have time to filter the registered colors
	 *
	 * @return void
	 */
	public static function register_theme_colors_filters() {
		$colors = self::get_registered_theme_colors();
		foreach ( $colors as $color ) {
			add_filter( 'theme_mod_' . $color['theme_mod_name'], [ __CLASS__, 'filter_theme_color' ] );
		}
	}

	/**
	 * Gets the theme colors that will be customizable for each brand.
	 *
	 * @return array The theme colors. Each item in the array should one "theme_mod" that holds a color code.
	 */
	public static function get_registered_theme_colors() {
		/**
		 * Filters the theme colors that will be customizable for each brand.
		 *
		 * Themes should use the filter to register which colors they want to be customizable.
		 *
		 * @param array $theme_colors The theme colors. Each item should be an array with the following keys:
		 *                           - theme_mod_name: The name of the theme_mod that holds the color code.
		 *                           - label: The label that will be displayed in the UI.
		 *                           - default: The default color code.
		 */
		return apply_filters( 'newspack_multibranded_site_theme_colors', [] );
	}

	/**
	 * Checks if the current request is for a brand that has a custom color
	 *
	 * Themes can use this method to determine if their colors are being filtered by this plugin
	 *
	 * @param array $color_names An array of color names to check. If empty, it will check if any color is being filtered.
	 * @return bool
	 */
	public static function current_brand_has_custom_colors( $color_names = [] ) {
		$brand = Taxonomy::get_current();
		if ( ! $brand ) {
			return false;
		}
		$custom_colors = get_term_meta( $brand->term_id, Theme_Colors_Meta::get_key(), true );
		if ( ! empty( $custom_colors ) ) {
			if ( empty( $color_names ) ) {
				return true;
			}
			foreach ( $custom_colors as $custom_color ) {
				if ( in_array( $custom_color['name'], $color_names, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Filters the theme color
	 *
	 * @param string $value The theme mod value.
	 * @return string
	 */
	public static function filter_theme_color( $value ) {
		$brand = Taxonomy::get_current();
		if ( ! $brand ) {
			return $value;
		}
		$theme_mod_name = str_replace( 'theme_mod_', '', current_filter() );
		$custom_colors  = get_term_meta( $brand->term_id, Theme_Colors_Meta::get_key(), true );

		if ( ! empty( $custom_colors ) ) {
			foreach ( $custom_colors as $custom_color ) {
				if ( $custom_color['name'] === $theme_mod_name ) {
					return $custom_color['color'];
				}
			}
		}

		return $value;
	}
}
