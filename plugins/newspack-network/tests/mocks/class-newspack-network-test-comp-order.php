<?php
/**
 * Stand-in order or subscription for the complimentary access tests.
 *
 * @package Newspack_Network
 */

/**
 * A stand-in order or subscription; the suite doesn't load WooCommerce.
 */
class Newspack_Network_Test_Comp_Order {
	/**
	 * Constructor.
	 *
	 * @param int    $id              ID.
	 * @param string $status          Status.
	 * @param bool   $is_comp         Whether it carries the complimentary stamp.
	 * @param int[]  $related_ids     Related order IDs, for a subscription.
	 */
	public function __construct( public $id, public $status, public $is_comp = false, public $related_ids = [] ) {}

	/**
	 * ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Meta, of which only the stamp is set.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	public function get_meta( $key ) {
		return '_newspack_complimentary' === $key && $this->is_comp ? 'yes' : '';
	}

	/**
	 * Related order IDs.
	 *
	 * @return int[]
	 */
	public function get_related_orders() {
		return $this->related_ids;
	}

	/**
	 * Guest checkout, so the billing email names the reader.
	 *
	 * @return int
	 */
	public function get_customer_id() {
		return 0;
	}

	/**
	 * Billing email.
	 *
	 * @return string
	 */
	public function get_billing_email() {
		return 'comp-reader@example.test';
	}

	/**
	 * No account.
	 *
	 * @return false
	 */
	public function get_user() {
		return false;
	}

	/**
	 * Formatted total.
	 *
	 * @return string
	 */
	public function get_formatted_order_total() {
		return '$0.00';
	}

	/**
	 * Currency.
	 *
	 * @return string
	 */
	public function get_currency() {
		return 'USD';
	}

	/**
	 * Total.
	 *
	 * @return string
	 */
	public function get_total() {
		return '0';
	}

	/**
	 * Payment method title.
	 *
	 * @return string
	 */
	public function get_payment_method_title() {
		return '';
	}

	/**
	 * Creation date.
	 *
	 * @return null
	 */
	public function get_date_created() {
		return null;
	}

	/**
	 * Subscription date.
	 *
	 * @return int
	 */
	public function get_date() {
		return 0;
	}

	/**
	 * Billing period.
	 *
	 * @return string
	 */
	public function get_billing_period() {
		return 'year';
	}

	/**
	 * Billing interval.
	 *
	 * @return int
	 */
	public function get_billing_interval() {
		return 1;
	}

	/**
	 * Line items.
	 *
	 * @return array
	 */
	public function get_items() {
		return [];
	}
}
