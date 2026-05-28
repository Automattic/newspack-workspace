<?php
/**
 * Class TestContentGateAccess
 *
 * @package Newspack_Network
 */

use Newspack_Network\Content_Gate\Access;
use Newspack_Network\Incoming_Events\Product_Updated;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Test the Content_Gate\Access class.
 */
class TestContentGateAccess extends WP_UnitTestCase {

	/**
	 * User with an active subscription on site1 for product 100.
	 *
	 * @var int
	 */
	public static $user_with_active_sub;

	/**
	 * User with a pending-cancel subscription on site2.
	 *
	 * @var int
	 */
	public static $user_with_pending_cancel_sub;

	/**
	 * User with a cancelled subscription on site2.
	 *
	 * @var int
	 */
	public static $user_with_cancelled_sub;

	/**
	 * User with no network subscriptions.
	 *
	 * @var int
	 */
	public static $user_without_subs;

	/**
	 * Local product ID with Network ID 'premium'.
	 *
	 * @var int
	 */
	public static $local_product_premium;

	/**
	 * Local product ID with Network ID 'basic'.
	 *
	 * @var int
	 */
	public static $local_product_basic;

	/**
	 * Local product ID with no Network ID.
	 *
	 * @var int
	 */
	public static $local_product_no_network;

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		// Set up synced network products data.
		$products = [
			'http://site1' => [
				100 => [
					'id'         => 100,
					'name'       => 'Premium Monthly',
					'slug'       => 'premium-monthly',
					'network_id' => 'premium',
				],
				101 => [
					'id'         => 101,
					'name'       => 'Basic Plan',
					'slug'       => 'basic-plan',
					'network_id' => 'basic',
				],
				102 => [
					'id'         => 102,
					'name'       => 'Local Only',
					'slug'       => 'local-only',
					'network_id' => '',
				],
			],
			'http://site2' => [
				200 => [
					'id'         => 200,
					'name'       => 'Premium Yearly',
					'slug'       => 'premium-yearly',
					'network_id' => 'premium',
				],
			],
		];
		update_option( Product_Updated::OPTION_NAME, $products, false );

		// Create local products with Network ID meta.
		self::$local_product_premium = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Local Premium',
			]
		);
		update_post_meta( self::$local_product_premium, Product_Admin::NETWORK_ID_META_KEY, 'premium' );

		self::$local_product_basic = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Local Basic',
			]
		);
		update_post_meta( self::$local_product_basic, Product_Admin::NETWORK_ID_META_KEY, 'basic' );

		self::$local_product_no_network = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'No Network ID',
			]
		);

		// Create test users with network subscription meta.
		self::$user_with_active_sub = wp_insert_user(
			[
				'user_login' => 'cg_user_active_sub',
				'user_pass'  => '123',
				'role'       => 'subscriber',
			]
		);
		add_user_meta(
			self::$user_with_active_sub,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				'http://site1' => [
					500 => [
						'id'       => 500,
						'status'   => 'active',
						'products' => [
							100 => [
								'id'   => 100,
								'name' => 'Premium Monthly',
							],
						],
					],
				],
			]
		);

		self::$user_with_pending_cancel_sub = wp_insert_user(
			[
				'user_login' => 'cg_user_pending_sub',
				'user_pass'  => '123',
				'role'       => 'subscriber',
			]
		);
		add_user_meta(
			self::$user_with_pending_cancel_sub,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				'http://site2' => [
					501 => [
						'id'       => 501,
						'status'   => 'pending-cancel',
						'products' => [
							200 => [
								'id'   => 200,
								'name' => 'Premium Yearly',
							],
						],
					],
				],
			]
		);

		self::$user_with_cancelled_sub = wp_insert_user(
			[
				'user_login' => 'cg_user_cancelled_sub',
				'user_pass'  => '123',
				'role'       => 'subscriber',
			]
		);
		add_user_meta(
			self::$user_with_cancelled_sub,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				'http://site2' => [
					502 => [
						'id'       => 502,
						'status'   => 'cancelled',
						'products' => [
							200 => [
								'id'   => 200,
								'name' => 'Premium Yearly',
							],
						],
					],
				],
			]
		);

		self::$user_without_subs = wp_insert_user(
			[
				'user_login' => 'cg_user_no_subs',
				'user_pass'  => '123',
				'role'       => 'subscriber',
			]
		);
	}

	/**
	 * When $has_subscription is already true, the filter should short-circuit and return true.
	 */
	public function test_check_network_subscriptions_already_granted() {
		$result = Access::check_network_subscriptions( true, self::$user_without_subs, [ self::$local_product_premium ] );
		$this->assertTrue( $result );
	}

	/**
	 * When product_ids is empty, the filter should return the original value.
	 */
	public function test_check_network_subscriptions_empty_product_ids() {
		$result = Access::check_network_subscriptions( false, self::$user_with_active_sub, [] );
		$this->assertFalse( $result );
	}

	/**
	 * When the local product has no Network ID, the filter should return the original value.
	 */
	public function test_check_network_subscriptions_no_network_id_on_product() {
		$result = Access::check_network_subscriptions( false, self::$user_with_active_sub, [ self::$local_product_no_network ] );
		$this->assertFalse( $result );
	}

	/**
	 * When the user has no network subscriptions, the filter should return the original value.
	 */
	public function test_check_network_subscriptions_user_without_subs() {
		$result = Access::check_network_subscriptions( false, self::$user_without_subs, [ self::$local_product_premium ] );
		$this->assertFalse( $result );
	}

	/**
	 * When the user has an active subscription on another site for a product with a matching
	 * Network ID, access should be granted.
	 */
	public function test_check_network_subscriptions_matching_network_id() {
		$result = Access::check_network_subscriptions( false, self::$user_with_active_sub, [ self::$local_product_premium ] );
		$this->assertTrue( $result );
	}

	/**
	 * Pending-cancel subscriptions should still grant access.
	 */
	public function test_check_network_subscriptions_pending_cancel_grants_access() {
		$result = Access::check_network_subscriptions( false, self::$user_with_pending_cancel_sub, [ self::$local_product_premium ] );
		$this->assertTrue( $result );
	}

	/**
	 * Cancelled subscriptions should not grant access.
	 */
	public function test_check_network_subscriptions_cancelled_does_not_grant_access() {
		$result = Access::check_network_subscriptions( false, self::$user_with_cancelled_sub, [ self::$local_product_premium ] );
		$this->assertFalse( $result );
	}

	/**
	 * When the user's subscription is for a different Network ID, access should not be granted.
	 */
	public function test_check_network_subscriptions_non_matching_network_id() {
		$result = Access::check_network_subscriptions( false, self::$user_with_active_sub, [ self::$local_product_basic ] );
		$this->assertFalse( $result );
	}

	/**
	 * When the synced product data is missing (option not set), access should not be granted.
	 */
	public function test_check_network_subscriptions_missing_synced_product_data() {
		$original = get_option( Product_Updated::OPTION_NAME );
		delete_option( Product_Updated::OPTION_NAME );

		$result = Access::check_network_subscriptions( false, self::$user_with_active_sub, [ self::$local_product_premium ] );
		$this->assertFalse( $result );

		update_option( Product_Updated::OPTION_NAME, $original, false );
	}

	/**
	 * Data provider for test_user_has_active_network_subscription_for_network_id.
	 *
	 * @return array
	 */
	public function network_subscription_for_network_id_data() {
		return [
			'active sub, matching network ID'         => [
				'user_with_active_sub',
				'premium',
				500,
				'http://site1',
			],
			'active sub, non-matching network ID'     => [
				'user_with_active_sub',
				'basic',
				false,
			],
			'active sub, nonexistent network ID'      => [
				'user_with_active_sub',
				'nonexistent',
				false,
			],
			'pending-cancel sub, matching network ID' => [
				'user_with_pending_cancel_sub',
				'premium',
				501,
				'http://site2',
			],
			'cancelled sub, matching network ID'      => [
				'user_with_cancelled_sub',
				'premium',
				false,
			],
			'no subs, matching network ID'            => [
				'user_without_subs',
				'premium',
				false,
			],
		];
	}

	/**
	 * Test user_has_active_network_subscription_for_network_id.
	 *
	 * @param string   $user_property Static property name for the user ID.
	 * @param string   $network_id    Product Network ID to look up.
	 * @param int|bool $expected_id   Expected subscription ID, or false.
	 * @param string   $expected_site Expected site URL (only when $expected_id is not false).
	 * @dataProvider network_subscription_for_network_id_data
	 */
	public function test_user_has_active_network_subscription_for_network_id( $user_property, $network_id, $expected_id, $expected_site = '' ) {
		$result = Access::user_has_active_network_subscription_for_network_id( self::$$user_property, $network_id );
		if ( $expected_id ) {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'site', $result );
			$this->assertArrayHasKey( 'subscription', $result );
			$this->assertEquals( $expected_id, $result['subscription']['id'] );
			$this->assertEquals( $expected_site, $result['site'] );
		} else {
			$this->assertFalse( $result );
		}
	}

	/**
	 * Test get_network_ids_for_products returns correct Network IDs.
	 */
	public function test_get_network_ids_for_products() {
		$network_ids = Access::get_network_ids_for_products( [ self::$local_product_premium, self::$local_product_basic ] );
		$this->assertCount( 2, $network_ids );
		$this->assertContains( 'premium', $network_ids );
		$this->assertContains( 'basic', $network_ids );
	}

	/**
	 * Test get_network_ids_for_products skips products without Network IDs.
	 */
	public function test_get_network_ids_for_products_skips_empty() {
		$network_ids = Access::get_network_ids_for_products( [ self::$local_product_no_network ] );
		$this->assertEmpty( $network_ids );
	}

	/**
	 * Test get_network_ids_for_products deduplicates.
	 */
	public function test_get_network_ids_for_products_deduplicates() {
		// Create another product with the same Network ID.
		$another_premium = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Another Premium',
			]
		);
		update_post_meta( $another_premium, Product_Admin::NETWORK_ID_META_KEY, 'premium' );

		$network_ids = Access::get_network_ids_for_products( [ self::$local_product_premium, $another_premium ] );
		$this->assertCount( 1, $network_ids );
		$this->assertContains( 'premium', $network_ids );

		wp_delete_post( $another_premium, true );
	}
}
