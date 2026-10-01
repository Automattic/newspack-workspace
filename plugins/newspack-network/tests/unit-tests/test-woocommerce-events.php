<?php
/**
 * Class TestWoocommerceEvents
 *
 * @package Newspack_Network
 */

use Newspack_Network\Woocommerce\Events;

/**
 * What subscription and order events tell the network about their products.
 */
class TestWoocommerceEvents extends WP_UnitTestCase {

	/**
	 * A stand-in product; the suite doesn't load WooCommerce.
	 *
	 * @param int    $id   Product ID.
	 * @param string $type Product type.
	 * @return object
	 */
	private function product( $id, $type = 'simple' ) {
		return new class( $id, $type ) {
			/**
			 * Constructor.
			 *
			 * @param int    $id   Product ID.
			 * @param string $type Product type.
			 */
			public function __construct( public $id, public $type ) {}

			/**
			 * Product ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return $this->id;
			}

			/**
			 * Product name.
			 *
			 * @return string
			 */
			public function get_name() {
				return 'Product ' . $this->id;
			}

			/**
			 * Product slug.
			 *
			 * @return string
			 */
			public function get_slug() {
				return 'product-' . $this->id;
			}

			/**
			 * Whether the product is one of the given types.
			 *
			 * @param string|string[] $types Types.
			 * @return bool
			 */
			public function is_type( $types ) {
				return in_array( $this->type, (array) $types, true );
			}
		};
	}

	/**
	 * A stand-in line item for a product, or for one that no longer exists.
	 *
	 * @param object|false $product Product.
	 * @return object
	 */
	private function item( $product ) {
		return new class( $product ) {
			/**
			 * Constructor.
			 *
			 * @param object|false $product Product.
			 */
			public function __construct( public $product ) {}

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
	 * A stand-in order or subscription.
	 *
	 * @param int    $id      ID.
	 * @param array  $items   Line items.
	 * @param string $status  Status.
	 * @param int    $created Creation time.
	 * @return object
	 */
	private function order( $id, $items, $status = 'completed', $created = 0 ) {
		return new class( $id, $items, $status, $created ) {
			/**
			 * Constructor.
			 *
			 * @param int    $id      ID.
			 * @param array  $items   Line items.
			 * @param string $status  Status.
			 * @param int    $created Creation time.
			 */
			public function __construct( public $id, public $items, public $status, public $created ) {}

			/**
			 * ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return $this->id;
			}

			/**
			 * Status.
			 *
			 * @return string
			 */
			public function get_status() {
				return $this->status;
			}

			/**
			 * Line items.
			 *
			 * @return array
			 */
			public function get_items() {
				return $this->items;
			}

			/**
			 * Customer ID.
			 *
			 * @return int
			 */
			public function get_customer_id() {
				return 7;
			}

			/**
			 * Billing email.
			 *
			 * @return string
			 */
			public function get_billing_email() {
				return 'reader@example.test';
			}

			/**
			 * Creation date.
			 *
			 * @return DateTime
			 */
			public function get_date_created() {
				return new DateTime( '@' . $this->created );
			}
		};
	}

	/**
	 * A line item whose product was deleted is left out instead of failing the event.
	 */
	public function test_subscription_products_skip_deleted_products() {
		$subscription = $this->order( 42, [ $this->item( $this->product( 7, 'subscription' ) ), $this->item( false ) ] );

		$this->assertSame(
			[
				7 => [
					'id'   => 7,
					'name' => 'Product 7',
					'slug' => 'product-7',
				],
			],
			Events::get_subscription_products( $subscription )
		);
	}
}
