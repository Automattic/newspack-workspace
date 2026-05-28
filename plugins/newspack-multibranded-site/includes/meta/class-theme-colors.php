<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Meta;

use Newspack_Multibranded_Site\Meta;

/**
 * Class to handle the Theme Colors Meta
 */
class Theme_Colors extends Meta {

	/**
	 * Gets the meta key
	 *
	 * @return string
	 */
	public static function get_key() {
		return '_theme_colors';
	}

	/**
	 * Gets the meta description
	 *
	 * @return string
	 */
	public static function get_description() {
		return __( 'An array of customizable colors defined by the theme. Each item has a name, which refers to the theme_mod name, and a value, which is a color hex code.', 'newspack-multibranded-site' );
	}

	/**
	 * Gets the meta schema
	 *
	 * @return array
	 */
	public static function get_schema() {
		return array(
			'type'     => 'array',
			'items'    => [
				'type'       => 'object',
				'properties' => [
					'name'  => [
						'type' => 'string',
					],
					'color' => [
						'type'      => 'string',
						'maxLength' => 7,
						'pattern'   => '^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$',
					],
				],
			],
			'nullable' => true,
			'default'  => [],
		);
	}
}
