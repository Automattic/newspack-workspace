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
			'module_enabled_print'      => Optional_Modules::is_optional_module_active( InDesign_Exporter::MODULE_NAME ),
			'indesign_platform'         => InDesign_Exporter::get_platform_setting(),
			'indesign_post_types'       => InDesign_Exporter::get_post_types_setting(),
			'available_post_types'      => InDesign_Exporter::get_available_post_types(),
			'indesign_exclude_captions' => InDesign_Exporter::get_exclude_captions_setting(),
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
			return new \WP_Error( 'invalid_param', __( 'Invalid parameter for module_enabled_print.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$has_platform_param = $request->has_param( 'indesign_platform' );
		$platform           = $has_platform_param ? $request->get_param( 'indesign_platform' ) : null;
		if ( $has_platform_param && ! in_array( $platform, InDesign_Exporter::ALLOWED_PLATFORMS, true ) ) {
			return new \WP_Error( 'invalid_param', __( 'Invalid parameter for indesign_platform.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$has_post_types_param = $request->has_param( 'indesign_post_types' );
		$post_types           = $has_post_types_param ? $request->get_param( 'indesign_post_types' ) : null;
		if ( $has_post_types_param ) {
			if ( ! is_array( $post_types ) ) {
				return new \WP_Error( 'invalid_param', __( 'Invalid parameter for indesign_post_types.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}
			// Validate against the same "available" list the settings UI offers
			// (public + show_ui, minus excluded types), which is the single source
			// of truth for a valid slug. Rejecting here — rather than silently
			// dropping unknown slugs — keeps the stored option from diverging from
			// the effective value the reader returns. Safe at this layer: REST runs
			// long after `init`, so the available list is complete.
			$available_post_types = array_column( InDesign_Exporter::get_available_post_types(), 'value' );
			foreach ( $post_types as $slug ) {
				if ( ! is_string( $slug ) || ! in_array( $slug, $available_post_types, true ) ) {
					return new \WP_Error( 'invalid_param', __( 'Invalid parameter for indesign_post_types.', 'newspack-plugin' ), [ 'status' => 400 ] );
				}
			}
			// Dedupe only after every element is known to be a string —
			// array_unique() stringifies elements while comparing, which would
			// raise "Array to string conversion" on a nested-array entry.
			$post_types = array_values( array_unique( $post_types ) );
		}

		$has_exclude_captions_param = $request->has_param( 'indesign_exclude_captions' );
		$exclude_captions           = $has_exclude_captions_param ? $request->get_param( 'indesign_exclude_captions' ) : null;
		if ( $has_exclude_captions_param && ! is_bool( $exclude_captions ) ) {
			return new \WP_Error( 'invalid_param', __( 'Invalid parameter for indesign_exclude_captions.', 'newspack-plugin' ), [ 'status' => 400 ] );
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

		if ( $has_exclude_captions_param ) {
			update_option( InDesign_Exporter::EXCLUDE_CAPTIONS_OPTION, $exclude_captions );
		}

		return [
			'module_enabled_print'      => $module_enabled_print,
			'indesign_platform'         => InDesign_Exporter::get_platform_setting(),
			'indesign_post_types'       => InDesign_Exporter::get_post_types_setting(),
			'available_post_types'      => InDesign_Exporter::get_available_post_types(),
			'indesign_exclude_captions' => InDesign_Exporter::get_exclude_captions_setting(),
		];
	}
}
