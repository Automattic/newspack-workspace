<?php
/**
 * Newspack Network One-Time Purchase Changed Incoming Event
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

/**
 * A reader's order on another site for a one-time product with a Network ID changed status.
 *
 * The reading site's gate decides how long after the purchase access lasts, so
 * the record carries the purchase time and nothing about duration.
 */
class One_Time_Purchase_Changed extends Access_Grant_Changed {

	/**
	 * Keyed by the order.
	 *
	 * @return string
	 */
	protected function get_grant_key() {
		return 'order:' . $this->get_id();
	}

	/**
	 * The purchase as stored.
	 *
	 * @return array
	 */
	protected function get_grant_record() {
		return [
			'type'         => 'purchase',
			'id'           => $this->get_id(),
			'status'       => $this->get_status_after(),
			'purchased_at' => (int) ( $this->data->purchased_at ?? 0 ),
			'products'     => $this->get_products(),
		];
	}
}
