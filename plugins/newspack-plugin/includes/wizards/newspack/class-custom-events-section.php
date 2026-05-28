<?php
/**
 * Custom Events Section Object.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

/**
 * WordPress dependencies
 */
use WP_Error, WP_REST_Server;

/**
 * Internal dependencies
 */
use Newspack\Wizards\Wizard_Section;

/**
 * Custom Events Section Object.
 *
 * @package Newspack\Wizards\Newspack
 */
class Custom_Events_Section extends Wizard_Section {

	/**
	 * Register Wizard Section specific endpoints.
	 *
	 * @return void 
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE_V2,
			'/wizard/analytics/ga4-credentials',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_set_ga4_credentials' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'measurement_id'              => [
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [ $this, 'validate_measurement_id' ],
					],
					'measurement_protocol_secret' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE_V2,
			'/wizard/analytics/ga4-credentials/reset',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_reset_ga4_credentials' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
	}

	/**
	 * Updates the GA4 crendetials
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_set_ga4_credentials( $request ) {
		$measurement_id              = $request->get_param( 'measurement_id' );
		$measurement_protocol_secret = $request->get_param( 'measurement_protocol_secret' );

		if ( ! $measurement_protocol_secret ) {
			return new WP_Error(
				'newspack_analytics_wizard_invalid_params',
				esc_html__( 'Invalid Measurement Protocol API Secret.', 'newspack' ),
				[ 'status' => 400 ]
			);
		}

		update_option( 'ga4_measurement_id', sanitize_text_field( $measurement_id ) );
		update_option( 'ga4_measurement_protocol_secret', sanitize_text_field( $measurement_protocol_secret ) );

		return rest_ensure_response(
			[
				'measurement_id'              => esc_html( $measurement_id ),
				'measurement_protocol_secret' => esc_html( $measurement_protocol_secret ),
			] 
		);
	}

	/**
	 * Reset the GA4 crendetials
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_reset_ga4_credentials() {
		delete_option( 'ga4_measurement_id' );
		delete_option( 'ga4_measurement_protocol_secret' );
		return rest_ensure_response(
			[
				'measurement_id'              => '',
				'measurement_protocol_secret' => '',
			] 
		);
	}

	/**
	 * Gets the credentials for the GA4 API.
	 *
	 * @return array
	 */
	public static function get_data() {
		return [
			'measurement_id'              => esc_html( get_option( 'ga4_measurement_id', '' ) ),
			'measurement_protocol_secret' => esc_html( get_option( 'ga4_measurement_protocol_secret', '' ) ),
		];
	}

	/**
	 * Validates the Measurement ID
	 *
	 * @link https://measureschool.com/ga4-measurement-id/
	 * @param string $value The value to validate.
	 * @return bool
	 */
	public function validate_measurement_id( $value ) {
		return is_string( $value ) && preg_match( '/^G-[A-Za-z0-9]{10,}$/', $value );
	}
}
