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
				// Declaring a `sanitize_callback` stops WordPress from installing its default
				// validator, so every arg below names `rest_validate_request_arg` explicitly.
				// Without it the types are documentation rather than rules.
				'args'                => [
					// An omitted `module_enabled_nextdoor` deliberately means "no change": the handler below
					// only acts on a non-null value. The settings card POSTs just the fields it touches, so
					// adding a `default` here would silently deactivate the module on every one of those saves.
					'module_enabled_nextdoor' => [
						'required'          => false,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'client_id'               => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'client_secret'           => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'allowed_roles'           => [
						'required'          => false,
						'type'              => 'array',
						'items'             => [ 'type' => 'string' ],
						'sanitize_callback' => [ $this, 'sanitize_allowed_roles' ],
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
	}

	/**
	 * Restrict the submitted roles to the roles registered on this site.
	 *
	 * `rest_is_array()` accepts a comma-separated scalar, so the schema alone would
	 * let one through to be stored as an empty list. Refuse it instead of emptying
	 * the publishing roles behind the publisher's back.
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]|WP_Error Role names, re-indexed, or an error if not a list.
	 */
	public function sanitize_allowed_roles( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'newspack_nextdoor_invalid_allowed_roles',
				__( 'Publishing roles must be submitted as a list.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$submitted = array_filter( $value, 'is_string' );
		// The offered roles, not every role on the site, so the endpoint cannot grant
		// what the picker withholds.
		$roles = wp_list_pluck( Nextdoor_Module::get_available_roles(), 'value' );

		return array_values( array_unique( array_intersect( $submitted, $roles ) ) );
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
				// Read off the stored token: refreshing here would make every settings load wait on Nextdoor.
				'token_valid'                 => Auth::has_usable_token(),
			];

			$settings = [
				'client_id'       => $settings['client_id'] ?? '',
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
	 * @return WP_REST_Response|WP_Error
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

			// Once the module is off nothing is left running to reconcile the publishing
			// capability, so it has to be revoked on the way out.
			if ( ! $module_enabled ) {
				Nextdoor_Module::remove_nextdoor_capability();
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

			// `admin_init` never fires on a REST request, so grant and revoke the
			// publishing capability here rather than on the next wp-admin page load.
			Nextdoor_Module::add_nextdoor_capability();
		}

		return $this->api_get_nextdoor_settings();
	}
}
