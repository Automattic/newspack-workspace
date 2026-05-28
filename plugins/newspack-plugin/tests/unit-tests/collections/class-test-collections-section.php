<?php
/**
 * Unit tests for the Collections_Section wizard class.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Wizards\Newspack\Collections_Section
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use WP_REST_Request;
use Newspack\Wizards\Newspack\Collections_Section;
use Newspack\Optional_Modules;
use Newspack\Optional_Modules\Collections;
use Newspack\Collections\Settings;

/**
 * Tests for the Collections_Section wizard class.
 */
class Test_Collections_Section extends WP_UnitTestCase {

	use Traits\Trait_Collections_Test;

	/**
	 * Clean up settings before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Optional_Modules::OPTION_NAME );
		delete_option( Settings::OPTION_NAME );
	}

	/**
	 * Test that the class can be instantiated.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section
	 */
	public function test_class_can_be_instantiated() {
		$section = new Collections_Section();
		$this->assertInstanceOf( Collections_Section::class, $section );
	}

	/**
	 * Test that api_get_settings returns an array with the expected keys.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section::api_get_settings
	 */
	public function test_api_get_settings_returns_array() {
		$result = Collections_Section::api_get_settings();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME, $result );
		$this->assertArrayHasKey( 'custom_naming_enabled', $result );
		$this->assertArrayHasKey( 'custom_name', $result );
		$this->assertArrayHasKey( 'custom_singular_name', $result );
		$this->assertArrayHasKey( 'custom_slug', $result );
		$this->assertArrayHasKey( 'subscribe_link', $result );
		$this->assertArrayHasKey( 'order_link', $result );
	}

	/**
	 * Test that api_update_settings updates the module enabled option and returns the new settings.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section::api_update_settings
	 */
	public function test_api_update_settings_updates_module_option() {
		$request = new WP_REST_Request();
		$request->set_param( Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME, true );

		$result = Collections_Section::api_update_settings( $request );
		$this->assertTrue( $result[ Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME ] );

		$settings = get_option( Optional_Modules::OPTION_NAME );
		$this->assertTrue( $settings[ Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME ] );
	}

	/**
	 * Test that api_update_settings updates the subscription link option.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section::api_update_settings
	 */
	public function test_api_update_settings_updates_subscription_link() {
		$request  = new WP_REST_Request();
		$test_url = 'https://example.com/subscribe';
		$request->set_param( 'subscribe_link', $test_url );

		$result = Collections_Section::api_update_settings( $request );
		$this->assertEquals( $test_url, $result['subscribe_link'] );

		$collection_settings = get_option( Settings::OPTION_NAME );
		$this->assertEquals( $test_url, $collection_settings['subscribe_link'] );
	}

	/**
	 * Test that api_update_settings updates the custom naming enabled option.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section::api_update_settings
	 */
	public function test_api_update_settings_updates_custom_naming_enabled() {
		$request = new WP_REST_Request();
		$request->set_param( 'custom_naming_enabled', true );
		$result = Collections_Section::api_update_settings( $request );
		$this->assertTrue( $result['custom_naming_enabled'] );

		$collection_settings = get_option( Settings::OPTION_NAME );
		$this->assertTrue( $collection_settings['custom_naming_enabled'] );
	}

	/**
	 * Test that api_update_settings updates the custom name options.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section::api_update_settings
	 */
	public function test_api_update_settings_updates_custom_name_options() {
		$custom_name          = 'Issues';
		$custom_singular_name = 'Issue';
		$custom_slug          = 'issue';

		$request = new WP_REST_Request();
		$request->set_param( 'custom_name', $custom_name );
		$request->set_param( 'custom_singular_name', $custom_singular_name );
		$request->set_param( 'custom_slug', $custom_slug );

		$result = Collections_Section::api_update_settings( $request );

		$this->assertEquals( $custom_name, $result['custom_name'] );
		$this->assertEquals( $custom_singular_name, $result['custom_singular_name'] );
		$this->assertEquals( $custom_slug, $result['custom_slug'] );

		$collection_settings = get_option( Settings::OPTION_NAME );
		$this->assertEquals( $custom_name, $collection_settings['custom_name'] );
		$this->assertEquals( $custom_singular_name, $collection_settings['custom_singular_name'] );
		$this->assertEquals( $custom_slug, $collection_settings['custom_slug'] );
	}

	/**
	 * Test that api_update_settings handles multiple parameters at once.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section::api_update_settings
	 */
	public function test_api_update_settings_handles_multiple_parameters() {
		$subscribe_link = 'https://example.com/subscribe';
		$custom_name    = 'Issues';

		$request = new WP_REST_Request();
		$request->set_param( Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME, true );
		$request->set_param( 'subscribe_link', $subscribe_link );
		$request->set_param( 'custom_naming_enabled', true );
		$request->set_param( 'custom_name', $custom_name );

		$result = Collections_Section::api_update_settings( $request );

		$this->assertTrue( $result[ Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME ] );
		$this->assertEquals( $subscribe_link, $result['subscribe_link'] );
		$this->assertTrue( $result['custom_naming_enabled'] );
		$this->assertEquals( $custom_name, $result['custom_name'] );
	}

	/**
	 * Test that api_update_settings handles module activation and deactivation.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section::api_update_settings
	 */
	public function test_api_update_settings_handles_module_toggling() {
		// First activate the module.
		$request = new WP_REST_Request();
		$request->set_param( Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME, true );
		$result = Collections_Section::api_update_settings( $request );
		$this->assertTrue( $result[ Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME ] );
		$this->assertTrue( Optional_Modules::is_optional_module_active( Collections::MODULE_NAME ) );

		// Then deactivate it.
		$request = new WP_REST_Request();
		$request->set_param( Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME, false );
		$result = Collections_Section::api_update_settings( $request );
		$this->assertFalse( $result[ Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME ] );
		$this->assertFalse( Optional_Modules::is_optional_module_active( Collections::MODULE_NAME ) );
	}

	/**
	 * Test that permissions check returns true for admin users.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section::api_permissions_check
	 */
	public function test_api_permissions_check_for_admin() {
		$this->set_current_user_role( 'administrator' );
		$section = new Collections_Section();
		$this->assertTrue( $section->api_permissions_check() );
	}

	/**
	 * Test that permissions check returns false for non-admin users.
	 *
	 * @covers \Newspack\Wizards\Newspack\Collections_Section::api_permissions_check
	 */
	public function test_api_permissions_check_for_non_admin() {
		$this->set_current_user_role( 'subscriber' );
		$section = new Collections_Section();
		$result  = $section->api_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'newspack_rest_forbidden', $result->get_error_code() );
	}
}
