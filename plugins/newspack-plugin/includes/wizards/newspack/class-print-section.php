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

		if ( $module_enabled_print ) {
			Optional_Modules::activate_optional_module( InDesign_Exporter::MODULE_NAME );
		} else {
			Optional_Modules::deactivate_optional_module( InDesign_Exporter::MODULE_NAME );
		}

		return [
			'module_enabled_print' => $module_enabled_print,
		];
	}
}
