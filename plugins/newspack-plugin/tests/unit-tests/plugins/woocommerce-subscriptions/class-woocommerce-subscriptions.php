<?php
/**
 * Tests the WooCommerce Subscriptions integration class.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Subscriptions;
use Newspack\Reader_Activation;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';

/**
 * Test WooCommerce Subscriptions integration functionality.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_WooCommerce_Subscriptions extends WP_UnitTestCase {
	/**
	 * Reset the global mock databases before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
	}

	/**
	 * Test WooCommerce_Subscriptions::is_active.
	 */
	public function test_is_active() {
		$is_active = WooCommerce_Subscriptions::is_active();
		$this->assertFalse( $is_active, 'WooCommerce Subscriptions integration should not be active if the main WooCommerce plugin is not available.' );
	}

	/**
	 * Test WooCommerce_Subscriptions::is_enabled.
	 */
	public function test_is_enabled() {
		$is_enabled = WooCommerce_Subscriptions::is_enabled();
		$this->assertFalse( $is_enabled, 'WooCommerce Subscriptions integration should not be active if the main WooCommerce plugin is not available.' );
	}

	/**
	 * Test get_user_subscription returns an active subscription for a simple product.
	 */
	public function test_get_user_subscription_simple_product_active() {
		$user_id    = $this->factory->user->create();
		$product_id = 100;
		$product    = wc_create_mock_product( [ 'id' => $product_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ $product_id ],
			]
		);

		$result = WooCommerce_Subscriptions::get_user_subscription( $product, $user_id );
		$this->assertInstanceOf( WC_Subscription::class, $result, 'Should find active subscription for a simple product.' );
	}

	/**
	 * Test get_user_subscription returns null for a simple product with no subscription.
	 */
	public function test_get_user_subscription_simple_product_none() {
		$user_id = $this->factory->user->create();
		$product = wc_create_mock_product( [ 'id' => 200 ] );

		$result = WooCommerce_Subscriptions::get_user_subscription( $product, $user_id );
		$this->assertNull( $result, 'Should return null when the user has no subscription for the product.' );
	}

	/**
	 * Test get_user_subscription finds an active subscription on a child product.
	 */
	public function test_get_user_subscription_variable_product() {
		$user_id  = $this->factory->user->create();
		$child_id = 301;

		wc_create_mock_product( [ 'id' => $child_id ] );
		$parent = wc_create_mock_product(
			[
				'id'       => 300,
				'type'     => 'variable',
				'children' => [ $child_id ],
			]
		);

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ $child_id ],
			]
		);

		$result = WooCommerce_Subscriptions::get_user_subscription( $parent, $user_id );
		$this->assertInstanceOf( WC_Subscription::class, $result, 'Should find active subscription on a child/variation product.' );
	}

	/**
	 * Test get_user_subscription treats pending-cancel as active.
	 */
	public function test_get_user_subscription_pending_cancel_is_active() {
		$user_id    = $this->factory->user->create();
		$product_id = 400;
		$product    = wc_create_mock_product( [ 'id' => $product_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'pending-cancel',
				'products'    => [ $product_id ],
			]
		);

		$result = WooCommerce_Subscriptions::get_user_subscription( $product, $user_id );
		$this->assertInstanceOf( WC_Subscription::class, $result, 'Should treat pending-cancel subscriptions as active.' );
	}

	/**
	 * Test get_user_subscription returns null for an expired subscription.
	 */
	public function test_get_user_subscription_expired_returns_null() {
		$user_id    = $this->factory->user->create();
		$product_id = 500;
		$product    = wc_create_mock_product( [ 'id' => $product_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'expired',
				'products'    => [ $product_id ],
			]
		);

		$result = WooCommerce_Subscriptions::get_user_subscription( $product, $user_id );
		$this->assertNull( $result, 'Should return null for an expired subscription.' );
	}

	/**
	 * Test get_user_subscription returns null for a cancelled subscription.
	 */
	public function test_get_user_subscription_cancelled_returns_null() {
		$user_id    = $this->factory->user->create();
		$product_id = 600;
		$product    = wc_create_mock_product( [ 'id' => $product_id ] );

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'cancelled',
				'products'    => [ $product_id ],
			]
		);

		$result = WooCommerce_Subscriptions::get_user_subscription( $product, $user_id );
		$this->assertNull( $result, 'Should return null for a cancelled subscription.' );
	}
}
