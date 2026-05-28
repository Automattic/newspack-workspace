<?php
/**
 * Newspack's Syndication Settings
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Syndication
 */
class Syndication {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
	}

	/**
	 * Get settings.
	 */
	public static function api_get_settings() {
		return Optional_Modules::get_settings();
	}

	/**
	 * Update settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response with the info.
	 */
	public static function api_update_settings( $request ) {
		$settings = Optional_Modules::get_settings();
		foreach ( Optional_Modules::get_available_optional_modules() as $module_name ) {
			$setting_name              = Optional_Modules::MODULE_ENABLED_PREFIX . $module_name;
			$settings[ $setting_name ] = $request->get_param( $setting_name );
		}
		update_option( Optional_Modules::OPTION_NAME, $settings );
		return Optional_Modules::get_settings();
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Settings', 'newspack' );
	}

	/**
	 * Get the description of this wizard.
	 *
	 * @return string The wizard description.
	 */
	public function get_description() {
		return esc_html__( 'Configure settings.', 'newspack' );
	}

	/**
	 * Get the duration of this wizard.
	 *
	 * @return string A description of the expected duration (e.g. '10 minutes').
	 */
	public function get_length() {
		return esc_html__( '10 minutes', 'newspack' );
	}
}
