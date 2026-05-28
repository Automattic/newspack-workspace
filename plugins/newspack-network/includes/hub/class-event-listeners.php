<?php
/**
 * Newspack Hub Event Listeners.
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub;

use Newspack_Network\Accepted_Actions;

/**
 * Class to listen to local events and add them to the Event Log.
 *
 * Not to be confused with the Newspack Data Events API listeners.
 *
 * This will listen to events triggered by the Newspack Data Events API and store them locally in the Event Log.
 *
 * This is the Hub's equivalent to the Webhooks in the Node. While the nodes listen to events and send them to the Hub, the Hub listens to events and stores them in the Event Log.
 */
class Event_Listeners {

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'newspack_data_event_dispatch', [ __CLASS__, 'handle_dispatch' ], 10, 4 );
	}

	/**
	 * Stores the local events in the Event Log.
	 *
	 * @param string $action_name Action name.
	 * @param int    $timestamp   Event timestamp.
	 * @param array  $data        Event data.
	 * @param string $client_id   Optional user session's client ID.
	 */
	public static function handle_dispatch( $action_name, $timestamp, $data, $client_id ) {
		
		if ( ! array_key_exists( $action_name, Accepted_Actions::ACTIONS ) ) {
			return;
		}

		$incoming_event_class = 'Newspack_Network\\Incoming_Events\\' . Accepted_Actions::ACTIONS[ $action_name ];
		$incoming_event       = new $incoming_event_class( get_bloginfo( 'url' ), $data, $timestamp );
		$incoming_event->process_in_hub();
	}
}
