<?php
/**
 * Newspack Network Content Distribution Post Delete.
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;
use Newspack_Network\Content_Distribution\Incoming_Post;

/**
 * Class to handle the network post delete.
 */
class Network_Post_Deleted extends Abstract_Incoming_Event {
	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->process_post_deleted();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->process_post_deleted();
	}

	/**
	 * Process post deleted
	 */
	protected function process_post_deleted() {
		$payload = (array) $this->get_data();

		Debugger::log( 'Processing network_post_deleted ' . wp_json_encode( $payload['config'] ) );

		$error = Incoming_Post::get_payload_error( $payload );
		if ( is_wp_error( $error ) ) {
			Debugger::log( 'Error processing network_post_deleted: ' . $error->get_error_message() );
			return;
		}

		try {
			$incoming_post = new Incoming_Post( $payload );
		} catch ( \Exception $e ) {
			Debugger::log( 'Error processing network_post_deleted: ' . $e->getMessage() );
			return;
		}

		$incoming_post->delete();
	}
}
