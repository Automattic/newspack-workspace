<?php
/**
 * Tests the WooCommerce Subscriptions integration class.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Subscriptions;
use Newspack\Reader_Activation;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';
require_once __DIR__ . '/class-newspack-test-modal-checkout.php';

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
		global $subscriptions_database, $products_database, $wcs_mock_total_paid_including_signup_fee, $wcs_mock_last_calculate_total_paid_args, $wcs_mock_order_items, $wcs_mock_items_sign_up_fee, $wcs_mock_prices_include_tax, $wcs_mock_last_items_sign_up_fee_tax;
		$subscriptions_database                   = [];
		$products_database                        = [];
		$wcs_mock_total_paid_including_signup_fee = 0;
		$wcs_mock_last_calculate_total_paid_args  = null;
		$wcs_mock_order_items                     = [];
		$wcs_mock_items_sign_up_fee               = 0;
		$wcs_mock_prices_include_tax              = false;
		$wcs_mock_last_items_sign_up_fee_tax      = null;
		wp_set_current_user( 0 );
		unset( $_POST['billing_email'], $_POST['post_data'], $_REQUEST['modal_checkout'], $_REQUEST['post_data'] );
	}

	/**
	 * Reset any filters or mock state added by individual tests so they do
	 * not leak across tests.
	 */
	public function tear_down() {
		global $wcs_mock_total_paid_including_signup_fee, $wcs_mock_last_calculate_total_paid_args, $wcs_mock_order_items, $wcs_mock_items_sign_up_fee, $wcs_mock_prices_include_tax, $wcs_mock_last_items_sign_up_fee_tax;
		$wcs_mock_total_paid_including_signup_fee = 0;
		$wcs_mock_last_calculate_total_paid_args  = null;
		$wcs_mock_order_items                     = [];
		$wcs_mock_items_sign_up_fee               = 0;
		$wcs_mock_prices_include_tax              = false;
		$wcs_mock_last_items_sign_up_fee_tax      = null;
		remove_all_filters( 'newspack_wc_subs_switch_include_signup_fee' );
		wp_set_current_user( 0 );
		unset( $_POST['billing_email'], $_POST['post_data'], $_REQUEST['modal_checkout'], $_REQUEST['post_data'] );
		parent::tear_down();
	}

	/**
	 * Set serialized checkout data in the request.
	 *
	 * @param string $post_data Serialized checkout data.
	 */
	private function set_serialized_post_data( $post_data ) {
		$_POST['post_data']    = $post_data;
		$_REQUEST['post_data'] = $post_data;
	}

	/**
	 * Ensure a modal checkout implementation is available to the integration under test.
	 */
	private function ensure_modal_checkout_available() {
		if ( ! class_exists( 'Newspack_Blocks\Modal_Checkout' ) ) {
			class_alias( 'Newspack_Test_Modal_Checkout', 'Newspack_Blocks\Modal_Checkout' );
		}
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
	 * Trial limiting resolves serialized checkout emails outside modal checkout.
	 */
	public function test_limit_free_trials_resolves_serialized_email_outside_modal_checkout() {
		$this->ensure_modal_checkout_available();

		$user_id = $this->factory->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);
		$product = wc_create_mock_product(
			[
				'id'   => 900,
				'type' => 'subscription',
			]
		);
		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'cancelled',
				'products'    => [ 900 ],
			]
		);
		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=repeat%40example.com' );

		$this->assertSame( 0, WooCommerce_Subscriptions::limit_free_trials_to_one_per_user( 14, $product ) );
	}

	/**
	 * Trial limiting still resolves serialized checkout emails in modal checkout.
	 */
	public function test_limit_free_trials_resolves_serialized_email_in_modal_checkout() {
		$this->ensure_modal_checkout_available();

		$user_id = $this->factory->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);
		$product = wc_create_mock_product(
			[
				'id'   => 901,
				'type' => 'subscription',
			]
		);
		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'cancelled',
				'products'    => [ 901 ],
			]
		);
		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=repeat%40example.com&modal_checkout=1' );

		$this->assertSame( 0, WooCommerce_Subscriptions::limit_free_trials_to_one_per_user( 14, $product ) );
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

	/**
	 * When WCS finds no amount paid, recover the baseline from the
	 * subscription's recurring line-item total.
	 */
	public function test_recover_total_paid_when_wcs_returns_zero() {
		$subscription  = new WC_Subscription(
			[
				'id'     => 1,
				'status' => 'active',
				'meta'   => [ '_piano_subscription_id' => 'piano-1' ],
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 50.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 50.0, $result, 'A zero WCS value should fall back to the recurring line-item total.' );
	}

	/**
	 * A legitimate non-zero WCS value must be returned unchanged.
	 */
	public function test_recover_total_paid_leaves_positive_value_untouched() {
		$subscription  = new WC_Subscription(
			[
				'id'     => 2,
				'status' => 'active',
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 50.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 12.34, $subscription, $existing_item );

		$this->assertSame( 12.34, $result, 'A positive WCS value must not be overridden.' );
	}

	/**
	 * A genuinely free subscription (recurring total is zero) must stay zero
	 * so no phantom credit is created.
	 */
	public function test_recover_total_paid_stays_zero_for_free_subscription() {
		$subscription  = new WC_Subscription(
			[
				'id'     => 3,
				'status' => 'active',
				'meta'   => [ '_piano_subscription_id' => 'piano-3' ],
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 0.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 0.0, $result, 'A free subscription must not gain a phantom proration credit.' );
	}

	/**
	 * A non-object / unexpected existing item must be passed through untouched
	 * so the filter never fatals or fabricates a value.
	 */
	public function test_recover_total_paid_passes_through_when_item_not_an_order_item() {
		$subscription = new WC_Subscription(
			[
				'id'     => 4,
				'status' => 'active',
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, null );

		$this->assertSame( 0.0, $result, 'A non-order-item argument must be returned unchanged.' );
	}

	/**
	 * A subscription still in its free trial must not gain a recovered
	 * baseline, otherwise an unpaid trial could be switched into
	 * manufactured proration credit.
	 */
	public function test_recover_total_paid_skips_active_free_trial() {
		$subscription  = new WC_Subscription(
			[
				'id'     => 5,
				'status' => 'active',
				'times'  => [
					'trial_end' => time() + DAY_IN_SECONDS,
				],
				'meta'   => [ '_piano_subscription_id' => 'piano-5' ],
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 50.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 0.0, $result, 'A subscription in an active free trial must not receive a recovered baseline.' );
	}

	/**
	 * A non-migrated subscription must not gain a recovered baseline. WCS's
	 * default switching behavior is intentional for comped, discounted, or
	 * otherwise zero-paid subscriptions that originate in WooCommerce.
	 */
	public function test_recover_total_paid_skips_non_migrated_subscription() {
		$subscription  = new WC_Subscription(
			[
				'id'     => 6,
				'status' => 'active',
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 50.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 0.0, $result, 'A non-migrated subscription must be left to WCS default behavior.' );
	}

	/**
	 * With sign-up-fee counting enabled, a non-migrated subscription whose
	 * amount paid (including the sign-up fee) is higher than WCS's value
	 * recovers to the sign-up-fee-inclusive amount.
	 */
	public function test_recover_total_paid_counts_signup_fee_when_enabled() {
		global $wcs_mock_total_paid_including_signup_fee;
		$wcs_mock_total_paid_including_signup_fee = 30.0;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$subscription  = new WC_Subscription(
			[
				'id'     => 10,
				'status' => 'active',
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 0.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 30.0, $result, 'The paid sign-up fee should become the recovered baseline when counting is enabled.' );
	}

	/**
	 * With sign-up-fee counting disabled (the default), a non-migrated
	 * subscription is left to WCS's default switching behavior even when a
	 * sign-up fee was paid.
	 */
	public function test_recover_total_paid_skips_signup_fee_when_disabled() {
		global $wcs_mock_total_paid_including_signup_fee;
		$wcs_mock_total_paid_including_signup_fee = 30.0;

		$subscription  = new WC_Subscription(
			[
				'id'     => 11,
				'status' => 'active',
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 0.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 0.0, $result, 'A non-migrated subscription must not recover the sign-up fee while counting is disabled.' );
	}

	/**
	 * With sign-up-fee counting enabled but nothing actually paid (a comped
	 * purchase, no sign-up fee), the subscription is left untouched.
	 */
	public function test_recover_total_paid_skips_signup_fee_when_nothing_paid() {
		global $wcs_mock_total_paid_including_signup_fee;
		$wcs_mock_total_paid_including_signup_fee = 0.0;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$subscription  = new WC_Subscription(
			[
				'id'     => 12,
				'status' => 'active',
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 0.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 0.0, $result, 'With no sign-up fee actually paid there is nothing to recover.' );
	}

	/**
	 * A migrated subscription is recovered through the migrated branch even
	 * when sign-up-fee counting is enabled; the sign-up-fee branch is not
	 * reached.
	 */
	public function test_recover_total_paid_migrated_takes_precedence_over_signup_fee() {
		global $wcs_mock_total_paid_including_signup_fee;
		$wcs_mock_total_paid_including_signup_fee = 999.0;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$subscription  = new WC_Subscription(
			[
				'id'     => 13,
				'status' => 'active',
				'meta'   => [ '_piano_subscription_id' => 'piano-13' ],
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 50.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 50.0, $result, 'A migrated subscription must recover via the recurring total, not the sign-up-fee branch.' );
	}

	/**
	 * Stripe migration meta triggers the same recovery as Piano migration meta.
	 *
	 * The recovery path keys off the meta-driven helper, so both keys must
	 * behave identically. Without this test, dropping `_stripe_subscription_id`
	 * from the helper would not fail any assertion.
	 */
	public function test_recover_total_paid_recognizes_stripe_migration_meta() {
		$subscription  = new WC_Subscription(
			[
				'id'     => 20,
				'status' => 'active',
				'meta'   => [ '_stripe_subscription_id' => 'stripe-20' ],
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 50.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 50.0, $result, 'A Stripe-migrated subscription should recover the same way a Piano-migrated one does.' );
	}

	/**
	 * The `instanceof WC_Order_Item_Product` guard rejects any object that is
	 * not an order item -- not just `null`. Without this test, a regression
	 * that removed the instanceof check would only fail the null case.
	 */
	public function test_recover_total_paid_passes_through_for_non_order_item_object() {
		$subscription = new WC_Subscription(
			[
				'id'     => 21,
				'status' => 'active',
				'meta'   => [ '_piano_subscription_id' => 'piano-21' ],
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, new stdClass() );

		$this->assertSame( 0.0, $result, 'A wrong-typed object must be returned unchanged, just like null.' );
	}

	/**
	 * A paid-trial subscription with the opt-in enabled and an active free
	 * trial recovers to the sign-up fee the reader actually paid.
	 *
	 * This is the publisher use case the opt-in is designed for: stepped
	 * pricing as a sign-up fee plus a free trial. WCS sees `$0` paid (the
	 * sign-up fee is excluded from its accounting), but the reader did pay
	 * the fee, and a switch during the trial should be prorated against it.
	 */
	public function test_recover_total_paid_counts_signup_fee_during_active_trial() {
		global $wcs_mock_total_paid_including_signup_fee;
		$wcs_mock_total_paid_including_signup_fee = 25.0;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$subscription  = new WC_Subscription(
			[
				'id'     => 22,
				'status' => 'active',
				'times'  => [
					'trial_end' => time() + DAY_IN_SECONDS,
				],
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 0.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 25.0, $result, 'A paid-trial sub mid-trial with opt-in enabled should recover the paid sign-up fee.' );
	}

	/**
	 * The `newspack_wc_subs_switch_include_signup_fee` filter must receive
	 * the subscription and line item alongside the enabled flag so callbacks
	 * can scope the decision per-subscription or per-product. A regression
	 * dropping those args would silently downgrade the filter to a global
	 * on/off toggle.
	 */
	public function test_signup_fee_filter_receives_subscription_and_item() {
		$captured     = [];
		$subscription = new WC_Subscription(
			[
				'id'     => 40,
				'status' => 'active',
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 0.0,
			]
		);

		add_filter(
			'newspack_wc_subs_switch_include_signup_fee',
			function ( $enabled, $sub, $item ) use ( &$captured ) {
				$captured = [
					'enabled'      => $enabled,
					'subscription' => $sub,
					'item'         => $item,
				];
				return $enabled;
			},
			10,
			3
		);

		WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( $subscription, $captured['subscription'], 'Filter must receive the subscription so callbacks can scope per-subscription.' );
		$this->assertSame( $existing_item, $captured['item'], 'Filter must receive the line item so callbacks can scope per-product.' );
		$this->assertFalse( $captured['enabled'], 'Default enabled value must be false when neither constant nor opt-in filter sets it.' );
	}

	/**
	 * When the sign-up-fee branch fires, the WCS call must include sign-up
	 * fees -- not the default `exclude_sign_up_fees` mode. A regression
	 * flipping that flag would silently break the recovery without changing
	 * the returned value.
	 */
	public function test_recover_total_paid_passes_include_sign_up_fees_argument() {
		global $wcs_mock_total_paid_including_signup_fee, $wcs_mock_last_calculate_total_paid_args;
		$wcs_mock_total_paid_including_signup_fee = 25.0;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$subscription  = new WC_Subscription(
			[
				'id'     => 23,
				'status' => 'active',
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 0.0,
			]
		);

		WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertNotNull( $wcs_mock_last_calculate_total_paid_args, 'WCS::calculate_total_paid_since_last_order() should have been called.' );
		$this->assertSame( 'include_sign_up_fees', $wcs_mock_last_calculate_total_paid_args['include_sign_up_fees'], 'Sign-up fees must be included; otherwise the recovery is a no-op.' );
	}

	/**
	 * Migrated subscriptions clamp days_in_old_cycle to one billing cycle.
	 *
	 * Without this clamp, WCS divides the recovered recurring total by the
	 * span from the original platform sign-up to the next renewal -- often
	 * many cycles -- which makes old_price_per_day artificially low and
	 * misclassifies a downgrade as an upgrade even after
	 * recover_total_paid_for_switch supplies one cycle's worth of value.
	 */
	public function test_bound_switch_proration_days_in_old_cycle_clamps_migrated_subscription() {
		$subscription = new WC_Subscription(
			[
				'id'               => 30,
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'meta'             => [ '_piano_subscription_id' => 'piano-30' ],
			]
		);

		// 730 days = ~2 years of accumulated span since the original platform sign-up.
		$result = WooCommerce_Subscriptions::bound_switch_proration_days_in_old_cycle( 730, $subscription );

		$this->assertSame( 30, $result, 'A migrated monthly sub must clamp to one cycle (30 days), not the full span since original sign-up.' );
	}

	/**
	 * If WCS already computed a value inside a single billing cycle (early
	 * switches, monthly subs newly migrated), respect that value instead of
	 * inflating it to one cycle's worth.
	 */
	public function test_bound_switch_proration_days_in_old_cycle_respects_smaller_value() {
		$subscription = new WC_Subscription(
			[
				'id'               => 31,
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'meta'             => [ '_piano_subscription_id' => 'piano-31' ],
			]
		);

		$result = WooCommerce_Subscriptions::bound_switch_proration_days_in_old_cycle( 12, $subscription );

		$this->assertSame( 12, $result, 'The clamp is a ceiling, not a floor; a smaller WCS value must pass through.' );
	}

	/**
	 * Non-migrated subscriptions are left to WCS's default behavior even
	 * when WCS computes a denominator longer than one cycle.
	 */
	public function test_bound_switch_proration_days_in_old_cycle_skips_non_migrated_subscription() {
		$subscription = new WC_Subscription(
			[
				'id'               => 32,
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
			]
		);

		$result = WooCommerce_Subscriptions::bound_switch_proration_days_in_old_cycle( 730, $subscription );

		$this->assertSame( 730, $result, 'A non-migrated subscription must pass through unchanged.' );
	}

	/**
	 * Annual migrated subscriptions clamp to one annual cycle, not one month.
	 */
	public function test_bound_switch_proration_days_in_old_cycle_uses_billing_period_for_clamp() {
		$subscription = new WC_Subscription(
			[
				'id'               => 33,
				'status'           => 'active',
				'billing_period'   => 'year',
				'billing_interval' => 1,
				'meta'             => [ '_stripe_subscription_id' => 'stripe-33' ],
			]
		);

		// 1500 days = ~4+ years of accumulated span.
		$result = WooCommerce_Subscriptions::bound_switch_proration_days_in_old_cycle( 1500, $subscription );

		$this->assertSame( 365, $result, 'An annual migrated sub must clamp to one year (365 days), not one month.' );
	}

	/**
	 * Helper: stage a fake WCS_Switch_Cart_Item with the subscription,
	 * existing line item, new product, and the three numeric getters our
	 * filter reads (total_paid, days_in_old_cycle, days_until_next_payment).
	 *
	 * @param array $args Test parameters: paid_sign_up_fee, total_paid,
	 *                    new_recurring, days_in_old_cycle, days_until_next,
	 *                    trial_active (bool), trial_periods_match (bool),
	 *                    one_payment (bool).
	 * @return object Minimal switch_item stub.
	 */
	private function stage_switch_item( array $args = [] ) {
		$args = wp_parse_args(
			$args,
			[
				'paid_sign_up_fee'    => 3.0,
				'total_paid'          => 3.0,
				'new_recurring'       => 10.0,
				'days_in_old_cycle'   => 30,
				'days_until_next'     => 30,
				'trial_active'        => true,
				'trial_periods_match' => true,
				'one_payment'         => false,
			]
		);

		$existing_item = new WC_Order_Item_Product(
			[
				'id'         => 999,
				'product_id' => 100,
				'total'      => 5.0,
				'meta'       => [ '_subscription_sign_up_fee' => (string) $args['paid_sign_up_fee'] ],
			]
		);

		$subscription = new WC_Subscription(
			[
				'id'     => 50,
				'status' => 'active',
				'times'  => [
					'trial_end' => $args['trial_active'] ? time() + ( 15 * DAY_IN_SECONDS ) : 0,
				],
			]
		);

		$new_product = wc_create_mock_product(
			[
				'id'   => 200,
				'meta' => [ '_subscription_price' => (string) $args['new_recurring'] ],
			]
		);

		return new Mock_WCS_Switch_Cart_Item_For_Stepped_Pricing(
			$subscription,
			$existing_item,
			$new_product,
			[
				'total_paid'          => $args['total_paid'],
				'days_in_old_cycle'   => $args['days_in_old_cycle'],
				'days_until_next'     => $args['days_until_next'],
				'trial_periods_match' => $args['trial_periods_match'],
				'one_payment'         => $args['one_payment'],
			]
		);
	}

	/**
	 * Stepped-pricing immediate switch: nothing consumed, full unconsumed
	 * credit applied. For Regular ($3 paid) -> Pro ($10/mo) at day 0,
	 * unconsumed = $3, charge = $10 - $3 = $7.
	 */
	public function test_apply_stepped_pricing_switch_charge_returns_seven_at_day_0() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$switch_item = $this->stage_switch_item( [ 'days_until_next' => 30 ] );

		// WCS computed sign_up_fee_delta = $3 (apportion=yes); our filter overrides.
		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 7.0, $result, 'Day-0 switch charges new_recurring ($10) minus full unconsumed credit ($3).' );
	}

	/**
	 * Mid-trial switch: half consumed, half credited. For Regular ($3 paid)
	 * -> Pro ($10/mo) at day 15 of 30, unconsumed = $1.50, charge = $8.50.
	 */
	public function test_apply_stepped_pricing_switch_charge_returns_eight_fifty_at_day_15() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$switch_item = $this->stage_switch_item( [ 'days_until_next' => 15 ] );

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 8.5, $result, 'Day-15 switch charges new_recurring ($10) minus half-unconsumed ($1.50).' );
	}

	/**
	 * Downgrade: when the unconsumed credit exceeds the new recurring price
	 * (Pro -> Regular mid-trial), the charge clamps to 0 -- we do not
	 * refund or carry credit across switches.
	 */
	public function test_apply_stepped_pricing_switch_charge_clamps_negative_to_zero() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		// New plan is $2/mo, but reader is owed $3 of credit. $2 - $3 = -$1 -> clamped.
		$switch_item = $this->stage_switch_item(
			[
				'paid_sign_up_fee' => 6.0,
				'total_paid'       => 6.0,
				'new_recurring'    => 2.0,
				'days_until_next'  => 30,
			]
		);

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 0.0, $switch_item );

		$this->assertSame( 0.0, $result, 'When unconsumed credit exceeds the new recurring, charge clamps to 0.' );
	}

	/**
	 * Without the opt-in, the filter is a no-op -- publishers who have not
	 * opted in keep WCS default behavior.
	 */
	public function test_apply_stepped_pricing_switch_charge_passes_through_without_optin() {
		$switch_item = $this->stage_switch_item();

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 3.0, $result, 'Without the opt-in, WCS-computed value must pass through unchanged.' );
	}

	/**
	 * Out-of-trial switches are left to WCS's default behavior -- the
	 * stepped-pricing override is only meaningful during the discount
	 * period.
	 */
	public function test_apply_stepped_pricing_switch_charge_passes_through_outside_trial() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$switch_item = $this->stage_switch_item( [ 'trial_active' => false ] );

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 3.0, $result, 'Out-of-trial switches must not be re-priced.' );
	}

	/**
	 * If the existing line item has no paid sign-up fee (genuine free
	 * trial, comp, etc.), the stepped-pricing pattern does not apply and
	 * the filter must pass through.
	 */
	public function test_apply_stepped_pricing_switch_charge_passes_through_when_no_paid_signup_fee() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$switch_item = $this->stage_switch_item(
			[
				'paid_sign_up_fee' => 0.0,
				'total_paid'       => 0.0,
			]
		);

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 0.0, $switch_item );

		$this->assertSame( 0.0, $result, 'Without a paid sign-up fee on the existing item, the filter must not intervene.' );
	}

	/**
	 * A non-object switch_item is returned unchanged so a malformed call
	 * cannot fatal the filter chain.
	 */
	public function test_apply_stepped_pricing_switch_charge_passes_through_invalid_switch_item() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, null );

		$this->assertSame( 3.0, $result, 'A non-object switch_item must be returned unchanged.' );
	}

	/**
	 * When the new product's trial period does not match the old product's
	 * trial period (e.g. switching from a paid-trial plan into a no-trial
	 * plan), WCS does not force new_price_per_day to 0 and computes
	 * extra_to_pay via calculate_upgrade_cost(), which -- given the
	 * corrected total_paid baseline from recover_total_paid_for_switch --
	 * already equals the prorated remaining-term price minus the prorated
	 * unconsumed credit. Overriding the sign-up fee on top of that would
	 * double-charge the reader, so the stepped-pricing override must pass
	 * through and let WCS's default apply.
	 */
	public function test_apply_stepped_pricing_switch_charge_passes_through_when_trial_periods_do_not_match() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$switch_item = $this->stage_switch_item( [ 'trial_periods_match' => false ] );

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 3.0, $result, 'When trial periods do not match, the WCS-computed value must pass through so the prorated extra_to_pay is the final charge.' );
	}

	/**
	 * Near a cycle boundary, days_until_next (WCS ceil) can exceed
	 * days_in_old_cycle (WCS round) by ~1 day, pushing the unconsumed fraction
	 * above 1.0. The credit must clamp so the reader is never credited more
	 * than they paid (and therefore never under-charged). For Regular ($3
	 * paid) -> Pro ($10/mo) with 31 days until next over a 30-day cycle, the
	 * unclamped credit would be $3.10 (charge $6.90); clamped it is $3
	 * (charge $7.00).
	 */
	public function test_apply_stepped_pricing_switch_charge_clamps_unconsumed_ratio_at_cycle_boundary() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$switch_item = $this->stage_switch_item(
			[
				'days_in_old_cycle' => 30,
				'days_until_next'   => 31,
			]
		);

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 7.0, $result, 'The unconsumed fraction must clamp to 1.0 so credit never exceeds the amount paid.' );
	}

	/**
	 * A switch into a one-payment (length-1) subscription routes through WCS's
	 * set_upgrade_cost(), which stacks the apportioned sign-up fee on top of
	 * the gap payment. The override must bail so we do not double-charge.
	 */
	public function test_apply_stepped_pricing_switch_charge_passes_through_for_one_payment_target() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$switch_item = $this->stage_switch_item( [ 'one_payment' => true ] );

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 3.0, $result, 'A switch into a one-payment subscription must pass through to WCS.' );
	}

	/**
	 * On a WCS version that predates trial_periods_match() /
	 * is_switch_to_one_payment_subscription(), the override must fail safe and
	 * pass through rather than apply on an unverifiable assumption.
	 */
	public function test_apply_stepped_pricing_switch_charge_fails_safe_when_methods_absent() {
		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$existing_item = new WC_Order_Item_Product(
			[
				'id'         => 999,
				'product_id' => 100,
				'total'      => 5.0,
				'meta'       => [ '_subscription_sign_up_fee' => '3.0' ],
			]
		);
		$subscription  = new WC_Subscription(
			[
				'id'     => 60,
				'status' => 'active',
				'times'  => [ 'trial_end' => time() + ( 15 * DAY_IN_SECONDS ) ],
			]
		);
		$new_product   = wc_create_mock_product(
			[
				'id'   => 200,
				'meta' => [ '_subscription_price' => '10.0' ],
			]
		);

		$switch_item = new Mock_WCS_Switch_Cart_Item_Legacy( $subscription, $existing_item, $new_product );

		$result = WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 3.0, $result, 'When trial_periods_match() is unavailable, the override must pass through.' );
	}

	/**
	 * On a tax-inclusive store the recovered sign-up fee must be read in the
	 * same tax mode WCS uses (WCS_Switch_Totals_Calculator::
	 * apportion_sign_up_fees), otherwise the baseline is dimensionally
	 * mismatched against new_recurring.
	 */
	public function test_apply_stepped_pricing_switch_charge_reads_signup_fee_inclusive_of_tax_on_tax_inclusive_store() {
		global $wcs_mock_prices_include_tax, $wcs_mock_last_items_sign_up_fee_tax;
		$wcs_mock_prices_include_tax = true;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$switch_item = $this->stage_switch_item();

		WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 'inclusive_of_tax', $wcs_mock_last_items_sign_up_fee_tax, 'On a tax-inclusive store the sign-up fee must be read inclusive_of_tax to match WCS.' );
	}

	/**
	 * On a tax-exclusive store (the default) the sign-up fee is read
	 * exclusive_of_tax, matching WCS.
	 */
	public function test_apply_stepped_pricing_switch_charge_reads_signup_fee_exclusive_of_tax_by_default() {
		global $wcs_mock_last_items_sign_up_fee_tax;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$switch_item = $this->stage_switch_item();

		WooCommerce_Subscriptions::apply_stepped_pricing_switch_charge( 3.0, $switch_item );

		$this->assertSame( 'exclusive_of_tax', $wcs_mock_last_items_sign_up_fee_tax, 'On a tax-exclusive store the sign-up fee must be read exclusive_of_tax.' );
	}

	/**
	 * Documents the intentional looser gate on recover_total_paid_for_switch
	 * branch 2: with the opt-in on it recovers even outside an active trial,
	 * but the recovery is bounded by what the reader actually paid (WCS's own
	 * accounting), so it can never fabricate credit. Here WCS reports $15
	 * actually paid and that -- not more -- becomes the baseline.
	 */
	public function test_recover_total_paid_optin_recovery_is_bounded_by_actual_payment() {
		global $wcs_mock_total_paid_including_signup_fee;
		$wcs_mock_total_paid_including_signup_fee = 15.0;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		// No trial set: out of any active trial.
		$subscription  = new WC_Subscription(
			[
				'id'     => 70,
				'status' => 'active',
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 0.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 15.0, $result, 'Recovery is bounded by the actual amount paid, never more.' );
	}

	/**
	 * No-op: a genuine free trial (opt-in on, but nothing actually paid)
	 * recovers nothing.
	 */
	public function test_recover_total_paid_optin_genuine_free_trial_is_noop() {
		global $wcs_mock_total_paid_including_signup_fee;
		$wcs_mock_total_paid_including_signup_fee = 0.0;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$subscription  = new WC_Subscription(
			[
				'id'     => 71,
				'status' => 'active',
				'times'  => [ 'trial_end' => time() + DAY_IN_SECONDS ],
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 0.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 0.0, $subscription, $existing_item );

		$this->assertSame( 0.0, $result, 'A genuine free trial with nothing paid must not gain a recovered baseline.' );
	}

	/**
	 * No-op: a subscription with normal order history (WCS reports a positive
	 * amount paid) returns early and never reaches branch 2, even with the
	 * opt-in on.
	 */
	public function test_recover_total_paid_optin_leaves_normal_history_positive_value_untouched() {
		global $wcs_mock_total_paid_including_signup_fee;
		$wcs_mock_total_paid_including_signup_fee = 999.0;

		add_filter( 'newspack_wc_subs_switch_include_signup_fee', '__return_true' );

		$subscription  = new WC_Subscription(
			[
				'id'     => 72,
				'status' => 'active',
			]
		);
		$existing_item = new WC_Order_Item_Product(
			[
				'product_id' => 100,
				'total'      => 50.0,
			]
		);

		$result = WooCommerce_Subscriptions::recover_total_paid_for_switch( 8.0, $subscription, $existing_item );

		$this->assertSame( 8.0, $result, 'A positive WCS value (normal history) must be returned untouched even with the opt-in on.' );
	}
}
