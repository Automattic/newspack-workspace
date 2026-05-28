<?php
/**
 * Newspack Hub Generic Woo Changed Incoming Event for Subscription and Orders
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

/**
 * Class to handle the Generic Woo Changed Incoming Event for Subscription and Orders
 */
abstract class Woo_Item_Changed extends Abstract_Incoming_Event {

	/**
	 * Returns the subscription_id property
	 *
	 * @return ?int
	 */
	public function get_id() {
		return $this->data->id ?? null;
	}

	/**
	 * Returns the status_before property
	 *
	 * @return ?string
	 */
	public function get_status_before() {
		return $this->data->status_before ?? null;
	}

	/**
	 * Returns the status_after property
	 *
	 * @return ?string
	 */
	public function get_status_after() {
		return $this->data->status_after ?? null;
	}

	/**
	 * Returns the user_email property
	 *
	 * @return ?string
	 */
	public function get_user_name() {
		return $this->data->user_name ?? null;
	}

	/**
	 * Returns the payment_count property
	 *
	 * @return ?string
	 */
	public function get_payment_count() {
		return $this->data->payment_count ?? null;
	}

	/**
	 * Returns the formatted_total property
	 *
	 * @return ?string
	 */
	public function get_formatted_total() {
		return $this->data->formatted_total ?? null;
	}

	/**
	 * Returns the subscription_relationship property
	 *
	 * @return ?string
	 */
	public function get_subscription_relationship() {
		return $this->data->subscription_relationship ?? null;
	}

	/**
	 * Returns the currency property
	 *
	 * @return ?string
	 */
	public function get_currency() {
		return $this->data->currency ?? null;
	}

	/**
	 * Returns the total property
	 *
	 * @return ?string
	 */
	public function get_total() {
		return $this->data->total ?? null;
	}

	/**
	 * Returns the payment_method_title property
	 *
	 * @return ?string
	 */
	public function get_payment_method_title() {
		return $this->data->payment_method_title ?? null;
	}

	/**
	 * Returns the date_created property
	 *
	 * @return ?string
	 */
	public function get_date_created() {
		return $this->data->date_created ?? null;
	}
}
