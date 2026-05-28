<?php
/**
 * Tests the Settings.
 *
 * @package Newspack\Tests
 */

use Newspack\Optional_Modules;
use Newspack\Syndication;

/**
 * Tests the Settings.
 */
class Newspack_Test_Settings extends WP_UnitTestCase {
	/**
	 * Setup for the tests.
	 */
	public function set_up() {
		delete_option( Optional_Modules::OPTION_NAME );
	}

	/**
	 * Default settings.
	 */
	public function test_settings_defaults() {
		self::assertEquals(
			Optional_Modules::get_settings(),
			[
				'module_enabled_rss'                   => false,
				'module_enabled_media-partners'        => false,
				'module_enabled_woo-member-commenting' => false,
				'module_enabled_collections'           => false,
				'module_enabled_indesign-export'       => false,
				'module_enabled_nextdoor'              => false,
			],
			'Default settings are as expected.'
		);
	}

	/**
	 * Updating settings.
	 */
	public function test_settings_update() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_rss', true );
		Syndication::api_update_settings( $request );
		self::assertEquals(
			Optional_Modules::get_settings(),
			[
				'module_enabled_rss'                   => true,
				'module_enabled_media-partners'        => false,
				'module_enabled_woo-member-commenting' => false,
				'module_enabled_collections'           => false,
				'module_enabled_indesign-export'       => false,
				'module_enabled_nextdoor'              => false,
			],
			'Settings is updated.'
		);

		$request->set_param( 'module_enabled_collections', true );
		Syndication::api_update_settings( $request );
		self::assertEquals(
			Optional_Modules::get_settings(),
			[
				'module_enabled_rss'                   => true,
				'module_enabled_media-partners'        => false,
				'module_enabled_woo-member-commenting' => false,
				'module_enabled_collections'           => true,
				'module_enabled_indesign-export'       => false,
				'module_enabled_nextdoor'              => false,
			],
			'Settings is updated.'
		);

		$request->set_param( 'non_existent_setting', true );
		Syndication::api_update_settings( $request );
		self::assertEquals(
			Optional_Modules::get_settings(),
			[
				'module_enabled_rss'                   => true,
				'module_enabled_media-partners'        => false,
				'module_enabled_woo-member-commenting' => false,
				'module_enabled_collections'           => true,
				'module_enabled_indesign-export'       => false,
				'module_enabled_nextdoor'              => false,
			],
			'A non-existent setting is not saved.'
		);
	}

	/**
	 * Optional modules.
	 */
	public function test_settings_optional_modules() {
		self::assertEquals(
			Optional_Modules::is_optional_module_active( 'rss' ),
			false,
			'RSS module is not active by default.'
		);

		Optional_Modules::activate_optional_module( 'rss' );

		self::assertEquals(
			Optional_Modules::is_optional_module_active( 'rss' ),
			true,
			'RSS module is active after being activated.'
		);

		Optional_Modules::deactivate_optional_module( 'rss' );
		self::assertEquals(
			Optional_Modules::is_optional_module_active( 'rss' ),
			false,
			'RSS module is deactivated.'
		);

		// Test collections module activation.
		self::assertEquals(
			Optional_Modules::is_optional_module_active( 'collections' ),
			false,
			'Collections module is not active by default.'
		);

		Optional_Modules::activate_optional_module( 'collections' );
		self::assertEquals(
			Optional_Modules::is_optional_module_active( 'collections' ),
			true,
			'Collections module is active after being activated.'
		);

		Optional_Modules::deactivate_optional_module( 'collections' );
		self::assertEquals(
			Optional_Modules::is_optional_module_active( 'collections' ),
			false,
			'Collections module is deactivated.'
		);
	}
}
