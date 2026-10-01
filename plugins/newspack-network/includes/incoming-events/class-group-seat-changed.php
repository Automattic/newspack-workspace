<?php
/**
 * Newspack Network Group Seat Changed Incoming Event
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

/**
 * A reader's seat on another site's group subscription changed: they joined or
 * left, or the subscription's status changed.
 */
class Group_Seat_Changed extends Access_Grant_Changed {

	/**
	 * Keyed by the group subscription.
	 *
	 * @return string
	 */
	protected function get_grant_key() {
		return 'group:' . $this->get_id();
	}

	/**
	 * The seat as stored: the subscription's status and products.
	 *
	 * @return array
	 */
	protected function get_grant_record() {
		return [
			'type'     => 'group',
			'id'       => $this->get_id(),
			'status'   => $this->get_status_after(),
			'products' => $this->get_products(),
		];
	}
}
