<?php
/**
 * REST API for profiles.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\RestAPIs;

use NewspackProfiles\Import_Manager;
use NewspackProfiles\Page_Template_Manager;
use NewspackProfiles\Profile_Collections;
use NewspackProfiles\Sitemap_Generator;
use NewspackProfiles\Traits\Singleton;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Profile_Collections_Rest_Api class to handle REST API endpoints for profiles.
 */
class Profile_Collections_Rest_Api {

	use Singleton;

	private const ROUTE_NAMESPACE                = 'newspack-profiles/v1';
	private const ROUTE_PROFILES                 = '/profile-collections';
	private const ROUTE_DISCONNECT_REMOTE_SOURCE = '/profile-collections/disconnect-remote-source';
	private const ROUTE_UPDATE_STATUS            = '/profile-collections/update-status';

	/**
	 * Data source for profiles.
	 *
	 * @var Profile_Collections
	 */
	private Profile_Collections $profile_collections;

	/**
	 * Page template manager.
	 *
	 * @var Page_Template_Manager
	 */
	private Page_Template_Manager $page_template_manager;

	/**
	 * Import manager.
	 *
	 * @var Import_Manager
	 */
	private Import_Manager $import_manager;

	/**
	 * Sitemap generator.
	 *
	 * @var Sitemap_Generator
	 */
	private Sitemap_Generator $sitemap_generator;

	/**
	 * Constructor for the RestApi class.
	 */
	protected function __construct() {
		$this->profile_collections   = Profile_Collections::get_instance();
		$this->page_template_manager = Page_Template_Manager::get_instance();
		$this->import_manager        = Import_Manager::get_instance();
		$this->sitemap_generator     = Sitemap_Generator::get_instance();

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
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => $this->get_args_schema(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => $this->get_args_schema(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'slug' => array(
							'required' => true,
							'type'     => 'string',
							'pattern'  => '^[a-z0-9]+(-[a-z0-9]+)*$',
						),
					),
				),
			),
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_DISCONNECT_REMOTE_SOURCE,
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'disconnect_remote_data_source' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'slug' => array(
							'required' => true,
							'type'     => 'string',
							'pattern'  => '^[a-z0-9]+(-[a-z0-9]+)*$',
						),
					),
				),
			),
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_UPDATE_STATUS,
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_status' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'slug'   => array(
							'required' => true,
							'type'     => 'string',
							'pattern'  => '^[a-z0-9]+(-[a-z0-9]+)*$',
						),
						'status' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'publish', 'draft' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Get the argument schema for REST API endpoints.
	 *
	 * @return array The argument schema.
	 */
	private function get_args_schema(): array {
		return array(
			'collection' => array(
				'required'             => true,
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'status'      => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'publish', 'draft' ),
					),
					'name'        => array(
						'required' => true,
						'type'     => 'string',
						'pattern'  => '^[a-zA-Z0-9 _-]+$',
					),
					'slug'        => array(
						'required' => true,
						'type'     => 'string',
						'pattern'  => '^[a-z0-9]+(-[a-z0-9]+)*$',
					),
					'slugFields'  => array(
						'required'    => true,
						'type'        => 'array',
						'items'       => array(
							'type'    => 'string',
							'pattern' => '^[a-zA-Z0-9 _()&:/-]*$',
						),
						'minItems'    => 1,
						'uniqueItems' => true,
					),
					'titleFields' => array(
						'required'    => true,
						'type'        => 'array',
						'items'       => array(
							'type'    => 'string',
							'pattern' => '^[a-zA-Z0-9 _()&:/-]*$',
						),
						'uniqueItems' => true,
					),
					'seoFields'   => array(
						'required'             => true,
						'type'                 => 'object',
						'properties'           => array(
							'title'       => array(
								'required' => true,
								'type'     => 'array',
								'items'    => array(
									'type'    => 'string',
									'pattern' => '^[a-zA-Z0-9_()&:/,|-]+(?:\s+[a-zA-Z0-9_()&:/,|-]+)*$',
								),
							),
							'description' => array(
								'required' => true,
								'type'     => 'array',
								'items'    => array(
									'type'    => 'string',
									'pattern' => '^[a-zA-Z0-9_()&:/,|-]+(?:\s+[a-zA-Z0-9_()&:/,|-]+)*$',
								),
							),
							'image'       => array(
								'type'    => 'string',
								'pattern' => '^[a-zA-Z0-9 _()&:/-]*$',
							),
						),
						'additionalProperties' => false,
					),
					'dataSource'  => array(
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
					'mappings'    => array(
						'required'             => true,
						'type'                 => 'object',
						'patternProperties'    => array(
							'^[a-zA-Z0-9 _()&:/-]*$' => array(
								'required'             => true,
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'label'           => array(
										'type'    => 'string',
										'pattern' => '^[a-zA-Z0-9 _()&/-]*$',
									),
									'type'            => array(
										'required' => true,
										'type'     => 'string',
										'enum'     => array( '', 'string', 'button_url', 'image_url', 'social_link' ),
									),
									'social_platform' => array(
										'type'    => 'string',
										'pattern' => '^[a-zA-Z0-9]*$',
									),
									'visible'         => array(
										'type'    => 'boolean',
										'default' => true,
									),
									'order'           => array(
										'type'    => 'integer',
										'default' => 0,
									),
								),
							),
						),
						'additionalProperties' => false,
					),
					'pattern'     => array(
						'required'             => true,
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'single' => array(
								'required' => true,
								'type'     => 'string',
								'pattern'  => '^[a-z0-9]+([-\/][a-z0-9]+)*$',
							),
							'list'   => array(
								'required' => true,
								'type'     => 'string',
								'pattern'  => '^[a-z0-9]+([-\/][a-z0-9]+)*$',
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
	 * Get all profiles.
	 *
	 * @return WP_REST_Response|WP_Error The REST response containing profiles or WP_Error on failure.
	 */
	public function get_all(): WP_REST_Response|WP_Error {
		return rest_ensure_response( $this->profile_collections->get_all() );
	}

	/**
	 * Add a new profile.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error The REST response after adding the profile or WP_Error on failure.
	 */
	public function add( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$collection = $request->get_param( 'collection' );

		$existing_collection = $this->profile_collections->get( $collection['slug'] );

		if ( ! empty( $existing_collection ) ) {
			return rest_ensure_response(
				new WP_Error(
					'profile_collection_exists',
					'Profile already exists.',
					array( 'status' => 400 )
				)
			);
		}

		if ( 'wpdb' === $collection['dataSource']['type'] ) {
			return rest_ensure_response(
				new WP_Error(
					'invalid_data_source',
					'Cannot create a new profile with wpdb data source. This data source type is reserved for internal use.',
					array( 'status' => 400 )
				)
			);
		}

		$pages = $this->page_template_manager->create( $collection );

		if ( empty( $pages ) ) {
			return rest_ensure_response(
				new WP_Error(
					'profile_collection_page_creation_failed',
					'Failed to create profile pages.',
					array( 'status' => 500 )
				)
			);
		}

		$collection['pages'] = $pages;

		$this->profile_collections->add( $collection );

		/**
		 * Update rewrite rules in next init cycle to include new profile rewrite rules.
		 */
		update_option( 'newspack_profiles_flush_rewrite_rules', true );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Update a profile.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error The REST response after updating the profile or WP_Error on failure.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$collection = $request->get_param( 'collection' );

		$existing_collection = $this->profile_collections->get( $collection['slug'] );

		if ( empty( $existing_collection ) ) {
			return rest_ensure_response(
				new WP_Error(
					'profile_collection_not_found',
					'Profile not found.',
					array( 'status' => 404 )
				)
			);
		}

		$collection['pages'] = $existing_collection['pages'];

		if ( 'wpdb' === $existing_collection['dataSource']['type'] && 'wpdb' !== $collection['dataSource']['type'] ) {
			return rest_ensure_response(
				new WP_Error(
					'invalid_data_source_change',
					'Cannot change data source type from wpdb to another type.',
					array( 'status' => 400 )
				)
			);
		}

		if ( 'wpdb' !== $existing_collection['dataSource']['type'] && 'wpdb' === $collection['dataSource']['type'] ) {
			return rest_ensure_response(
				new WP_Error(
					'invalid_data_source_change',
					'Cannot change data source type to wpdb; wpdb is reserved for internal use.',
					array( 'status' => 400 )
				)
			);
		}

		$this->profile_collections->update( $collection );

		$is_same_data_source = $this->is_same_data_source(
			$existing_collection['dataSource'],
			$collection['dataSource']
		);

		$is_single_pattern_changed = $existing_collection['pattern']['single'] !== $collection['pattern']['single'];

		if ( $is_single_pattern_changed || ! $is_same_data_source ) {
			$this->page_template_manager->update( $collection, 'single' );
		}

		$is_list_pattern_changed = $existing_collection['pattern']['list'] !== $collection['pattern']['list'];

		if ( $is_list_pattern_changed || ! $is_same_data_source ) {
			$this->page_template_manager->update( $collection, 'list' );
		}

		if ( 'publish' === $collection['status'] ) {
			$this->sitemap_generator->start_generation( $collection['slug'] );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Delete a profile.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error The REST response after deleting the profile or WP_Error on failure.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = $request->get_param( 'slug' );

		$existing_collection = $this->profile_collections->get( $slug );

		if ( empty( $existing_collection ) ) {
			return rest_ensure_response(
				new WP_Error(
					'profile_collection_not_found',
					'Profile not found.',
					array( 'status' => 404 )
				)
			);
		}

		$this->profile_collections->delete( $slug );
		$this->page_template_manager->delete( $existing_collection['pages'] );
		$this->import_manager->delete_imported_data( $existing_collection );
		$this->sitemap_generator->clear_collection_sitemap( $slug );

		/**
		 * Update rewrite rules in next init cycle to include new profile rewrite rules.
		 */
		update_option( 'newspack_profiles_flush_rewrite_rules', true );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Disconnect the remote data source for a profile.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error The REST response after disconnecting the data source or WP_Error on failure.
	 */
	public function disconnect_remote_data_source( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = $request->get_param( 'slug' );

		$existing_collection = $this->profile_collections->get( $slug );

		if ( empty( $existing_collection ) ) {
			return rest_ensure_response(
				new WP_Error(
					'profile_collection_not_found',
					'Profile not found.',
					array( 'status' => 404 )
				)
			);
		}

		if ( 'wpdb' === $existing_collection['dataSource']['type'] ) {
			return rest_ensure_response(
				new WP_Error(
					'cannot_disconnect_wpdb_source',
					'Cannot disconnect wpdb data source.',
					array( 'status' => 400 )
				)
			);
		}

		$is_disconnected = $this->profile_collections->disconnect_remote_data_source( $slug );

		if ( ! $is_disconnected ) {
			return rest_ensure_response(
				new WP_Error(
					'failed_to_disconnect_data_source',
					'Failed to disconnect remote data source.',
					array( 'status' => 500 )
				)
			);
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Update the status of a profile.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error The REST response after updating the status or an error.
	 */
	public function update_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug   = $request->get_param( 'slug' );
		$status = $request->get_param( 'status' );

		$existing_collection = $this->profile_collections->get( $slug );

		if ( empty( $existing_collection ) ) {
			return rest_ensure_response(
				new WP_Error(
					'profile_collection_not_found',
					'Profile not found.',
					array( 'status' => 404 )
				)
			);
		}

		$existing_collection['status'] = $status;

		$this->profile_collections->update( $existing_collection );
		$this->page_template_manager->update_status( $existing_collection, $status );

		if ( 'publish' === $existing_collection['status'] ) {
			$this->sitemap_generator->start_generation( $existing_collection['slug'] );
		}

		/**
		 * Update rewrite rules in next init cycle to include new profile rewrite rules.
		 */
		update_option( 'newspack_profiles_flush_rewrite_rules', true );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Check if two data sources are the same.
	 *
	 * @param array $ds1 First data source.
	 * @param array $ds2 Second data source.
	 *
	 * @return bool True if the data sources are the same, false otherwise.
	 */
	private function is_same_data_source( array $ds1, array $ds2 ): bool {
		if ( $ds1['type'] !== $ds2['type'] || $ds1['name'] !== $ds2['name'] ) {
			return false;
		}

		switch ( $ds1['type'] ) {
			case 'google-sheet':
				return $ds1['spreadsheet'] === $ds2['spreadsheet'] && $ds1['sheet'] === $ds2['sheet'];
			case 'airtable':
				return $ds1['base'] === $ds2['base'] && $ds1['table'] === $ds2['table'];
			case 'wpdb':
				return $ds1['table'] === $ds2['table'];
			default:
				return true;
		}
	}
}
