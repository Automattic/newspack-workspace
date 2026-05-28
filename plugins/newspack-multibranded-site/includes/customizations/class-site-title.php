<?php
/**
 * Newspack Multibranded site title.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Site Title customizations for brands.
 */
class Site_Title {

	/**
	 * Initialization.
	 */
	public static function init() {
		add_filter( 'newspack_site_title_url', [ __CLASS__, 'filter_site_url' ] );
	}

	/**
	 * Filters the site url.
	 *
	 * @param string $site_url The site url.
	 * @return string
	 */
	public static function filter_site_url( $site_url ) {
		$brand = Taxonomy::get_current();
		if ( ! $brand ) {
			return $site_url;
		}

		return get_term_link( $brand );
	}
}
