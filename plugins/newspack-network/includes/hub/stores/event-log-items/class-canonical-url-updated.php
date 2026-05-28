<?php
/**
 * Newspack Hub Canonical Url Updated Log Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores\Event_Log_Items;

use Newspack_Network\Hub\Stores\Abstract_Event_Log_Item;

/**
 * Class to handle the Canonical Url Updated Log Item
 */
class Canonical_Url_Updated extends Abstract_Event_Log_Item {

	/**
	 * Gets a summary for this event
	 *
	 * @return string
	 */
	public function get_summary() {
		return sprintf(
			/* translators: $s is the new URL */
			__( 'The distributor Canonical Url option was updated to: %s', 'newspack-network' ),
			$this->get_data()->url
		);
	}
}
