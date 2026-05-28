<?php
/**
 * Newspack Hub Membership Plan Updated Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Hub\Stores\Subscriptions;
use Newspack_Network\Debugger;

/**
 * Class to handle the Membership Plan Updated Incoming Event
 */
class Membership_Plan_Updated extends Woo_Item_Changed {

	const OPTION_NAME = 'newspack_network_membership_plans';

	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->update_option();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->update_option();
	}

	/**
	 * Updates the user meta with the subscription data
	 *
	 * @return void
	 */
	public function update_option() {

		Debugger::log( 'Processing membership_plan_Updated ' );

		$current_value = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $current_value ) ) {
			$current_value = [];
		}

		if ( ! isset( $current_value[ $this->get_site() ] ) ) {
			$current_value[ $this->get_site() ] = [];
		}

		$current_value[ $this->get_site() ][ $this->get_id() ] = [
			'id'         => $this->get_id(),
			'name'       => $this->get_name(),
			'slug'       => $this->get_slug(),
			'network_id' => $this->get_network_id(),
			'products'   => $this->get_products(),

		];

		update_option( self::OPTION_NAME, $current_value );
	}

	/**
	 * Returns the id property
	 *
	 * @return ?int
	 */
	public function get_id() {
		return $this->data->id ?? null;
	}

	/**
	 * Returns the name property
	 *
	 * @return ?string
	 */
	public function get_name() {
		return $this->data->name ?? null;
	}

	/**
	 * Returns the slug property
	 *
	 * @return ?string
	 */
	public function get_slug() {
		return $this->data->slug ?? null;
	}

	/**
	 * Returns the network_id property
	 *
	 * @return ?string
	 */
	public function get_network_id() {
		return $this->data->network_id ?? null;
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
