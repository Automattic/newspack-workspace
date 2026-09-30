<?php
/**
 * Newspack Hub Subscription Changed Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Hub\Stores\Subscriptions;
use Newspack_Network\Debugger;

/**
 * Class to handle the Subscription Changed Incoming Event
 */
class Subscription_Changed extends Woo_Item_Changed {

	const USER_SUBSCRIPTIONS_META_KEY = '_newspack_network_subscriptions';

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

		$subscription_id = $this->get_id();

		if ( ! $subscription_id ) {
			return;
		}

		Subscriptions::persist( $this );
	}

	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->maybe_update_user_meta();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->maybe_update_user_meta();
	}

	/**
	 * Updates the user meta with the subscription data
	 *
	 * @return void
	 */
	public function maybe_update_user_meta() {
		$email = $this->get_email();

		if ( ! $email ) {
			return;
		}

		Debugger::log( 'Processing subscription_changed with email: ' . $email );

		$existing_user = get_user_by( 'email', $email );

		if ( ! $existing_user ) {
			return;
		}

		Debugger::log( 'Found user: ' . $existing_user->ID );

		$current_value = get_user_meta( $existing_user->ID, self::USER_SUBSCRIPTIONS_META_KEY, true );

		if ( ! is_array( $current_value ) ) {
			$current_value = [];
		}

		if ( ! isset( $current_value[ $this->get_site() ] ) ) {
			$current_value[ $this->get_site() ] = [];
		}

		$previous_products = $current_value[ $this->get_site() ][ $this->get_id() ]['products'] ?? [];

		$current_value[ $this->get_site() ][ $this->get_id() ] = [
			'id'       => $this->get_id(),
			'status'   => $this->get_status_after(),
			'products' => self::keep_network_ids( $this->get_products(), $previous_products ),
		];

		Debugger::log( sprintf( 'Adding meta for site %s and subscription id %d. Value: %s', $this->get_site(), $this->get_id(), wp_json_encode( $current_value, true ) ) );

		update_user_meta( $existing_user->ID, self::USER_SUBSCRIPTIONS_META_KEY, $current_value );
	}

	/**
	 * Copy each product's Network ID from the record being replaced.
	 *
	 * A record pulled from the subscription's own site names each product's Network ID,
	 * which the event doesn't carry. Keeping it means a status change doesn't drop the
	 * match for a product whose sync event never reached this site.
	 *
	 * @param array $products          Products from the event.
	 * @param array $previous_products Products from the record being replaced.
	 * @return array
	 */
	private static function keep_network_ids( $products, $previous_products ) {
		$previous_network_ids = [];
		foreach ( (array) $previous_products as $previous_product ) {
			$previous_product = (array) $previous_product;
			if ( isset( $previous_product['id'] ) && ! empty( $previous_product['network_id'] ) ) {
				$previous_network_ids[ (string) $previous_product['id'] ] = $previous_product['network_id'];
			}
		}
		if ( empty( $previous_network_ids ) ) {
			return $products;
		}

		$result = [];
		foreach ( (array) $products as $key => $product ) {
			$product    = (array) $product;
			$product_id = (string) ( $product['id'] ?? '' );
			if ( empty( $product['network_id'] ) && isset( $previous_network_ids[ $product_id ] ) ) {
				$product['network_id'] = $previous_network_ids[ $product_id ];
			}
			$result[ $key ] = $product;
		}
		return $result;
	}

	/**
	 * Returns the start_date property
	 *
	 * @return ?string
	 */
	public function get_start_date() {
		return $this->data->start_date ?? null;
	}

	/**
	 * Returns the trial_end_date property
	 *
	 * @return ?string
	 */
	public function get_trial_end_date() {
		return $this->data->trial_end_date ?? null;
	}

	/**
	 * Returns the next_payment_date property
	 *
	 * @return ?string
	 */
	public function get_next_payment_date() {
		return $this->data->next_payment_date ?? null;
	}

	/**
	 * Returns the last_payment_date property
	 *
	 * @return ?string
	 */
	public function get_last_payment_date() {
		return $this->data->last_payment_date ?? null;
	}

	/**
	 * Returns the end_date property
	 *
	 * @return ?string
	 */
	public function get_end_date() {
		return $this->data->end_date ?? null;
	}

	/**
	 * Returns the products property
	 *
	 * This is an array of products included in the subscription.
	 * Each product has an id, a name and a slug.
	 *
	 * @return array
	 */
	public function get_products() {
		return $this->data->products ?? [];
	}
}
