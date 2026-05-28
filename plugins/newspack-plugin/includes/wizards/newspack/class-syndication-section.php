<?php
/**
 * Syndication Section Object.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

/**
 * WordPress dependencies
 */

use Newspack\Optional_Modules;
use Newspack\Syndication;
use WP_REST_Server;

/**
 * Internal dependencies
 */
use Newspack\Wizards\Wizard_Section;

/**
 * Syndication Section Object.
 *
 * @package Newspack\Wizards\Newspack
 */
class Syndication_Section extends Wizard_Section {

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
			'/wizard/' . $this->wizard_slug . '/syndication',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ Syndication::class, 'api_get_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		$required_args = array_reduce(
			Optional_Modules::get_available_optional_modules(),
			function( $acc, $module_name ) {
				$acc[ Optional_Modules::MODULE_ENABLED_PREFIX . $module_name ] = [
					'required'          => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
				];
				return $acc;
			},
			[]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/syndication',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ Syndication::class, 'api_update_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => $required_args,
			]
		);
	}
}
