<?php
/**
 * Newspack Network Node integrity check endpoints.
 *
 * @package Newspack
 */

namespace Newspack_Network\Node;

use Newspack_Network\Integrity_Check_Utils;

/**
 * Class that registers the integrity check endpoints for nodes
 */
class Integrity_Check_Endpoints {
	/**
	 * Runs the initialization.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the routes for the integrity check endpoints.
	 */
	public static function register_routes() {
		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/hash',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_hash_request' ],
					'permission_callback' => function( $request ) {
						return \Newspack_Network\Rest_Authenticaton::verify_signature( $request, 'integrity-check', Settings::get_secret_key() );
					},
				],
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/memberships',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_memberships_request' ],
					'permission_callback' => function( $request ) {
						return \Newspack_Network\Rest_Authenticaton::verify_signature( $request, 'integrity-check', Settings::get_secret_key() );
					},
				],
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/range-hash',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_range_hash_request' ],
					'permission_callback' => function( $request ) {
						return \Newspack_Network\Rest_Authenticaton::verify_signature( $request, 'integrity-check', Settings::get_secret_key() );
					},
					'args'                => [
						'start' => [
							'required' => true,
							'type'     => 'string',
						],
						'end'   => [
							'required' => true,
							'type'     => 'string',
						],
						'max'   => [
							'required' => false,
							'type'     => 'integer',
						],
					],
				],
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/integrity-check/range-data',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'handle_range_data_request' ],
					'permission_callback' => function( $request ) {
						return \Newspack_Network\Rest_Authenticaton::verify_signature( $request, 'integrity-check', Settings::get_secret_key() );
					},
					'args'                => [
						'start' => [
							'required' => true,
							'type'     => 'string',
						],
						'end'   => [
							'required' => true,
							'type'     => 'string',
						],
						'max'   => [
							'required' => false,
							'type'     => 'integer',
						],
					],
				],
			]
		);
	}

	/**
	 * Handles the hash request.
	 * 
	 * Returns hash for memberships within a specific email range, enabling range-based
	 * chunking that avoids the shifting problem of positional chunks.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_hash_request( $request ) {
		$max_records = $request->get_param( 'max' );
		$membership_data = Integrity_Check_Utils::get_membership_data( $max_records );
		$hash = Integrity_Check_Utils::generate_hash( $membership_data );

		return rest_ensure_response(
			[
				'hash'  => $hash,
				'count' => count( $membership_data ),
			]
		);
	}

	/**
	 * Handles the memberships request.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_memberships_request( $request ) {
		$membership_data = Integrity_Check_Utils::get_membership_data();

		return rest_ensure_response(
			[
				'memberships' => $membership_data,
				'count'       => count( $membership_data ),
			]
		);
	}

	/**
	 * Handles the range hash request.
	 * 
	 * Returns hash for memberships within a specific email range, enabling range-based
	 * chunking that avoids the shifting problem of positional chunks.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_range_hash_request( $request ) {
		$start_email = strtolower( $request->get_param( 'start' ) );
		$end_email = strtolower( $request->get_param( 'end' ) );
		$max_records = $request->get_param( 'max' );

		$range_data = Integrity_Check_Utils::get_membership_data_range( $start_email, $end_email, $max_records );
		$hash = Integrity_Check_Utils::generate_hash( $range_data );

		return rest_ensure_response(
			[
				'hash'  => $hash,
				'start' => $start_email,
				'end'   => $end_email,
				'count' => count( $range_data ),
			]
		);
	}

	/**
	 * Handles the range data request.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 */
	public static function handle_range_data_request( $request ) {
		$start_email = strtolower( $request->get_param( 'start' ) );
		$end_email = strtolower( $request->get_param( 'end' ) );
		$max_records = $request->get_param( 'max' );

		$range_data = Integrity_Check_Utils::get_membership_data_range( $start_email, $end_email, $max_records );

		return rest_ensure_response(
			[
				'memberships' => $range_data,
				'start'       => $start_email,
				'end'         => $end_email,
				'count'       => count( $range_data ),
			]
		);
	}
}
