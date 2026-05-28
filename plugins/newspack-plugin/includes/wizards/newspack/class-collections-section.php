<?php
/**
 * Collections Section Object.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\Optional_Modules;
use Newspack\Optional_Modules\Collections;
use Newspack\Collections\Settings;
use Newspack\Wizards\Wizard_Section;
use WP_REST_Server;

/**
 * Collections Section Object.
 *
 * @package Newspack\Wizards\Newspack
 */
class Collections_Section extends Wizard_Section {
	/**
	 * Containing wizard slug.
	 *
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

	/**
	 * Register Wizard Section specific endpoints.
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/' . Collections::MODULE_NAME,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'api_get_settings' ],
					'permission_callback' => [ $this, 'api_permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'api_update_settings' ],
					'permission_callback' => [ $this, 'api_permissions_check' ],
					'args'                => self::get_collection_fields_args(),
				],
			]
		);
	}

	/**
	 * Get REST API args for collection fields (including module enabled).
	 *
	 * @return array REST API args.
	 */
	private static function get_collection_fields_args() {
		$collection_args = Settings::get_rest_args();
		$module_args     = [
			Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME => [
				'required'          => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
		];

		return array_merge( $module_args, $collection_args );
	}

	/**
	 * Get settings.
	 *
	 * @return array Collections settings.
	 */
	public static function api_get_settings() {
		$settings            = Optional_Modules::get_settings();
		$collection_settings = Settings::get_settings();

		return array_merge( $settings, $collection_settings );
	}

	/**
	 * Update collections settings.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return array Updated collections settings.
	 */
	public static function api_update_settings( $request ) {
		$module            = Collections::MODULE_NAME;
		$enabled_param_key = Optional_Modules::MODULE_ENABLED_PREFIX . $module;
		$settings          = Optional_Modules::get_settings();

		if ( $request->has_param( $enabled_param_key ) ) {
			$is_enabled     = (bool) $request->get_param( $enabled_param_key );
			$current_status = Optional_Modules::is_optional_module_active( $module );

			if ( $is_enabled !== $current_status ) {
				$settings = $is_enabled
					? Optional_Modules::activate_optional_module( $module )
					: Optional_Modules::deactivate_optional_module( $module );

				// Initialize Collections module when turning it on to register flush rewrite-related actions.
				if ( $is_enabled ) {
					Collections::init();
				}

				/**
				 * Fires before flushing rewrite rules after collections module is toggled.
				 *
				 * @param bool $settings Optional module settings.
				 */
				do_action( 'newspack_collections_before_flush_rewrites', $settings );

				flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
			}
		}

		$collection_settings = Settings::update_from_request( $request );

		return array_merge( $settings, $collection_settings );
	}
}
