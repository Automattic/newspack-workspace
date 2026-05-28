<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Meta\Logo as Logo_Meta;
use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Logo Customization
 */
class Logo {

	/**
	 * Initializes
	 */
	public static function init() {
		add_filter( 'theme_mod_custom_logo', [ __CLASS__, 'filter_logo' ] );
		add_filter( 'get_custom_logo', [ __CLASS__, 'get_custom_logo' ] );
	}

	/**
	 * Filters the logo
	 *
	 * @param int $logo_id The logo ID.
	 * @return int
	 */
	public static function filter_logo( $logo_id ) {
		$brand = Taxonomy::get_current();
		if ( ! $brand ) {
			return $logo_id;
		}
		$custom_logo = get_term_meta( $brand->term_id, Logo_Meta::get_key(), true );
		if ( $custom_logo ) {
			$logo_id = $custom_logo;
		}
		return $logo_id;
	}

	/**
	 * Filters the html output of the custom logo
	 *
	 * @param string $html The custom logo html.
	 * @return string
	 */
	public static function get_custom_logo( $html ) {
		$brand = Taxonomy::get_current();
		if ( ! $brand ) {
			return $html;
		}

		$html = preg_replace( '|href="[^"]+"|', 'href="' . get_term_link( $brand ) . '"', $html );

		return $html;
	}
}
