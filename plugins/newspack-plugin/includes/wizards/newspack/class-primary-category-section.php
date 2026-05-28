<?php
/**
 * Newspack's Primary Category Section.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\Primary_Category;
use Newspack\Wizards\Wizard_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Primary Category Section Class.
 */
class Primary_Category_Section extends Wizard_Section {

	/**
	 * Containing wizard slug.
	 *
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

	/**
	 * Register the endpoints needed for the section.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/primary-category',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/primary-category',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'enabled' => [
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);
	}

	/**
	 * Get primary category settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function api_get_settings() {
		return rest_ensure_response( $this->get_settings() );
	}

	/**
	 * Update primary category settings.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function api_update_settings( $request ) {
		if ( isset( $request['enabled'] ) ) {
			update_option( Primary_Category::OPTION_NAME, (int) $request['enabled'] );
			// Clean up legacy classic theme mod now that the setting is managed here.
			remove_theme_mod( 'post_primary_category' );
		}
		return rest_ensure_response( $this->get_settings() );
	}

	/**
	 * Retrieve settings.
	 *
	 * @return array Settings data.
	 */
	private function get_settings() {
		return [
			'enabled'      => Primary_Category::is_enabled(),
			'yoast_active' => Primary_Category::is_yoast_active(),
		];
	}
}
