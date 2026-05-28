<?php
/**
 * Newspack Hub Reader Registered Event Log Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores\Event_Log_Items;

use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Stores\Abstract_Event_Log_Item;

/**
 * Class to handle the Reader Registered Event Log Item
 */
class Reader_Registered extends Abstract_Event_Log_Item {

	/**
	 * Gets a summary for this event
	 *
	 * @return string
	 */
	public function get_summary() {
		$url = empty( $this->get_node_id() ) ? get_bloginfo( 'url' ) : $this->get_node_url();
		return sprintf(
			/* translators: 1: email 2: site url */
			__( 'New reader registered with email %1$s on %2$s', 'newspack-network' ),
			$this->get_email(),
			$url
		);
	}
}
