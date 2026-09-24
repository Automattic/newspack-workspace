<?php
/**
 * Tests for Newspack\Dynamic_Pricing_Bridges.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing_Bridges;

/**
 * Tests for Newspack\Dynamic_Pricing_Bridges.
 *
 * @group Dynamic_Pricing
 */
class Newspack_Test_Dynamic_Pricing_Bridges extends WP_UnitTestCase {
	/**
	 * Set up the test: register the product post type and init the bridges.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type( 'product', [ 'public' => true ] );
		}
		Dynamic_Pricing_Bridges::init();
	}

	/**
	 * Tear down the test: remove the filters added by the bridges.
	 */
	public function tear_down() {
		remove_filter( 'woocommerce_dynamic_pricing_is_excluded', [ Dynamic_Pricing_Bridges::class, 'exclude_donations' ], 10 );
		remove_filter( 'woocommerce_dynamic_pricing_is_excluded', [ Dynamic_Pricing_Bridges::class, 'exclude_group_subscriptions' ], 10 );
		parent::tear_down();
	}

	/**
	 * Donation products are excluded from dynamic pricing.
	 */
	public function test_excludes_donation_products() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $post_id, '_newspack_is_donation', 'yes' );

		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( $post_id );

		$excluded = apply_filters( 'woocommerce_dynamic_pricing_is_excluded', false, $product, null );
		$this->assertTrue( $excluded, 'Donation products must be excluded.' );
	}

	/**
	 * Non-donation products are not excluded from dynamic pricing.
	 */
	public function test_does_not_exclude_non_donation_products() {
		$post_id = $this->factory->post->create( [ 'post_type' => 'product' ] );

		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$product->method( 'get_id' )->willReturn( $post_id );
		// The group bridge reads the product's meta too; WooCommerce answers '' for an unset key.
		$product->method( 'get_meta' )->willReturn( '' );

		$excluded = apply_filters( 'woocommerce_dynamic_pricing_is_excluded', false, $product, null );
		$this->assertFalse( $excluded );
	}



	/**
	 * An already-excluded product short-circuits and stays excluded.
	 */
	public function test_short_circuits_when_already_excluded() {
		$product = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$excluded = apply_filters( 'woocommerce_dynamic_pricing_is_excluded', true, $product, null );
		$this->assertTrue( $excluded );
	}

	/**
	 * Group subscriptions (per-subscription enabled meta) are excluded from
	 * dynamic pricing.
	 */
	public function test_excludes_group_subscriptions() {
		$product      = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$subscription = new \WC_Subscription(
			[
				'id'   => 123,
				'meta' => [ '_newspack_group_subscription_enabled' => 'yes' ],
			]
		);

		$excluded = apply_filters( 'woocommerce_dynamic_pricing_is_excluded', false, $product, $subscription );
		$this->assertTrue( $excluded, 'Group subscriptions must be excluded.' );
	}

	/**
	 * Regular (non-group) subscriptions are not excluded from dynamic pricing.
	 */
	public function test_does_not_exclude_regular_subscriptions() {
		$product      = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$subscription = new \WC_Subscription( [ 'id' => 124 ] );

		$excluded = apply_filters( 'woocommerce_dynamic_pricing_is_excluded', false, $product, $subscription );
		$this->assertFalse( $excluded );
	}

	/**
	 * Group products are excluded before the subscription exists: at checkout,
	 * where the target is the cart item, and in previews, which pass none.
	 * Priced at checkout, the subscription would be created at the rule's price
	 * and the renewal exclusion would then freeze it there.
	 */
	public function test_excludes_group_products_before_the_subscription_exists() {
		$product   = new \WC_Product(
			[
				'id'   => $this->factory->post->create( [ 'post_type' => 'product' ] ),
				'type' => 'subscription',
				'meta' => [ '_newspack_group_subscription_enabled' => 'yes' ],
			]
		);
		$cart_item = [
			'product_id' => $product->get_id(),
			'data'       => $product,
		];

		$excluded = apply_filters( 'woocommerce_dynamic_pricing_is_excluded', false, $product, $cart_item );
		$this->assertTrue( $excluded, 'Group products must be excluded at checkout.' );
		$excluded = apply_filters( 'woocommerce_dynamic_pricing_is_excluded', false, $product, null );
		$this->assertTrue( $excluded, 'Group products must be excluded from previews.' );
	}

	/**
	 * Products without the group setting are priced at checkout as usual.
	 */
	public function test_does_not_exclude_non_group_products_at_checkout() {
		$product   = new \WC_Product(
			[
				'id'   => $this->factory->post->create( [ 'post_type' => 'product' ] ),
				'type' => 'subscription',
			]
		);
		$cart_item = [
			'product_id' => $product->get_id(),
			'data'       => $product,
		];

		$excluded = apply_filters( 'woocommerce_dynamic_pricing_is_excluded', false, $product, $cart_item );
		$this->assertFalse( $excluded );
	}

	/**
	 * A variation carries its own group setting rather than inheriting its
	 * parent's (this parent has none), so the variation decides — the same
	 * product the renewal exclusion reads.
	 */
	public function test_excludes_a_group_variation_by_its_own_setting() {
		$parent_id = $this->factory->post->create( [ 'post_type' => 'product' ] );
		$variation = new \WC_Product(
			[
				'id'        => $this->factory->post->create( [ 'post_type' => 'product' ] ),
				'type'      => 'subscription_variation',
				'parent_id' => $parent_id,
				'meta'      => [ '_newspack_group_subscription_enabled' => 'yes' ],
			]
		);
		$cart_item = [
			'product_id'   => $parent_id,
			'variation_id' => $variation->get_id(),
			'data'         => $variation,
		];

		$excluded = apply_filters( 'woocommerce_dynamic_pricing_is_excluded', false, $variation, $cart_item );
		$this->assertTrue( $excluded, 'Group variations must be excluded at checkout.' );
	}

	/**
	 * Off-contract filter input (truthy non-bool, null product) is normalized to
	 * a boolean instead of raising a TypeError inside the pricing path.
	 */
	public function test_tolerates_off_contract_filter_input() {
		$this->assertTrue( Dynamic_Pricing_Bridges::exclude_donations( 'yes', null, null ) );
		$this->assertFalse( Dynamic_Pricing_Bridges::exclude_donations( 0, null, null ) );
		$this->assertTrue( Dynamic_Pricing_Bridges::exclude_group_subscriptions( 1, null, null ) );
		$this->assertFalse( Dynamic_Pricing_Bridges::exclude_group_subscriptions( false, null, 'not-a-subscription' ) );
	}
}
