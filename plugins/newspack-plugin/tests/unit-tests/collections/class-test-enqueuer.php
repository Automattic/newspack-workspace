<?php
/**
 * Unit tests for the Collections Enqueuer.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Collections\Enqueuer
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use Newspack\Collections\Enqueuer;

/**
 * Test the Collections Enqueuer functionality.
 */
class Test_Enqueuer extends WP_UnitTestCase {
	use Traits\Trait_Enqueuer_Test;

	/**
	 * Tear down the test environment.
	 */
	public function tear_down() {
		parent::tear_down();

		$this->cleanup_enqueuer_state();
	}

	/**
	 * Test that data can be added and retrieved.
	 *
	 * @covers \Newspack\Collections\Enqueuer::add_data
	 * @covers \Newspack\Collections\Enqueuer::get_data
	 */
	public function test_add_and_get_data() {
		$key       = 'test_key';
		$test_data = [
			'key1' => 'value1',
			'key2' => [ 'nested' => 'value' ],
		];

		Enqueuer::add_data( $key, $test_data );
		$retrieved_data = Enqueuer::get_data();

		$this->assertArrayHasKey( $key, $retrieved_data, 'Data should be stored under the correct key.' );
		$this->assertEquals( $test_data, $retrieved_data[ $key ], 'Retrieved data should match the added data.' );
	}

	/**
	 * Data provider for asset enqueuing tests.
	 *
	 * @return array
	 */
	public function asset_enqueuing_provider() {
		return [
			'admin assets'    => [
				'script_name' => Enqueuer::SCRIPT_NAME_ADMIN,
				'method'      => 'maybe_enqueue_admin_assets',
			],
			'frontend assets' => [
				'script_name' => Enqueuer::SCRIPT_NAME_FRONTEND,
				'method'      => 'maybe_enqueue_frontend_assets',
			],
		];
	}

	/**
	 * Test that assets are not enqueued when no data is present.
	 *
	 * @param string $script_name The script name constant.
	 * @param string $method      The method to call.
	 * @dataProvider asset_enqueuing_provider
	 * @covers \Newspack\Collections\Enqueuer::maybe_enqueue_admin_assets
	 * @covers \Newspack\Collections\Enqueuer::maybe_enqueue_frontend_assets
	 */
	public function test_maybe_enqueue_assets_no_data( $script_name, $method ) {
		// Call the method with no data.
		Enqueuer::$method();

		// Verify no scripts were enqueued.
		$this->assertFalse( wp_script_is( $script_name, 'enqueued' ), 'Script should not be enqueued when no data is present.' );
		$this->assertFalse( wp_style_is( $script_name, 'enqueued' ), 'Style should not be enqueued when no data is present.' );
	}

	/**
	 * Test that assets are enqueued when data is present.
	 *
	 * @param string $script_name The script name constant.
	 * @param string $method      The method to call.
	 * @dataProvider asset_enqueuing_provider
	 * @covers \Newspack\Collections\Enqueuer::maybe_enqueue_admin_assets
	 * @covers \Newspack\Collections\Enqueuer::maybe_enqueue_frontend_assets
	 */
	public function test_maybe_enqueue_assets_with_data( $script_name, $method ) {
		// Add test data.
		$test_data = [ 'test' => 'value' ];
		Enqueuer::add_data( 'test_key', $test_data );

		// Call the method with data.
		Enqueuer::$method();

		// Verify scripts and styles were enqueued.
		$this->assertTrue( wp_script_is( $script_name, 'enqueued' ), 'Script should be enqueued when data is present.' );
		$this->assertTrue( wp_style_is( $script_name, 'enqueued' ), 'Style should be enqueued when data is present.' );

		// Verify data was localized.
		global $wp_scripts;
		$script = $wp_scripts->registered[ $script_name ];
		$this->assertArrayHasKey( 'data', $script->extra, 'Script should have localized data.' );
		$this->assertStringContainsString( Enqueuer::JS_OBJECT_NAME, $script->extra['data'], 'Data should be localized with the correct object name.' );
	}
}
