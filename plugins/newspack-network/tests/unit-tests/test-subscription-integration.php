<?php
/**
 * Class TestSubscriptionIntegration
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Incoming_Events\Membership_Plan_Updated;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Woocommerce_Memberships\Subscriptions_Integration;

/**
 * Test the Node class.
 */
class TestSubscriptionIntegration extends WP_UnitTestCase {

	/**
	 * User ID
	 *
	 * @var int
	 */
	public static $user_with_sub_on_1;

	/**
	 * User ID
	 *
	 * @var int
	 */
	public static $user_with_sub_on_2;

	/**
	 * User ID
	 *
	 * @var int
	 */
	public static $user_with_cancelled_sub_on_2;

	/**
	 * User ID
	 *
	 * @var int
	 */
	public static $user_with_non_network_sub_on_1;

	/**
	 * User ID
	 *
	 * @var int
	 */
	public static $user_without_subs;

	/**
	 * Get a reflection of a private method from the class for testing.
	 *
	 * @param string $name Method name.
	 * @return ReflectionMethod
	 */
	protected static function get_method( $name ) {
		$method = new ReflectionMethod( 'Newspack_Network\Woocommerce_Memberships\Subscriptions_Integration', $name );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * Set up before class.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		$plans = [
			'http://site1' => [
				1 => [
					'id'         => 1,
					'name'       => 'Plan 1',
					'network_id' => 'net-1',
					'products'   => [
						100 => [
							'id'   => 100,
							'name' => 'Product 100',
						],
					],
				],
				2 => [
					'id'         => 2,
					'name'       => 'Plan 2',
					'network_id' => 'net-2',
					'products'   => [
						200 => [
							'id'   => 200,
							'name' => 'Product 200',
						],
					],
				],
				3 => [
					'id'         => 3,
					'name'       => 'Plan 3',
					'network_id' => '',
				],
			],
			'http://site2' => [
				1  => [
					'id'         => 1,
					'name'       => 'Plan 1B',
					'network_id' => 'net-1',
					'products'   => [
						110 => [
							'id'   => 110,
							'name' => 'Product 110',
						],
					],
				],
				20 => [
					'id'         => 20,
					'name'       => 'Plan 2B',
					'network_id' => 'net-2',
					'products'   => [
						220 => [
							'id'   => 220,
							'name' => 'Product 220',
						],
					],
				],
				3  => [
					'id'         => 3,
					'name'       => 'Plan 3B',
					'network_id' => '',
				],
			],
		];

		update_option( Membership_Plan_Updated::OPTION_NAME, $plans );

		self::$user_with_sub_on_1 = wp_insert_user(
			array(
				'user_login' => 'user_with_sub_on_1',
				'user_pass'  => '123',
				'role'       => 'subscriber',
			)
		);

		self::$user_with_sub_on_2 = wp_insert_user(
			array(
				'user_login' => 'user_with_sub_on_2',
				'user_pass'  => '123',
				'role'       => 'subscriber',
			)
		);

		self::$user_with_cancelled_sub_on_2 = wp_insert_user(
			array(
				'user_login' => 'user_with_cancelled_sub_on_2',
				'user_pass'  => '123',
				'role'       => 'subscriber',
			)
		);

		self::$user_with_non_network_sub_on_1 = wp_insert_user(
			array(
				'user_login' => 'user_with_non_network_sub_on_1',
				'user_pass'  => '123',
				'role'       => 'subscriber',
			)
		);

		self::$user_without_subs = wp_insert_user(
			array(
				'user_login' => 'user_without_subs',
				'user_pass'  => '123',
				'role'       => 'subscriber',
			)
		);

		add_user_meta(
			self::$user_with_sub_on_1,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				'http://site1' => [
					500 => [
						'id'       => 500,
						'name'     => 'Subscription 500',
						'status'   => 'active',
						'products' => [
							100 => [
								'id'   => 100,
								'name' => 'Plan 100',
							],
						],
					],
				],
			]
		);

		add_user_meta(
			self::$user_with_sub_on_2,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				'http://site2' => [
					501 => [
						'id'       => 501,
						'name'     => 'Subscription 501',
						'status'   => 'pending-cancel',
						'products' => [
							220 => [
								'id'   => 220,
								'name' => 'Plan 220',
							],
						],
					],
				],
			]
		);

		add_user_meta(
			self::$user_with_cancelled_sub_on_2,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				'http://site2' => [
					502 => [
						'id'       => 502,
						'name'     => 'Subscription 502',
						'status'   => 'cancelled',
						'products' => [
							220 => [
								'id'   => 220,
								'name' => 'Plan 220',
							],
						],
					],
				],
			]
		);

		add_user_meta(
			self::$user_with_non_network_sub_on_1,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				'http://site1' => [
					503 => [
						'id'       => 503,
						'name'     => 'Subscription 503',
						'status'   => 'active',
						'products' => [
							999 => [
								'id'   => 999,
								'name' => 'Plan 999',
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Test get_network_membership_plans
	 */
	public function test_get_network_membership_plans() {
		$get_network_membership_plans = self::get_method( 'get_network_membership_plans' );

		$network_membership_plans = $get_network_membership_plans->invoke( null, 'net-1' );
		$this->assertCount( 2, $network_membership_plans );
		$this->assertArrayHasKey( 'http://site1', $network_membership_plans );
		$this->assertArrayHasKey( 'http://site2', $network_membership_plans );
		$this->assertArrayHasKey( 1, $network_membership_plans['http://site1'] );
		$this->assertArrayHasKey( 1, $network_membership_plans['http://site2'] );

		$network_membership_plans = $get_network_membership_plans->invoke( null, 'net-2' );
		$this->assertCount( 2, $network_membership_plans );
		$this->assertArrayHasKey( 'http://site1', $network_membership_plans );
		$this->assertArrayHasKey( 'http://site2', $network_membership_plans );
		$this->assertArrayHasKey( 2, $network_membership_plans['http://site1'] );
		$this->assertArrayHasKey( 20, $network_membership_plans['http://site2'] );

		$network_membership_plans = $get_network_membership_plans->invoke( null, 'net-3' );
		$this->assertEquals( [], $network_membership_plans );
	}

	/**
	 * Test get_user_network_active_subscriptions
	 */
	public function test_get_user_network_active_subscriptions() {
		$get_user_network_active_subscriptions = self::get_method( 'get_user_network_active_subscriptions' );

		$active_subscriptions = $get_user_network_active_subscriptions->invoke( null, self::$user_with_sub_on_1 );
		$this->assertCount( 1, $active_subscriptions );
		$this->assertArrayHasKey( 'http://site1', $active_subscriptions );
		$this->assertCount( 1, $active_subscriptions['http://site1'] );
		$this->assertArrayHasKey( 500, $active_subscriptions['http://site1'] );

		$active_subscriptions = $get_user_network_active_subscriptions->invoke( null, self::$user_with_sub_on_2 );
		$this->assertCount( 1, $active_subscriptions );
		$this->assertArrayHasKey( 'http://site2', $active_subscriptions );
		$this->assertCount( 1, $active_subscriptions['http://site2'] );
		$this->assertArrayHasKey( 501, $active_subscriptions['http://site2'] );

		$active_subscriptions = $get_user_network_active_subscriptions->invoke( null, self::$user_with_cancelled_sub_on_2 );
		$this->assertEquals( [], $active_subscriptions );

		$active_subscriptions = $get_user_network_active_subscriptions->invoke( null, self::$user_with_non_network_sub_on_1 );
		$this->assertCount( 1, $active_subscriptions );
		$this->assertArrayHasKey( 'http://site1', $active_subscriptions );
		$this->assertCount( 1, $active_subscriptions['http://site1'] );
		$this->assertArrayHasKey( 503, $active_subscriptions['http://site1'] );

		$active_subscriptions = $get_user_network_active_subscriptions->invoke( null, self::$user_without_subs );
		$this->assertEquals( [], $active_subscriptions );
	}

	/**
	 * Data provider for test_subscriptions_includes_plan
	 *
	 * @return array
	 */
	public function subscriptions_includes_plan_data() {
		return [
			[
				'http://site1',
				'user_with_sub_on_1',
				'net-1',
				500, // Expected ID of the subscription.
			],
			[
				'http://site1',
				'user_with_sub_on_1',
				'net-2',
				false,
			],
			[
				'http://site2',
				'user_with_sub_on_2',
				'net-2',
				501,
			],
			[
				'http://site2',
				'user_with_sub_on_2',
				'net-1',
				false,
			],
		];
	}

	/**
	 * Test subscriptions_includes_plan method
	 *
	 * @param string   $site The site url.
	 * @param int      $user User ID.
	 * @param string   $plan_network_id Plan Network ID.
	 * @param int|bool $expected_id Expected Subscription ID or false.
	 * @dataProvider subscriptions_includes_plan_data
	 * @return void
	 */
	public function test_subscriptions_includes_plan( $site, $user, $plan_network_id, $expected_id ) {
		$get_subscriptions_method = self::get_method( 'get_user_network_active_subscriptions' );
		$method = self::get_method( 'subscriptions_includes_plan' );

		$subscriptions = $get_subscriptions_method->invoke( null, self::$$user );

		$result = $method->invoke( null, $site, $subscriptions[ $site ], $plan_network_id );
		if ( $expected_id ) {
			$this->assertArrayHasKey( 'id', $result );
			$this->assertEquals( $expected_id, $result['id'] );
		} else {
			$this->assertFalse( $result );
		}
	}

	/**
	 * Data provider for test_user_has_active_subscription_in_network
	 *
	 * @return array
	 */
	public function user_has_active_subscription_in_network_data() {
		return [
			[
				'user_with_sub_on_1',
				'net-1',
				500,
				'http://site1',
			],
			[
				'user_with_sub_on_1',
				'net-2',
				false,
			],
			[
				'user_with_sub_on_2',
				'net-1',
				false,
			],
			[
				'user_with_sub_on_2',
				'net-2',
				501,
				'http://site2',
			],
			[
				'user_with_cancelled_sub_on_2',
				'net-2',
				false,
			],
			[
				'user_with_non_network_sub_on_1',
				'net-2',
				false,
			],
		];
	}

	/**
	 * Test subscriptions_includes_plan method
	 *
	 * @param int      $user User ID.
	 * @param string   $plan_network_id Plan Network ID.
	 * @param int|bool $expected_id Expected Subscription ID or false.
	 * @param string   $expected_site The expected site url.
	 * @dataProvider user_has_active_subscription_in_network_data
	 * @return void
	 */
	public function test_user_has_active_subscription_in_network( $user, $plan_network_id, $expected_id, $expected_site = '' ) {

		$result = Subscriptions_Integration::user_has_active_subscription_in_network( self::$$user, $plan_network_id );
		if ( $expected_id ) {
			$this->assertArrayHasKey( 'site', $result );
			$this->assertArrayHasKey( 'subscription', $result );
			$this->assertEquals( $expected_id, $result['subscription']['id'] );
			$this->assertEquals( $expected_site, $result['site'] );
		} else {
			$this->assertFalse( $result );
		}
	}
}
