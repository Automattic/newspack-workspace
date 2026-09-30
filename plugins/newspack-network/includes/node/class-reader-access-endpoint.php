<?php
/**
 * Newspack Network Node reader access endpoint.
 *
 * @package Newspack
 */

namespace Newspack_Network\Node;

use Newspack_Network\Content_Gate\Reader_Access_Collector;
use Newspack_Network\Content_Gate\Reader_Access_Envelope;
use Newspack_Network\Rest_Authenticaton;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Answers the hub's question about what a reader holds on this node, so another
 * network site can decide whether to let them in.
 */
class Reader_Access_Endpoint {

	/**
	 * Endpoint ID the hub signs requests for.
	 */
	const ENDPOINT_ID = 'reader-access';

	/**
	 * Initializer.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the route.
	 */
	public static function register_routes() {
		register_rest_route(
			'newspack-network/v1',
			'/reader-access',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'handle_request' ],
					'permission_callback' => [ __CLASS__, 'check_permission' ],
					'args'                => [
						'email'      => [
							'type'     => 'string',
							'required' => true,
						],
						'request_id' => [
							'type'     => 'string',
							'required' => true,
						],
					],
				],
			]
		);
	}

	/**
	 * Only the hub may ask.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function check_permission( $request ) {
		if ( ! Rest_Authenticaton::is_request_signed( $request ) ) {
			return new \WP_Error( 'newspack_network_reader_access_unsigned', __( 'Unsigned request.', 'newspack-network' ), [ 'status' => 401 ] );
		}
		return Rest_Authenticaton::verify_signature( $request, self::ENDPOINT_ID, Settings::get_secret_key() );
	}

	/**
	 * Answer with what the reader holds here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( $request ) {
		$payload = array_merge(
			Reader_Access_Collector::collect( (string) $request['email'] ),
			[
				'email'      => (string) $request['email'],
				'request_id' => (string) $request['request_id'],
			]
		);
		$envelope = Reader_Access_Envelope::seal( $payload, Settings::get_secret_key() );
		if ( is_wp_error( $envelope ) ) {
			return new WP_REST_Response( [ 'error' => $envelope->get_error_message() ], 500 );
		}
		return new WP_REST_Response( $envelope );
	}
}
