<?php
/**
 * Newspack Network One-Time Purchase Changed Incoming Event
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Content_Gate\Access;

/**
 * A reader's order on another site for a one-time product with a Network ID changed status.
 *
 * The reading site's gate decides how long after the purchase access lasts, so
 * the record carries the purchase time and nothing about duration.
 */
class One_Time_Purchase_Changed extends Reader_Product_Changed {

	/**
	 * Keyed by the order.
	 *
	 * @return string
	 */
	protected function get_record_key() {
		return 'order:' . $this->get_id();
	}

	/**
	 * The purchase as stored.
	 *
	 * @return array
	 */
	protected function get_record() {
		return [
			'type'         => 'purchase',
			'id'           => $this->get_id(),
			'status'       => $this->get_status_after(),
			'purchased_at' => (int) ( $this->data->purchased_at ?? 0 ),
			'products'     => $this->get_products(),
		];
	}

	/**
	 * A paid order grants; a refunded or cancelled one only revokes. An order with no
	 * customer (a guest checkout, or one an admin created without choosing a customer)
	 * still records on an account that exists here, but creates none: the origin
	 * names no reader to propagate.
	 *
	 * @return bool
	 */
	protected function grants_access() {
		return ! empty( $this->data->user_id ) && in_array( $this->get_status_after(), Access::get_paid_statuses(), true );
	}
}
