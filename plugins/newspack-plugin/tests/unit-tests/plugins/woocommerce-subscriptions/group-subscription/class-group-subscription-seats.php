<?php
/**
 * Tests for Group_Subscription_Seats.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Invite;
use Newspack\Group_Subscription_Seats;
use Newspack\Group_Subscription_Settings;

/**
 * Test Group_Subscription_Seats.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Test_Group_Subscription_Seats extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Include WC mocks.
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Set up: reset the products and subscriptions databases, recorded notices,
	 * the is_product() mock, the group member cache, the current user and the
	 * switch request, and enable the content-gates feature flag the class's
	 * init() checks.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database, $subscriptions_database;
		$products_database      = [];
		$subscriptions_database = [];
		wc_mocks_reset_notices();
		wc_mocks_set_is_product( false );
		Group_Subscription::reset_cache();
		wp_set_current_user( 0 );
		unset( $_REQUEST['switch-subscription'], $_REQUEST['product_id'], $_REQUEST['variation_id'], $_REQUEST['add-to-cart'] );
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Tear down: reset everything set_up() does, so no test's switch request,
	 * current user or cached membership can leak into the next. The admin screen
	 * is reset here rather than at the end of the one test that sets it, so a
	 * failure part-way through cannot leave is_admin() true for everything after.
	 */
	public function tear_down() {
		global $products_database, $subscriptions_database;
		$products_database      = [];
		$subscriptions_database = [];
		wc_mocks_set_is_product( false );
		Group_Subscription::reset_cache();
		wp_set_current_user( 0 );
		unset( $_REQUEST['switch-subscription'], $_REQUEST['product_id'], $_REQUEST['variation_id'], $_REQUEST['add-to-cart'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Build a per-seat group subscription product registered in the mock products database.
	 *
	 * @param int $id  Product ID.
	 * @param int $min Minimum seats.
	 * @param int $max Maximum seats (0 = unlimited).
	 *
	 * @return WC_Product
	 */
	private function make_per_seat_product( $id, $min, $max ) {
		return wc_create_mock_product(
			[
				'id'   => $id,
				'meta' => [
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled'      => 'yes',
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'pricing_mode' => Group_Subscription_Settings::PRICING_MODE_PER_SEAT,
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'min_seats'     => $min,
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'max_seats'     => $max,
				],
			]
		);
	}

	/**
	 * Build a flat (`per_team`) group subscription product registered in the mock products database.
	 *
	 * @param int $id Product ID.
	 *
	 * @return WC_Product
	 */
	private function make_flat_product( $id ) {
		return wc_create_mock_product(
			[
				'id'   => $id,
				'meta' => [
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled' => 'yes',
				],
			]
		);
	}

	/**
	 * Build a group subscription owned by a fresh user, with its own product, a
	 * seat line item, members and invitations.
	 *
	 * The product carries the pricing mode, so a per-seat subscription is one
	 * whose product is per seat — the same resolution `is_per_seat()` performs.
	 *
	 * @param int   $id   Subscription ID. The product gets this ID plus 1000, keeping
	 *                    it clear of the standalone product IDs the other tests use.
	 * @param array $args Fixture options: `quantity` (seats bought), `members`
	 *                    (how many member users to add besides the owner),
	 *                    `pending_invites` and `expired_invites` (how many of each),
	 *                    `per_seat` (false for a flat product), `min_seats`/`max_seats`.
	 *
	 * @return WC_Subscription
	 */
	private function make_group_subscription( $id, $args = [] ) {
		$args = array_merge(
			[
				'quantity'        => 1,
				'members'         => 0,
				'pending_invites' => 0,
				'expired_invites' => 0,
				'per_seat'        => true,
				'min_seats'       => 1,
				'max_seats'       => 0,
			],
			$args
		);

		$product_id = $id + 1000;
		if ( $args['per_seat'] ) {
			$this->make_per_seat_product( $product_id, $args['min_seats'], $args['max_seats'] );
		} else {
			$this->make_flat_product( $product_id );
		}

		$invites = [];
		for ( $i = 0; $i < $args['pending_invites']; $i++ ) {
			$invites[ 'pending-' . $i ] = [
				'email'      => 'pending-' . $i . '@example.com',
				'expiration' => time() + HOUR_IN_SECONDS,
			];
		}
		for ( $i = 0; $i < $args['expired_invites']; $i++ ) {
			$invites[ 'expired-' . $i ] = [
				'email'      => 'expired-' . $i . '@example.com',
				'expiration' => time() - HOUR_IN_SECONDS,
			];
		}

		$subscription = wcs_create_subscription(
			[
				'id'          => $id,
				'customer_id' => self::factory()->user->create(),
				'status'      => 'active',
				'meta'        => [ Group_Subscription_Invite::META => $invites ],
			]
		);
		$subscription->add_item(
			new WC_Order_Item_Product(
				[
					'product_id' => $product_id,
					'quantity'   => $args['quantity'],
				]
			)
		);

		for ( $i = 0; $i < $args['members']; $i++ ) {
			add_user_meta( self::factory()->user->create(), Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $id );
		}
		Group_Subscription::reset_cache();

		return $subscription;
	}

	/**
	 * The product ID behind a subscription's seat line item — what a switch request
	 * for that subscription would be adding to the cart.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 *
	 * @return int
	 */
	private function product_id_for( $subscription ) {
		$item = Group_Subscription_Settings::get_seat_line_item( $subscription );
		return $item ? $item->get_product_id() : 0;
	}

	/**
	 * Bounds resolve from product meta, a flat product has no seats field, and
	 * the field label uses the publisher's configured group label.
	 */
	public function test_bounds_and_label() {
		$product = $this->make_per_seat_product( 911, 2, 8 );
		$this->assertSame(
			[
				'min' => 2,
				'max' => 8,
			],
			Group_Subscription_Seats::get_bounds( $product ) 
		);
		$this->assertNull( Group_Subscription_Seats::get_field_args( $this->make_flat_product( 912 ) ) );

		update_option( 'newspack_group_subscription_label_singular', 'Team' );
		$this->assertStringContainsString( 'team', Group_Subscription_Seats::get_field_label() );
		delete_option( 'newspack_group_subscription_label_singular' );
	}

	/**
	 * A maximum saved below the minimum would leave the product with no seat count
	 * that satisfies both bounds, so it could not be bought at all. The maximum
	 * gives way, and the plan sells at exactly its minimum.
	 */
	public function test_bounds_raise_a_maximum_below_the_minimum() {
		$product = $this->make_per_seat_product( 9160, 5, 2 );

		$this->assertSame(
			[
				'min' => 5,
				'max' => 5,
			],
			Group_Subscription_Seats::get_bounds( $product )
		);
		$this->assertTrue( Group_Subscription_Seats::validate_quantity( $product, 5 ) );
	}

	/**
	 * Enforces both the minimum and maximum seat bounds; a max of 0 means unlimited.
	 */
	public function test_validate_quantity_enforces_bounds() {
		$product = $this->make_per_seat_product( 913, 2, 5 );
		$this->assertTrue( Group_Subscription_Seats::validate_quantity( $product, 3 ) );
		$this->assertWPError( Group_Subscription_Seats::validate_quantity( $product, 1 ) );
		$this->assertWPError( Group_Subscription_Seats::validate_quantity( $product, 6 ) );

		$unlimited = $this->make_per_seat_product( 914, 1, 0 );
		$this->assertTrue( Group_Subscription_Seats::validate_quantity( $unlimited, 500 ) );
	}

	/**
	 * The woocommerce_add_cart_item_data guard throws on an out-of-bounds
	 * quantity, since that filter is the only one direct WC_Cart::add_to_cart()
	 * calls (e.g. the modal checkout) actually run.
	 */
	public function test_cart_item_data_filter_throws_out_of_bounds() {
		$this->make_per_seat_product( 915, 2, 5 );
		$this->expectException( \Exception::class );
		Group_Subscription_Seats::guard_add_cart_item_data( [], 915, 0, 1 );
	}

	/**
	 * Flat products never enter the seats guard, so the cart item data passes through untouched.
	 */
	public function test_cart_item_data_filter_ignores_flat_products() {
		$this->make_flat_product( 916 );
		$this->assertSame( [ 'x' => 1 ], Group_Subscription_Seats::guard_add_cart_item_data( [ 'x' => 1 ], 916, 0, 1 ) );
	}

	/**
	 * The woocommerce_add_to_cart_validation guard adds a notice and blocks the
	 * add when the requested quantity is out of bounds.
	 */
	public function test_add_to_cart_validation_blocks_out_of_bounds() {
		global $wc_mock_notices;
		$this->make_per_seat_product( 917, 2, 5 );

		$this->assertTrue( Group_Subscription_Seats::validate_add_to_cart( true, 917, 3 ) );
		$this->assertEmpty( $wc_mock_notices );

		$this->assertFalse( Group_Subscription_Seats::validate_add_to_cart( true, 917, 1 ) );
		$this->assertNotEmpty( $wc_mock_notices );
		$this->assertSame( 'error', $wc_mock_notices[0]['type'] );
	}

	/**
	 * A prior filter's failure (passed = false) is preserved, not overridden.
	 */
	public function test_add_to_cart_validation_preserves_prior_failure() {
		$this->make_per_seat_product( 918, 2, 5 );
		$this->assertFalse( Group_Subscription_Seats::validate_add_to_cart( false, 918, 3 ) );
	}

	/**
	 * The woocommerce_update_cart_validation guard reads the product ID from
	 * the cart item's values (preferring variation_id when present) and blocks
	 * an out-of-bounds quantity change.
	 */
	public function test_update_cart_validation_blocks_out_of_bounds() {
		global $wc_mock_notices;
		$this->make_per_seat_product( 919, 2, 5 );

		$this->assertTrue( Group_Subscription_Seats::validate_cart_update( true, 'key', [ 'product_id' => 919 ], 3 ) );
		$this->assertFalse( Group_Subscription_Seats::validate_cart_update( true, 'key', [ 'product_id' => 919 ], 6 ) );
		$this->assertNotEmpty( $wc_mock_notices );
	}

	/**
	 * A posted quantity of 0 is WooCommerce's "remove this item" signal, not a
	 * request for a 0-seat group, so it must never be blocked by the seat
	 * minimum — otherwise a reader could never remove a per-seat item from the
	 * cart via the quantity field.
	 */
	public function test_update_cart_validation_allows_zero_quantity_removal() {
		global $wc_mock_notices;
		$this->make_per_seat_product( 923, 2, 5 );

		$this->assertTrue( Group_Subscription_Seats::validate_cart_update( true, 'key', [ 'product_id' => 923 ], 0 ) );
		$this->assertEmpty( $wc_mock_notices );
	}

	/**
	 * Constrains min/max/step to the product's bounds and clamps a below-minimum
	 * input value up to the minimum, sets a product-specific input_id, but
	 * leaves a product with no group settings untouched.
	 */
	public function test_quantity_input_args_constrains_bounds() {
		wc_mocks_set_is_product( true );

		$product = $this->make_per_seat_product( 920, 2, 5 );
		$args    = Group_Subscription_Seats::quantity_input_args( [ 'input_value' => 1 ], $product );
		$this->assertSame( 2, $args['min_value'] );
		$this->assertSame( 5, $args['max_value'] );
		$this->assertSame( 1, $args['step'] );
		$this->assertSame( 2, $args['input_value'], 'A below-minimum input value should be clamped up to the minimum.' );
		$this->assertSame( 'newspack-group-subscription-seats-quantity-920', $args['input_id'], 'The input id should be scoped to the product so multiple per-seat quantity fields on one page cannot collide.' );

		$unlimited      = $this->make_per_seat_product( 921, 1, 0 );
		$unlimited_args = Group_Subscription_Seats::quantity_input_args( [], $unlimited );
		$this->assertSame( '', $unlimited_args['max_value'], 'A max of 0 (unlimited) should not set a max_value.' );

		$plain         = wc_create_mock_product( [ 'id' => 922 ] );
		$original_args = [
			'min_value' => 1,
			'max_value' => 99,
		];
		$this->assertSame( $original_args, Group_Subscription_Seats::quantity_input_args( $original_args, $plain ) );
	}

	/**
	 * A flat group plan's quantity input is pinned to one on the product page.
	 * Its price covers the whole group, so there is no second one to buy — and
	 * without a maximum the reader can put five of them in the cart.
	 */
	public function test_quantity_input_args_pins_flat_group_to_one() {
		wc_mocks_set_is_product( true );

		$flat = $this->make_flat_product( 931 );
		$args = Group_Subscription_Seats::quantity_input_args(
			[
				'min_value'   => 1,
				'max_value'   => 99,
				'input_value' => 3,
			],
			$flat
		);

		$this->assertSame( 1, $args['max_value'] );
		$this->assertSame( 1, $args['input_value'] );
	}

	/**
	 * WooCommerce's variation script rebuilds the quantity input from the chosen
	 * variation's own data, so each variation has to publish its own bounds: the
	 * seat minimum and maximum for a per-seat tier, a maximum of one for a flat
	 * tier, and nothing at all for a variation that is not a group product.
	 */
	public function test_available_variation_args_publish_per_variation_bounds() {
		$parent = wc_create_mock_product( [ 'id' => 932 ] );

		$bounded = Group_Subscription_Seats::available_variation_args(
			[
				'min_qty' => 1,
				'max_qty' => '',
			],
			$parent,
			$this->make_per_seat_product( 933, 2, 6 )
		);
		$this->assertSame( 2, $bounded['min_qty'] );
		$this->assertSame( 6, $bounded['max_qty'] );

		$unlimited = Group_Subscription_Seats::available_variation_args( [], $parent, $this->make_per_seat_product( 934, 3, 0 ) );
		$this->assertSame( 3, $unlimited['min_qty'] );
		$this->assertSame( '', $unlimited['max_qty'], 'A max of 0 (unlimited) should leave the input without a maximum.' );

		$flat = Group_Subscription_Seats::available_variation_args(
			[
				'min_qty' => 1,
				'max_qty' => 99,
			],
			$parent,
			$this->make_flat_product( 935 )
		);
		$this->assertSame( 1, $flat['max_qty'] );

		$untouched = [
			'min_qty' => 1,
			'max_qty' => 99,
		];
		$this->assertSame( $untouched, Group_Subscription_Seats::available_variation_args( $untouched, $parent, wc_create_mock_product( [ 'id' => 936 ] ) ) );
	}

	/**
	 * A per-seat product is switchable whatever WooCommerce Subscriptions' own
	 * product-type rule says: changing seats is a quantity switch, and WCS counts
	 * only variable and grouped products as switchable, so a simple per-seat plan
	 * would otherwise have a "Change seats" button that goes nowhere.
	 */
	public function test_per_seat_product_is_switchable() {
		$per_seat = $this->make_per_seat_product( 937, 2, 5 );

		$this->assertTrue( Group_Subscription_Seats::allow_per_seat_switching( false, $per_seat ) );
	}

	/**
	 * WooCommerce Subscriptions resolves a variation to its parent before
	 * filtering and passes the variation alongside, and per-seat meta lives on
	 * the variation for a tiered plan.
	 */
	public function test_per_seat_variation_is_switchable() {
		$parent    = wc_create_mock_product( [ 'id' => 938 ] );
		$variation = $this->make_per_seat_product( 939, 2, 5 );

		$this->assertTrue( Group_Subscription_Seats::allow_per_seat_switching( false, $parent, $variation ) );
	}

	/**
	 * A plan the publisher has since unpublished is not switchable, the same test
	 * WooCommerce Subscriptions applies to every type it answers for. The group
	 * page's switch link is the plan's own permalink, so saying yes here would give
	 * an owner a button to a plan that is no longer there.
	 */
	public function test_unpublished_per_seat_product_is_not_switchable() {
		$draft = wc_create_mock_product(
			[
				'id'     => 942,
				'status' => 'draft',
				'meta'   => [
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled'      => 'yes',
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'pricing_mode' => Group_Subscription_Settings::PRICING_MODE_PER_SEAT,
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'min_seats'    => 2,
				],
			]
		);

		$this->assertFalse( Group_Subscription_Seats::allow_per_seat_switching( false, $draft ) );
	}

	/**
	 * Every other product keeps whatever WooCommerce Subscriptions decided, in
	 * both directions: this answers for per-seat plans alone.
	 */
	public function test_switchability_of_other_products_is_untouched() {
		$flat  = $this->make_flat_product( 940 );
		$plain = wc_create_mock_product( [ 'id' => 941 ] );

		$this->assertFalse( Group_Subscription_Seats::allow_per_seat_switching( false, $flat ) );
		$this->assertFalse( Group_Subscription_Seats::allow_per_seat_switching( false, $plain ) );
		$this->assertFalse( Group_Subscription_Seats::allow_per_seat_switching( false, null ) );
		$this->assertTrue( Group_Subscription_Seats::allow_per_seat_switching( true, $flat ) );
	}

	/**
	 * A flat group plan is bought once. Its price covers the whole group and its
	 * capacity is the publisher's own limit, so several of it in the cart would
	 * bill the group price over and over for capacity already paid for.
	 */
	public function test_flat_group_product_cannot_be_bought_more_than_once() {
		global $wc_mock_notices;
		$this->make_flat_product( 942 );

		$this->assertTrue( Group_Subscription_Seats::validate_add_to_cart( true, 942, 1 ) );
		$this->assertEmpty( $wc_mock_notices );

		$this->assertFalse( Group_Subscription_Seats::validate_add_to_cart( true, 942, 5 ) );
		$this->assertNotEmpty( $wc_mock_notices );
		$this->assertSame( 'error', $wc_mock_notices[0]['type'] );
	}

	/**
	 * The same guard on the direct-call path — and only for group products: a
	 * product with no group settings is an ordinary purchase at any quantity.
	 */
	public function test_flat_quantity_guard_applies_only_to_group_products() {
		wc_create_mock_product( [ 'id' => 943 ] );
		$this->assertSame( [], Group_Subscription_Seats::guard_add_cart_item_data( [], 943, 0, 5 ) );

		$this->make_flat_product( 944 );
		$this->expectException( \Exception::class );
		Group_Subscription_Seats::guard_add_cart_item_data( [], 944, 0, 2 );
	}

	/**
	 * Outside the single-product page — the cart table, for instance, which
	 * renders one quantity input per cart row on one page — the filter must
	 * leave WooCommerce's own args untouched. Overriding them there would
	 * collide input ids across rows and would defeat the cart's "set quantity
	 * to 0 to remove the item" convention (see the two seat-bound overrides,
	 * `min_value` in particular).
	 */
	public function test_quantity_input_args_ignored_outside_product_page() {
		wc_mocks_set_is_product( false );

		$product       = $this->make_per_seat_product( 924, 2, 5 );
		$original_args = [
			'min_value' => 0,
			'max_value' => 10,
		];
		$this->assertSame( $original_args, Group_Subscription_Seats::quantity_input_args( $original_args, $product ) );
	}

	/**
	 * The modal checkout's quantity-field filter turns the in-modal seats form
	 * on for a per-seat product, with the same args every other seat field uses.
	 */
	public function test_modal_quantity_field_returns_field_args_for_per_seat_product() {
		$product = $this->make_per_seat_product( 926, 3, 8 );

		$args = Group_Subscription_Seats::modal_quantity_field( null, $product );

		$this->assertSame( Group_Subscription_Seats::get_field_args( $product ), $args );
		$this->assertSame( 3, $args['min'] );
		$this->assertSame( 8, $args['max'] );
	}

	/**
	 * A flat (per-team) product leaves the incoming value alone, so the modal
	 * stays single-quantity and another consumer's args are not clobbered.
	 */
	public function test_modal_quantity_field_passes_through_for_flat_product() {
		$product = $this->make_flat_product( 927 );

		$this->assertNull( Group_Subscription_Seats::modal_quantity_field( null, $product ) );

		$other = [ 'label' => 'Licenses' ];
		$this->assertSame( $other, Group_Subscription_Seats::modal_quantity_field( $other, $product ) );
	}

	/**
	 * A per-seat product keeps the seat count it was asked for: seats are exactly
	 * what the modal checkout's quantity means.
	 */
	public function test_clamp_modal_quantity_keeps_per_seat_quantity() {
		$this->make_per_seat_product( 928, 2, 0 );

		$this->assertSame( 5, Group_Subscription_Seats::clamp_modal_quantity( null, 928, 5 ) );
	}

	/**
	 * A flat (per-team) product sells no seats, so this class vouches for nothing:
	 * the modal checkout's own default of one stands. Its price covers the whole
	 * group, so honoring a seat count would bill that price per seat — the case a
	 * group owner hits by switching from a per-seat tier to a flat one, carrying
	 * their seats with them.
	 */
	public function test_clamp_modal_quantity_vouches_for_nothing_on_a_flat_product() {
		$this->make_flat_product( 929 );

		$this->assertNull( Group_Subscription_Seats::clamp_modal_quantity( null, 929, 5 ) );
	}

	/**
	 * A product with no group settings at all, and a product ID that resolves to
	 * nothing, are neither of them sold per seat, so the incoming answer is handed
	 * back untouched rather than overridden.
	 */
	public function test_clamp_modal_quantity_leaves_non_group_products_alone() {
		wc_create_mock_product( [ 'id' => 930 ] );

		$this->assertNull( Group_Subscription_Seats::clamp_modal_quantity( null, 930, 4 ) );
		$this->assertNull( Group_Subscription_Seats::clamp_modal_quantity( null, 999, 4 ) );
		$this->assertSame( 3, Group_Subscription_Seats::clamp_modal_quantity( 3, 930, 4 ), 'Another consumer of the filter keeps its answer.' );
	}

	/**
	 * Occupancy is everyone a seat is already committed to: the owner, the
	 * members, and the invitations still waiting to be accepted. An expired
	 * invitation holds nothing, so it does not count.
	 */
	public function test_occupancy_counts_owner_members_and_pending_invites() {
		$subscription = $this->make_group_subscription(
			931,
			[
				'quantity'        => 5,
				'members'         => 2,
				'pending_invites' => 1,
				'expired_invites' => 3,
			]
		);

		$this->assertSame( 4, Group_Subscription_Seats::get_occupancy( $subscription ), 'Owner + 2 members + 1 pending invite.' );
	}

	/**
	 * A switch cannot cut a group's seats below the people already in it: the
	 * seat count is the capacity, so shrinking past occupancy would leave
	 * members or pending invitations with nowhere to sit.
	 */
	public function test_switch_below_occupancy_is_rejected() {
		$subscription = $this->make_group_subscription(
			932,
			[
				'quantity' => 5,
				'members'  => 2,
			]
		);
		wp_set_current_user( $subscription->get_user_id() );
		$_REQUEST['switch-subscription'] = 932;

		// The message pins the rejection to occupancy: a quantity of 2 clears the
		// product's own bounds, so only the 3 people in the group can block it.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( '3 seats in use' );
		Group_Subscription_Seats::guard_add_cart_item_data( [], $this->product_id_for( $subscription ), 0, 2 );
	}

	/**
	 * Buying exactly as many seats as are occupied is fine — nobody loses a seat —
	 * and so is buying more, which is the ordinary way a group grows.
	 */
	public function test_switch_at_or_above_occupancy_passes() {
		$subscription = $this->make_group_subscription(
			933,
			[
				'quantity' => 5,
				'members'  => 1,
			]
		);
		wp_set_current_user( $subscription->get_user_id() );
		$_REQUEST['switch-subscription'] = 933;
		$product_id                      = $this->product_id_for( $subscription );

		$this->assertSame( [], Group_Subscription_Seats::guard_add_cart_item_data( [], $product_id, 0, 2 ), 'Exactly at occupancy.' );
		$this->assertSame( [], Group_Subscription_Seats::guard_add_cart_item_data( [], $product_id, 0, 9 ), 'Well above occupancy.' );
	}

	/**
	 * A group moving off a flat plan onto a per-seat one is bound by occupancy
	 * just the same. Pricing mode is a property of the product, so a variable
	 * subscription can offer both a flat and a per-seat variation and a reader
	 * can switch between them — and it is the plan being switched TO that
	 * decides whether seats are what capacity is counted in.
	 */
	public function test_flat_to_per_seat_switch_below_occupancy_is_rejected() {
		$flat = $this->make_group_subscription(
			942,
			[
				'members'  => 3,
				'per_seat' => false,
			]
		);
		$this->make_per_seat_product( 9420, 1, 0 );
		wp_set_current_user( $flat->get_user_id() );
		$_REQUEST['switch-subscription'] = 942;

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( '4 seats in use' );
		Group_Subscription_Seats::guard_add_cart_item_data( [], 9420, 0, 2 );
	}

	/**
	 * Occupancy is only ever read from the requester's own group. A crafted
	 * request naming somebody else's subscription is ignored, so it can neither
	 * block their purchase nor report how many people are in a group they have
	 * no part in.
	 */
	public function test_switch_occupancy_ignores_another_readers_subscription() {
		$subscription = $this->make_group_subscription(
			934,
			[
				'quantity' => 5,
				'members'  => 3,
			]
		);
		wp_set_current_user( self::factory()->user->create() );
		$_REQUEST['switch-subscription'] = 934;

		$this->assertSame( [], Group_Subscription_Seats::guard_add_cart_item_data( [], $this->product_id_for( $subscription ), 0, 1 ) );
	}

	/**
	 * Without a switch request there is no group to measure, so a first purchase
	 * of a single seat still passes.
	 */
	public function test_first_purchase_is_not_measured_against_occupancy() {
		$subscription = $this->make_group_subscription(
			935,
			[
				'quantity' => 5,
				'members'  => 3,
			]
		);

		$this->assertSame( [], Group_Subscription_Seats::guard_add_cart_item_data( [], $this->product_id_for( $subscription ), 0, 1 ) );
	}

	/**
	 * Editing the seat count on the cart page is bound by occupancy too. Nothing
	 * in that request says it is a switch — the cart item is what remembers —
	 * so without reading it a reader could walk back to the cart and cut the
	 * seats they were just stopped from cutting.
	 */
	public function test_cart_update_below_occupancy_is_rejected() {
		global $wc_mock_notices;
		$subscription = $this->make_group_subscription(
			940,
			[
				'quantity' => 5,
				'members'  => 2,
			]
		);
		wp_set_current_user( $subscription->get_user_id() );
		$values = [
			'product_id'          => $this->product_id_for( $subscription ),
			'subscription_switch' => [ 'subscription_id' => 940 ],
		];

		$this->assertFalse( Group_Subscription_Seats::validate_cart_update( true, 'key', $values, 2 ) );
		$this->assertStringContainsString( '3 seats in use', $wc_mock_notices[0]['notice'] );

		wc_mocks_reset_notices();
		$this->assertTrue(
			Group_Subscription_Seats::validate_cart_update( true, 'key', $values, 3 ),
			'Keeping a seat for everyone already in the group is allowed.'
		);
		$this->assertEmpty( $wc_mock_notices );
	}

	/**
	 * Proration is forced on while the request is switching onto a per-seat plan,
	 * and left to the publisher's own setting once it is not.
	 */
	public function test_proration_forced_on_for_per_seat_switch() {
		$subscription = $this->make_group_subscription( 936, [ 'quantity' => 3 ] );
		wp_set_current_user( $subscription->get_user_id() );
		$_REQUEST['switch-subscription'] = 936;
		$_REQUEST['product_id']          = $this->product_id_for( $subscription );

		$this->assertSame( 'yes', Group_Subscription_Seats::force_recurring_proration( false ) );

		unset( $_REQUEST['switch-subscription'] );
		$this->assertFalse( Group_Subscription_Seats::force_recurring_proration( false ) );
	}

	/**
	 * A flat group switching onto a per-seat plan is buying seats, and every one
	 * of them has to be paid for from the day of the switch. What it is leaving
	 * behind does not come into it.
	 */
	public function test_proration_forced_on_for_flat_to_per_seat_switch() {
		$flat = $this->make_group_subscription( 943, [ 'per_seat' => false ] );
		$this->make_per_seat_product( 9430, 1, 0 );
		wp_set_current_user( $flat->get_user_id() );
		$_REQUEST['switch-subscription'] = 943;
		$_REQUEST['product_id']          = 9430;

		$this->assertSame( 'yes', Group_Subscription_Seats::force_recurring_proration( false ) );
	}

	/**
	 * A per-seat group switching onto a flat plan is buying one price for the
	 * whole group, so there are no seats to prorate and the publisher's setting
	 * stands.
	 */
	public function test_proration_untouched_for_per_seat_to_flat_switch() {
		$per_seat = $this->make_group_subscription( 944, [ 'quantity' => 5 ] );
		$this->make_flat_product( 9440 );
		wp_set_current_user( $per_seat->get_user_id() );
		$_REQUEST['switch-subscription'] = 944;
		$_REQUEST['product_id']          = 9440;

		$this->assertFalse( Group_Subscription_Seats::force_recurring_proration( false ) );
	}

	/**
	 * On a WooCommerce settings screen the option has to read back exactly what
	 * the publisher stored: the form saves whatever it was rendered with, so an
	 * answer there would rewrite a store-wide billing setting behind their back.
	 * The modal checkout's admin-ajax requests are admin requests too, and those
	 * are real switches, so they still get an answer.
	 */
	public function test_proration_untouched_on_admin_screens() {
		$subscription = $this->make_group_subscription( 941, [ 'quantity' => 3 ] );
		wp_set_current_user( $subscription->get_user_id() );
		$_REQUEST['switch-subscription'] = 941;
		$_REQUEST['product_id']          = $this->product_id_for( $subscription );

		set_current_screen( 'dashboard' );
		$this->assertFalse( Group_Subscription_Seats::force_recurring_proration( false ) );

		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertSame( 'yes', Group_Subscription_Seats::force_recurring_proration( false ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	/**
	 * By the time the checkout totals are calculated the switch request params
	 * are gone: the cart item records that this is a switch, and its own product
	 * says whether what is being bought is seats.
	 */
	public function test_cart_switch_detection_reads_the_cart_item() {
		$this->make_per_seat_product( 945, 1, 0 );
		$this->make_flat_product( 946 );

		$this->assertTrue(
			Group_Subscription_Seats::cart_has_per_seat_switch(
				[
					'key' => [
						'product_id'          => 945,
						'subscription_switch' => [ 'subscription_id' => 938 ],
					],
				]
			)
		);
		$this->assertTrue(
			Group_Subscription_Seats::cart_has_per_seat_switch(
				[
					'key' => [
						'product_id'          => 946,
						'variation_id'        => 945,
						'subscription_switch' => [ 'subscription_id' => 938 ],
					],
				]
			),
			'A variation is what carries the pricing mode, so it wins over its parent.'
		);
		$this->assertFalse(
			Group_Subscription_Seats::cart_has_per_seat_switch(
				[
					'key' => [
						'product_id'          => 946,
						'subscription_switch' => [ 'subscription_id' => 939 ],
					],
				]
			),
			'A switch onto a flat plan buys no seats.'
		);
		$this->assertFalse(
			Group_Subscription_Seats::cart_has_per_seat_switch( [ 'key' => [ 'product_id' => 945 ] ] ),
			'A per-seat product that is not a switch at all should not be mistaken for one.'
		);
	}

	/**
	 * The modal checkout carries no seat count of its own, so a Checkout Button on
	 * a per-seat plan asks for one. That has to become the plan's minimum, not one:
	 * a quantity of one on a plan that sells no fewer than two seats is refused by
	 * the guards, and the reader lands on an empty cart with nothing to fix.
	 */
	public function test_modal_quantity_is_raised_to_the_minimum() {
		$this->make_per_seat_product( 950, 2, 10 );

		$this->assertSame( 2, Group_Subscription_Seats::clamp_modal_quantity( null, 950, 1 ) );
		$this->assertSame( 2, Group_Subscription_Seats::clamp_modal_quantity( null, 950, 0 ) );
		$this->assertSame( 4, Group_Subscription_Seats::clamp_modal_quantity( null, 950, 4 ) );
		$this->assertSame( 10, Group_Subscription_Seats::clamp_modal_quantity( null, 950, 25 ), 'A request above the maximum is still clamped down to it.' );

		$this->make_per_seat_product( 951, 3, 0 );
		$this->assertSame( 3, Group_Subscription_Seats::clamp_modal_quantity( null, 951, 1 ) );
		$this->assertSame( 25, Group_Subscription_Seats::clamp_modal_quantity( null, 951, 25 ), 'An unlimited maximum leaves a large request alone.' );
	}

	/**
	 * WooCommerce Subscriptions re-adds a subscription's own line items to the cart
	 * at their stored quantity to take a renewal, resubscribe or initial payment.
	 * The reader is choosing nothing there, so the seat guards must not refuse it:
	 * a group that bought ten seats before the publisher lowered the maximum to
	 * five would otherwise be unable to pay for the plan it already has.
	 */
	public function test_wcs_payment_carts_bypass_the_seat_guards() {
		global $wc_mock_notices;
		$this->make_per_seat_product( 952, 2, 5 );

		foreach ( [ 'subscription_renewal', 'subscription_resubscribe', 'subscription_initial_payment' ] as $key ) {
			$cart_item_data = [ $key => [ 'subscription_id' => 123 ] ];
			$this->assertTrue(
				Group_Subscription_Seats::validate_add_to_cart( true, 952, 10, 0, [], $cart_item_data ),
				sprintf( 'A %s cart item should pass validation at its stored quantity.', $key )
			);
			$this->assertSame(
				$cart_item_data,
				Group_Subscription_Seats::guard_add_cart_item_data( $cart_item_data, 952, 0, 10 ),
				sprintf( 'A %s cart item should not be thrown out of WC_Cart::add_to_cart().', $key )
			);
		}
		$this->assertEmpty( $wc_mock_notices );
	}

	/**
	 * The same exemption for a flat group plan bought before per-seat pricing
	 * existed: its line item can carry a quantity above one, and that is the
	 * quantity WooCommerce Subscriptions re-adds to take the payment.
	 */
	public function test_wcs_payment_cart_keeps_a_legacy_flat_group_quantity() {
		global $wc_mock_notices;
		$this->make_flat_product( 953 );
		$cart_item_data = [ 'subscription_renewal' => [ 'subscription_id' => 124 ] ];

		$this->assertTrue( Group_Subscription_Seats::validate_add_to_cart( true, 953, 3, 0, [], $cart_item_data ) );
		$this->assertSame( $cart_item_data, Group_Subscription_Seats::guard_add_cart_item_data( $cart_item_data, 953, 0, 3 ) );
		$this->assertEmpty( $wc_mock_notices );
	}

	/**
	 * The exemption is for what WooCommerce Subscriptions re-adds, not for anything
	 * that happens to carry cart item data: an ordinary add is still held to the
	 * publisher's bounds.
	 */
	public function test_a_plain_add_is_still_held_to_the_bounds() {
		global $wc_mock_notices;
		$this->make_per_seat_product( 954, 2, 5 );

		$this->assertFalse( Group_Subscription_Seats::validate_add_to_cart( true, 954, 10, 0, [], [ 'referer' => 'https://example.com' ] ) );
		$this->assertNotEmpty( $wc_mock_notices );
		$this->assertSame( 'error', $wc_mock_notices[0]['type'] );
	}
}
