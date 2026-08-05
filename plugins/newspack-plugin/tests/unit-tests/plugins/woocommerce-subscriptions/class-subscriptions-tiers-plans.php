<?php
/**
 * Tests tier composition under the APFS subscription-plan product model.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Subscriptions;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';

/**
 * Test tiers under the subscription-plan product model.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscriptions_Tiers_Plans extends WP_UnitTestCase {
	/**
	 * Reset mock state before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database;
		$products_database                              = [];
		WCS_ATT_Product_Schemes::$products_with_schemes = [];
		WCS_ATT_Product_Schemes::$product_schemes       = [];
	}

	/**
	 * Reset mock state after each test.
	 */
	public function tear_down() {
		WCS_ATT_Product_Schemes::$products_with_schemes = [];
		WCS_ATT_Product_Schemes::$product_schemes       = [];
		parent::tear_down();
	}

	/**
	 * Register a product with the given plans in the mock scheme registry.
	 *
	 * @param int   $id    Product ID.
	 * @param array $plans [ scheme_key => [ 'period' => string, 'interval' => int ] ].
	 */
	protected function give_plans( $id, $plans ) {
		WCS_ATT_Product_Schemes::$products_with_schemes[] = $id;
		WCS_ATT_Product_Schemes::$product_schemes[ $id ]  = $plans;
	}

	/**
	 * A product's plans come back keyed by scheme key.
	 */
	public function test_get_subscription_plans_returns_schemes() {
		$product = wc_create_mock_product(
			[
				'id'   => 300,
				'type' => 'variable',
			]
		);
		$this->give_plans(
			300,
			[
				'mkey' => [
					'period'   => 'month',
					'interval' => 1,
				],
				'ykey' => [
					'period'   => 'year',
					'interval' => 1,
				],
			]
		);

		$plans = WooCommerce_Subscriptions::get_subscription_plans( $product );
		$this->assertEquals( [ 'mkey', 'ykey' ], array_keys( $plans ) );
		$this->assertSame( 'month_1', WooCommerce_Subscriptions::get_plan_frequency( $plans['mkey'] ) );
		$this->assertSame( 'year_1', WooCommerce_Subscriptions::get_plan_frequency( $plans['ykey'] ) );
	}

	/**
	 * A product with no plans returns an empty array, not a warning.
	 */
	public function test_get_subscription_plans_without_plans() {
		$product = wc_create_mock_product(
			[
				'id'   => 301,
				'type' => 'variable',
			]
		);
		$this->assertSame( [], WooCommerce_Subscriptions::get_subscription_plans( $product ) );
	}
}
