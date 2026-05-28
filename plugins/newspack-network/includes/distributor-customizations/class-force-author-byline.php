<?php
/**
 * Newspack Distributor Force Author byline option.
 *
 * @package Newspack
 */

namespace Newspack_Network\Distributor_Customizations;

/**
 * Class to enforce that the "Override Author Byline" option is always disabled.
 */
class Force_Author_Byline {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		add_filter( 'option_dt_settings', [ __CLASS__, 'filter_dt_settings' ] );
		add_filter( 'default_option_dt_settings', [ __CLASS__, 'filter_dt_settings' ] );
		add_action( 'admin_init', [ __CLASS__, 'remove_setting' ], 11 );
	}

	/**
	 * Filter the Distributor settings to ensure that the "Override Author Byline" option is always disabled.
	 *
	 * @param array $settings The Distributor settings.
	 */
	public static function filter_dt_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}
		$settings['override_author_byline'] = false;
		return $settings;
	}

	/**
	 * Remove the "Override Author Byline" setting from the Distributor settings page.
	 */
	public static function remove_setting() {
		global $wp_settings_fields;

		if (
			isset( $wp_settings_fields['distributor'] ) &&
			isset( $wp_settings_fields['distributor']['dt-section-1'] ) &&
			isset( $wp_settings_fields['distributor']['dt-section-1']['override_author_byline'] )
		) {
			unset( $wp_settings_fields['distributor']['dt-section-1']['override_author_byline'] );
		}
	}
}
