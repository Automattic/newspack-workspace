<?php
/**
 * Newspack Order Item
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores;

use Newspack_Network\Debugger;
use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Database\Orders as Orders_DB;
use WP_Post;

/**
 * Order Item
 */
class Order_Item extends Woo_Item {

	/**
	 * Gets the post type slug
	 *
	 * @return string
	 */
	protected function get_post_type_slug() {
		return Orders_DB::POST_TYPE_SLUG;
	}

	/**
	 * Returns the Item's date_created
	 *
	 * @return ?string
	 */
	public function get_date_created() {
		return get_post_meta( $this->get_id(), 'date_created', true );
	}

	/**
	 * Returns the Item's subscription_relationship
	 *
	 * @return ?string
	 */
	public function get_subscription_relationship() {
		return get_post_meta( $this->get_id(), 'subscription_relationship', true );
	}
}
