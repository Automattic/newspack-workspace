<?php
/**
 * Class TestContentGateNetworkAccess
 *
 * @package Newspack_Network
 */

use Newspack_Network\Content_Gate\Access;
use Newspack_Network\Content_Gate\Reader_Access_Sync;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Access granted by data pulled from other network sites: group seats,
 * one-time orders, and subscriptions whose products carry their own Network ID.
 */
class TestContentGateNetworkAccess extends WP_UnitTestCase {

	/**
	 * Reader under test.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Local subscription product tagged 'premium'.
	 *
	 * @var int
	 */
	private $premium_product;

	/**
	 * Local one-time product tagged 'annual-pass'.
	 *
	 * @var int
	 */
	private $pass_product;

	/**
	 * Set up a reader and two tagged local products.
	 */
	public function set_up() {
		parent::set_up();
		$this->user_id         = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->premium_product = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$this->pass_product    = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $this->premium_product, Product_Admin::NETWORK_ID_META_KEY, 'premium' );
		update_post_meta( $this->pass_product, Product_Admin::NETWORK_ID_META_KEY, 'annual-pass' );
	}

	/**
	 * Store a pulled snapshot for the reader.
	 *
	 * @param array $sites Per-site groups and orders.
	 */
	private function set_snapshot( $sites ) {
		update_user_meta(
			$this->user_id,
			Reader_Access_Sync::META_KEY,
			[
				'synced_at'    => time(),
				'attempted_at' => time(),
				'sites'        => $sites,
			]
		);
	}

	/**
	 * A group seat on one site, in the given status and with the given Network IDs.
	 *
	 * @param string $status      Subscription status.
	 * @param array  $network_ids Network IDs of the group subscription's products.
	 */
	private function set_group_seat( $status, $network_ids = [ 'premium' ] ) {
		$this->set_snapshot(
			[
				'https://other.test' => [
					'groups' => [
						[
							'id'          => 900,
							'status'      => $status,
							'network_ids' => $network_ids,
						],
					],
					'orders' => [],
				],
			]
		);
	}

	/**
	 * A paid order on one site, created $days_ago days ago.
	 *
	 * @param int   $days_ago    Order age in days.
	 * @param array $network_ids Network IDs of the order's one-time products.
	 */
	private function set_order( $days_ago, $network_ids = [ 'annual-pass' ] ) {
		$this->set_snapshot(
			[
				'https://other.test' => [
					'groups' => [],
					'orders' => [
						[
							'id'           => 700,
							'date_created' => time() - $days_ago * DAY_IN_SECONDS,
							'network_ids'  => $network_ids,
						],
					],
				],
			]
		);
	}

	/**
	 * One-time rule value for the local pass product.
	 *
	 * @param int    $duration_value Duration value.
	 * @param string $duration_unit  Duration unit.
	 * @return array
	 */
	private function one_time_rule( $duration_value, $duration_unit ) {
		return [
			'product_ids'    => [ $this->pass_product ],
			'duration_value' => $duration_value,
			'duration_unit'  => $duration_unit,
		];
	}

	/**
	 * An active seat on another site's group subscription grants access.
	 */
	public function test_active_group_seat_grants_access() {
		$this->set_group_seat( 'active' );
		$this->assertTrue( Access::check_network_subscriptions( false, $this->user_id, [ $this->premium_product ], false ) );
	}

	/**
	 * A seat on a pending-cancel group subscription still grants access.
	 */
	public function test_pending_cancel_group_seat_grants_access() {
		$this->set_group_seat( 'pending-cancel' );
		$this->assertTrue( Access::check_network_subscriptions( false, $this->user_id, [ $this->premium_product ], false ) );
	}

	/**
	 * A seat on a group subscription the owner has ended grants nothing.
	 */
	public function test_ended_group_seat_denies_access() {
		$this->set_group_seat( 'cancelled' );
		$this->assertFalse( Access::check_network_subscriptions( false, $this->user_id, [ $this->premium_product ], false ) );
	}

	/**
	 * A seat whose products carry a different Network ID grants nothing.
	 */
	public function test_group_seat_with_other_network_id_denies_access() {
		$this->set_group_seat( 'active', [ 'basic' ] );
		$this->assertFalse( Access::check_network_subscriptions( false, $this->user_id, [ $this->premium_product ], false ) );
	}

	/**
	 * A strict check counts only subscriptions the reader owns, as it does locally.
	 */
	public function test_strict_check_ignores_group_seats() {
		$this->set_group_seat( 'active' );
		$this->assertFalse( Access::check_network_subscriptions( false, $this->user_id, [ $this->premium_product ], true ) );
	}

	/**
	 * A pulled subscription names each product's Network ID itself, so it matches
	 * even when this site never received the product's sync event.
	 */
	public function test_embedded_network_id_matches_without_synced_product_map() {
		update_user_meta(
			$this->user_id,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				'https://unsynced.test' => [
					42 => [
						'id'       => 42,
						'status'   => 'active',
						'products' => [
							7 => [
								'id'         => 7,
								'name'       => 'Premium',
								'network_id' => 'premium',
							],
						],
					],
				],
			]
		);
		$this->assertTrue( Access::check_network_subscriptions( false, $this->user_id, [ $this->premium_product ], false ) );
		$this->assertNotFalse( Access::user_has_active_network_subscription_for_network_id( $this->user_id, 'premium' ) );
	}

	/**
	 * A paid order on another site grants access within the rule's duration.
	 */
	public function test_one_time_order_within_duration_grants_access() {
		$this->set_order( 10 );
		$this->assertTrue( Access::check_network_one_time_purchases( false, $this->user_id, $this->one_time_rule( 30, 'days' ) ) );
	}

	/**
	 * Once the duration has passed since the order date, the order grants nothing.
	 */
	public function test_one_time_order_after_duration_denies_access() {
		$this->set_order( 40 );
		$this->assertFalse( Access::check_network_one_time_purchases( false, $this->user_id, $this->one_time_rule( 30, 'days' ) ) );
	}

	/**
	 * Month durations count back from now, as the local rule does.
	 */
	public function test_one_time_order_month_duration() {
		$this->set_order( 300 );
		$this->assertTrue( Access::check_network_one_time_purchases( false, $this->user_id, $this->one_time_rule( 12, 'months' ) ) );
		$this->assertFalse( Access::check_network_one_time_purchases( false, $this->user_id, $this->one_time_rule( 6, 'months' ) ) );
	}

	/**
	 * A lifetime rule accepts an order of any age.
	 */
	public function test_one_time_order_forever_grants_access() {
		$this->set_order( 3000 );
		$this->assertTrue( Access::check_network_one_time_purchases( false, $this->user_id, $this->one_time_rule( 0, 'forever' ) ) );
	}

	/**
	 * A misconfigured duration fails closed, as the local rule does.
	 */
	public function test_one_time_order_misconfigured_duration_denies_access() {
		$this->set_order( 1 );
		$this->assertFalse( Access::check_network_one_time_purchases( false, $this->user_id, $this->one_time_rule( 0, 'days' ) ) );
		$this->assertFalse( Access::check_network_one_time_purchases( false, $this->user_id, $this->one_time_rule( 5, 'years' ) ) );
	}

	/**
	 * An order for a product with a different Network ID grants nothing.
	 */
	public function test_one_time_order_with_other_network_id_denies_access() {
		$this->set_order( 1, [ 'other-pass' ] );
		$this->assertFalse( Access::check_network_one_time_purchases( false, $this->user_id, $this->one_time_rule( 30, 'days' ) ) );
	}

	/**
	 * A local purchase short-circuits the network check.
	 */
	public function test_one_time_local_purchase_passes_through() {
		$this->assertTrue( Access::check_network_one_time_purchases( true, $this->user_id, $this->one_time_rule( 30, 'days' ) ) );
	}

	/**
	 * A rule whose products carry no Network ID never matches another site's order.
	 */
	public function test_one_time_rule_without_network_id_denies_access() {
		$this->set_order( 1 );
		$untagged = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$rule     = $this->one_time_rule( 30, 'days' );

		$rule['product_ids'] = [ $untagged ];
		$this->assertFalse( Access::check_network_one_time_purchases( false, $this->user_id, $rule ) );
	}

	/**
	 * The one-time bridge is hooked where newspack-plugin evaluates the rule.
	 */
	public function test_one_time_bridge_is_hooked() {
		$this->assertNotFalse( has_filter( 'newspack_access_rules_has_one_time_purchase', [ Access::class, 'check_network_one_time_purchases' ] ) );
	}
}
