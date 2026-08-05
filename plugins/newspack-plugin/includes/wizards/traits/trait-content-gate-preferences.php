<?php
/**
 * Wizard Traits - Content Gate Preferences
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Traits;

use Newspack\Content_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Trait Content_Gate_Preferences
 *
 * Shared /preferences REST route and handler for the wizards that surface the
 * content gate editor (Audience Access control and Premium Newsletters), so
 * the validation and read-back response cannot drift between the two surfaces.
 */
trait Content_Gate_Preferences {
	/**
	 * Register the wizard's /preferences endpoint.
	 */
	public function register_preferences_route() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/preferences',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_preferences' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'presave_checks_enabled' => [
						'type'              => 'boolean',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'default_gate_status'    => [
						'type'              => 'string',
						'enum'              => [ 'publish', 'draft' ],
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
	}

	/**
	 * Update the content gate preferences.
	 *
	 * `presave_checks_enabled` is a per-user preference; `default_gate_status`
	 * is a site-wide default applied to newly created gates.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function update_preferences( $request ) {
		if ( null !== $request->get_param( 'presave_checks_enabled' ) ) {
			Content_Gate::set_presave_checks_enabled( (bool) $request->get_param( 'presave_checks_enabled' ) );
		}
		if ( null !== $request->get_param( 'default_gate_status' ) ) {
			Content_Gate::set_default_new_gate_status( $request->get_param( 'default_gate_status' ) );
		}
		return rest_ensure_response(
			[
				'presave_checks_enabled' => Content_Gate::get_presave_checks_enabled(),
				'default_gate_status'    => Content_Gate::get_default_new_gate_status(),
			]
		);
	}
}
