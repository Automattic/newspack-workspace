<?php
/**
 * Newspack Network Access Grant Changed Incoming Event
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;

/**
 * A reader's tie to a product on another site that isn't a subscription they own:
 * a seat on someone else's group subscription, or a paid one-time order.
 *
 * Recorded per reader, keyed by site and item, in its own user meta so that
 * newspack-plugin's strict subscription check (owned subscriptions only, used by
 * access attribution) and the My Account "Other Subscriptions" tab keep seeing
 * only subscriptions the reader owns.
 */
abstract class Access_Grant_Changed extends Abstract_Incoming_Event {

	const USER_GRANTS_META_KEY = '_newspack_network_access_grants';

	/**
	 * The key this grant is stored under for its site, e.g. "group:90" or "order:86".
	 *
	 * @return string
	 */
	abstract protected function get_grant_key();

	/**
	 * The record stored for this grant.
	 *
	 * @return array
	 */
	abstract protected function get_grant_record();

	/**
	 * The grants a reader holds on other sites.
	 *
	 * @param int $user_id User ID.
	 * @return array Site URL => [ key => record ].
	 */
	public static function get_user_grants( $user_id ) {
		$grants = get_user_meta( $user_id, self::USER_GRANTS_META_KEY, true );
		return is_array( $grants ) ? $grants : [];
	}

	/**
	 * Record the grant on the hub's own copy of the reader.
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->maybe_update_user_meta();
	}

	/**
	 * Record the grant on the node's copy of the reader.
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->maybe_update_user_meta();
	}

	/**
	 * Record the grant on the reader, if they have an account here.
	 *
	 * @return void
	 */
	public function maybe_update_user_meta() {
		$email = $this->get_email();
		if ( ! $email || ! $this->get_id() ) {
			return;
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			Debugger::log( 'No user for access grant: ' . $email );
			return;
		}
		$grants = self::get_user_grants( $user->ID );
		$grants[ $this->get_site() ][ $this->get_grant_key() ] = $this->get_grant_record();
		update_user_meta( $user->ID, self::USER_GRANTS_META_KEY, $grants );
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
