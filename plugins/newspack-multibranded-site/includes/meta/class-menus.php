<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Meta;

use Newspack_Multibranded_Site\Meta;

/**
 * Class to handle the Menus Meta
 */
class Menus extends Meta {

	/**
	 * Gets the meta key
	 *
	 * @return string
	 */
	public static function get_key() {
		return '_menus';
	}

	/**
	 * Gets the meta description
	 *
	 * @return string
	 */
	public static function get_description() {
		return __( 'An array containing objects describing which menu should be displayed in which location', 'newspack-multibranded-site' );
	}

	/**
	 * Gets the meta schema
	 *
	 * @return array
	 */
	public static function get_schema() {
		return array(
			'type'     => 'array',
			'nullable' => true,
			'items'    => [
				'type'       => 'object',
				'properties' => [
					'location' => [
						'type'     => 'string',
						'nullable' => false,
					],
					'menu'     => [
						'type'     => 'integer',
						'nullable' => false,
					],
				],
			],
		);
	}
}
