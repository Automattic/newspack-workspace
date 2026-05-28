<?php
/**
 * Newspack Hub Product Updated Event Log Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores\Event_Log_Items;

use Newspack_Network\Hub\Stores\Abstract_Event_Log_Item;

/**
 * Class to handle the Product Updated Event Log Item
 */
class Product_Updated extends Abstract_Event_Log_Item {

	/**
	 * Gets a summary for this event.
	 *
	 * @return string
	 */
	public function get_summary() {
		$url  = empty( $this->get_node_id() ) ? get_bloginfo( 'url' ) : $this->get_node_url();
		$data = $this->get_data();
		return sprintf(
			/* translators: 1: Product name 2: Network ID 3: site url */
			__( 'Product "%1$s" (Network ID: %2$s) updated on %3$s', 'newspack-network' ),
			$data->name ?? __( 'Unknown', 'newspack-network' ),
			empty( $data->network_id ) ? __( 'none', 'newspack-network' ) : $data->network_id,
			$url
		);
	}
}
