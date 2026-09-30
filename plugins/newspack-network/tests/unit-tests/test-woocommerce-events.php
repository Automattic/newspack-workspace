<?php
/**
 * Class TestWoocommerceEvents
 *
 * @package Newspack_Network
 */

use Newspack_Network\Woocommerce\Events;

/**
 * What order events tell the hub about an order's line items.
 */
class TestWoocommerceEvents extends WP_UnitTestCase {

	/**
	 * A stand-in line item; the suite doesn't load WooCommerce.
	 *
	 * @param int         $product_id   Product ID (the parent, for a variation).
	 * @param int         $variation_id Variation ID, or 0.
	 * @param string|null $type         Product type, or null for a product that no longer exists.
	 * @return object
	 */
	private function item( $product_id, $variation_id, $type ) {
		$product = null === $type ? false : new class( $type ) {
			/**
			 * Constructor.
			 *
			 * @param string $type Product type.
			 */
			public function __construct( public $type ) {}

			/**
			 * Whether the product is of one of the given types.
			 *
			 * @param string|string[] $types Types to check.
			 * @return bool
			 */
			public function is_type( $types ) {
				return in_array( $this->type, (array) $types, true );
			}
		};
		return new class( $product_id, $variation_id, $product ) {
			/**
			 * Constructor.
			 *
			 * @param int          $product_id   Product ID.
			 * @param int          $variation_id Variation ID.
			 * @param object|false $product      Product.
			 */
			public function __construct( public $product_id, public $variation_id, public $product ) {}

			/**
			 * Product ID.
			 *
			 * @return int
			 */
			public function get_product_id() {
				return $this->product_id;
			}

			/**
			 * Variation ID.
			 *
			 * @return int
			 */
			public function get_variation_id() {
				return $this->variation_id;
			}

			/**
			 * Item name.
			 *
			 * @return string
			 */
			public function get_name() {
				return 'Item ' . $this->product_id;
			}

			/**
			 * The item's product.
			 *
			 * @return object|false
			 */
			public function get_product() {
				return $this->product;
			}
		};
	}

	/**
	 * Each line item is reported with its product, variation and whether it is a
	 * subscription product; items whose product is gone are left out, since their
	 * type can't be told.
	 */
	public function test_order_products() {
		$order = new class( [ $this->item( 30, 0, 'simple' ), $this->item( 31, 32, 'subscription_variation' ), $this->item( 33, 0, null ) ] ) {
			/**
			 * Constructor.
			 *
			 * @param array $items Line items.
			 */
			public function __construct( public $items ) {}

			/**
			 * Line items.
			 *
			 * @return array
			 */
			public function get_items() {
				return $this->items;
			}
		};

		$this->assertSame(
			[
				[
					'id'           => 30,
					'variation_id' => 0,
					'name'         => 'Item 30',
					'subscription' => false,
				],
				[
					'id'           => 31,
					'variation_id' => 32,
					'name'         => 'Item 31',
					'subscription' => true,
				],
			],
			Events::get_order_products( $order )
		);
	}
}
