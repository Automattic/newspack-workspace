<?php
/**
 * Print Section Object.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

/**
 * WordPress dependencies
 */

use Newspack\Optional_Modules;
use Newspack\Optional_Modules\InDesign_Exporter;
use WP_REST_Server;

/**
 * Internal dependencies
 */
use Newspack\Wizards\Wizard_Section;

/**
 * Print Section Object.
 *
 * @package Newspack\Wizards\Newspack
 */
class Print_Section extends Wizard_Section {

	/**
	 * Containing wizard slug.
	 *
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

	/**
	 * Register Wizard Section specific endpoints.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/print',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_print_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/print',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_print_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
	}

	/**
	 * Get print settings.
	 *
	 * @return array
	 */
	public function api_get_print_settings() {
		return [
			'module_enabled_print' => Optional_Modules::is_optional_module_active( InDesign_Exporter::MODULE_NAME ),
			'indesign_platform'    => InDesign_Exporter::get_platform_setting(),
			'indesign_post_types'  => InDesign_Exporter::get_post_types_setting(),
			'available_post_types' => InDesign_Exporter::get_available_post_types(),
		];
	}

	/**
	 * Update print settings.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return array
	 */
	public function api_update_print_settings( $request ) {
		$module_enabled_print = $request->get_param( 'module_enabled_print' );
		if ( ! is_bool( $module_enabled_print ) ) {
			return new \WP_Error( 'invalid_param', __( 'Invalid parameter for module_enabled_print.', 'newspack' ), [ 'status' => 400 ] );
		}

		$has_platform_param = $request->has_param( 'indesign_platform' );
		$platform           = $has_platform_param ? $request->get_param( 'indesign_platform' ) : null;
		if ( $has_platform_param && ! in_array( $platform, InDesign_Exporter::ALLOWED_PLATFORMS, true ) ) {
			return new \WP_Error( 'invalid_param', __( 'Invalid parameter for indesign_platform.', 'newspack' ), [ 'status' => 400 ] );
		}

		$has_post_types_param = $request->has_param( 'indesign_post_types' );
		$post_types           = $has_post_types_param ? $request->get_param( 'indesign_post_types' ) : null;
		if ( $has_post_types_param ) {
			if ( ! is_array( $post_types ) ) {
				return new \WP_Error( 'invalid_param', __( 'Invalid parameter for indesign_post_types.', 'newspack' ), [ 'status' => 400 ] );
			}
			$post_types = array_values(
				array_unique(
					array_filter(
						$post_types,
						static function ( $slug ) {
							return is_string( $slug ) && post_type_exists( $slug );
						}
					)
				)
			);
		}

		if ( $module_enabled_print ) {
			Optional_Modules::activate_optional_module( InDesign_Exporter::MODULE_NAME );
		} else {
			Optional_Modules::deactivate_optional_module( InDesign_Exporter::MODULE_NAME );
		}

		if ( $has_platform_param ) {
			update_option( InDesign_Exporter::PLATFORM_OPTION, $platform );
		}

		if ( $has_post_types_param ) {
			update_option( InDesign_Exporter::POST_TYPES_OPTION, $post_types );
		}

		return [
			'module_enabled_print' => $module_enabled_print,
			'indesign_platform'    => InDesign_Exporter::get_platform_setting(),
			'indesign_post_types'  => InDesign_Exporter::get_post_types_setting(),
			'available_post_types' => InDesign_Exporter::get_available_post_types(),
		];
	}
}
