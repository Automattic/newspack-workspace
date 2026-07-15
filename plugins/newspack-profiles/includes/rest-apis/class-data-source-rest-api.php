<?php
/**
 * REST API for data sources.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\RestAPIs;

use NewspackProfiles\Data_Sources;
use NewspackProfiles\Traits\Singleton;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Data_Source_Rest_Api class to handle REST API endpoints for data sources.
 */
class Data_Source_Rest_Api {

	use Singleton;

	const ROUTE_NAMESPACE = 'newspack-profiles/v1';
	const ROUTE_PROFILES  = '/data-sources';
	const ROUTE_SAMPLE    = '/data-sources/sample-data';

	/**
	 * Constructor for the RestApi class.
	 */
	protected function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PROFILES,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_all' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			),
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_SAMPLE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_sample_data' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'dataSource' => array(
							'required' => true,
							'type'     => 'object',
							'oneOf'    => array(
								array(
									'properties'           => array(
										'type'        => array(
											'required' => true,
											'type'     => 'string',
											'enum'     => array( 'google-sheet' ),
										),
										'name'        => array(
											'required' => true,
											'type'     => 'string',
											'pattern'  => '^[a-zA-Z0-9 _-]+$',
										),
										'spreadsheet' => array(
											'required' => true,
											'type'     => 'string',
											'pattern'  => '^[a-zA-Z0-9 _-]+$',
										),
										'sheet'       => array(
											'required' => true,
											'type'     => 'string',
											'pattern'  => '^[a-zA-Z0-9 _-]+$',
										),
									),
									'additionalProperties' => false,
								),
								array(
									'properties'           => array(
										'type'  => array(
											'required' => true,
											'type'     => 'string',
											'enum'     => array( 'airtable' ),
										),
										'name'  => array(
											'required' => true,
											'type'     => 'string',
											'pattern'  => '^[a-zA-Z0-9 _-]+$',
										),
										'base'  => array(
											'required' => true,
											'type'     => 'string',
											'pattern'  => '^[a-zA-Z0-9 _-]+$',
										),
										'table' => array(
											'required' => true,
											'type'     => 'string',
											'pattern'  => '^[a-zA-Z0-9 _-]+$',
										),
									),
									'additionalProperties' => false,
								),
								array(
									'properties'           => array(
										'type'  => array(
											'required' => true,
											'type'     => 'string',
											'enum'     => array( 'wpdb' ),
										),
										'name'  => array(
											'required' => true,
											'type'     => 'string',
											'pattern'  => '^[a-zA-Z0-9 _-]+$',
										),
										'table' => array(
											'required' => true,
											'type'     => 'string',
											'pattern'  => '^[a-zA-Z0-9 _-]+$',
										),
									),
									'additionalProperties' => false,
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Permission check for REST API endpoints.
	 *
	 * @return bool True if the user has permission, false otherwise.
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get all data sources.
	 *
	 * @return WP_REST_Response|WP_Error REST response containing all data sources or WP_Error on failure.
	 */
	public function get_all(): WP_REST_Response|WP_Error {
		return rest_ensure_response( Data_Sources::get_all() );
	}

	/**
	 * Get sample data for data sources.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error REST response containing sample data or WP_Error on failure.
	 */
	public function get_sample_data( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data_source = $request->get_param( 'dataSource' );

		$config = array(
			'dataSource' => $data_source,
			'slugFields' => array(),
		);

		return rest_ensure_response( Data_Sources::get_sample_data( $config ) );
	}
}
