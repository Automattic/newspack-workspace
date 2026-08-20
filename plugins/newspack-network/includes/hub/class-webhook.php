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
	 * Freshness window, in seconds, for an incoming delivery's `timestamp`.
	 *
	 * The `timestamp` is when the Node created the event, not when it was delivered:
	 * the Node queues each webhook and retries a failed delivery with exponential
	 * backoff, so a legitimate first success can arrive up to roughly 3.7 days after
	 * its timestamp. The window sits above that whole retry span, so a real delivery
	 * never reaches the stale branch within its retry lifecycle. That is why turning a
	 * stale delivery away with a non-2xx (below) is safe: the only deliveries that
	 * reach it cannot succeed on retry anyway.
	 *
	 * This is a coarse bound only. The single-use nonce record ({@see Used_Nonces}) is
	 * the authoritative guard against a delivery being processed more than once.
	 *
	 * @var int
	 */
	const FRESHNESS_WINDOW_SECONDS = 4 * DAY_IN_SECONDS;

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

		// Turn away deliveries whose timestamp is well outside the freshness window.
		// A legitimate delivery, including the Node's retry backoff, stays within it;
		// one created far earlier does not. The window sits above the Node's whole retry
		// span, so a real retry never reaches this branch, which is why the non-2xx
		// is safe here (a stale delivery only ages further and can never succeed).
		if ( abs( time() - (int) $timestamp ) > self::FRESHNESS_WINDOW_SECONDS ) {
			Debugger::log( 'Webhook timestamp outside freshness window.' );
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

		// Record this delivery's nonce before processing it, so a repeat is recognised
		// and each delivery is handled at most once.
		$claim = Used_Nonces::claim( $nonce );
		if ( false === $claim ) {
			// Already processed. Acknowledge so the Node stops retrying, but do not
			// run the event a second time.
			Debugger::log( 'Webhook nonce already processed; acknowledging without reprocessing.' );
			return new WP_REST_Response( 'success' );
		}
		if ( null === $claim ) {
			// The delivery could not be recorded. Ask the Node to retry rather than
			// process an event we cannot guarantee is not a repeat.
			Debugger::log( 'Could not record webhook nonce; requesting retry.' );
			return new WP_REST_Response( array( 'error' => 'Could not record delivery.' ), 500 );
		}

		$incoming_event_class = 'Newspack_Network\\Incoming_Events\\' . $incoming_events[ $action ];

		// The nonce is claimed before the event is processed, so each delivery is
		// handled at most once. If processing throws, the claim is released so the
		// Node's retry can process it again. An uncatchable fatal in the narrow gap
		// between the claim and the event-log write would drop the event — a window
		// narrower than, and consistent with, the pre-existing drop of a fatal after
		// that write, which the event log's own de-duplication already made permanent.
		try {
			$incoming_event = new $incoming_event_class( $site, $verified_data, $timestamp );
			$incoming_event->process_in_hub();
		} catch ( \Throwable $e ) {
			Used_Nonces::release( $nonce );
			Debugger::log( 'Webhook processing failed; released nonce for retry: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'Could not process delivery.' ), 500 );
		}

		return new WP_REST_Response( 'success' );
	}
}
