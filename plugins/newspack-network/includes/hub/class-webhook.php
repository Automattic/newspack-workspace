<?php
/**
 * Newspack Hub Webhook.
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub;

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Debugger;
use WP_REST_Response;
use WP_REST_Request;

/**
 * Class to handle the Webhook
 */
class Webhook {

	/**
	 * Runs the initialization.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public static function register_routes() {
		register_rest_route(
			'newspack-network/v1',
			'/webhook',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'handle_webhook' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * Handle the webhook
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( $request ) {
		$site            = $request['site'];
		$data            = $request['data'];
		$action          = $request['action'];
		$timestamp       = $request['timestamp'];
		$nonce           = $request['nonce'];
		$incoming_events = Accepted_Actions::ACTIONS;

		Debugger::log( 'Webhook received' );
		Debugger::log( $site );
		Debugger::log( $data );
		Debugger::log( $nonce );
		Debugger::log( $action );
		Debugger::log( $timestamp );

		if ( empty( $site ) ||
			empty( $data ) ||
			empty( $timestamp ) ||
			empty( $action ) ||
			empty( $nonce ) ||
			! array_key_exists( $action, $incoming_events )
		) {
			return new WP_REST_Response( array( 'error' => 'Bad request.' ), 400 );
		}

		$node = Nodes::get_node_by_url( $site );

		if ( ! $node ) {
			Debugger::log( 'Node not found.' );
			return new WP_REST_Response( array( 'error' => 'Bad request. Site not registered in this Hub.' ), 403 );
		}

		$verified_data = $node->decrypt_message( $data, $nonce );
		if ( ! $verified_data ) {
			Debugger::log( 'Signature check failed' );
			return new WP_REST_Response( array( 'error' => 'INVALID_SIGNATURE' ), 403 );
		}

		$verified_data = json_decode( $verified_data, true );

		if ( empty( $verified_data ) ) {
			Debugger::log( 'Invalid data' );
			return new WP_REST_Response( array( 'error' => 'Bad request. Invalid Data.' ), 400 );
		}

		Debugger::log( 'Successfully verified data' );
		Debugger::log( $verified_data );

		$incoming_event_class = 'Newspack_Network\\Incoming_Events\\' . $incoming_events[ $action ];

		$incoming_event = new $incoming_event_class( $site, $verified_data, $timestamp );

		$incoming_event->process_in_hub();

		return new WP_REST_Response( 'success' );
	}
}
