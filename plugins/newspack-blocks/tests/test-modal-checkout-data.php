<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ModalCheckoutDataTest
 *
 * @package Newspack_Blocks
 */

use Newspack_Blocks\Modal_Checkout\Checkout_Data;

// These stubs assume WooCommerce is absent from the blocks PHPUnit bootstrap.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed
if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * Minimal WooCommerce product stub for checkout data tests.
	 */
	class WC_Product {
		/**
		 * Product ID.
		 *
		 * @var int
		 */
		private $id;

		/**
		 * Product type.
		 *
		 * @var string
		 */
		private $type;

		/**
		 * Child product IDs.
		 *
		 * @var int[]
		 */
		private $children;

		/**
		 * Product price.
		 *
		 * @var string
		 */
		private $price;

		/**
		 * Product name.
		 *
		 * @var string
		 */
		private $name;

		/**
		 * Parent product ID.
		 *
		 * @var int
		 */
		private $parent_id;

		/**
		 * Constructor.
		 *
		 * @param int    $id        Product ID.
		 * @param string $type      Product type.
		 * @param int[]  $children  Child product IDs.
		 * @param string $price     Product price.
		 * @param string $name      Product name.
		 * @param int    $parent_id Parent product ID.
		 */
		public function __construct( $id = 1, $type = 'simple', $children = [], $price = '1', $name = 'Product', $parent_id = 0 ) {
			$this->id        = $id;
			$this->type      = $type;
			$this->children  = $children;
			$this->price     = $price;
			$this->name      = $name;
			$this->parent_id = $parent_id;
		}

		/**
		 * Check the product type.
		 *
		 * @param string|string[] $type Product type.
		 * @return bool
		 */
		public function is_type( $type ) {
			return is_array( $type ) ? in_array( $this->type, $type, true ) : $this->type === $type;
		}

		/**
		 * Get the product type.
		 *
		 * @return string
		 */
		public function get_type() {
			return $this->type;
		}

		/**
		 * Get the product ID.
		 *
		 * @return int
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Get child product IDs.
		 *
		 * @return int[]
		 */
		public function get_children() {
			return $this->children;
		}

		/**
		 * Get the product price.
		 *
		 * @return string
		 */
		public function get_price() {
			return $this->price;
		}

		/**
		 * Get the product parent ID.
		 *
		 * @return int
		 */
		public function get_parent_id() {
			return $this->parent_id;
		}

		/**
		 * Get the product name.
		 *
		 * @return string
		 */
		public function get_name() {
			return $this->name;
		}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	/**
	 * Minimal WooCommerce variation stub.
	 */
	class WC_Product_Variation extends WC_Product {
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	/**
	 * Minimal order line item stub.
	 */
	class WC_Order_Item_Product {
		/**
		 * Product ID.
		 *
		 * @var int
		 */
		private $product_id;

		/**
		 * Line subtotal.
		 *
		 * @var string
		 */
		private $subtotal;

		/**
		 * Line-item quantity.
		 *
		 * @var int
		 */
		private $quantity;

		/**
		 * Constructor.
		 *
		 * @param int    $product_id Product ID.
		 * @param string $subtotal   Line subtotal.
		 * @param int    $quantity   Line-item quantity.
		 */
		public function __construct( $product_id, $subtotal = '25', $quantity = 1 ) {
			$this->product_id = $product_id;
			$this->subtotal   = $subtotal;
			$this->quantity   = $quantity;
		}

		public function get_product_id() {
			return $this->product_id;
		}

		public function get_variation_id() {
			return 0;
		}

		public function get_subtotal() {
			return $this->subtotal;
		}

		public function get_quantity() {
			return $this->quantity;
		}
	}
}

if ( ! class_exists( 'WC_Cart' ) ) {
	/**
	 * Minimal WooCommerce cart stub whose get_cart() returns pre-set line items.
	 */
	class WC_Cart {
		/**
		 * Cart items, keyed by cart item key.
		 *
		 * @var array
		 */
		private $items;

		/**
		 * Constructor.
		 *
		 * @param array $items Cart items, keyed by cart item key.
		 */
		public function __construct( $items ) {
			$this->items = $items;
		}

		/**
		 * Get cart items.
		 *
		 * @return array
		 */
		public function get_cart() {
			return $this->items;
		}
	}
}

if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
	/**
	 * Minimal WC_Subscriptions_Product stub exposing only what get_price_summary() reads.
	 */
	class WC_Subscriptions_Product {
		/**
		 * Billing interval.
		 *
		 * @param WC_Product $product Product.
		 * @return int
		 */
		public static function get_interval( $product ) {
			unset( $product );
			return 1;
		}

		/**
		 * Trial length.
		 *
		 * @param WC_Product $product Product.
		 * @return int
		 */
		public static function get_trial_length( $product ) {
			unset( $product );
			return 0;
		}

		/**
		 * Trial period.
		 *
		 * @param WC_Product $product Product.
		 * @return string
		 */
		public static function get_trial_period( $product ) {
			unset( $product );
			return '';
		}

		/**
		 * Sign-up fee, read from a test-controlled global so a single test can set it.
		 *
		 * @param WC_Product $product Product.
		 * @return float
		 */
		public static function get_sign_up_fee( $product ) {
			unset( $product );
			return $GLOBALS['newspack_blocks_test_sign_up_fee'] ?? 0;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Minimal WooCommerce order stub.
	 */
	class WC_Order {
		/**
		 * Line items.
		 *
		 * @var array
		 */
		protected $items = [];

		/**
		 * Order ID.
		 *
		 * @var int
		 */
		protected $id;

		/**
		 * Constructor.
		 *
		 * @param array $items Line items.
		 * @param int   $id    Order ID.
		 */
		public function __construct( $items = [], $id = 900 ) {
			$this->items = $items;
			$this->id    = $id;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_items() {
			return $this->items;
		}

		public function get_meta( $key ) {
			unset( $key );
			return '';
		}
	}
}

if ( ! class_exists( 'WC_Subscription' ) ) {
	/**
	 * Minimal subscription stub. A subscription created by hand in wp-admin has
	 * no parent order, so get_parent() returning false is the case under test.
	 */
	class WC_Subscription extends WC_Order {
		/**
		 * Parent order, or false when there is none.
		 *
		 * @var WC_Order|false
		 */
		private $parent;

		/**
		 * Constructor.
		 *
		 * @param array          $items  Line items.
		 * @param WC_Order|false $parent Parent order, or false.
		 * @param int            $id     Subscription ID.
		 */
		public function __construct( $items = [], $parent = false, $id = 901 ) {
			parent::__construct( $items, $id );
			$this->parent = $parent;
		}

		public function get_parent() {
			return $this->parent;
		}
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * Minimal wc_get_product stub.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product|null
	 */
	function wc_get_product( $product_id ) {
		if ( isset( $GLOBALS['newspack_blocks_test_products'][ $product_id ] ) ) {
			return $GLOBALS['newspack_blocks_test_products'][ $product_id ];
		}
		return new WC_Product( $product_id );
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Modal checkout data tests.
 */
class Newspack_Blocks_Modal_Checkout_Data_Test extends WP_UnitTestCase_Blocks {
	/**
	 * Clean up product fixtures.
	 */
	public function tear_down() {
		unset(
			$GLOBALS['newspack_blocks_test_products'],
			$GLOBALS['newspack_blocks_test_sign_up_fee'],
			$GLOBALS['newspack_blocks_test_last_wcs_price_string_args']
		);
		parent::tear_down();
	}

	/**
	 * Build a minimal WC_Cart stub whose get_cart() returns a single, given item.
	 *
	 * @param array $cart_item Cart item array (product_id, variation_id, quantity, data, ...).
	 * @return WC_Cart
	 */
	private function make_cart( $cart_item ) {
		return new WC_Cart( [ 'cart_item_key' => $cart_item ] );
	}

	/**
	 * Build a simple WC_Product stub for cart/order quantity fixtures.
	 *
	 * @param int    $id    Product ID.
	 * @param string $price Product price.
	 * @return WC_Product
	 */
	private function make_product( $id, $price ) {
		return new WC_Product( $id, 'simple', [], (string) $price, 'Product ' . $id );
	}

	/**
	 * A cart line item's quantity flows into `quantity`, and the per-unit price is
	 * multiplied into `amount` — a cart's price is per unit, unlike an order's.
	 */
	public function test_cart_checkout_data_includes_quantity_and_multiplied_amount() {
		$cart = $this->make_cart( [ 'product_id' => 77, 'variation_id' => 0, 'quantity' => 4, 'data' => $this->make_product( 77, 10 ) ] );
		$data = Checkout_Data::get_checkout_data( $cart );
		$this->assertSame( 4, $data['quantity'] );
		$this->assertSame( 40.0, (float) $data['amount'] );
	}

	/**
	 * A cart item missing `quantity` (version skew, or a non-WooCommerce caller)
	 * must not fatal, and behaves as a single seat.
	 */
	public function test_cart_checkout_data_defaults_missing_quantity_to_one() {
		$cart = $this->make_cart( [ 'product_id' => 78, 'variation_id' => 0, 'data' => $this->make_product( 78, 10 ) ] );
		$data = Checkout_Data::get_checkout_data( $cart );
		$this->assertSame( 1, $data['quantity'] );
		$this->assertSame( 10.0, (float) $data['amount'] );
	}

	/**
	 * An order line item's `get_subtotal()` already reflects quantity, so it must
	 * not be multiplied again — only `quantity` itself is surfaced.
	 */
	public function test_order_checkout_data_does_not_double_multiply_amount() {
		$GLOBALS['newspack_blocks_test_products'] = [
			79 => new WC_Product( 79, 'simple', [], '10', 'Product 79' ),
		];
		$order = new WC_Order( [ new WC_Order_Item_Product( 79, '40', 4 ) ], 950 );

		$data = Checkout_Data::get_checkout_data( $order );

		$this->assertSame( 4, $data['quantity'] );
		$this->assertSame( '40', $data['amount'], 'The order subtotal already reflects quantity and must not be multiplied again.' );
	}

	/**
	 * A bare product source (no cart or order) is always a single seat.
	 */
	public function test_product_checkout_data_quantity_defaults_to_one() {
		$product = new WC_Product( 80, 'simple', [], '10', 'Product 80' );

		$data = Checkout_Data::get_checkout_data( $product );

		$this->assertSame( 1, $data['quantity'] );
		$this->assertEquals( 10, $data['amount'] );
	}

	/**
	 * The subscription sign-up fee is charged per seat by WCS, so get_price_summary()
	 * must multiply it by quantity before handing it to wcs_price_string().
	 */
	public function test_price_summary_multiplies_signup_fee_by_quantity() {
		$GLOBALS['newspack_blocks_test_sign_up_fee'] = 5;
		$GLOBALS['newspack_blocks_test_products']    = [
			321 => new WC_Product( 321, 'subscription', [], '10', 'Supporter' ),
		];

		Checkout_Data::get_price_summary( 'Supporter', 10, 'month', 321, 3 );

		$this->assertEquals(
			15,
			$GLOBALS['newspack_blocks_test_last_wcs_price_string_args']['initial_amount'],
			'The sign-up fee should be multiplied by quantity, since WCS charges it per seat.'
		);
	}

	/**
	 * The default quantity of 1 leaves the sign-up fee unchanged, so existing
	 * (pre-quantity) callers of get_price_summary() see no behavior change.
	 */
	public function test_price_summary_defaults_to_unmultiplied_signup_fee() {
		$GLOBALS['newspack_blocks_test_sign_up_fee'] = 5;
		$GLOBALS['newspack_blocks_test_products']    = [
			322 => new WC_Product( 322, 'subscription', [], '10', 'Supporter' ),
		];

		Checkout_Data::get_price_summary( 'Supporter', 10, 'month', 322 );

		$this->assertEquals( 5, $GLOBALS['newspack_blocks_test_last_wcs_price_string_args']['initial_amount'] );
	}

	/**
	 * Variable subscription parents should behave like variable products.
	 */
	public function test_variable_subscription_parent_is_marked_variable() {
		$product = new WC_Product( 1406, 'variable-subscription', [ 1407, 1408 ], '', 'Subscription' );

		$GLOBALS['newspack_blocks_test_products'] = [
			1406 => $product,
			1407 => new WC_Product( 1407, 'subscription', [], '10', 'Monthly', 1406 ),
			1408 => new WC_Product( 1408, 'subscription', [], '20', 'Annual', 1406 ),
		];

		$data = Checkout_Data::get_checkout_data( $product );

		$this->assertSame( '1406', $data['product_id'] );
		$this->assertTrue( $data['is_variable'] );
		$this->assertSame( [ 1407, 1408 ], $data['variation_ids'] );
		$this->assertArrayNotHasKey( 'amount', $data );
	}

	/**
	 * A subscription created by hand in wp-admin has no parent order. Reading its
	 * purchase details must fall back to its own line items rather than calling
	 * get_items() on the `false` that get_parent() returns, which took down the
	 * modal's completion step with a fatal (NPPD-2170).
	 */
	public function test_subscription_without_a_parent_order_reads_its_own_items() {
		$GLOBALS['newspack_blocks_test_products'] = [
			2170 => new WC_Product( 2170, 'subscription', [], '25', 'Monthly Supporter' ),
		];

		$subscription = new WC_Subscription( [ new WC_Order_Item_Product( 2170, '25' ) ], false, 901 );

		$data = Checkout_Data::get_checkout_data( $subscription );

		$this->assertSame( '2170', $data['product_id'] );
		$this->assertSame( '25', $data['amount'] );

		// The subscription must identify itself as a subscription. Reporting its ID
		// as order_id sends the modal to /view-order/<subscription id>, which nothing
		// links to and which renders WCS's read-only receipt.
		$this->assertArrayNotHasKey( 'order_id', $data );
		$this->assertSame( [ 901 ], $data['subscription_ids'] );
	}

	/**
	 * With a parent order present, its items remain the source of truth.
	 */
	public function test_subscription_with_a_parent_order_reads_the_parent() {
		$GLOBALS['newspack_blocks_test_products'] = [
			2171 => new WC_Product( 2171, 'subscription', [], '99', 'Annual Supporter' ),
		];

		$parent       = new WC_Order( [ new WC_Order_Item_Product( 2171, '99' ) ], 900 );
		$subscription = new WC_Subscription( [ new WC_Order_Item_Product( 2170, '25' ) ], $parent, 901 );

		$data = Checkout_Data::get_checkout_data( $subscription );

		$this->assertSame( '2171', $data['product_id'] );
		$this->assertSame( '99', $data['amount'] );

		// The parent order is a real order, so it identifies the purchase.
		$this->assertSame( 900, $data['order_id'] );
	}

	/**
	 * A subscription that has a parent order keeps that order's identity, even when
	 * its product type is one the subscription_ids branch skips. A recurring
	 * donation resolves to `donation`, so widening the parentless fallback would
	 * silently move those readers from /view-order/ to /view-subscription/.
	 */
	public function test_donation_subscription_with_a_parent_keeps_order_identity() {
		$GLOBALS['newspack_blocks_test_products'] = [
			2172 => new WC_Product( 2172, 'simple', [], '15', 'Monthly Donation' ),
		];

		$parent       = new WC_Order( [ new WC_Order_Item_Product( 2172, '15' ) ], 902 );
		$subscription = new WC_Subscription( [ new WC_Order_Item_Product( 2172, '15' ) ], $parent, 903 );

		$data = Checkout_Data::get_checkout_data( $subscription );

		$this->assertSame( 902, $data['order_id'] );
		$this->assertArrayNotHasKey( 'subscription_ids', $data );
	}

	/**
	 * A subscription or order with no line items has no purchase to summarise.
	 * It must return empty rather than fatalling on the null product the missing
	 * line item would leave behind (NPPD-2170).
	 */
	public function test_source_with_no_line_items_returns_empty() {
		$subscription = new WC_Subscription( [], false, 901 );

		$this->assertSame( [], Checkout_Data::get_checkout_data( $subscription ) );
	}

	/**
	 * The data-checkout attribute must escape values so a product name containing
	 * an apostrophe can't break out of the single-quoted attribute.
	 */
	public function test_data_checkout_attr_escapes_single_quotes() {
		$attr = Checkout_Data::data_checkout_attr( [ 'name' => "Editor's Choice" ] );

		// Single-quote delimited attribute, matching the markup that consumes it.
		$this->assertStringStartsWith( "data-checkout='", $attr );
		$this->assertStringEndsWith( "'", $attr );

		// The apostrophe from the payload is escaped, so it can't terminate the attribute.
		$inner = substr( $attr, strlen( "data-checkout='" ), -1 );
		$this->assertStringNotContainsString( "'", $inner );
		$this->assertStringContainsString( '&#039;', $inner );

		// The escaped value still round-trips back to the original payload.
		$this->assertSame(
			[ 'name' => "Editor's Choice" ],
			json_decode( html_entity_decode( $inner, ENT_QUOTES ), true )
		);
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
