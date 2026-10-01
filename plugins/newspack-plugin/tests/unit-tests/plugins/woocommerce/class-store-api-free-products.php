<?php
/**
 * Tests that the Store API refuses to sell free products.
 *
 * @package Newspack\Tests
 */

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';
require_once __DIR__ . '/../../../mocks/store-api-mocks.php';
require_once __DIR__ . '/../../../../includes/plugins/woocommerce/class-woocommerce-store-api-free-products.php';

/**
 * Pins that the Store API rejects a free product at both of its validation points,
 * and still sells anything that costs the reader money.
 *
 * @group WooCommerce_Store_API_Free_Products
 */
class Newspack_Test_WooCommerce_Store_API_Free_Products extends WP_UnitTestCase {
	/**
	 * Product ID shared by the fixtures.
	 */
	const PRODUCT_ID = 101;

	/**
	 * Start with no saved product under the fixture ID, so each test judges its own fixture.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database;
		unset( $products_database[ self::PRODUCT_ID ] );
	}

	/**
	 * Remove saved products.
	 */
	public function tear_down() {
		global $products_database;
		unset( $products_database[ self::PRODUCT_ID ] );
		parent::tear_down();
	}

	/**
	 * Build and save a product double, as the catalog holds it.
	 *
	 * @param string $price Active price, as WooCommerce stores it.
	 * @param array  $meta  Product meta.
	 * @return WC_Product
	 */
	private function product( $price, $meta = [] ) {
		global $products_database;
		$products_database[ self::PRODUCT_ID ] = new WC_Product(
			[
				'id'    => self::PRODUCT_ID,
				'name'  => 'Complimentary Access',
				'type'  => 'subscription',
				'price' => $price,
				'meta'  => $meta,
			]
		);
		return $products_database[ self::PRODUCT_ID ];
	}

	/**
	 * Fire both Store API validation hooks for a product and collect the error codes.
	 *
	 * @param WC_Product $product Product being bought, as the cart holds it.
	 * @return string[] Error codes, one per hook that rejected the product.
	 */
	private function store_api_rejections( $product ) {
		$rejections = [];
		foreach ( [ 'woocommerce_store_api_validate_add_to_cart', 'woocommerce_store_api_validate_cart_item' ] as $validation_hook ) {
			try {
				do_action( $validation_hook, $product, [] );
			} catch ( RouteException $e ) {
				$rejections[] = $e->getErrorCode();
			}
		}
		return $rejections;
	}

	/**
	 * A $0 product can be neither added to a Store API cart nor checked out, and a
	 * Name Your Price flag left behind by an inactive plugin does not exempt it.
	 */
	public function test_free_product_is_rejected_at_add_to_cart_and_checkout() {
		$both_hooks_rejected = [ 'woocommerce_rest_product_not_purchasable', 'woocommerce_rest_product_not_purchasable' ];
		$this->assertSame( $both_hooks_rejected, $this->store_api_rejections( $this->product( '0' ) ) );
		$this->assertSame( $both_hooks_rejected, $this->store_api_rejections( $this->product( '0', [ '_nyp' => 'yes' ] ) ), 'Stale Name Your Price meta.' );
	}

	/**
	 * Anything the reader pays for stays on sale: a priced product, and a $0
	 * subscription with a sign-up fee.
	 */
	public function test_products_the_reader_pays_for_are_sold() {
		$this->assertSame( [], $this->store_api_rejections( $this->product( '5' ) ), 'Priced product.' );
		$this->assertSame( [], $this->store_api_rejections( $this->product( '0', [ '_subscription_sign_up_fee' => '10' ] ) ), 'Sign-up fee.' );
	}

	/**
	 * A paid product stays on sale when the reader is shown $0 for it: a subscriber
	 * discount filters the price, and a renewal cart reprices a comped line on the
	 * cart item. Both are judged on the saved product.
	 */
	public function test_paid_product_shown_at_zero_is_sold() {
		$cart_item_repriced_to_zero = clone $this->product( '5' );
		$cart_item_repriced_to_zero->set_price( '0' );
		$this->assertSame( [], $this->store_api_rejections( $cart_item_repriced_to_zero ), 'Repriced cart item.' );

		$discount_to_zero = function () {
			return '0';
		};
		add_filter( 'woocommerce_product_get_price', $discount_to_zero );
		$this->assertSame( [], $this->store_api_rejections( $this->product( '5' ) ), 'Discounted price.' );
	}

	/**
	 * A site that sells a free product on purpose through the Checkout block can opt
	 * that product back in.
	 */
	public function test_filter_allows_a_free_product() {
		add_filter( 'newspack_store_api_allow_free_product', '__return_true' );
		$this->assertSame( [], $this->store_api_rejections( $this->product( '0' ) ) );
	}
}
