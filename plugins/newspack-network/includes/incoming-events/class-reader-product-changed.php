<?php
/**
 * Newspack Network Reader Product Changed Incoming Event
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;
use Newspack_Network\User_Update_Watcher;
use Newspack_Network\Utils\Users;

/**
 * A reader's tie to a product on another site that isn't a subscription they own:
 * a seat on someone else's group subscription, or a paid one-time order.
 *
 * Recorded per reader, keyed by site and item, in its own user meta so that
 * newspack-plugin's strict subscription check (owned subscriptions only, used by
 * access attribution) and the My Account "Other Subscriptions" tab keep seeing
 * only subscriptions the reader owns. Named for what it stores, the reader's
 * products, not for gating: campaign segmentation or anything else that cares
 * which products a reader holds across the network can read it too.
 */
abstract class Reader_Product_Changed extends Abstract_Incoming_Event {

	const USER_PRODUCTS_META_KEY = '_newspack_network_reader_products';

	/**
	 * The key this record is stored under for its site, e.g. "group:90" or "order:86".
	 *
	 * @return string
	 */
	abstract protected function get_record_key();

	/**
	 * The record stored for this product.
	 *
	 * @return array
	 */
	abstract protected function get_record();

	/**
	 * The products a reader holds on other sites.
	 *
	 * @param int $user_id User ID.
	 * @return array Site URL => [ key => record ].
	 */
	public static function get_user_products( $user_id ) {
		$records = get_user_meta( $user_id, self::USER_PRODUCTS_META_KEY, true );
		return is_array( $records ) ? $records : [];
	}

	/**
	 * Record the product on the hub's own copy of the reader.
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->maybe_update_user_meta();
	}

	/**
	 * Record the product on the node's copy of the reader.
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->maybe_update_user_meta();
	}

	/**
	 * Record the product on the reader, creating their network reader account here
	 * first if it doesn't exist, as membership events do.
	 *
	 * The account usually arrives through `reader_registered` before this event, but
	 * not always: that event can be delayed by a webhook retry, and accounts created
	 * on the origin without a sign-up or checkout never send one.
	 *
	 * @return void
	 */
	public function maybe_update_user_meta() {
		$email = $this->get_email();
		if ( ! $email || ! $this->get_id() ) {
			return;
		}
		User_Update_Watcher::$enabled = false;
		$user = Users::get_or_create_user_by_email( $email, $this->get_site(), $this->data->user_id ?? '' );
		if ( ! $user instanceof \WP_User ) {
			Debugger::log( 'Could not find or create a user for reader product record: ' . $email );
			return;
		}
		$records = self::get_user_products( $user->ID );
		$records[ $this->get_site() ][ $this->get_record_key() ] = $this->get_record();
		update_user_meta( $user->ID, self::USER_PRODUCTS_META_KEY, $records );
	}

	/**
	 * The order or subscription ID on the origin site.
	 *
	 * @return int
	 */
	public function get_id() {
		return (int) ( $this->data->id ?? 0 );
	}

	/**
	 * The status after the change.
	 *
	 * @return string
	 */
	public function get_status_after() {
		return (string) ( $this->data->status_after ?? '' );
	}

	/**
	 * The products, keyed by ID, each with id, name and slug.
	 *
	 * @return array
	 */
	public function get_products() {
		$products = [];
		foreach ( (array) ( $this->data->products ?? [] ) as $product ) {
			$product = (array) $product;
			if ( isset( $product['id'] ) ) {
				$products[ (int) $product['id'] ] = $product;
			}
		}
		return $products;
	}
}
