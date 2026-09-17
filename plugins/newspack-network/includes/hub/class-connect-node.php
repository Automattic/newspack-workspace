<?php
/**
 * Newspack Network Utils to automatically link Nodes to the Hub
 *
 * @package Newspack_Network
 */

namespace Newspack_Network\Hub;

use WP_REST_Response;
use WP_REST_Server;
/**
 * Class to handle the automatic connection between Nodes and the Hub
 */
class Connect_Node {

	/**
	 * The option name for the connection nonces
	 */
	const NONCES_OPTIONS = 'newspack_network_connection_nonces';

	/**
	 * Runs the initialization.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Generates a connection nonce for a node
	 *
	 * @param int $node_id The node ID.
	 * @return string
	 */
	public static function generate_nonce( $node_id ) {
		$nonces = get_option( self::NONCES_OPTIONS, [] );
		$nonces[ $node_id ] = [
			'nonce' => wp_generate_password( 12, false ),
			'time'  => time(),
		];
		update_option( self::NONCES_OPTIONS, $nonces );
		return $nonces[ $node_id ]['nonce'];
	}

	/**
	 * Checks a nonce for a node
	 *
	 * @param int    $node_id The node ID.
	 * @param string $nonce The nonce.
	 * @return bool
	 */
	public static function check_nonce( $node_id, $nonce ) {
		$nonces = get_option( self::NONCES_OPTIONS, [] );
		if ( ! isset( $nonces[ $node_id ] ) ) {
			return false;
		}
		$nonce_data = $nonces[ $node_id ];
		if ( $nonce_data['nonce'] !== $nonce ) {
			return false;
		}
		if ( time() - $nonce_data['time'] > 60 * 60 ) {
			return false;
		}
		unset( $nonces[ $node_id ] );
		update_option( self::NONCES_OPTIONS, $nonces );
		return true;
	}

	/**
	 * Register the REST route
	 */
	public static function register_routes() {
		register_rest_route(
			'newspack-network/v1',
			'/retrieve-key',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'handle_retrieve_key' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * Handle retrieving the key
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public static function handle_retrieve_key( $request ) {
		$site = $request['site'];
		$nonce = $request['nonce'];

		if ( empty( $site ) || empty( $nonce ) ) {
			return new WP_REST_Response( array( 'error' => 'Bad request.' ), 400 );
		}

		$node = Nodes::get_node_by_url( $site );
		if ( ! $node ) {
			return new WP_REST_Response( array( 'error' => 'Bad request. Site not registered in this Hub.' ), 403 );
		}

		$verified_nonce = self::check_nonce( $node->get_id(), $nonce );
		if ( ! $verified_nonce ) {
			return new WP_REST_Response( array( 'error' => 'Invalid link.' ), 403 );
		}

		$response_body = [
			'secret_key' => $node->get_secret_key(),
		];
		return new WP_REST_Response( $response_body );
	}
}
