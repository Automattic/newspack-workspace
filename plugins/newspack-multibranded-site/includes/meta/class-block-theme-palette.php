<?php
/**
 * Newspack Multibranded site: block theme base palette meta.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Meta;

use Newspack_Multibranded_Site\Meta;

/**
 * The style variation whose palette a brand starts from, on block themes.
 */
class Block_Theme_Palette extends Meta {

	/**
	 * Gets the meta key
	 *
	 * @return string
	 */
	public static function get_key() {
		return '_block_theme_palette';
	}

	/**
	 * Gets the meta description
	 *
	 * @return string
	 */
	public static function get_description() {
		return __( 'On block themes, the title of the style variation whose colors this brand starts from. Empty for the main site colors', 'newspack-multibranded-site' );
	}

	/**
	 * Gets the meta schema
	 *
	 * @return array
	 */
	public static function get_schema() {
		return [
			'type'    => 'string',
			'default' => '',
		];
	}
}
