<?php
/**
 * Class TestWoocommerceEvents
 *
 * @package Newspack_Network
 */

use Newspack_Network\Woocommerce\Events;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * What subscription and order events tell the network about their products.
 */
class TestWoocommerceEvents extends WP_UnitTestCase {

	/**
	 * The customer account behind the stand-in orders.
	 *
	 * @var int
	 */
	private $customer_id;

	/**
	 * Orders belong to a real account unless a test says otherwise: a paid order
	 * without one isn't sent.
	 */
	public function set_up() {
		parent::set_up();
		$this->customer_id = self::factory()->user->create( [ 'user_email' => 'customer@example.test' ] );
	}

	/**
	 * A stand-in product; the suite doesn't load WooCommerce.
	 *
	 * @param int    $id        Product ID.
	 * @param string $type      Product type.
	 * @param int    $parent_id Parent product ID, for a variation.
	 * @return object
	 */
	private function product( $id, $type = 'simple', $parent_id = 0 ) {
		return new class( $id, $type, $parent_id ) {
			/**
			 * Constructor.
			 *
			 * @param int    $id        Product ID.
			 * @param string $type      Product type.
			 * @param int    $parent_id Parent product ID.
			 */
			public function __construct( public $id, public $type, public $parent_id ) {}

			/**
			 * Product ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return $this->id;
			}

			/**
			 * Parent product ID.
			 *
			 * @return int
			 */
			public function get_parent_id() {
				return $this->parent_id;
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
				// WooCommerce Subscriptions makes a subscription variation answer to 'variation' too.
				if ( 'subscription_variation' === $this->type && in_array( 'variation', (array) $types, true ) ) {
					return true;
				}
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
	 * @param int      $id          ID.
	 * @param array    $items       Line items.
	 * @param string   $status      Status.
	 * @param int      $created     Creation time.
	 * @param int|null $customer_id Customer ID; 0 for a guest order, null for the test's customer.
	 * @return object
	 */
	private function order( $id, $items, $status = 'completed', $created = 0, $customer_id = null ) {
		$customer_id = null === $customer_id ? $this->customer_id : $customer_id;
		return new class( $id, $items, $status, $created, $customer_id ) {
			/**
			 * Constructor.
			 *
			 * @param int    $id          ID.
			 * @param array  $items       Line items.
			 * @param string $status      Status.
			 * @param int    $created     Creation time.
			 * @param int    $customer_id Customer ID; 0 for a guest order.
			 */
			public function __construct( public $id, public $items, public $status, public $created, public $customer_id ) {}

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
				return $this->customer_id;
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

	/**
	 * A product post tagged with a Network ID, as a stand-in product.
	 *
	 * @param string $network_id Network ID or ''.
	 * @param string $type       Product type.
	 * @return object
	 */
	private function tagged_product( $network_id, $type = 'simple' ) {
		$id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		if ( $network_id ) {
			update_post_meta( $id, Product_Admin::NETWORK_ID_META_KEY, $network_id );
		}
		return $this->product( $id, $type );
	}

	/**
	 * An order is reported with only its one-time products that carry a Network ID.
	 * A subscription product sharing a Network ID must not turn a renewal into a purchase.
	 */
	public function test_one_time_purchase_event_lists_tagged_one_time_products() {
		$pass    = $this->tagged_product( 'annual-pass' );
		$order   = $this->order(
			86,
			[
				$this->item( $pass ),
				$this->item( $this->tagged_product( 'premium', 'subscription' ) ),
				$this->item( $this->tagged_product( '' ) ),
				$this->item( false ),
			],
			'completed',
			1700000000
		);
		$payload = Events::one_time_purchase_changed( 86, 'processing', 'completed', $order );

		$this->assertSame( 'customer@example.test', $payload['email'] );
		$this->assertSame( $this->customer_id, $payload['user_id'] );
		$this->assertSame( 86, $payload['id'] );
		$this->assertSame( 'completed', $payload['status_after'] );
		$this->assertSame( 1700000000, $payload['purchased_at'] );
		$this->assertSame( [ $pass->id ], array_keys( $payload['products'] ) );
	}

	/**
	 * A renewal of a variable subscription is a subscription variation, which
	 * WooCommerce Subscriptions reports as a 'variation' too. It must never be
	 * sent as a one-time purchase, even when the parent carries a Network ID.
	 */
	public function test_subscription_variation_renewal_is_not_a_purchase() {
		$parent    = $this->tagged_product( 'premium', 'variable-subscription' );
		$variation = $this->product( self::factory()->post->create( [ 'post_type' => 'product' ] ), 'subscription_variation', $parent->id );
		update_post_meta( $variation->id, Product_Admin::NETWORK_ID_META_KEY, 'premium' );
		$order = $this->order( 88, [ $this->item( $variation ) ] );

		$this->assertTrue( $variation->is_type( 'variation' ), 'The stand-in mirrors the WooCommerce Subscriptions quirk.' );
		$this->assertNull( Events::one_time_purchase_changed( 88, 'pending', 'completed', $order ) );
	}

	/**
	 * An order with no tagged one-time product sends nothing: most orders are
	 * renewals or untagged, and every site would otherwise receive them all.
	 */
	public function test_order_without_tagged_one_time_product_sends_nothing() {
		$order = $this->order( 87, [ $this->item( $this->tagged_product( 'premium', 'subscription' ) ), $this->item( $this->tagged_product( '' ) ) ] );

		$this->assertNull( Events::one_time_purchase_changed( 87, 'pending', 'completed', $order ) );
	}

	/**
	 * An order line item for a variable product is the variation, but another site's
	 * synced product list only knows the parent, so the event reports both.
	 */
	public function test_variation_purchase_also_reports_its_parent_product() {
		$parent_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		// The suite has no wc_get_product() to resolve a variation to its parent, so the tag sits on the variation.
		$variation_id = self::factory()->post->create( [ 'post_type' => 'product_variation' ] );
		update_post_meta( $variation_id, Product_Admin::NETWORK_ID_META_KEY, 'annual-pass' );

		$variation = $this->product( $variation_id, 'variation', $parent_id );
		$order     = $this->order( 88, [ $this->item( $variation ) ], 'completed', 1700000000 );
		$payload   = Events::one_time_purchase_changed( 88, 'processing', 'completed', $order );

		$this->assertArrayHasKey( $variation_id, $payload['products'] );
		$this->assertArrayHasKey( $parent_id, $payload['products'] );
		$this->assertSame( $parent_id, $payload['products'][ $parent_id ]['id'] );
		$this->assertSame( $variation_id, $payload['products'][ $variation_id ]['id'] );
	}

	/**
	 * The event names the reader by their account email, which is what the other sites
	 * match and create accounts by, so the stand-in's billing address of
	 * reader@example.test never reaches them for a logged-in customer.
	 */
	public function test_purchase_event_names_the_customer_by_account_email() {
		$customer = self::factory()->user->create( [ 'user_email' => 'account@example.test' ] );
		$order    = $this->order( 86, [ $this->item( $this->tagged_product( 'annual-pass' ) ) ], 'completed', 0, $customer );

		$this->assertSame( 'account@example.test', Events::one_time_purchase_changed( 86, '', 'completed', $order )['email'] );
	}

	/**
	 * A paid order with no customer account grants nothing on the selling site, so it
	 * isn't sent to the others; the same goes for a customer since deleted. Its unpaid
	 * statuses still go out, under the billing email, to revoke a record an earlier
	 * build may have written.
	 */
	public function test_order_without_a_customer_account_is_sent_only_to_revoke() {
		$product = $this->tagged_product( 'annual-pass' );

		$this->assertNull( Events::one_time_purchase_changed( 87, 'pending', 'completed', $this->order( 87, [ $this->item( $product ) ], 'completed', 0, 0 ) ) );
		$this->assertNull( Events::one_time_purchase_changed( 88, 'pending', 'completed', $this->order( 88, [ $this->item( $product ) ], 'completed', 0, 999999 ) ) );

		$refund = Events::one_time_purchase_changed( 87, 'completed', 'refunded', $this->order( 87, [ $this->item( $product ) ], 'refunded', 0, 0 ) );
		$this->assertSame( 'refunded', $refund['status_after'] );
		$this->assertSame( 'reader@example.test', $refund['email'] );
	}
}
