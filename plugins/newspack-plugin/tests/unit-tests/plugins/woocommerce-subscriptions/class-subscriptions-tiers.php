<?php
/**
 * Tests the Subscriptions_Tiers "current tier" detection.
 *
 * @package Newspack\Tests
 */

use Newspack\Group_Subscription_Settings;
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
		wc_mocks_reset_order_items();
		wc_mocks_reset_notices();
		wp_set_current_user( 0 );
		unset(
			$_REQUEST['switch-subscription'],
			$_REQUEST['item'],
			$_REQUEST['price'],
			$_REQUEST['quantity']
		);
	}

	/**
	 * Reset the current user and request state after each test.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		unset(
			$_REQUEST['switch-subscription'],
			$_REQUEST['item'],
			$_REQUEST['price'],
			$_REQUEST['quantity']
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

		// The frequency is what drives the "Current" badge and front-end guard, so
		// its absence is exactly the silent failure this regression test pins.
		$this->assertSame( 'month_1', $frequency );
		$this->assertSame( $premium, $product, 'A pending-cancel subscription should be recognized as the current tier.' );
		$this->assertNotNull( $subscription );
	}

	/**
	 * A subscription the user can see but does not own (e.g. injected into
	 * `wcs_get_users_subscriptions` for a group-subscription member) is not their
	 * current tier — matching the ownership test the switch backstop applies.
	 */
	public function test_ignores_subscription_owned_by_another_user() {
		$owner_id  = self::factory()->user->create();
		$member_id = self::factory()->user->create();
		wp_set_current_user( $member_id );

		[ $basic, $premium ] = $this->make_tier_products();
		$tiers               = [ 'month_1' => [ $basic, $premium ] ];

		$owner_subscription = wcs_create_subscription(
			[
				'customer_id' => $owner_id,
				'status'      => 'active',
				'products'    => [ 102 ],
			]
		);

		// Simulate Group_Subscription_MyAccount::inject_member_group_subscriptions().
		$inject = function ( $subscriptions ) use ( $owner_subscription ) {
			$subscriptions[ $owner_subscription->get_id() ] = $owner_subscription;
			return $subscriptions;
		};
		add_filter( 'wcs_get_users_subscriptions', $inject );

		[ $frequency, $product, $subscription ] = Subscriptions_Tiers::get_current_tier( $tiers );

		remove_filter( 'wcs_get_users_subscriptions', $inject );

		$this->assertNull( $frequency );
		$this->assertNull( $product );
		$this->assertNull( $subscription );
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
	 * @param int        $quantity     Line item quantity.
	 *
	 * @return \WC_Subscription
	 */
	private function make_subscription( $user_id, $status, $product_id, $amount = null, $product_meta = [], $variation_id = 0, $quantity = 1 ) {
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
				// No discount on the fixture: pre-discount subtotal equals total.
				'subtotal'     => $amount ?? 0,
				'total'        => $amount ?? 0,
				'quantity'     => $quantity,
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

	/**
	 * An `item` naming a line item from an unrelated order can't bypass the
	 * backstop: order item IDs are globally unique and the default
	 * `WC_Abstract_Order::get_item()` resolves them without an order scope, so an
	 * unscoped lookup would return the foreign item, mismatch the product and let
	 * the same-tier switch through. The lookup must be scoped to the switched
	 * subscription's own items.
	 */
	public function test_prevent_switch_ignores_foreign_item_id() {
		$user_id  = self::factory()->user->create();
		$other_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		// The reader's own subscription holds tier product 102 via line item 555.
		$subscription = $this->make_subscription( $user_id, 'active', 102 );

		// A different user's order holds product 45 via line item 999.
		wc_create_mock_product(
			[
				'id'   => 45,
				'type' => 'subscription',
				'name' => 'Unrelated',
			]
		);
		$foreign_item = new WC_Order_Item_Product(
			[
				'id'         => 999,
				'product_id' => 45,
				'total'      => 5,
			]
		);
		wcs_create_subscription(
			[
				'customer_id' => $other_id,
				'status'      => 'active',
				'products'    => [ 45 ],
				'items'       => [ $foreign_item ],
			]
		);

		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();
		$_REQUEST['item']                = '999'; // Crafted: item 999 belongs to another order.

		// The foreign item must not resolve; the fallback scan finds the reader's own
		// line item for product 102 and the same-tier switch stays blocked.
		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * A deliberate quantity change on the same plan is a legitimate switch in
	 * WCS's native flow (product, variation and quantity must all match to be
	 * "identical"), so the backstop steps aside for it. WCS's switch form
	 * submits the quantity, which is what marks the change as deliberate.
	 */
	public function test_prevent_switch_allows_quantity_change() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                    = $this->make_subscription( $user_id, 'active', 102, null, [], 0, 1 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();
		$_REQUEST['quantity']            = '3';

		// Seat change from 1 to 3 on the same product: a real switch, allowed.
		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102, 3 ) );
	}

	/**
	 * Without a submitted quantity there is no deliberate seat change: the modal
	 * submits the line item's own quantity on every switch, so a request that
	 * omits it never came from the modal, and a multi-seat line item must not make
	 * the guard bail on the quantity check and skip the same-product comparison.
	 */
	public function test_prevent_switch_blocks_multi_seat_line_item_without_submitted_quantity() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		// The reader's line item holds 3 seats; the modal-style request adds 1.
		$subscription                    = $this->make_subscription( $user_id, 'active', 102, null, [], 0, 3 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * Name-your-price with quantity > 1: the line total is normalized per unit
	 * before comparing, so an unchanged per-unit amount is blocked and a changed
	 * one is allowed.
	 */
	public function test_prevent_switch_nyp_compares_per_unit_amount() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		// 2 seats at $10/unit → $20 line total, monthly.
		$subscription                    = $this->make_subscription( $user_id, 'active', 102, 20.00, [ '_nyp' => 'yes' ], 0, 2 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		$_REQUEST['price'] = '10'; // Unchanged per-unit amount.
		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102, 2 ) );

		$_REQUEST['price'] = '12'; // Changed per-unit amount.
		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102, 2 ) );
	}

	/**
	 * The name-your-price comparison uses the pre-discount subtotal: a coupon on
	 * the existing subscription discounts the line total, which must not make an
	 * unchanged amount re-submission look like a genuine change.
	 */
	public function test_prevent_switch_nyp_compares_pre_discount_amount() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		wc_create_mock_product(
			[
				'id'   => 102,
				'type' => 'subscription',
				'name' => 'Tier 102',
				'meta' => [ '_nyp' => 'yes' ],
			]
		);
		// Reader chose $10/month; a 20% coupon discounts the line total to $8.
		$item         = new WC_Order_Item_Product(
			[
				'id'         => 555,
				'product_id' => 102,
				'subtotal'   => 10.00,
				'total'      => 8.00,
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

		$_REQUEST['price'] = '10'; // The amount the reader actually chose: unchanged → blocked.
		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );

		$_REQUEST['price'] = '12'; // A genuine change is still allowed.
		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );
	}

	/**
	 * Blocking queues the reader-facing error notice.
	 */
	public function test_prevent_switch_records_error_notice() {
		global $wc_mock_notices;
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                    = $this->make_subscription( $user_id, 'active', 102 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 102 ) );

		$this->assertCount( 1, $wc_mock_notices );
		$this->assertSame( 'error', $wc_mock_notices[0]['type'] );
		$this->assertStringContainsString( 'already subscribed', $wc_mock_notices[0]['notice'] );
	}

	/**
	 * The pure decision helper compares amounts in minor units: a one-cent change
	 * is a real change (binary float noise made abs( 10.01 - 10.00 ) fall under a
	 * 0.01 epsilon), while a re-submission of the same value in another textual
	 * form is not.
	 */
	public function test_is_same_subscription_switch_one_cent_change() {
		$this->assertFalse( Subscriptions_Tiers::is_same_subscription_switch( 101, 10.00, 101, 10.01 ) );
		$this->assertTrue( Subscriptions_Tiers::is_same_subscription_switch( 101, 10.00, 101, (float) '10.00' ) );
	}

	/**
	 * The cart-item-data twin of the guard throws to abort the add — the
	 * mechanism `WC_Cart::add_to_cart()` documents for plugins, and the only one
	 * that runs on direct `add_to_cart()` calls such as the modal checkout's.
	 */
	public function test_cart_item_data_guard_throws_when_blocked() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                    = $this->make_subscription( $user_id, 'active', 102 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'already subscribed' );
		Subscriptions_Tiers::prevent_switch_to_same_subscription_cart_item_data( [], 102 );
	}

	/**
	 * The cart-item-data guard passes the data through untouched for a genuine
	 * switch.
	 */
	public function test_cart_item_data_guard_passes_data_through() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$subscription                    = $this->make_subscription( $user_id, 'active', 102 );
		$_REQUEST['switch-subscription'] = (string) $subscription->get_id();

		$cart_item_data = [ 'referer' => 'https://example.test/' ];
		$this->assertSame( $cart_item_data, Subscriptions_Tiers::prevent_switch_to_same_subscription_cart_item_data( $cart_item_data, 101 ) );
	}

	/**
	 * The guard is registered on both add-to-cart filters. The validation filter
	 * is applied by WooCommerce's request handlers but not by
	 * `WC_Cart::add_to_cart()` itself, which the modal checkout calls directly —
	 * the cart-item-data filter is what actually runs on that path, before WCS
	 * consumes the switch params at priority 10.
	 */
	public function test_guard_hook_registrations() {
		$this->assertSame(
			10,
			has_filter( 'woocommerce_add_to_cart_validation', [ Subscriptions_Tiers::class, 'prevent_switch_to_same_subscription' ] )
		);
		$this->assertSame(
			9,
			has_filter( 'woocommerce_add_cart_item_data', [ Subscriptions_Tiers::class, 'prevent_switch_to_same_subscription_cart_item_data' ] )
		);
	}

	/**
	 * Build a monthly subscription tier product registered in the mock products
	 * database.
	 *
	 * @param int   $id   Product ID.
	 * @param array $meta Extra product meta, merged over the billing period meta.
	 *
	 * @return \WC_Product
	 */
	private function make_tier_product( $id, $meta = [] ) {
		return wc_create_mock_product(
			[
				'id'    => $id,
				'type'  => 'subscription',
				'name'  => 'Tier ' . $id,
				'price' => 10,
				'meta'  => array_merge(
					[
						'_subscription_period'          => 'month',
						'_subscription_period_interval' => 1,
					],
					$meta
				),
			]
		);
	}

	/**
	 * Product meta that turns a product into a per-seat group subscription.
	 *
	 * @param int $min Minimum seats.
	 * @param int $max Maximum seats (0 = unlimited).
	 *
	 * @return array Meta keyed by the group subscription meta keys.
	 */
	private function per_seat_meta( $min, $max ) {
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;
		return [
			$prefix . 'enabled'      => 'yes',
			$prefix . 'pricing_mode' => Group_Subscription_Settings::PRICING_MODE_PER_SEAT,
			$prefix . 'min_seats'    => $min,
			$prefix . 'max_seats'    => $max,
		];
	}

	/**
	 * Build the switch data the modal is rendered with: an owned subscription
	 * holding the given products, and the line item being switched.
	 *
	 * @param int   $user_id     Owner user ID.
	 * @param array $product_ids Product IDs the subscription holds.
	 * @param int   $switched_id Product ID of the line item being switched.
	 * @param int   $quantity    Seats on the switched line item.
	 *
	 * @return array Switch data: `item_id`, `item` and `subscription`.
	 */
	private function make_switch_data( $user_id, $product_ids, $switched_id, $quantity ) {
		$items    = [];
		$switched = null;
		foreach ( array_values( $product_ids ) as $index => $product_id ) {
			$item = new WC_Order_Item_Product(
				[
					'id'           => 554 + $index,
					'product_id'   => $product_id,
					'variation_id' => 0,
					'subtotal'     => 10,
					'total'        => 10,
					'quantity'     => $product_id === $switched_id ? $quantity : 1,
				]
			);
			$items[] = $item;
			if ( $product_id === $switched_id ) {
				$switched = $item;
			}
		}
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => $product_ids,
				'items'       => $items,
			]
		);
		return [
			'item_id'      => $switched->get_id(),
			'item'         => $switched,
			'subscription' => $subscription,
		];
	}

	/**
	 * Render the tiers form and return its markup.
	 *
	 * @param \WC_Product $product     Product the form is rendered for.
	 * @param array|null  $switch_data Switch data, or null for a plain purchase.
	 *
	 * @return string The rendered form markup.
	 */
	private function render_tier_form( $product, $switch_data = null ) {
		ob_start();
		Subscriptions_Tiers::render_form( $product, null, null, $switch_data );
		return ob_get_clean();
	}

	/**
	 * Every switch carries the seats the reader already pays for. The modal
	 * checkout adds the chosen product at a quantity of one, so without this a
	 * tier change on a four-seat line item would rewrite it to a single seat.
	 */
	public function test_switch_form_carries_current_quantity_as_hidden_input() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$product     = $this->make_tier_product( 301 );
		$switch_data = $this->make_switch_data( $user_id, [ 301 ], 301, 4 );

		$html = $this->render_tier_form( $product, $switch_data );

		$this->assertStringContainsString( '<input type="hidden" name="quantity" value="4">', $html );
	}

	/**
	 * A purchase that isn't a switch and isn't per seat has no quantity to carry,
	 * so no quantity is submitted at all and the checkout keeps its default of one.
	 */
	public function test_form_without_switch_has_no_quantity_input() {
		$product = $this->make_tier_product( 302 );

		$html = $this->render_tier_form( $product );

		$this->assertStringNotContainsString( 'name="quantity"', $html );
	}

	/**
	 * A per-seat product gets a seats field, defaulting to the product's minimum
	 * and visible from the start because the tier that sells seats is the one
	 * selected.
	 */
	public function test_per_seat_form_renders_seats_input() {
		$product = $this->make_tier_product( 303, $this->per_seat_meta( 2, 0 ) );

		$html = $this->render_tier_form( $product );

		// Defaults to the minimum. Asserted as one attribute cluster so the values
		// are pinned to the seats input rather than to anything else on the form.
		$this->assertStringContainsString( 'name="quantity" id="group_seats" step="1" min="2" value="2"', $html );
		$this->assertStringContainsString( '<label for="group_seats">', $html );
		$this->assertStringContainsString( '<p class="newspack__subscription-tiers__seats">', $html );
		// An unlimited product has no ceiling to enforce in the browser: the cluster
		// above runs straight from min to value, with no max attribute between them.
		$this->assertStringContainsString( 'data-seats-max=""', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	/**
	 * On a switch the seats field starts at the seats the reader already has, and
	 * carries that same value as the original the front-end guard compares
	 * against — so leaving it alone is still a no-op. A capped product also
	 * publishes its ceiling.
	 */
	public function test_per_seat_switch_form_prefills_line_quantity() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$product     = $this->make_tier_product( 304, $this->per_seat_meta( 2, 10 ) );
		$switch_data = $this->make_switch_data( $user_id, [ 304 ], 304, 5 );

		$html = $this->render_tier_form( $product, $switch_data );

		$this->assertStringContainsString( 'id="group_seats" step="1" min="2" max="10" value="5"', $html );
		$this->assertStringContainsString( 'data-original-value="5"', $html );
		// The seats field submits the quantity, so no hidden input duplicates it.
		$this->assertStringNotContainsString( 'type="hidden" name="quantity"', $html );
	}

	/**
	 * Each tier publishes its own seat bounds on its radio, so the single seats
	 * field can follow whichever tier is checked. A flat tier publishes none,
	 * which is what tells the field to hide and stop submitting.
	 */
	public function test_tier_cards_carry_their_own_seat_bounds() {
		$this->make_tier_product( 307, $this->per_seat_meta( 2, 10 ) );
		$this->make_tier_product( 308, $this->per_seat_meta( 5, 0 ) );
		$this->make_tier_product( 309 );
		$grouped = wc_create_mock_product(
			[
				'id'       => 310,
				'type'     => 'grouped',
				'name'     => 'Tiers',
				'children' => [ 307, 308, 309 ],
			]
		);

		$html = $this->render_tier_form( $grouped );

		$this->assertStringContainsString( 'value="307" data-per-seat="1" data-seats-min="2" data-seats-max="10" checked', $html );
		// No ceiling: the max attribute is present but empty, so the JS reads it as unlimited.
		$this->assertStringContainsString( 'value="308" data-per-seat="1" data-seats-min="5" data-seats-max=""', $html );
		// The flat tier publishes no bounds at all.
		$this->assertStringContainsString( 'value="309" >', $html );
	}

	/**
	 * With a flat tier selected the seats field is rendered but hidden and
	 * disabled: a disabled input submits nothing, so the flat tier is bought once
	 * rather than once per seat. Choosing the per-seat tier brings the field back
	 * without a round trip.
	 */
	public function test_seats_field_is_hidden_when_the_selected_tier_is_flat() {
		$this->make_tier_product( 311 ); // Flat, and first — so it starts out selected.
		$this->make_tier_product( 312, $this->per_seat_meta( 3, 0 ) );
		$grouped = wc_create_mock_product(
			[
				'id'       => 313,
				'type'     => 'grouped',
				'name'     => 'Tiers',
				'children' => [ 311, 312 ],
			]
		);

		$html = $this->render_tier_form( $grouped );

		$this->assertStringContainsString( '<p class="newspack__subscription-tiers__seats" hidden>', $html );
		$this->assertStringContainsString( 'data-original-value="" disabled>', $html );
	}

	/**
	 * The seat count comes from the line item being switched, not from whichever
	 * tier wears the "Current" badge: a reader holding two tiers in one
	 * subscription is badged on the first one found. Visibility still follows the
	 * selected tier, so a flat badge leaves the field hidden until the reader
	 * picks the per-seat tier.
	 */
	public function test_seats_field_follows_the_switched_line_item() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$this->make_tier_product( 305 ); // Flat: matched as the "current" tier first.
		$this->make_tier_product( 306, $this->per_seat_meta( 2, 0 ) );
		$grouped = wc_create_mock_product(
			[
				'id'       => 300,
				'type'     => 'grouped',
				'name'     => 'Tiers',
				'children' => [ 305, 306 ],
			]
		);
		$switch_data = $this->make_switch_data( $user_id, [ 305, 306 ], 306, 3 );

		$html = $this->render_tier_form( $grouped, $switch_data );

		$this->assertStringContainsString( 'id="group_seats" step="1" min="2" value="3"', $html );
		$this->assertStringContainsString( 'data-original-value="3" disabled>', $html );
		$this->assertStringContainsString( '<p class="newspack__subscription-tiers__seats" hidden>', $html );
	}

	/**
	 * A seat count the newly chosen tier cannot sell is pulled into its bounds
	 * before the field is ever shown, rather than being submitted and rejected at
	 * the far end.
	 */
	public function test_seats_value_is_clamped_to_the_selected_tier_bounds() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$product     = $this->make_tier_product( 314, $this->per_seat_meta( 2, 4 ) );
		$switch_data = $this->make_switch_data( $user_id, [ 314 ], 314, 9 );

		$html = $this->render_tier_form( $product, $switch_data );

		$this->assertStringContainsString( 'min="2" max="4" value="4"', $html );
		// The reader's real seat count is still what "unchanged" means.
		$this->assertStringContainsString( 'data-original-value="9"', $html );
	}

	/**
	 * Screens that print their own switch link never fire WooCommerce
	 * Subscriptions' switch-link filter, so they register the modal themselves.
	 * Once registered, the footer prints a modal bound to that subscription — the
	 * hook the group page's "Change seats" link opens.
	 */
	public function test_register_switch_modal_prints_a_modal_for_the_subscription() {
		global $wcs_grouped_parents;
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$this->make_tier_product( 320, $this->per_seat_meta( 2, 10 ) );
		wc_create_mock_product(
			[
				'id'       => 321,
				'type'     => 'grouped',
				'name'     => 'Tiers',
				'children' => [ 320 ],
			]
		);
		// The footer resolves the line item's product to its grouped parent before
		// rendering; without a parent there is no tier list to render.
		$wcs_grouped_parents = [ 320 => [ 321 ] ];
		$switch_data         = $this->make_switch_data( $user_id, [ 320 ], 320, 4 );

		Subscriptions_Tiers::register_switch_modal( $switch_data['item_id'], $switch_data['item'], $switch_data['subscription'] );

		ob_start();
		Subscriptions_Tiers::print_switch_subscription_link_modal();
		$html = ob_get_clean();

		$wcs_grouped_parents = [];

		$this->assertStringContainsString( 'data-subscription-id="' . $switch_data['subscription']->get_id() . '"', $html );
		// The modal carries the switch params the checkout needs, at the seats already paid for.
		$this->assertStringContainsString( '<input type="hidden" name="item" value="' . $switch_data['item_id'] . '">', $html );
		$this->assertStringContainsString( 'id="group_seats" step="1" min="2" max="10" value="4"', $html );
	}
}
