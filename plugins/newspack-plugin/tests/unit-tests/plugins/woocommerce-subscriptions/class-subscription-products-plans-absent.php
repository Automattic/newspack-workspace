<?php
/**
 * The layer on a site without the subscription plans API (Subscriptions before 9.0,
 * no standalone plugin): only legacy subscription types sell as subscriptions.
 *
 * Runs in a separate process so no other file's plans mocks leak in.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscription_Products;

/**
 * A site with no subscription plans API loaded.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscription_Products_Plans_Absent extends WP_UnitTestCase {
	/**
	 * With no plans API loaded, a product carrying plan meta offers nothing.
	 */
	public function test_plain_product_offers_nothing_without_the_plans_api() {
		require_once dirname( __DIR__, 3 ) . '/mocks/wc-mocks.php';
		$this->assertFalse( class_exists( 'WCS_ATT_Product_Schemes' ), 'Meaningless if the plans mocks leaked in.' );
		$product = wc_create_mock_product(
			[
				'id'   => 5,
				'type' => 'simple',
				'meta' => [ '_wcsatt_schemes_status' => 'override' ],
			] 
		);
		$this->assertFalse( Subscription_Products::offers_subscription( $product ) );
		$this->assertSame( [], Subscription_Products::get_purchase_options( $product ) );
		$this->assertSame( '', Subscription_Products::get_purchased_plan_key( new WC_Order_Item_Product( [] ) ) );
	}
}
