<?php
/**
 * Newspack Multibranded - Access Control integration.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Integrations;

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Access Control integration.
 */
class Access_Control {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'newspack_content_gate_supported_taxonomies', array( __CLASS__, 'add_brand_content_rule' ) );
	}

	/**
	 * Add brand taxonomy as an Access Control content rule.
	 *
	 * @param array $available_taxonomies Array of taxonomies.
	 *
	 * @return array
	 */
	public static function add_brand_content_rule( $available_taxonomies ) {
		if ( ! in_array( Taxonomy::SLUG, array_column( $available_taxonomies, 'slug' ), true ) ) {
			$available_taxonomies[] = array(
				'slug'        => Taxonomy::SLUG,
				'label'       => __( 'Brands', 'newspack-multibranded-site' ),
				'description' => __( 'Content within specific brands.', 'newspack-multibranded-site' ),
			);
		}

		return $available_taxonomies;
	}
}
