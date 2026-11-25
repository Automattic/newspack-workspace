<?php
/**
 * Newspack Hub Gift Changed Event Log Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores\Event_Log_Items;

use Newspack_Network\Hub\Stores\Abstract_Event_Log_Item;

/**
 * Class to handle the Gift Changed Event Log Item
 */
class Gift_Changed extends Abstract_Event_Log_Item {

	/**
	 * Gets a summary for this event
	 *
	 * @return string
	 */
	public function get_summary() {
		$url = empty( $this->get_node_id() ) ? get_bloginfo( 'url' ) : $this->get_node_url();
		return sprintf(
			/* translators: 1: recipient email, 2: Subscription ID, 3: gift status, 4: site url */
			__( 'Gift subscription #%2$d for %1$s is now %3$s on %4$s', 'newspack-network' ),
			$this->get_data()->email ?? '',
			$this->get_data()->id ?? 0,
			$this->get_data()->status_after ?? '',
			$url
		);
	}
}
