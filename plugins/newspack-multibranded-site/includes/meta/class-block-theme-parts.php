<?php
/**
 * Newspack Multibranded site: block theme template parts meta.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Meta;

use Newspack_Multibranded_Site\Meta;

/**
 * The template parts a brand shows instead of the main site's, on block themes.
 */
class Block_Theme_Parts extends Meta {

	/**
	 * Gets the meta key
	 *
	 * @return string
	 */
	public static function get_key() {
		return '_block_theme_parts';
	}

	/**
	 * Gets the meta description
	 *
	 * @return string
	 */
	public static function get_description() {
		return __( 'On block themes, the template part this brand shows in each header, footer and sidebar slot, keyed by slot', 'newspack-multibranded-site' );
	}

	/**
	 * Gets the meta schema
	 *
	 * @return array
	 */
	public static function get_schema() {
		return [
			'type'                 => 'object',
			'default'              => [],
			'additionalProperties' => [
				'type' => 'string',
			],
		];
	}
}
