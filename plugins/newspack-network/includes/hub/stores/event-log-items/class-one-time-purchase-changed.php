<?php
/**
 * Newspack Hub One-Time Purchase Changed Event Log Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores\Event_Log_Items;

use Newspack_Network\Hub\Stores\Abstract_Event_Log_Item;

/**
 * Class to handle the One-Time Purchase Changed Event Log Item
 */
class One_Time_Purchase_Changed extends Abstract_Event_Log_Item {

	/**
	 * Gets a summary for this event
	 *
	 * @return string
	 */
	public function get_summary() {
		$url = empty( $this->get_node_id() ) ? get_bloginfo( 'url' ) : $this->get_node_url();
		return sprintf(
			/* translators: 1: Order ID, 2: order status, 3: site url */
			__( 'One-time purchase order #%1$d is now %2$s on %3$s', 'newspack-network' ),
			$this->get_data()->id ?? 0,
			$this->get_data()->status_after ?? '',
			$url
		);
	}
}
