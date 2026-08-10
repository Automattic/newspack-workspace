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
