<?php
/**
 * Newspack Nodes Synced Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;
use Newspack_Network\Hub\Node;

/**
 * Class to handle the Nodes Synced Event
 *
 * This event is always sent from the Hub and received by Nodes.
 */
class Nodes_Synced extends Abstract_Incoming_Event {
	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		Debugger::log( 'Processing network_nodes_synced event.' );
		// Get data passed for node urls.
		$data = $this->get_data();

		// If the data is empty, return early.
		if ( empty( $data ) ) {
			Debugger::log( 'No data passed for network_nodes_synced event.' );
			return;
		}

		$nodes_data = $data->nodes_data;

		// If the nodes data is empty, return early.
		if ( empty( $nodes_data ) ) {
			Debugger::log( 'No nodes data passed for network_nodes_synced event.' );
			return;
		}

		foreach ( $nodes_data as $key => $node ) {
			// We don't need top store data for the current node.
			if ( $node['url'] === get_site_url() ) {
				unset( $nodes_data[ $key ] );
			}
		}

		$updated = update_option( Node::HUB_NODES_SYNCED_OPTION, $nodes_data );

		if ( $updated ) {
			Debugger::log( 'network_nodes_synced event processed. Synced ' . count( $nodes_data ) . ' nodes.' );
		} else {
			Debugger::log( 'Error processing network_nodes_synced event.' );
		}
	}
}
