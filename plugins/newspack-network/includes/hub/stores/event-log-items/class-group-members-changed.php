<?php
/**
 * Newspack Hub Group Members Changed Event Log Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores\Event_Log_Items;

use Newspack_Network\Hub\Stores\Abstract_Event_Log_Item;

/**
 * Group Members Changed Event Log Item.
 */
class Group_Members_Changed extends Abstract_Event_Log_Item {

	/**
	 * Gets a summary for this event
	 *
	 * @return string
	 */
	public function get_summary() {
		$url = empty( $this->get_node_id() ) ? get_bloginfo( 'url' ) : $this->get_node_url();
		return sprintf(
			/* translators: 1: Subscription ID 2: number of members, 3: site url */
			__( 'Group subscription #%1$d now has %2$d members on %3$s', 'newspack-network' ),
			$this->get_data()->id,
			count( (array) ( $this->get_data()->group_members ?? [] ) ),
			$url
		);
	}
}
