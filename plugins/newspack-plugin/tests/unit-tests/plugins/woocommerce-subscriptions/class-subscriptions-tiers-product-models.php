<?php
/**
 * Tests that Subscriptions_Tiers recognises both subscription product models.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscriptions_Tiers;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';

/**
 * Test Subscriptions_Tiers product-model detection.
 *
 * Subscriptions 9.0 folded All Products for Subscriptions into core, so a
 * recurring product is no longer necessarily of the `subscription` /
 * `variable-subscription` type — an ordinary `variable` product carrying
 * subscription plans is now what the product editor produces. Tiers have to
 * recognise both, or the variation modal renders empty (NPPM-3053).
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscriptions_Tiers_Product_Models extends WP_UnitTestCase {
	/**
	 * Reset the mock databases and scheme registry before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database;
		$products_database = [];
		WCS_ATT_Product_Schemes::$products_with_schemes = [];
	}

	/**
	 * Reset the scheme registry after each test.
	 */
	public function tear_down() {
		WCS_ATT_Product_Schemes::$products_with_schemes = [];
		parent::tear_down();
	}

	/**
	 * Build a mock product.
	 *
	 * @param int    $id      Product ID.
	 * @param string $type    Product type.
	 * @param array  $meta    Product meta.
	 *
	 * @return \WC_Product
	 */
	private function make_product( $id, $type, $meta = [] ) {
		return wc_create_mock_product(
			[
				'id'   => $id,
				'type' => $type,
				'meta' => $meta,
			]
		);
	}

	/**
	 * The legacy `subscription` product type is still a subscription.
	 */
	public function test_legacy_subscription_type_is_a_subscription() {
		$product = $this->make_product( 201, 'subscription' );
		$this->assertTrue( Subscriptions_Tiers::is_subscription_product( $product ) );
		$this->assertFalse( Subscriptions_Tiers::is_variable_subscription_product( $product ) );
	}

	/**
	 * The legacy `variable-subscription` type is a variable subscription.
	 */
	public function test_legacy_variable_subscription_type_is_variable() {
		$product = $this->make_product( 202, 'variable-subscription' );
		$this->assertTrue( Subscriptions_Tiers::is_subscription_product( $product ) );
		$this->assertTrue( Subscriptions_Tiers::is_variable_subscription_product( $product ) );
	}

	/**
	 * A plain product with no subscription plans is not a subscription.
	 */
	public function test_plain_product_is_not_a_subscription() {
		$simple   = $this->make_product( 203, 'simple' );
		$variable = $this->make_product( 204, 'variable' );
		$this->assertFalse( Subscriptions_Tiers::is_subscription_product( $simple ) );
		$this->assertFalse( Subscriptions_Tiers::is_subscription_product( $variable ) );
		$this->assertFalse( Subscriptions_Tiers::is_variable_subscription_product( $variable ) );
	}

	/**
	 * A `variable` product carrying subscription plans is a variable subscription.
	 *
	 * This is the NPPM-3053 regression: without it the variation modal renders
	 * its header and an empty body.
	 */
	public function test_variable_product_with_plans_is_a_variable_subscription() {
		$product = $this->make_product( 205, 'variable' );
		WCS_ATT_Product_Schemes::$products_with_schemes = [ 205 ];

		$this->assertTrue( Subscriptions_Tiers::has_subscription_plans( $product ) );
		$this->assertTrue( Subscriptions_Tiers::is_subscription_product( $product ) );
		$this->assertTrue( Subscriptions_Tiers::is_variable_subscription_product( $product ) );
	}

	/**
	 * A `simple` product carrying subscription plans is a subscription, but is
	 * not variable — its tier is the product itself, not its variations.
	 */
	public function test_simple_product_with_plans_is_a_non_variable_subscription() {
		$product = $this->make_product( 206, 'simple' );
		WCS_ATT_Product_Schemes::$products_with_schemes = [ 206 ];

		$this->assertTrue( Subscriptions_Tiers::is_subscription_product( $product ) );
		$this->assertFalse( Subscriptions_Tiers::is_variable_subscription_product( $product ) );
	}

	/**
	 * The `_wcsatt_disabled` opt-out wins over the presence of schemes, matching
	 * the check Subscriptions itself makes.
	 */
	public function test_disabled_meta_opts_out_of_subscription_plans() {
		$product = $this->make_product( 207, 'variable', [ '_wcsatt_disabled' => 'yes' ] );
		WCS_ATT_Product_Schemes::$products_with_schemes = [ 207 ];

		$this->assertFalse( Subscriptions_Tiers::has_subscription_plans( $product ) );
		$this->assertFalse( Subscriptions_Tiers::is_subscription_product( $product ) );
		$this->assertFalse( Subscriptions_Tiers::is_variable_subscription_product( $product ) );
	}
}
