<?php
/**
 * Newspack Hub Canonical Url Updated Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Content_Distribution\Canonical_Url;

/**
 * Class to handle the Canonical Url Updated Event
 *
 * This event is always sent from the Hub and received by Nodes.
 */
class Canonical_Url_Updated extends Abstract_Incoming_Event {

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		update_option( Canonical_Url::OPTION_NAME, $this->get_data()->url );
	}
}
