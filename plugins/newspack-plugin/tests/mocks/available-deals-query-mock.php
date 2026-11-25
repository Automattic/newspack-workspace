<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Recording stand-in for the dynamic-pricing engine's Available_Deals_Query.
 * Require it only from separate-process tests: once declared, it switches
 * Available_Deals_Bridge on for every later test.
 *
 * @package Newspack\Tests
 */

namespace Automattic\WooCommerce\DynamicPricing\Subscriptions;

/**
 * Records the customer of each lookup and offers no deals.
 */
class Available_Deals_Query {
	/**
	 * User IDs of the customers looked up, in order.
	 *
	 * @var int[]
	 */
	public static $customer_ids = [];

	/**
	 * The deal IDs a customer can be offered.
	 *
	 * @param \WC_Customer $customer Customer.
	 * @return int[]
	 */
	public static function for_customer( $customer ) {
		self::$customer_ids[] = (int) $customer->get_id();
		return [];
	}
}
