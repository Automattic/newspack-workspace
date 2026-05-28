<?php
/**
 * Nextdoor Section Object.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

/**
 * WordPress dependencies
 */
use WP_REST_Server;
use WP_Error;

/**
 * Internal dependencies
 */
use Newspack\Optional_Modules;
use Newspack\Nextdoor as Nextdoor_Module;
use Newspack\Wizards\Wizard_Section;
use Newspack\Nextdoor\Auth;

/**
 * Nextdoor Section Object.
 *
 * @package Newspack\Wizards\Newspack
 */
class Nextdoor_Section extends Wizard_Section {

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
		// Nextdoor module toggle endpoint.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/social/nextdoor',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_nextdoor_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/social/nextdoor',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_nextdoor_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'module_enabled_nextdoor' => [
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
					'client_id'               => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'client_secret'           => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'allowed_roles'           => [
						'required' => false,
						'type'     => 'array',
					],
				],
			]
		);
	}

	/**
	 * Get Nextdoor settings via API.
	 *
	 * @return WP_REST_Response
	 */
	public function api_get_nextdoor_settings() {
		$is_enabled        = Optional_Modules::is_optional_module_active( 'nextdoor' );
		$is_connected      = false;
		$connection_status = [];
		$settings          = [];

		if ( $is_enabled ) {
			$is_connected                = Nextdoor_Module::is_connected();
			$settings                    = Nextdoor_Module::get_settings();
			$has_centralized_credentials = Nextdoor_Module::has_centralized_credentials();

			$connection_status = [
				'is_connected'                => $is_connected,
				'has_credentials'             => ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] ),
				'has_centralized_credentials' => $has_centralized_credentials,
				'has_tokens'                  => ! empty( $settings['access_token'] ),
				'has_page'                    => ! empty( $settings['page_id'] ),
				'token_valid'                 => Auth::validate_token(),
			];

			$settings = [
				'client_id'       => $settings['client_id'] ?? '',
				'client_secret'   => $settings['client_secret'] ?? '',
				'publication_url' => $settings['publication_url'] ?? '',
				'allowed_roles'   => $settings['allowed_roles'] ?? [],
			];
		}

		return rest_ensure_response(
			[
				'module_enabled_nextdoor' => $is_enabled,
				'is_connected'            => $is_connected,
				'connection_status'       => $connection_status,
				'settings'                => $settings,
			]
		);
	}

	/**
	 * Update Nextdoor settings via API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function api_update_nextdoor_settings( $request ) {
		$module_enabled = $request->get_param( 'module_enabled_nextdoor' );
		$client_id      = $request->get_param( 'client_id' );
		$client_secret  = $request->get_param( 'client_secret' );
		$allowed_roles  = $request->get_param( 'allowed_roles' );

		if ( null !== $module_enabled ) {
			if ( $module_enabled ) {
				$module_settings = Optional_Modules::activate_optional_module( 'nextdoor' );
			} else {
				$module_settings = Optional_Modules::deactivate_optional_module( 'nextdoor' );
			}
	
			if ( ! $module_settings ) {
				return new WP_Error(
					'newspack_nextdoor_module_update_failed',
					__( 'Failed to update Nextdoor module settings.', 'newspack-plugin' ),
					[ 'status' => 500 ]
				);
			}
		}

		if ( Optional_Modules::is_optional_module_active( 'nextdoor' ) ) {
			$nextdoor_settings = Nextdoor_Module::get_settings();

			if ( null !== $client_id ) {
				$nextdoor_settings['client_id'] = $client_id;
			}

			if ( null !== $client_secret ) {
				$nextdoor_settings['client_secret'] = $client_secret;
			}

			if ( null !== $allowed_roles ) {
				$nextdoor_settings['allowed_roles'] = $allowed_roles;
			}

			Nextdoor_Module::update_settings( $nextdoor_settings );
		}

		return $this->api_get_nextdoor_settings();
	}
}
