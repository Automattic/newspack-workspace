<?php
/**
 * Tests the Subscriptions_Tiers "current tier" detection.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscriptions_Tiers;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';

/**
 * Test Subscriptions_Tiers::get_current_tier().
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscriptions_Tiers extends WP_UnitTestCase {
	/**
	 * Reset the mock databases before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		wp_set_current_user( 0 );
		unset(
			$_GET['switch-subscription'],
			$_GET['item'],
			$_GET['price'],
			$_REQUEST['switch-subscription'],
			$_REQUEST['item'],
			$_REQUEST['price']
		);
	}

	/**
	 * Reset the current user and request state after each test.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		unset(
			$_GET['switch-subscription'],
			$_GET['item'],
			$_GET['price'],
			$_REQUEST['switch-subscription'],
			$_REQUEST['item'],
			$_REQUEST['price']
		);
		parent::tear_down();
	}

	/**
	 * Build two subscription tier products under a single monthly frequency.
	 *
	 * @return \WC_Product[] [ $basic, $premium ] tier products (ids 101, 102).
	 */
	private function make_tier_products() {
		$basic   = wc_create_mock_product(
			[
				'id'   => 101,
				'type' => 'subscription',
				'name' => 'Basic',
			]
		);
		$premium = wc_create_mock_product(
			[
				'id'   => 102,
				'type' => 'subscription',
				'name' => 'Premium',
			]
		);
		return [ $basic, $premium ];
	}

	/**
	 * An active subscription for a tier product is detected as the current tier.
	 */
	public function test_detects_active_subscription_as_current_tier() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		[ $basic, $premium ] = $this->make_tier_products();
		$tiers               = [ 'month_1' => [ $basic, $premium ] ];

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 102 ],
			]
		);

		[ $frequency, $product, $subscription ] = Subscriptions_Tiers::get_current_tier( $tiers );

		$this->assertSame( 'month_1', $frequency );
		$this->assertSame( $premium, $product );
		$this->assertNotNull( $subscription );
	}

	/**
	 * A pending-cancel subscription must still be detected as the current tier.
	 *
	 * Regression test for NPPM-2952: the "current tier" detection used a
	 * stricter status filter ('active' only) than the switch-eligibility check
	 * ('active' or 'pending-cancel'). For a pending-cancel subscriber the modal
	 * therefore rendered no "Current" tier, disabling the front-end guard and
	 * allowing a switch to the subscription the reader already owned.
	 */
	public function test_detects_pending_cancel_subscription_as_current_tier() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		[ $basic, $premium ] = $this->make_tier_products();
		$tiers               = [ 'month_1' => [ $basic, $premium ] ];

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'pending-cancel',
				'products'    => [ 102 ],
			]
		);

		[ $frequency, $product, $subscription ] = Subscriptions_Tiers::get_current_tier( $tiers );

		$this->assertSame( $premium, $product, 'A pending-cancel subscription should be recognized as the current tier.' );
		$this->assertNotNull( $subscription );
	}

	/**
	 * A fully cancelled subscription is not the current tier.
	 */
	public function test_ignores_inactive_subscription() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		[ $basic, $premium ] = $this->make_tier_products();
		$tiers               = [ 'month_1' => [ $basic, $premium ] ];

		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'cancelled',
				'products'    => [ 102 ],
			]
		);

		[ $frequency, $product, $subscription ] = Subscriptions_Tiers::get_current_tier( $tiers );

		$this->assertNull( $product );
		$this->assertNull( $subscription );
		$this->assertNull( $frequency );
	}

	/**
	 * A logged-out visitor owns no tier.
	 */
	public function test_returns_nulls_for_logged_out_visitor() {
		[ $basic, $premium ] = $this->make_tier_products();
		$tiers               = [ 'month_1' => [ $basic, $premium ] ];

		[ $frequency, $product, $subscription ] = Subscriptions_Tiers::get_current_tier( $tiers );

		$this->assertNull( $product );
		$this->assertNull( $subscription );
		$this->assertNull( $frequency );
	}

	/**
	 * Create a mock subscription holding a single product line item, and register the
	 * switched-to product so wc_get_product() can resolve its name-your-price flag and
	 * billing interval.
	 *
	 * @param int        $user_id      Owner user ID.
	 * @param string     $status       Subscription status.
	 * @param int        $product_id   Product ID held by the subscription.
	 * @param float|null $amount       Recurring line total, for name-your-price checks.
	 * @param array      $product_meta Meta for the registered product (e.g. `_nyp`, `_subscription_period_interval`).
	 * @param int        $variation_id Variation ID held by the line item, if any.
	 *
	 * @return \WC_Subscription
	 */
	private function make_subscription( $user_id, $status, $product_id, $amount = null, $product_meta = [], $variation_id = 0 ) {
		$canonical_id = $variation_id ? $variation_id : $product_id;
		wc_create_mock_product(
			[
				'id'   => $canonical_id,
				'type' => 'subscription',
				'name' => 'Tier ' . $canonical_id,
				'meta' => $product_meta,
			]
		);
		$item = new WC_Order_Item_Product(
			[
				'id'           => 555,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'total'        => $amount ?? 0,
			]
		);
		return wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => $status,
				'products'    => [ $canonical_id ],
				'items'       => [ $item ],
			]
		);
	}

	/**
	 * The pure decision helper: a different product is never a "same" switch.
	 */
	public function test_is_same_subscription_switch_different_product() {
		$this->assertFalse( Subscriptions_Tiers::is_same_subscription_switch( 101, null, 102, null ) );
	}

	/**
	 * The pure decision helper: re-selecting a fixed-price tier is a no-op.
	 */
	public function test_is_same_subscription_switch_same_fixed_product() {
		$this->assertTrue( Subscriptions_Tiers::is_same_subscription_switch( 101, null, 101, null ) );
	}

	/**
	 * The pure decision helper: name-your-price with an unchanged amount is a no-op.
	 */
	public function test_is_same_subscription_switch_nyp_same_amount() {
		$this->assertTrue( Subscriptions_Tiers::is_same_subscription_switch( 101, 10.00, 101, 10.00 ) );
	}

	/**
	 * The pure decision helper: name-your-price with a changed amount is a real switch.
	 */
	public function test_is_same_subscription_switch_nyp_changed_amount() {
		$this->assertFalse( Subscriptions_Tiers::is_same_subscription_switch( 101, 10.00, 101, 15.00 ) );
	}

	/**
	 * The pure decision helper fails open when the current amount is unknown.
	 */
	public function test_is_same_subscription_switch_nyp_unknown_current_amount() {
		$this->assertFalse( Subscriptions_Tiers::is_same_subscription_switch( 101, null, 101, 15.00 ) );
	}

	/**
	 * A normal add-to-cart (no switch params) passes validation through untouched.
	 */
	public function test_prevent_switch_is_noop_without_switch_params() {
		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * Regression backstop for NPPM-2952: switching to the same fixed-price tier
	 * is blocked even when the front-end guard is bypassed.
	 */
	public function test_prevent_switch_blocks_same_fixed_product() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                = $this->make_subscription( $user_id, 'pending-cancel', 102 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * Switching to a different tier is allowed.
	 */
	public function test_prevent_switch_allows_different_product() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                = $this->make_subscription( $user_id, 'active', 102 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 101 ) );
	}

	/**
	 * A name-your-price amount change on the same product is allowed.
	 */
	public function test_prevent_switch_allows_nyp_amount_change() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                = $this->make_subscription( $user_id, 'active', 102, 10.00, [ '_nyp' => 'yes' ] );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();
		$_REQUEST['price']               = '15';

		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * A name-your-price "switch" to the same product and amount is blocked.
	 */
	public function test_prevent_switch_blocks_nyp_same_amount() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                = $this->make_subscription( $user_id, 'active', 102, 10.00, [ '_nyp' => 'yes' ] );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();
		$_REQUEST['price']               = '10';

		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * A subscription belonging to another user is left for WCS to authorize.
	 */
	public function test_prevent_switch_ignores_other_users_subscription() {
		$owner_id        = self::factory()->user->create();
		$other_id        = self::factory()->user->create();
		$subscription    = $this->make_subscription( $owner_id, 'active', 102 );
		wp_set_current_user( $other_id );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * A spurious `price` on a fixed-price tier can't slip a same-tier switch past the
	 * backstop — the amount is only considered for name-your-price tiers.
	 */
	public function test_prevent_switch_blocks_fixed_product_with_spurious_price() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                    = $this->make_subscription( $user_id, 'active', 102, 50.00 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();
		$_REQUEST['price']               = '999'; // Ignored: product 102 is not name-your-price.

		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * The variation branch: a switch onto the variation the reader already holds is
	 * blocked (matched via the line item's variation ID).
	 */
	public function test_prevent_switch_blocks_same_variation() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		// Line item holds variation 202 of product 102.
		$subscription                    = $this->make_subscription( $user_id, 'active', 102, null, [], 202 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102, 1, 202 ) );
	}

	/**
	 * Switching to a different variation of a held product is a real switch and allowed
	 * (pins the intended no-op behaviour for WCS-native variation switches).
	 */
	public function test_prevent_switch_allows_different_variation() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                    = $this->make_subscription( $user_id, 'active', 102, null, [], 202 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		// Variation 203 is not the one held → allowed.
		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102, 1, 203 ) );
	}

	/**
	 * Name-your-price billed every N>1 periods: the amount is compared per period, so an
	 * unchanged per-period amount is blocked even though it differs from the full-cycle
	 * line total.
	 */
	public function test_prevent_switch_blocks_nyp_same_amount_multi_interval() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		// $20 billed every 2 months → $10 per period.
		$subscription                    = $this->make_subscription(
			$user_id,
			'active',
			102,
			20.00,
			[
				'_nyp'                          => 'yes',
				'_subscription_period_interval' => 2,
			] 
		);
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();
		$_REQUEST['price']               = '10'; // Unchanged per-period amount.

		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * The same multi-interval name-your-price subscription allows a genuine per-period
	 * change.
	 */
	public function test_prevent_switch_allows_nyp_changed_amount_multi_interval() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                    = $this->make_subscription(
			$user_id,
			'active',
			102,
			20.00,
			[
				'_nyp'                          => 'yes',
				'_subscription_period_interval' => 2,
			] 
		);
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();
		$_REQUEST['price']               = '12'; // Changed per-period amount.

		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * With multiple tier products in one subscription, the submitted `item` targets the
	 * specific line item being switched, so switching one tier to another the reader
	 * also holds is treated as a real switch rather than over-blocked.
	 */
	public function test_prevent_switch_uses_item_to_target_line_item() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		wc_create_mock_product(
			[
				'id'   => 102,
				'type' => 'subscription',
				'name' => 'Basic',
			] 
		);
		wc_create_mock_product(
			[
				'id'   => 103,
				'type' => 'subscription',
				'name' => 'Premium',
			] 
		);
		$item_a       = new WC_Order_Item_Product(
			[
				'id'         => 555,
				'product_id' => 102,
				'total'      => 10,
			] 
		);
		$item_b       = new WC_Order_Item_Product(
			[
				'id'         => 556,
				'product_id' => 103,
				'total'      => 20,
			] 
		);
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 102, 103 ],
				'items'       => [ $item_a, $item_b ],
			]
		);
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();
		$_REQUEST['item']                = '555'; // Switch the "102" line item...

		// ...to product 103. 103 is also held (via item 556), but targeting the 102 line
		// item makes this a genuine switch → allowed.
		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 103 ) );
	}

	/**
	 * The submitted `item` still blocks a no-op switch onto the very product that line
	 * item already holds.
	 */
	public function test_prevent_switch_item_blocks_same_line_item() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		wc_create_mock_product(
			[
				'id'   => 102,
				'type' => 'subscription',
				'name' => 'Basic',
			] 
		);
		$item         = new WC_Order_Item_Product(
			[
				'id'         => 555,
				'product_id' => 102,
				'total'      => 10,
			] 
		);
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 102 ],
				'items'       => [ $item ],
			]
		);
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();
		$_REQUEST['item']                = '555';

		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}
}
