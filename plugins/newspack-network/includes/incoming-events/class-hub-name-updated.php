<?php
/**
 * Newspack Hub Name Updated Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;

/**
 * Class to handle the Hub Name Updated Event
 *
 * This will store the Hub's name on every Node options table.
 */
class Hub_Name_Updated extends Abstract_Incoming_Event {

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		update_option( 'newspack_network_hub_name', $this->get_new_name() );
	}

	/**
	 * Get the new name
	 *
	 * @return string
	 */
	public function get_new_name() {
		return $this->get_data()->name;
	}
}
