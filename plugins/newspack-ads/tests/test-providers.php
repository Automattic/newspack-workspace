<?php
/**
 * Tests the ads providers functionality.
 *
 * @package Newspack\Tests
 */

use Newspack_Ads\Providers;
use Newspack_Ads\Providers\Provider;

/**
 * Test ads providers functionality.
 */
class ProvidersTest extends WP_UnitTestCase {

	/**
	 * Test provider.
	 *
	 * @var Newspack_Ads_Test_Provider
	 */
	private static $provider = null;

	/**
	 * Set up test
	 */
	public function set_up() {
		include_once __DIR__ . '/class-newspack-ads-test-provider.php';
		// Register the test provider.
		self::$provider = new Newspack_Ads_Test_Provider();
		Providers::register_provider( self::$provider );
	}

	/**
	 * Test serialised provider.
	 */
	public function test_serialised_provider() {
		$serialised_provider = Providers::get_serialised_provider( self::$provider );
		self::assertEquals(
			[
				'id'     => self::$provider->get_provider_id(),
				'name'   => self::$provider->get_provider_name(),
				'active' => self::$provider->is_active(),
			],
			$serialised_provider
		);
	}

	/**
	 * Test getting a registered provider.
	 */
	public function test_get_provider() {
		$provider = Providers::get_provider( self::$provider->get_provider_id() );
		self::assertTrue(
			$provider instanceof Provider
		);
		self::assertEquals(
			self::$provider->get_provider_id(),
			$provider->get_provider_id()
		);
	}

	/**
	 * Test rendering ad code for a placement.
	 */
	public function test_render_placement() {
		ob_start();
		Providers::render_placement_ad_code(
			'test_ad_unit',
			self::$provider->get_provider_id(),
			'test_placement_id',
			'test_hook_key',
			[]
		);
		$code = ob_get_clean();
		self::assertEquals(
			'test_ad_unit test_placement_id test_hook_key',
			$code
		);
	}

	/**
	 * Test method to get a provider data.
	 */
	public function test_provider_data() {
		self::assertEquals(
			[
				'id'     => 'test_provider',
				'name'   => 'Test Provider',
				'active' => true,
				'units'  => [
					[
						'name'  => 'Test Ad Unit',
						'value' => 'test_ad_unit',
						'sizes' => [
							[ 300, 250 ],
						],
					],
				],
			],
			Providers::get_provider_data( 'test_provider' )
		);
	}

	/**
	 * Test method to get a provider unit data.
	 */
	public function test_provider_unit_data() {
		self::assertEquals(
			[
				'name'  => 'Test Ad Unit',
				'value' => 'test_ad_unit',
				'sizes' => [
					[ 300, 250 ],
				],
			],
			Providers::get_provider_unit_data( 'test_provider', 'test_ad_unit' )
		);
		self::assertNull( Providers::get_provider_unit_data( 'test_provider', 'not_an_ad_unit' ) );
	}
}
