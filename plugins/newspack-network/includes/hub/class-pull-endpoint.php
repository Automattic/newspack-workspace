<?php
/**
 * Newspack Pull Endpoint.
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub;

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Crypto;
use Newspack_Network\Debugger;
use Newspack_Network\Hub\Stores\Event_Log;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class to handle the Endpoint that Nodes will reach to pull new data from
 */
class Pull_Endpoint {

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
			'/pull',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'handle_pull' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * Get the number of events returned in each pull request.
	 *
	 * @return int
	 */
	public static function get_pull_limit() {
		/**
		 * Maximum number of events to pull from the network hub per request.
		 * Increase for faster sync, decrease to reduce server load.
		 *
		 * @constant NEWSPACK_NETWORK_EVENTS_PULL_LIMIT
		 * @type     int
		 * @default  40 events per pull
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NETWORK_EVENTS_PULL_LIMIT', 100 );
		 */
		return defined( 'NEWSPACK_NETWORK_EVENTS_PULL_LIMIT' ) && is_numeric( NEWSPACK_NETWORK_EVENTS_PULL_LIMIT ) ? NEWSPACK_NETWORK_EVENTS_PULL_LIMIT : 40;
	}

	/**
	 * Handle the pull
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public static function handle_pull( $request ) {
		$verified_params = \Newspack_Network\Utils\Requests::verify_request_to_hub( $request );
		if ( \is_wp_error( $verified_params ) ) {
			return new WP_REST_Response( [ 'error' => $verified_params->get_error_message() ], 403 );
		}

		// Read the request parameters from the verified (signed) payload, not the plaintext
		// copies, so a man-in-the-middle can't tamper with the action list, the cursor, or
		// the signed-response flag. The plaintext 'site' is fine to use for the Node lookup:
		// it selected the secret that decrypted the signature, so it identifies the sender.
		$site              = $request['site'];
		$last_processed_id = (int) ( $verified_params['last_processed_id'] ?? 0 );
		$actions           = (array) ( $verified_params['actions'] ?? [] );
		$signed_response   = ! empty( $verified_params['signed_response'] );

		// Defense-in-depth: cross-check the plaintext 'site' against the signed copy so a
		// mismatch is rejected even if a future change makes the lookup tolerant of either.
		if ( isset( $verified_params['site'] ) && $verified_params['site'] !== $site ) {
			return new WP_REST_Response( [ 'error' => 'Site mismatch.' ], 403 );
		}

		Debugger::log( sprintf( 'Pull request received from site %s, with last processed ID %d, for actions: %s.', $site, $last_processed_id, implode( ', ', $actions ) ) );

		if ( empty( $actions ) ) {
			return new WP_REST_Response( array( 'error' => 'Bad request.' ), 400 );
		}

		$node = Nodes::get_node_by_url( $site );
		$query_args = [
			'excluded_node_id' => $node->get_id(),
			'id_greater_than'  => $last_processed_id,
			'action_name_in'   => $actions,
		];

		$events = Event_Log::get(
			$query_args,
			self::get_pull_limit(),
			1,
			'ASC'
		);

		$total_events = Event_Log::get_total_items( $query_args );

		Debugger::log( count( $events ) . ' events found' );

		$events_formatted = array_map(
			function( $event ) {
				return [
					'id'        => $event->get_id(),
					'site'      => $event->get_node_url(),
					'action'    => $event->get_action_name(),
					'data'      => $event->get_data(),
					'timestamp' => $event->get_timestamp(),
				];
			},
			$events
		);
		$highest_returned_id = empty( $events_formatted ) ? 0 : max( array_column( $events_formatted, 'id' ) );
		$response_body = [
			'data'  => $events_formatted,
			'total' => $total_events,
		];

		// When the Node asked for a signed response, encrypt the body with the shared secret
		// so a man-in-the-middle can't inject events into it. Older Nodes don't set the flag
		// and get the body unencrypted, as before.
		if ( $signed_response ) {
			$response_json = wp_json_encode( $response_body );
			if ( false === $response_json ) {
				Debugger::log( 'Could not encode the pull response.' );
				return new WP_REST_Response( [ 'error' => 'Could not encode response.' ], 500 );
			}
			$response_nonce = Crypto::generate_nonce();
			$encrypted_body = Crypto::encrypt_message( $response_json, $node->get_secret_key(), $response_nonce );
			if ( is_wp_error( $encrypted_body ) || ! is_string( $encrypted_body ) ) {
				Debugger::log( 'Could not sign the pull response.' );
				return new WP_REST_Response( [ 'error' => 'Could not sign response.' ], 500 );
			}
			return new WP_REST_Response(
				[
					'nonce' => $response_nonce,
					'data'  => $encrypted_body,
				]
			);
		}

		return new WP_REST_Response( $response_body );
	}
}
