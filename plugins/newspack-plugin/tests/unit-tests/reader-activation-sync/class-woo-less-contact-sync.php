<?php
/**
 * Contact_Sync test double simulating a site without WooCommerce.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Contact_Sync;

/**
 * Simulates a site without WooCommerce. The suite's WC mocks are loaded
 * process-wide, so the real is_woocommerce_available() check can never be
 * false when tests run — this override makes the WooCommerce-less early
 * return of get_contact_data() reachable.
 */
class Woo_Less_Contact_Sync extends Contact_Sync {
	/**
	 * Report WooCommerce as unavailable.
	 *
	 * @return bool
	 */
	protected static function is_woocommerce_available() {
		return false;
	}
}
