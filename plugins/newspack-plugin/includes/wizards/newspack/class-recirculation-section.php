<?php
/**
 * Newspack > Settings > Advanced Settings Section Object.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

/**
 * WordPress dependencies
 */

use Newspack\Configuration_Managers;

/**
 * Internal dependencies
 */
use Newspack\Wizards\Wizard_Section;
use WP_Error;

/**
 * Custom Events Section Object.
 *
 * @package Newspack\Wizards\Newspack
 */
class Recirculation_Section extends Wizard_Section {

	/**
	 * The name of the option for Related Posts max age.
	 *
	 * @var string
	 */
	const RELATED_POSTS_OPTION = 'newspack_related_posts_max_age';

	/**
	 * Register Wizard Section specific endpoints.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/related-content',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_related_content_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/related-posts-max-age',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_related_posts_max_age' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'sanitize_callback'   => 'sanitize_text_field',
			]
		);
	}

	/**
	 * Get the Jetpack connection settings.
	 *
	 * @return WP_REST_Response with the info.
	 */
	public function api_get_related_content_settings() {
		$jetpack_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'jetpack' );
		return rest_ensure_response(
			[
				'relatedPostsEnabled' => $jetpack_configuration_manager->is_related_posts_enabled(),
				'relatedPostsMaxAge'  => get_option( self::RELATED_POSTS_OPTION, 0 ),
			]
		);
	}

	/**
	 * Update the Related Posts Max Age setting.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Updated value, if successful, or WP_Error.
	 */
	public function api_update_related_posts_max_age( $request ) {
		$args = $request->get_params();

		if ( is_numeric( $args['relatedPostsMaxAge'] ) && 0 <= $args['relatedPostsMaxAge'] ) {
			update_option( self::RELATED_POSTS_OPTION, $args['relatedPostsMaxAge'] );
		} else {
			return new WP_Error(
				'newspack_related_posts_invalid_arg',
				esc_html__( 'Invalid argument: max age must be a number greater than zero.', 'newspack' ),
				[
					'status' => 400,
					'level'  => 'notice',
				]
			);
		}

		return rest_ensure_response(
			[
				'relatedPostsMaxAge' => $args['relatedPostsMaxAge'],
			]
		);
	}
}
