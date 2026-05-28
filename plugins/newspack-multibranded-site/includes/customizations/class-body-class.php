<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Body tag class Customization
 */
class Body_Class {

	/**
	 * Initializes
	 */
	public static function init() {
		add_filter( 'body_class', [ __CLASS__, 'filter_body_class' ] );
	}

	/**
	 * Filters the Blog name
	 *
	 * @param string[] $classes Body tag classes list.
	 * @return string[]
	 */
	public static function filter_body_class( $classes ) {
		$brand = Taxonomy::get_current();
		if ( ! $brand ) {
			return $classes;
		}

		$classes[] = "newspack-brand-{$brand->slug}";

		return $classes;
	}
}
