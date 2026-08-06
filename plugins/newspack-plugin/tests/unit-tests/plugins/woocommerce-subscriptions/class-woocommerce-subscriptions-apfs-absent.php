<?php
/**
 * Tests the product-model predicates on a site without the APFS API.
 *
 * Deliberately does NOT require wc-mocks.php: that file defines the
 * WCS_ATT_Product_Schemes stub unconditionally, which would mask the guard this
 * covers. Runs in a separate process so no other test file's require leaks in.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Subscriptions;

/**
 * Test the pre-9.0 guard.
 *
 * @group WooCommerce_Subscriptions_Integration
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Newspack_Test_WooCommerce_Subscriptions_APFS_Absent extends WP_UnitTestCase {
	/**
	 * Without the APFS API, no product carries subscription plans and the legacy
	 * type check is the only thing that decides.
	 */
	public function test_plans_are_absent_without_the_apfs_api() {
		$this->assertFalse(
			class_exists( 'WCS_ATT_Product_Schemes' ),
			'This test is meaningless if the APFS stub has leaked into the process.'
		);

		$product = $this->getMockBuilder( 'stdClass' )
			->addMethods( [ 'get_meta', 'get_type', 'is_type' ] )
			->getMock();
		$product->method( 'get_meta' )->willReturn( '' );
		$product->method( 'get_type' )->willReturn( 'simple' );
		$product->method( 'is_type' )->willReturn( false );

		$this->assertFalse( WooCommerce_Subscriptions::has_subscription_plans( $product ) );
		$this->assertFalse( WooCommerce_Subscriptions::is_subscription_product( $product ) );
		$this->assertSame( [], WooCommerce_Subscriptions::get_subscription_plans( $product ) );
	}
}
