<?php
/**
 * Newspack Multibranded site: brand colors on block themes.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Block_Theme;

use Newspack_Multibranded_Site\Meta\Block_Theme_Palette;
use Newspack_Multibranded_Site\Meta\Theme_Colors as Theme_Colors_Meta;

/**
 * Brand colors on block themes come from the global styles palette.
 *
 * Every palette color is offered to the brand color pickers, saved in the same meta as
 * classic theme colors under a prefixed name. On a brand's pages the brand's palette
 * (site palette, then the brand's starting style variation, then its own picks) is merged
 * into the user (global styles) layer, so every block using a palette color follows it.
 */
class Colors {

	/**
	 * Prefix for palette colors in the brand colors meta, so they can sit alongside classic
	 * theme colors without clashing.
	 */
	const PREFIX = 'block_theme_palette_';

	/**
	 * Initializes.
	 */
	public static function init() {
		// Building the list resolves theme.json. current_screen, not is_admin(): admin-ajax and
		// REST requests are admin too, but never show the list.
		add_action( 'current_screen', [ __CLASS__, 'add_palette_colors_filter' ] );
		add_filter( 'wp_theme_json_data_user', [ __CLASS__, 'filter_user_theme_json' ] );
		add_action( 'wp', [ __CLASS__, 'clean_theme_json_cache' ], 20 );
	}

	/**
	 * Turn a palette as stored in theme.json data (a list, or lists keyed by origin) into slug => entry.
	 *
	 * @param mixed $palette Palette data.
	 * @param array $origins Origins to read, lowest priority first.
	 * @return array
	 */
	public static function normalize_palette( $palette, $origins = [ 'default', 'theme', 'custom' ] ) {
		$out = [];
		if ( ! is_array( $palette ) ) {
			return $out;
		}
		$lists = [];
		if ( isset( $palette[0] ) ) {
			$lists[] = $palette;
		} else {
			foreach ( $origins as $origin ) {
				if ( ! empty( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) {
					$lists[] = $palette[ $origin ];
				}
			}
		}
		foreach ( $lists as $list ) {
			foreach ( $list as $entry ) {
				if ( isset( $entry['slug'], $entry['color'] ) ) {
					$out[ $entry['slug'] ] = [
						'slug'  => $entry['slug'],
						'name'  => $entry['name'] ?? $entry['slug'],
						'color' => $entry['color'],
					];
				}
			}
		}
		return $out;
	}

	/**
	 * The site palette: the theme's, with the global styles changes on top.
	 *
	 * @param array|null $user_palette The user palette, when already known.
	 * @return array Slug => entry.
	 */
	public static function site_palette( $user_palette = null ) {
		$theme = \WP_Theme_JSON_Resolver::get_theme_data()->get_settings();
		if ( null === $user_palette ) {
			$user_palette = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data()['settings']['color']['palette'] ?? [];
		}
		return array_merge( self::normalize_palette( $theme['color']['palette'] ?? [], [ 'theme' ] ), self::normalize_palette( $user_palette ) );
	}

	/**
	 * Style variations that define a palette.
	 *
	 * @return array Title => [ slug => color ].
	 */
	public static function variation_palettes() {
		$out = [];
		foreach ( \WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			if ( ! empty( $variation['title'] ) && ! empty( $variation['settings']['color']['palette'] ) ) {
				$out[ $variation['title'] ] = wp_list_pluck( self::normalize_palette( $variation['settings']['color']['palette'] ), 'color' );
			}
		}
		return $out;
	}

	/**
	 * Colors the brand picked itself.
	 *
	 * @param \WP_Term $brand Brand.
	 * @return array Slug => color.
	 */
	public static function brand_picks( $brand ) {
		$out    = [];
		$colors = get_term_meta( $brand->term_id, Theme_Colors_Meta::get_key(), true );
		foreach ( is_array( $colors ) ? $colors : [] as $color ) {
			$color = (array) $color;
			if ( ! empty( $color['name'] ) && ! empty( $color['color'] ) && 0 === strpos( $color['name'], self::PREFIX ) ) {
				$out[ substr( $color['name'], strlen( self::PREFIX ) ) ] = $color['color'];
			}
		}
		return $out;
	}

	/**
	 * A brand's palette: site palette, then the brand's starting variation, then its own picks.
	 *
	 * @param \WP_Term $brand        Brand.
	 * @param array    $site_palette Site palette (slug => entry).
	 * @return array|null Null when the brand changes nothing.
	 */
	public static function brand_palette( $brand, $site_palette ) {
		$title = (string) get_term_meta( $brand->term_id, Block_Theme_Palette::get_key(), true );
		$base  = $title ? ( self::variation_palettes()[ $title ] ?? [] ) : [];
		$picks = self::brand_picks( $brand );
		if ( empty( $base ) && empty( $picks ) ) {
			return null;
		}
		$palette = $site_palette;
		foreach ( array_merge( $base, $picks ) as $slug => $color ) {
			$palette[ $slug ] = isset( $palette[ $slug ] ) ? array_merge( $palette[ $slug ], [ 'color' => $color ] ) : [
				'slug'  => $slug,
				'name'  => $slug,
				'color' => $color,
			];
		}
		return $palette;
	}

	/**
	 * Offers the palette colors on admin screens.
	 */
	public static function add_palette_colors_filter() {
		add_filter( 'newspack_multibranded_site_theme_colors', [ __CLASS__, 'register_palette_colors' ], 20 );
	}

	/**
	 * Offer every palette color to the brand color pickers.
	 *
	 * @param array $colors Registered colors.
	 * @return array
	 */
	public static function register_palette_colors( $colors ) {
		foreach ( self::site_palette() as $slug => $entry ) {
			$colors[] = [
				'theme_mod_name' => self::PREFIX . $slug,
				'label'          => $entry['name'],
				'default'        => $entry['color'],
			];
		}
		return $colors;
	}

	/**
	 * Merge the brand palette into the global styles layer on a brand's pages.
	 *
	 * @param \WP_Theme_JSON_Data $data User theme.json data.
	 * @return \WP_Theme_JSON_Data
	 */
	public static function filter_user_theme_json( $data ) {
		$brand = Block_Theme::current_brand();
		if ( ! $brand ) {
			return $data;
		}
		$raw     = $data->get_data();
		$palette = self::brand_palette( $brand, self::site_palette( $raw['settings']['color']['palette'] ?? [] ) );
		if ( ! $palette ) {
			return $data;
		}
		return $data->update_with(
			[
				'version'  => 3,
				'settings' => [ 'color' => [ 'palette' => array_values( $palette ) ] ],
			]
		);
	}

	/**
	 * Anything that read global styles before the brand was known (on `wp`) has the main
	 * site's colors cached.
	 */
	public static function clean_theme_json_cache() {
		if ( Block_Theme::current_brand() ) {
			wp_clean_theme_json_cache();
		}
	}
}
