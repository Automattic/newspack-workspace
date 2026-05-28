<?php
/**
 * Newspack Network Content Distribution Incoming Post Delete.
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;
use Newspack_Network\Content_Distribution\Outgoing_Post;

/**
 * Class to handle the network incoming post delete.
 */
class Network_Incoming_Post_Deleted extends Abstract_Incoming_Event {
	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->process_incoming_post_deleted();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->process_incoming_post_deleted();
	}

	/**
	 * Process incoming post deleted
	 */
	protected function process_incoming_post_deleted() {
		$data = (array) $this->get_data();

		// Bail if origin is not here.
		if ( get_bloginfo( 'url' ) !== $data['outgoing']['site_url'] ) {
			return;
		}

		Debugger::log( 'Processing network_incoming_post_deleted ' . wp_json_encode( $data ) );

		try {
			$outgoing_post = new Outgoing_Post( $data['outgoing']['post_id'] );
		} catch ( \Exception $e ) {
			Debugger::log( 'Error processing network_incoming_post_deleted: ' . $e->getMessage() );
			return;
		}

		$result = $outgoing_post->remove_distribution( $data['incoming']['site_url'] );
		if ( is_wp_error( $result ) ) {
			Debugger::log( 'Error processing network_incoming_post_deleted: ' . $result->get_error_message() );
		}
	}
}
