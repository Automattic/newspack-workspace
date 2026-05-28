<?php
/**
 * Test Provider.
 *
 * @package Newspack\Tests
 */

use Newspack_Ads\Providers\Provider;

/**
 * Main Class.
 */
class Newspack_Ads_Test_Provider extends Provider {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->provider_id   = 'test_provider';
		$this->provider_name = 'Test Provider';
	}

	/**
	 * The provider available units for placement.
	 *
	 * @param bool $refresh Whether refresh provider units data.
	 *
	 * @return array[
	 *  'name'  => string,
	 *  'value' => string,
	 *  'sizes' => array[]
	 * ] The provider available units for placement.
	 */
	public function get_units( $refresh = false ) {
		return [
			[
				'name'  => 'Test Ad Unit',
				'value' => 'test_ad_unit',
				'sizes' => [
					[ 300, 250 ],
				],
			],
		];
	}

	/**
	 * The ad code for rendering.
	 *
	 * @param string $unit_id        The unit ID.
	 * @param string $placement_key  Optional placement key.
	 * @param string $hook_key       Optional hook key, if the placement has multiple hooks.
	 * @param array  $placement_data Optional placement data.
	 *
	 * @return string $ad_code The ad code for rendering.
	 */
	public function get_ad_code( $unit_id, $placement_key = '', $hook_key = '', $placement_data = [] ) {
		return $unit_id . ' ' . $placement_key . ' ' . $hook_key;
	}
}
