<?php
/**
 * Newspack Multibranded site: block theme menus meta.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Meta;

use Newspack_Multibranded_Site\Meta;

/**
 * The menus a brand shows instead of the main site's, on block themes.
 */
class Block_Theme_Menus extends Meta {

	/**
	 * Gets the meta key
	 *
	 * @return string
	 */
	public static function get_key() {
		return '_block_theme_menus';
	}

	/**
	 * Gets the meta description
	 *
	 * @return string
	 */
	public static function get_description() {
		return __( 'On block themes, the menu this brand shows in place of each main site menu, keyed by the main menu ID', 'newspack-multibranded-site' );
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
				'type' => 'integer',
			],
		];
	}
}
