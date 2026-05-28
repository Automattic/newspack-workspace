<?php
/**
 * Newspack Hub Order Changed Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Hub\Stores\Orders;

/**
 * Class to handle the Order Changed Incoming Event
 */
class Order_Changed extends Woo_Item_Changed {

	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function always_process_in_hub() {
		$email = $this->get_email();
		if ( ! $email ) {
			return;
		}

		$order_id = $this->get_id();

		if ( ! $order_id ) {
			return;
		}

		Orders::persist( $this );
	}
}
