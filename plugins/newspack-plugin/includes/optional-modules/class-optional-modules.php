<?php
/**
 * Optional modules.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Shared code for optional modules.
 */
class Optional_Modules {
	/**
	 * The name of the option that stores the settings.
	 *
	 * @TODO: Consider a more relevant option name e.g. 'newspack_optional_modules_settings'.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'newspack_settings';

	/**
	 * The prefix for the module enabled settings.
	 *
	 * @var string
	 */
	const MODULE_ENABLED_PREFIX = 'module_enabled_';

	/**
	 * Get all settings.
	 */
	public static function get_settings() {
		$default_settings = [
			self::MODULE_ENABLED_PREFIX . 'rss'            => false,
			self::MODULE_ENABLED_PREFIX . 'media-partners' => false,
			self::MODULE_ENABLED_PREFIX . 'woo-member-commenting' => false,
			self::MODULE_ENABLED_PREFIX . 'collections'    => false,
			self::MODULE_ENABLED_PREFIX . 'indesign-export' => false,
			self::MODULE_ENABLED_PREFIX . 'nextdoor'       => false,
		];
		return wp_parse_args( get_option( self::OPTION_NAME ), $default_settings );
	}

	/**
	 * Update a single setting value.
	 *
	 * @param string $key Setting key.
	 * @param string $value Setting value.
	 */
	private static function update_setting( $key, $value ) {
		$settings = self::get_settings();
		if ( isset( $settings[ $key ] ) ) {
			$settings[ $key ] = $value;
			update_option( self::OPTION_NAME, $settings );
		}
		return $settings;
	}

	/**
	 * Get the list of available optional modules.
	 *
	 * @return array List of available optional modules.
	 */
	public static function get_available_optional_modules(): array {
		return [ 'rss', 'woo-member-commenting', 'collections', 'indesign-export', 'nextdoor' ];
	}

	/**
	 * Check if an optional module is active.
	 *
	 * @param string $module_name Name of the module.
	 */
	public static function is_optional_module_active( $module_name ) {
		$settings     = self::get_settings();
		$setting_name = self::MODULE_ENABLED_PREFIX . $module_name;
		if ( isset( $settings[ $setting_name ] ) ) {
			return $settings[ $setting_name ];
		}
		return false;
	}

	/**
	 * Activate an optional module.
	 *
	 * @param string $module_name Name of the module.
	 */
	public static function activate_optional_module( $module_name ) {
		return self::update_setting( self::MODULE_ENABLED_PREFIX . $module_name, true );
	}

	/**
	 * Deactivate an optional module.
	 *
	 * @param string $module_name Name of the module.
	 */
	public static function deactivate_optional_module( string $module_name ) {
		return self::update_setting( self::MODULE_ENABLED_PREFIX . $module_name, false );
	}
}
