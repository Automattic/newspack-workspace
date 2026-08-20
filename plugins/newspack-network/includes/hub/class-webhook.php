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

		// Claim this delivery's nonce before processing. The claim holds the
		// delivery as pending until processing completes, so each delivery is
		// handled at most once no matter how many times, or how close together,
		// its copies arrive.
		$claim = Used_Nonces::claim( $nonce );
		if ( Used_Nonces::STATUS_COMPLETED === $claim ) {
			// Already processed to completion. Acknowledge so the Node stops
			// retrying, but do not run the event a second time.
			Debugger::log( 'Webhook nonce already completed; acknowledging without reprocessing.' );
			return new WP_REST_Response( 'success' );
		}
		if ( Used_Nonces::CLAIMED !== $claim ) {
			// Pending (an attempt to process this delivery is on record with no
			// outcome yet), or no state could be recorded at all. Either way, ask
			// the Node to retry rather than process a delivery that may also be
			// handled elsewhere. The two cases carry distinct errors: the Node
			// records the error string on its request, so its log tells a held
			// delivery apart from a store that cannot be written.
			Debugger::log( 'Webhook delivery not claimable (' . ( $claim ?? 'no recorded state' ) . '); requesting retry.' );
			$error = Used_Nonces::STATUS_PENDING === $claim ? 'Delivery is already being processed.' : 'Could not record delivery.';
			return new WP_REST_Response( array( 'error' => $error ), 500 );
		}

		$incoming_event_class = 'Newspack_Network\\Incoming_Events\\' . $incoming_events[ $action ];

		// The claim is held while the event is processed. Processing that fails
		// with nothing persisted releases it and returns a 5xx, so the Node's
		// retry can reprocess: a throw is caught below, and a failed Event Log
		// write is caught by the `false` return. A throw after the event is
		// persisted holds the claim instead (see the catch). A delivery already
		// in the Event Log (`null`) is a no-op whose handlers do not re-run — the
		// pipeline's existing at-most-once behaviour — and is acknowledged as
		// success.
		try {
			$incoming_event = new $incoming_event_class( $site, $verified_data, $timestamp );
			$persisted      = $incoming_event->process_in_hub();
		} catch ( \Throwable $e ) {
			// Release only if nothing was persisted. Once the Event Log row
			// exists, a retry cannot redo the work that failed — the pipeline
			// does not re-run handlers for a delivery already on record — so
			// holding the claim surfaces the failure as a failed request instead
			// of letting the retry be acknowledged as success.
			if ( empty( $incoming_event ) || ! $incoming_event->is_persisted ) {
				if ( Used_Nonces::release( $nonce ) ) {
					Debugger::log( 'Webhook processing threw; released nonce for retry: ' . $e->getMessage() );
				} else {
					Debugger::log( 'Webhook processing threw; the claim could not be released and stays pending: ' . $e->getMessage() );
				}
			} else {
				Debugger::log( 'Webhook processing threw after the event was persisted; holding the claim: ' . $e->getMessage() );
			}
			return new WP_REST_Response( array( 'error' => 'Could not process delivery.' ), 500 );
		}

		if ( false === $persisted ) {
			if ( Used_Nonces::release( $nonce ) ) {
				Debugger::log( 'Webhook delivery could not be recorded; released nonce for retry.' );
			} else {
				Debugger::log( 'Webhook delivery could not be recorded; the claim could not be released and stays pending.' );
			}
			return new WP_REST_Response( array( 'error' => 'Could not record delivery.' ), 500 );
		}

		// Mark the delivery completed. Best-effort: processing has already
		// succeeded, so the delivery is acknowledged either way. A claim whose
		// completion could not be written stays pending, and a later arrival of
		// the delivery draws a retryable error rather than being reprocessed.
		if ( ! Used_Nonces::complete( $nonce ) ) {
			Debugger::log( 'Webhook delivery processed, but marking it completed failed; the claim stays pending.' );
		}

		return new WP_REST_Response( 'success' );
	}
}
