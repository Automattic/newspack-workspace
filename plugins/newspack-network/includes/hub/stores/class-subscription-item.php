<?php
/**
 * Newspack Subscription Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores;

use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;
use WP_Post;

/**
 * Subscription Item
 */
class Subscription_Item extends Woo_Item {

	/**
	 * Gets the post type slug
	 *
	 * @return string
	 */
	protected function get_post_type_slug() {
		return Subscriptions_DB::POST_TYPE_SLUG;
	}

	/**
	 * Returns the Item's payment_method_title
	 *
	 * @return ?string
	 */
	public function get_payment_method_title() {
		return get_post_meta( $this->get_id(), 'payment_method_title', true );
	}

	/**
	 * Returns the Item's start_date
	 *
	 * @return ?string
	 */
	public function get_start_date() {
		return get_post_meta( $this->get_id(), 'start_date', true );
	}

	/**
	 * Returns the Item's trial_end_date
	 *
	 * @return ?string
	 */
	public function get_trial_end_date() {
		return get_post_meta( $this->get_id(), 'trial_end_date', true );
	}

	/**
	 * Returns the Item's next_payment_date
	 *
	 * @return ?string
	 */
	public function get_next_payment_date() {
		return get_post_meta( $this->get_id(), 'next_payment_date', true );
	}

	/**
	 * Returns the Item's last_payment_date
	 *
	 * @return ?string
	 */
	public function get_last_payment_date() {
		return get_post_meta( $this->get_id(), 'last_payment_date', true );
	}

	/**
	 * Returns the Item's end_date
	 *
	 * @return ?string
	 */
	public function get_end_date() {
		return get_post_meta( $this->get_id(), 'end_date', true );
	}

	/**
	 * Returns the Item's products
	 *
	 * @return ?array Array of line items with name and product_id keys.
	 */
	public function get_products() {
		return get_post_meta( $this->get_id(), 'products', false );
	}
}
