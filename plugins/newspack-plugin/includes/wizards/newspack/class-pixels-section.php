<?php
/**
 * Tracking Pixels Section Object. Supports Meta and X Pixels.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

/**
 * WordPress dependencies
 */
use WP_REST_Server;

/**
 * Internal dependencies
 */
use Newspack\Meta_Pixel;
use Newspack\Twitter_Pixel;
use Newspack\Wizards\Wizard_Section;

/**
 * Tracking Pixels Section Object.
 *
 * @package Newspack\Wizards\Newspack
 */
class Pixels_Section extends Wizard_Section {

	/**
	 * Register Wizard Section specific endpoints.
	 *
	 * @return void 
	 */
	public function register_rest_routes() {
		$meta_pixel = new Meta_Pixel();
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/social/meta_pixel',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $meta_pixel, 'api_get' ],
					'permission_callback' => [ $this, 'api_permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $meta_pixel, 'api_save' ],
					'permission_callback' => [ $this, 'api_permissions_check' ],
					'args'                => [
						'active'   => [
							'type'              => 'boolean',
							'required'          => true,
							'validate_callback' => [ $meta_pixel, 'validate_active' ],
						],
						'pixel_id' => [
							'type'              => [ 'string' ],
							'required'          => true,
							'validate_callback' => [ $meta_pixel, 'validate_pixel_id' ],
						],
					],
				],
			]
		);

		$x_pixel = new Twitter_Pixel();
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/social/x_pixel',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $x_pixel, 'api_get' ],
					'permission_callback' => [ $this, 'api_permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $x_pixel, 'api_save' ],
					'permission_callback' => [ $this, 'api_permissions_check' ],
					'args'                => [
						'active'   => [
							'type'              => 'boolean',
							'required'          => true,
							'validate_callback' => [ $x_pixel, 'validate_active' ],
						],
						'pixel_id' => [
							'type'              => [ 'integer', 'string' ],
							'required'          => true,
							'validate_callback' => [ $x_pixel, 'validate_pixel_id' ],
						],
					],
				],
			]
		);
	}
}
