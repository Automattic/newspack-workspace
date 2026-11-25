<?php
/**
 * Newspack Network Gift Changed Incoming Event
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

/**
 * A gift subscription on another site changed for its recipient: they were given
 * it or lost it, or its status changed.
 */
class Gift_Changed extends Reader_Product_Changed {

	/**
	 * Keyed by the gift subscription.
	 *
	 * @return string
	 */
	protected function get_record_key() {
		return 'gift:' . $this->get_id();
	}

	/**
	 * The gift as stored: the subscription's status and products.
	 *
	 * @return array
	 */
	protected function get_record() {
		return [
			'type'     => 'gift',
			'id'       => $this->get_id(),
			'status'   => $this->get_status_after(),
			'products' => $this->get_products(),
		];
	}
}
