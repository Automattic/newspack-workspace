<?php
/**
 * Newspack Multibranded Google Analytics integration.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Integrations;

/**
 * Class to handle the Google Analytics Integration.
 */
class Google_Analytics {

	/**
	 * Initializes
	 */
	public static function init() {
		add_filter( 'newspack_ga4_custom_parameters', [ __CLASS__, 'newspack_ga4_custom_parameters' ] );
	}

	/**
	 * Filters the Blog name
	 *
	 * @param array $params Custom parameters sent to GA4.
	 */
	public static function newspack_ga4_custom_parameters( $params ) {
		$brand = \Newspack_Multibranded_Site\Taxonomy::get_current();
		if ( ! $brand ) {
			return $params;
		}
		$params['brand'] = $brand->name;
		return $params;
	}
}
