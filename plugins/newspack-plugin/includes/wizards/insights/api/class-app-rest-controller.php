<?php
/**
 * Newspack Insights — Tab 10 App REST controller (NPPD-1882).
 *
 * PR0 endpoints — the connection + property-selection layer:
 *   GET  /newspack-insights/v1/app/config  → connection + property-picker state
 *   POST /newspack-insights/v1/app/config  → persist the chosen app property id
 *
 * The windowed metrics endpoint (GET /app) lands with the metric orchestration in
 * a later PR. Data + persistence live in {@see App_Metric}.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * App REST controller (config surface).
 */
class App_REST_Controller extends WP_REST_Controller {

	use Insights_REST_Trait;

	/**
	 * Shared Insights namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'newspack-insights/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'app';

	/**
	 * Register the App config routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_metrics' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => [
						'start' => [
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => [ $this, 'validate_date_string' ],
							'sanitize_callback' => [ $this, 'sanitize_date' ],
						],
						'end'   => [
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => [ $this, 'validate_date_string' ],
							'sanitize_callback' => [ $this, 'sanitize_date' ],
						],
					],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/config',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_config' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save_config' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => [
						'property_id' => [
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => [ $this, 'validate_property_id' ],
							'sanitize_callback' => [ $this, 'sanitize_property_id' ],
							'description'       => __( 'Numeric GA4 app property ID, or empty string to clear the selection.', 'newspack-plugin' ),
						],
					],
				],
			]
		);
	}

	/**
	 * GET handler — return the App tab config/state.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_config() {
		return rest_ensure_response( App_Metric::get_config() );
	}

	/**
	 * GET handler — windowed metric payloads for the selected app property.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_metrics( WP_REST_Request $request ) {
		$start = (string) $request->get_param( 'start' );
		$end   = (string) $request->get_param( 'end' );
		if ( '' === $start || '' === $end ) {
			return new \WP_Error(
				'invalid_window',
				__( 'A valid start and end date (YYYY-MM-DD) are required.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		return rest_ensure_response( [ 'current' => App_Metric::get_metrics( $start, $end ) ] );
	}

	/**
	 * Sanitize a YYYY-MM-DD date arg; anything else becomes '' (rejected upstream).
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_date( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * POST handler — persist (or clear) the selected app property, then return the
	 * refreshed config so the client re-renders in one round-trip.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_config( WP_REST_Request $request ) {
		App_Metric::set_selected_property_id( (string) $request->get_param( 'property_id' ) );
		return rest_ensure_response( App_Metric::get_config() );
	}

	/**
	 * Validate a property id: only all-digits or an explicitly empty string (the
	 * "clear selection" case) are accepted. Anything else is a 400, so a
	 * client-side bug or a mistyped request can't silently wipe the saved
	 * property.
	 *
	 * @param mixed $value Raw input.
	 * @return bool
	 */
	public function validate_property_id( $value ): bool {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value || ctype_digit( $value );
	}

	/**
	 * Sanitize a validated property id — just trim/cast; validation has already
	 * guaranteed it is digits-or-empty.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_property_id( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
