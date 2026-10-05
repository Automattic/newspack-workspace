<?php
/**
 * Tests the WooCommerce Update Payment Notice status gate (NPPM-2926, Gap B).
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Update_Payment_Notice;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Tests for WooCommerce_Update_Payment_Notice status allowlist logic.
 *
 * Runs in a separate process: the WC Memberships mock defines a global
 * WC_Memberships class + wc_memberships() function, which would otherwise flip
 * Memberships::is_active() true for the whole PHPUnit process and break
 * unrelated suites (PHP defines/classes can't be unset). The mock is loaded in
 * set_up() — which executes inside this class's isolated child process — rather
 * than at file scope or in set_up_before_class() (both run in the shared parent
 * process and would leak the globals into sibling suites). See the same
 * separate-process convention in
 * reader-activation-sync/class-test-content-gate-legacy.php.
 *
 * @group update_payment_notice
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Newspack_Test_WooCommerce_Update_Payment_Notice extends WP_UnitTestCase {
	/**
	 * Reset the mock subscription registry before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Loaded here (isolated child process), not at file scope or in
		// set_up_before_class() (shared parent), so the global WC_Memberships
		// class / wc_memberships() never leak into unrelated suites.
		require_once __DIR__ . '/../../mocks/wc-memberships-mocks.php';
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
		global $wc_memberships_plans, $wc_memberships_active_memberships, $wc_memberships_plan_subscription_products, $wc_memberships_membership_subscriptions;
		$wc_memberships_plans                      = [];
		$wc_memberships_active_memberships         = [];
		$wc_memberships_plan_subscription_products = [];
		$wc_memberships_membership_subscriptions   = [];
		global $wcs_grouped_parents;
		$wcs_grouped_parents = [];
	}

	/**
	 * Call the private get_notices() via reflection.
	 *
	 * @return string[]
	 */
	private function get_notices() {
		$method = new ReflectionMethod( WooCommerce_Update_Payment_Notice::class, 'get_notices' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * Register a logged-in customer and return its ID.
	 *
	 * @return int
	 */
	private function make_current_customer() {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Build a minimal line item exposing the methods the notice path uses.
	 *
	 * @param int $product_id Product ID.
	 * @return object
	 */
	private function make_line_item( $product_id ) {
		return new class( $product_id ) {
			/**
			 * Product ID this line item refers to.
			 *
			 * @var int
			 */
			private $product_id;

			/**
			 * Constructor.
			 *
			 * @param int $product_id Product ID.
			 */
			public function __construct( $product_id ) {
				$this->product_id = $product_id;
			}

			/**
			 * Return the product ID.
			 *
			 * @return int
			 */
			public function get_product_id() {
				return $this->product_id;
			}

			/**
			 * Return the WC_Product object for this line item.
			 *
			 * @return WC_Product|false
			 */
			public function get_product() {
				return wc_get_product( $this->product_id );
			}
		};
	}

	/**
	 * Register a subscription that needs payment, owned by the current customer.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $status      Subscription status.
	 * @param int    $product_id  Product ID granted by the subscription.
	 * @return WC_Subscription
	 */
	private function make_needs_payment_subscription( $customer_id, $status, $product_id ) {
		return wcs_create_subscription(
			[
				'customer_id'   => $customer_id,
				'status'        => $status,
				'needs_payment' => true,
				'products'      => [ $product_id ],
				'items'         => [ $this->make_line_item( $product_id ) ],
			]
		);
	}

	/**
	 * Statuses that must NOT produce a notice even when needs_payment() is true.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function non_recoverable_status_provider() {
		return [
			'expired'        => [ 'expired' ],
			'switched'       => [ 'switched' ],
			'active'         => [ 'active' ],
			'pending-cancel' => [ 'pending-cancel' ],
			'cancelled'      => [ 'cancelled' ],
		];
	}

	/**
	 * Non-recoverable statuses must not produce a payment notice even if needs_payment() is true.
	 *
	 * @dataProvider non_recoverable_status_provider
	 *
	 * @param string $status Subscription status.
	 */
	public function test_no_notice_for_non_recoverable_status( $status ) {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		$this->make_needs_payment_subscription( $customer_id, $status, $product->get_id() );

		$this->assertSame( [], $this->get_notices(), "Status '$status' must not produce a payment notice." );
	}

	/**
	 * The documented incident: an expired subscription with a stale unpaid renewal order.
	 */
	public function test_no_notice_for_expired_with_stale_unpaid_order() {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product(
			[
				'id'   => 54427,
				'name' => 'Newsroom Pro – Monthly',
			]
		);
		// needs_payment() true models the stale unpaid failed-renewal order still attached.
		$this->make_needs_payment_subscription( $customer_id, 'expired', $product->get_id() );

		$this->assertSame( [], $this->get_notices(), 'An expired subscription with a stale unpaid order must not nag.' );
	}

	/**
	 * Recoverable statuses still fire (regression guard).
	 */
	public function test_notice_fires_for_on_hold() {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		$this->make_needs_payment_subscription( $customer_id, 'on-hold', $product->get_id() );

		$notices = $this->get_notices();
		$this->assertCount( 1, $notices, 'An on-hold subscription that needs payment must produce a notice.' );
		$this->assertStringContainsString( 'Newsroom Pro', $notices[0] );
	}

	/**
	 * A pending subscription that needs payment fires a notice (regression guard).
	 */
	public function test_notice_fires_for_pending() {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		$this->make_needs_payment_subscription( $customer_id, 'pending', $product->get_id() );

		$this->assertCount( 1, $this->get_notices(), 'A pending subscription that needs payment must produce a notice.' );
	}

	/**
	 * A recoverable status that does NOT need payment produces nothing.
	 */
	public function test_no_notice_when_payment_not_needed() {
		$customer_id = $this->make_current_customer();
		$product     = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		wcs_create_subscription(
			[
				'customer_id'   => $customer_id,
				'status'        => 'on-hold',
				'needs_payment' => false,
				'products'      => [ $product->get_id() ],
				'items'         => [ $this->make_line_item( $product->get_id() ) ],
			]
		);

		$this->assertSame( [], $this->get_notices(), 'No notice when the subscription does not need payment.' );
	}

	/**
	 * Pin the recoverable-status allowlist to its reviewed value. This does NOT
	 * auto-detect new WooCommerce Subscriptions statuses — the known set is listed
	 * here, not queried. It fails when the allowlist itself changes, or gains a
	 * status outside the known set, forcing a human to confirm a new status is
	 * genuinely recoverable before it ships. NPPM-2926.
	 */
	public function test_allowlist_is_pinned_against_known_wcs_statuses() {
		$known_wcs_statuses = [ 'pending', 'active', 'on-hold', 'cancelled', 'switched', 'expired', 'pending-cancel' ];

		$reflection = new ReflectionClass( \Newspack\WooCommerce_Update_Payment_Notice::class );
		$allowlist  = $reflection->getConstant( 'NOTICE_RECOVERABLE_STATUSES' );

		$unknown = array_diff( $allowlist, $known_wcs_statuses );
		$this->assertSame( [], array_values( $unknown ), 'Allowlist contains a status not in the known WCS set.' );

		$this->assertEqualSets(
			[ 'on-hold', 'pending' ],
			$allowlist,
			'Allowlist changed. Confirm any new status is genuinely recoverable before updating this guard.'
		);
	}

	/**
	 * Invoke the private Memberships::get_plan_ids_for_product() via reflection.
	 *
	 * @param \WC_Product $product Product.
	 * @return int[]
	 */
	private function get_plan_ids_for_product( $product ) {
		$method = new ReflectionMethod( \Newspack\Memberships::class, 'get_plan_ids_for_product' );
		$method->setAccessible( true );
		return $method->invoke( null, $product );
	}

	/**
	 * A simple product is matched to the plan that grants it.
	 */
	public function test_plan_lookup_matches_simple_product() {
		newspack_register_mock_membership_plan( 700, [ 4242 ] );
		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		$this->assertSame( [ 700 ], $this->get_plan_ids_for_product( $product ) );
	}

	/**
	 * A variation is matched via its parent variable product.
	 */
	public function test_plan_lookup_matches_variation_via_parent() {
		newspack_register_mock_membership_plan( 701, [ 54426 ] );
		$variation = wc_create_mock_product(
			[
				'id'        => 54427,
				'name'      => 'Newsroom Pro – Monthly',
				'parent_id' => 54426,
			]
		);
		$this->assertSame( [ 701 ], $this->get_plan_ids_for_product( $variation ) );
	}

	/**
	 * A simple product (parent_id 0) must never match a plan that grants product 0.
	 */
	public function test_plan_lookup_does_not_query_empty_parent() {
		newspack_register_mock_membership_plan( 702, [ 0 ] );
		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		$this->assertSame( [], $this->get_plan_ids_for_product( $product ) );
	}

	/**
	 * No plan grants the product → empty result.
	 */
	public function test_plan_lookup_empty_when_memberships_have_no_match() {
		newspack_register_mock_membership_plan( 703, [ 9999 ] );
		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		$this->assertSame( [], $this->get_plan_ids_for_product( $product ) );
	}

	/**
	 * Equivalent access is detected via an active membership record.
	 */
	public function test_equivalent_access_via_active_membership() {
		$user_id = self::factory()->user->create();
		newspack_register_mock_membership_plan( 800, [ 4242, 99603 ] );
		global $wc_memberships_active_memberships;
		$wc_memberships_active_memberships[ $user_id ] = [ 800 ];

		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro – Monthly',
			]
		);
		$this->assertTrue( \Newspack\Memberships::user_has_equivalent_active_access( $product, $user_id ) );
	}

	/**
	 * An active membership tied to the *evaluated* subscription is not "other"
	 * access — Layer 3 must skip it so the notice the failing subscription needs
	 * is not falsely suppressed (mirrors Layer 2's active-status self-match
	 * proofing). NPPM-2926.
	 */
	public function test_no_equivalent_access_when_active_membership_is_from_evaluated_subscription() {
		$user_id = self::factory()->user->create();
		newspack_register_mock_membership_plan( 803, [ 4242 ] );
		// The user's only active membership for the plan derives from subscription #555.
		global $wc_memberships_active_memberships, $wc_memberships_membership_subscriptions;
		$wc_memberships_active_memberships[ $user_id ]       = [ 803 ];
		$wc_memberships_membership_subscriptions[ $user_id ] = [ 803 => 555 ];

		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro – Monthly',
			]
		);

		// Excluding #555 (the evaluated subscription) → no equivalent access.
		$this->assertFalse( \Newspack\Memberships::user_has_equivalent_active_access( $product, $user_id, 555 ) );
		// The same membership tied to a *different* subscription still counts.
		$this->assertTrue( \Newspack\Memberships::user_has_equivalent_active_access( $product, $user_id, 999 ) );
	}

	/**
	 * Equivalent access is detected via an active subscription covering the same plan.
	 */
	public function test_equivalent_access_via_active_subscription_for_same_plan() {
		$user_id = self::factory()->user->create();
		// Plan 801 is granted by products 4242 (the offending one's product) and 99603 (the active one's).
		newspack_register_mock_membership_plan( 801, [ 4242, 99603 ] );
		// User holds an ACTIVE subscription on the *other* product (99603), no active membership record.
		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 99603 ],
			]
		);

		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro – Monthly',
			]
		);
		$this->assertTrue( \Newspack\Memberships::user_has_equivalent_active_access( $product, $user_id ) );
	}

	/**
	 * No equivalent access when the user has neither an active membership nor subscription.
	 */
	public function test_no_equivalent_access_when_neither_membership_nor_subscription() {
		$user_id = self::factory()->user->create();
		newspack_register_mock_membership_plan( 802, [ 4242, 99603 ] );

		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro – Monthly',
			]
		);
		$this->assertFalse( \Newspack\Memberships::user_has_equivalent_active_access( $product, $user_id ) );
	}

	/**
	 * No equivalent access when no membership plans are registered.
	 */
	public function test_no_equivalent_access_when_no_plans_registered() {
		// With no registered plans, get_plan_ids_for_product() returns [] and the
		// helper must return false (fallback to Layer 1 only).
		$user_id = self::factory()->user->create();
		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		$this->assertFalse( \Newspack\Memberships::user_has_equivalent_active_access( $product, $user_id ) );
	}

	/**
	 * An active membership for the same plan suppresses the payment notice.
	 */
	public function test_no_notice_when_equivalent_membership_access_exists() {
		$customer_id = $this->make_current_customer();
		// Two different products granting the same plan; reader holds active membership.
		newspack_register_mock_membership_plan( 900, [ 4242, 99603 ] );
		global $wc_memberships_active_memberships;
		$wc_memberships_active_memberships[ $customer_id ] = [ 900 ];
		wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro – Monthly',
			]
		);
		// The offending, recoverable subscription on product 4242.
		$this->make_needs_payment_subscription( $customer_id, 'on-hold', 4242 );

		$this->assertSame( [], $this->get_notices(), 'Equivalent active membership must suppress the notice.' );
	}

	/**
	 * An active subscription for the same plan suppresses the payment notice.
	 */
	public function test_no_notice_when_equivalent_active_subscription_exists() {
		$customer_id = $this->make_current_customer();
		newspack_register_mock_membership_plan( 901, [ 4242, 99603 ] );
		wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro – Monthly',
			]
		);
		// Active subscription on the *other* product 99603 grants the same plan.
		wcs_create_subscription(
			[
				'customer_id' => $customer_id,
				'status'      => 'active',
				'products'    => [ 99603 ],
			]
		);
		// The offending, recoverable subscription on product 4242.
		$this->make_needs_payment_subscription( $customer_id, 'on-hold', 4242 );

		$this->assertSame( [], $this->get_notices(), 'An active subscription for the same plan must suppress the notice.' );
	}

	/**
	 * Without equivalent access the payment notice still fires (regression guard).
	 */
	public function test_notice_still_fires_without_equivalent_access() {
		$customer_id = $this->make_current_customer();
		// Plan exists but the reader has neither active membership nor an equivalent active sub.
		newspack_register_mock_membership_plan( 902, [ 4242, 99603 ] );
		wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro – Monthly',
			]
		);
		$this->make_needs_payment_subscription( $customer_id, 'on-hold', 4242 );

		$this->assertCount( 1, $this->get_notices(), 'No equivalent access — the notice must still fire.' );
	}

	/**
	 * A product belonging to a grouped product is matched to a plan granting the grouped parent.
	 */
	public function test_plan_lookup_matches_grouped_parent() {
		newspack_register_mock_membership_plan( 704, [ 8000 ] );
		global $wcs_grouped_parents;
		$product = wc_create_mock_product(
			[
				'id'   => 8001,
				'name' => 'Grouped child',
			]
		);
		$wcs_grouped_parents[8001] = [ 8000 ];
		$this->assertSame( [ 704 ], $this->get_plan_ids_for_product( $product ) );
	}

	/**
	 * A variation whose parent belongs to a grouped product is matched to a plan
	 * granting that grouped parent. WC_Subscriptions_Product::get_parent_ids()
	 * resolves grouped parents by matching the grouped product's `_children` meta,
	 * which holds top-level product IDs and never variation IDs -- so the grouped
	 * lookup only finds anything when it runs against the resolved parent.
	 */
	public function test_plan_lookup_matches_grouped_parent_of_variation() {
		newspack_register_mock_membership_plan( 705, [ 8100 ] );
		global $wcs_grouped_parents;
		wc_create_mock_product(
			[
				'id'   => 8101,
				'name' => 'Grouped child (variable)',
			]
		);
		$variation = wc_create_mock_product(
			[
				'id'        => 8102,
				'name'      => 'Grouped child – Monthly',
				'parent_id' => 8101,
			]
		);
		// The grouped parent is registered against the top-level product only,
		// as WC Subscriptions does -- the variation resolves to nothing.
		$wcs_grouped_parents[8101] = [ 8100 ];
		$this->assertSame( [ 705 ], $this->get_plan_ids_for_product( $variation ) );
	}

	/**
	 * When a product grants several plans and only some are still active, the
	 * reader must keep seeing the notice: paying is still required to restore
	 * the uncovered plan.
	 */
	public function test_no_equivalent_access_when_only_some_plans_are_covered() {
		$user_id = self::factory()->user->create();
		newspack_register_mock_membership_plan( 810, [ 4242 ] );
		newspack_register_mock_membership_plan( 811, [ 4242 ] );
		// Plan 810 is still active; plan 811 is not covered by either layer.
		global $wc_memberships_active_memberships;
		$wc_memberships_active_memberships[ $user_id ] = [ 810 ];
		$product                                       = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		$this->assertFalse( \Newspack\Memberships::user_has_equivalent_active_access( $product, $user_id ) );
	}

	/**
	 * When every plan a product grants is still covered -- here one by an active
	 * membership and the other by a separate active subscription -- access is
	 * equivalent and the notice is suppressed.
	 */
	public function test_equivalent_access_when_all_plans_are_covered() {
		$user_id = self::factory()->user->create();
		newspack_register_mock_membership_plan( 812, [ 4242 ] );
		newspack_register_mock_membership_plan( 813, [ 4242, 99603 ] );
		global $wc_memberships_active_memberships;
		$wc_memberships_active_memberships[ $user_id ] = [ 812 ];
		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'active',
				'products'    => [ 99603 ],
			]
		);
		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro',
			]
		);
		$this->assertTrue( \Newspack\Memberships::user_has_equivalent_active_access( $product, $user_id ) );
	}

	/**
	 * A pending-cancel subscription (still has access until period end) counts as equivalent access.
	 */
	public function test_equivalent_access_via_pending_cancel_subscription() {
		$user_id = self::factory()->user->create();
		newspack_register_mock_membership_plan( 803, [ 4242, 99603 ] );
		wcs_create_subscription(
			[
				'customer_id' => $user_id,
				'status'      => 'pending-cancel',
				'products'    => [ 99603 ],
			]
		);
		$product = wc_create_mock_product(
			[
				'id'   => 4242,
				'name' => 'Newsroom Pro – Monthly',
			]
		);
		$this->assertTrue( \Newspack\Memberships::user_has_equivalent_active_access( $product, $user_id ) );
	}

	/**
	 * Variation-accuracy guard: when a plan grants ONLY the variation (not its
	 * parent), suppression must use the variation-accurate $line_item->get_product()
	 * and not wc_get_product( get_product_id() ) (which returns the parent).
	 */
	public function test_no_notice_for_variation_when_plan_grants_the_variation() {
		$customer_id = $this->make_current_customer();
		// Plan grants ONLY the variation (54427), not its parent (54426).
		newspack_register_mock_membership_plan( 903, [ 54427 ] );
		global $wc_memberships_active_memberships;
		$wc_memberships_active_memberships[ $customer_id ] = [ 903 ];
		wc_create_mock_product(
			[
				'id'   => 54426,
				'name' => 'Newsroom Pro – Monthly (variable)',
			]
		);
		wc_create_mock_product(
			[
				'id'        => 54427,
				'name'      => 'Newsroom Pro – Monthly',
				'parent_id' => 54426,
			]
		);
		// Line item: get_product_id() is the PARENT (54426); get_product() is the VARIATION (54427).
		$line_item = new class() {
			/**
			 * Return the parent product ID (54426), not the variation.
			 *
			 * @return int
			 */
			public function get_product_id() {
				return 54426;
			}
			/**
			 * Return the variation product object (54427).
			 *
			 * @return WC_Product|false
			 */
			public function get_product() {
				return wc_get_product( 54427 );
			}
		};
		wcs_create_subscription(
			[
				'customer_id'   => $customer_id,
				'status'        => 'on-hold',
				'needs_payment' => true,
				'products'      => [ 54426 ],
				'items'         => [ $line_item ],
			]
		);

		$this->assertSame( [], $this->get_notices(), 'A variation-only plan must suppress via the variation-accurate product.' );
	}
}
