<?php
/**
 * The store every subscriber-discounts suite needs before it can assert anything.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce\Traits;

use Newspack\Product_Targeting;
use Newspack\Subscriber_Discounts_Pricing;
use Newspack\Subscriber_Eligibility;

/**
 * WooCommerce is not loaded in the test suite, so a "product" here is the repo's
 * `WC_Product` mock backed by a real `product` post, and a subscription is the
 * access-rules filter answering yes for one reader. Both are fixtures rather
 * than the real thing, which is why they are worth sharing: a suite that builds
 * them differently is testing a different store than its siblings.
 */
trait Trait_Subscriber_Discounts_Fixtures {

	/**
	 * The subscription product that grants the discount.
	 */
	const GRANTING_SUBSCRIPTION_ID = 4242;

	/**
	 * Reader who holds the granting subscription.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Only the subscriber holds the granting subscription.
	 *
	 * @param bool  $has_subscription Whether the reader has one.
	 * @param int   $user_id          Reader.
	 * @param int[] $product_ids      Subscription products that would grant it.
	 * @return bool
	 */
	public function grant_subscription_to_subscriber( $has_subscription, $user_id, $product_ids ) {
		return (int) $user_id === $this->subscriber_id && in_array( self::GRANTING_SUBSCRIPTION_ID, array_map( 'absint', $product_ids ), true );
	}

	/**
	 * Create a product post plus its mock, registered so wc_get_product() finds it.
	 *
	 * @param float $price      Product price.
	 * @param float $sale_price Sale price, when the product is on sale.
	 * @param int   $product_id Explicit post ID, when the test needs a known one.
	 * @return \WC_Product
	 */
	private function create_product( $price, $sale_price = null, $product_id = 0 ) {
		$post_id = $product_id ? $product_id : $this->factory->post->create( [ 'post_type' => 'product' ] );
		$data    = [
			'id'            => $post_id,
			'price'         => $price,
			'regular_price' => $price,
		];
		if ( null !== $sale_price ) {
			$data['price']      = $sale_price;
			$data['sale_price'] = $sale_price;
		}
		$product = new \WC_Product( $data );
		global $products_database;
		$products_database[ $post_id ] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return $product;
	}

	/**
	 * Empty the mock product registry, which is the one piece of fixture state
	 * `WP_UnitTestCase` cannot roll back for us.
	 */
	private function reset_products_database() {
		global $products_database;
		$products_database = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Reset every memoized layer between assertions.
	 */
	private function flush_caches() {
		Product_Targeting::flush_cache();
		Subscriber_Eligibility::flush_cache();
		Subscriber_Discounts_Pricing::flush_cache();
	}
}
