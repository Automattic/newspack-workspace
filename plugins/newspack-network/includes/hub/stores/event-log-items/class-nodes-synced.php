<?php
/**
 * Newspack Nodes Synced Log Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores\Event_Log_Items;

use Newspack_Network\Hub\Stores\Abstract_Event_Log_Item;

/**
 * Class to handle the Nodes Synced Log Item
 */
class Nodes_Synced extends Abstract_Event_Log_Item {

	/**
	 * Gets a summary for this event
	 *
	 * @return string
	 */
	public function get_summary() {
		return __( 'Node data synced', 'newspack-network' );
	}
}
