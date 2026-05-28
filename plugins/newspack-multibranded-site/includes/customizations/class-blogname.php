<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Blog Name Customization
 */
class Blogname {

	/**
	 * Initializes
	 */
	public static function init() {
		add_filter( 'option_blogname', [ __CLASS__, 'filter_blogname' ] );
	}

	/**
	 * Filters the Blog name
	 *
	 * @param string $blogname The blog name.
	 * @return string
	 */
	public static function filter_blogname( $blogname ) {
		$brand = Taxonomy::get_current();
		if ( ! $brand ) {
			return $blogname;
		}
		return $brand->name;
	}
}
