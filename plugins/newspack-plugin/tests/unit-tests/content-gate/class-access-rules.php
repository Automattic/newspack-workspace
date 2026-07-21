<?php
/**
 * Tests the Access Rules class with group subscription support.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Rules;
use Newspack\Content_Gate;
use Newspack\Group_Subscription;
use Newspack\Reader_Activation;
use Newspack\WooCommerce_Connection;

/**
 * Test Access Rules functionality.
 *
 * @group Access_Rules
 */
class Newspack_Test_Access_Rules extends WP_UnitTestCase {
	/**
	 * Test user ID for the subscription owner.
	 *
	 * @var int
	 */
	private static $owner_user_id;

	/**
	 * Test user ID for a group member.
	 *
	 * @var int
	 */
	private static $member_user_id;

	/**
	 * Test user ID for a non-member.
	 *
	 * @var int
	 */
	private static $non_member_user_id;

	/**
	 * Test subscription ID.
	 *
	 * @var int
	 */
	private static $subscription_id = 100;

	/**
	 * Test product ID.
	 *
	 * @var int
	 */
	private static $product_id = 50;

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Include WC mocks.
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Reset the subscriptions database.
		global $subscriptions_database;
		$subscriptions_database = [];

		// Create test users.
		self::$owner_user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		self::$member_user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		self::$non_member_user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		// Mark users as readers.
		update_user_meta( self::$owner_user_id, 'np_reader', true );
		update_user_meta( self::$member_user_id, 'np_reader', true );
		update_user_meta( self::$non_member_user_id, 'np_reader', true );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		parent::tear_down();

		// Clean up user meta.
		delete_user_meta( self::$member_user_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY );
	}

	/**
	 * Helper to create a test subscription.
	 *
	 * @param array $args Subscription arguments.
	 * @return WC_Subscription
	 */
	private function create_subscription( $args = [] ) {
		$defaults = [
			'id'               => self::$subscription_id,
			'customer_id'      => self::$owner_user_id,
			'status'           => 'active',
			'total'            => 10,
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'products'         => [ self::$product_id ],
			'dates'            => [
				'start' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 month' ) ),
			],
		];

		return wcs_create_subscription( array_merge( $defaults, $args ) );
	}

	/**
	 * Helper to enable group subscription for a subscription.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 */
	private function enable_group_subscription( $subscription ) {
		$subscription->update_meta_data( '_newspack_group_subscription_enabled', 'yes' );
		$subscription->update_meta_data( '_newspack_group_subscription_limit', 10 );
	}

	/**
	 * Helper to add a user as a group member.
	 *
	 * @param int $user_id The user ID.
	 * @param int $subscription_id The subscription ID.
	 */
	private function add_group_member( $user_id, $subscription_id ) {
		add_user_meta( $user_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription_id );
	}

	/**
	 * Test that subscription owner has access via their own subscription.
	 */
	public function test_owner_has_access_via_own_subscription() {
		$subscription = $this->create_subscription();

		$has_access = Access_Rules::has_active_subscription( self::$owner_user_id, [ self::$product_id ] );

		$this->assertTrue( $has_access, 'Subscription owner should have access via their own subscription.' );
	}

	/**
	 * Test that group member has access via group subscription.
	 */
	public function test_group_member_has_access_via_group_subscription() {
		$subscription = $this->create_subscription();
		$this->enable_group_subscription( $subscription );
		$this->add_group_member( self::$member_user_id, $subscription->get_id() );

		$has_access = Access_Rules::has_active_subscription( self::$member_user_id, [ self::$product_id ] );

		$this->assertTrue( $has_access, 'Group member should have access via group subscription.' );
	}

	/**
	 * Test that non-member does not have access.
	 */
	public function test_non_member_does_not_have_access() {
		$subscription = $this->create_subscription();
		$this->enable_group_subscription( $subscription );

		$has_access = Access_Rules::has_active_subscription( self::$non_member_user_id, [ self::$product_id ] );

		$this->assertFalse( $has_access, 'Non-member should not have access.' );
	}

	/**
	 * Test that group member does not have access if subscription is inactive.
	 */
	public function test_group_member_no_access_if_subscription_inactive() {
		$subscription = $this->create_subscription( [ 'status' => 'cancelled' ] );
		$this->enable_group_subscription( $subscription );
		$this->add_group_member( self::$member_user_id, $subscription->get_id() );

		$has_access = Access_Rules::has_active_subscription( self::$member_user_id, [ self::$product_id ] );

		$this->assertFalse( $has_access, 'Group member should not have access if subscription is inactive.' );
	}

	/**
	 * Test that group member does not have access if subscription has wrong product.
	 */
	public function test_group_member_no_access_if_wrong_product() {
		$subscription = $this->create_subscription( [ 'products' => [ 999 ] ] );
		$this->enable_group_subscription( $subscription );
		$this->add_group_member( self::$member_user_id, $subscription->get_id() );

		$has_access = Access_Rules::has_active_subscription( self::$member_user_id, [ self::$product_id ] );

		$this->assertFalse( $has_access, 'Group member should not have access if subscription has wrong product.' );
	}

	/**
	 * Test that group member has access with empty product filter (any subscription).
	 */
	public function test_group_member_has_access_with_empty_product_filter() {
		$subscription = $this->create_subscription();
		$this->enable_group_subscription( $subscription );
		$this->add_group_member( self::$member_user_id, $subscription->get_id() );

		$has_access = Access_Rules::has_active_subscription( self::$member_user_id, [] );

		$this->assertTrue( $has_access, 'Group member should have access when no product filter is specified.' );
	}

	/**
	 * Test evaluate_rules passes user_id to rule callbacks.
	 */
	public function test_evaluate_rules_with_explicit_user_id() {
		// Register a simple test rule that checks user meta.
		Access_Rules::register_rule(
			[
				'id'       => 'test_meta_rule',
				'name'     => 'Test meta rule',
				'callback' => function( $user_id, $args ) {
					return (bool) get_user_meta( $user_id, $args, true );
				},
			]
		);

		// Set meta on member but not on non-member.
		update_user_meta( self::$member_user_id, 'test_gate_pass', '1' );

		$rules = [
			[
				[
					'slug'  => 'test_meta_rule',
					'value' => 'test_gate_pass',
				],
			],
		];

		// Member should pass.
		$this->assertTrue(
			Access_Rules::evaluate_rules( $rules, self::$member_user_id ),
			'User with matching meta should pass evaluate_rules.'
		);

		// Non-member should fail.
		$this->assertFalse(
			Access_Rules::evaluate_rules( $rules, self::$non_member_user_id ),
			'User without matching meta should fail evaluate_rules.'
		);
	}

	/**
	 * Test evaluate_rules defaults to current user when no user_id is passed.
	 */
	public function test_evaluate_rules_defaults_to_current_user() {
		Access_Rules::register_rule(
			[
				'id'       => 'test_current_user_rule',
				'name'     => 'Test current user rule',
				'callback' => function( $user_id, $args ) {
					return $user_id === (int) $args;
				},
			]
		);

		wp_set_current_user( self::$member_user_id );

		$rules = [
			[
				[
					'slug'  => 'test_current_user_rule',
					'value' => (string) self::$member_user_id,
				],
			],
		];

		// Should pass using current user (no user_id argument).
		$this->assertTrue(
			Access_Rules::evaluate_rules( $rules ),
			'evaluate_rules should default to current user when no user_id is passed.'
		);
	}

	/**
	 * Test pending-cancel status still grants access.
	 */
	public function test_pending_cancel_status_grants_access() {
		$subscription = $this->create_subscription( [ 'status' => 'pending-cancel' ] );
		$this->enable_group_subscription( $subscription );
		$this->add_group_member( self::$member_user_id, $subscription->get_id() );

		$has_access = Access_Rules::has_active_subscription( self::$member_user_id, [ self::$product_id ] );

		$this->assertTrue( $has_access, 'Group member should have access with pending-cancel subscription.' );
	}

	// =========================================================================
	// evaluate_rules() with explicit $user_id — via built-in subscription rule
	// =========================================================================

	/**
	 * Test that evaluate_rules() routes to the correct user when an explicit
	 * $user_id is passed, using the built-in subscription rule type.
	 * (Complements the custom-callback variant in test_evaluate_rules_with_explicit_user_id.)
	 */
	public function test_evaluate_rules_respects_explicit_user_id() {
		$this->create_subscription();

		$access_rules = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ self::$product_id ],
				],
			],
		];

		$this->assertTrue(
			Access_Rules::evaluate_rules( $access_rules, self::$owner_user_id ),
			'evaluate_rules should return true for the subscription owner when called with their user ID.'
		);

		$this->assertFalse(
			Access_Rules::evaluate_rules( $access_rules, self::$non_member_user_id ),
			'evaluate_rules should return false for a non-member when called with their user ID.'
		);
	}

	/**
	 * Test that evaluate_rules() falls back to the current user when $user_id
	 * is null, using the built-in subscription rule type.
	 * (Complements the custom-callback variant in test_evaluate_rules_defaults_to_current_user.)
	 */
	public function test_evaluate_rules_defaults_to_current_user_when_user_id_is_null() {
		$this->create_subscription();

		$access_rules = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ self::$product_id ],
				],
			],
		];

		wp_set_current_user( self::$owner_user_id );
		$this->assertTrue(
			Access_Rules::evaluate_rules( $access_rules, null ),
			'evaluate_rules should return true for the subscription owner when they are the current user.'
		);

		wp_set_current_user( self::$non_member_user_id );
		$this->assertFalse(
			Access_Rules::evaluate_rules( $access_rules, null ),
			'evaluate_rules should return false for a non-member when they are the current user.'
		);

		wp_set_current_user( 0 );
	}

	// =========================================================================
	// Payment-recovery grace (NPPD-2052): on-hold subscriptions inside the Woo
	// Subscriptions failed-payment retry window keep granting access.
	// =========================================================================

	/**
	 * Test that an owner keeps access while their on-hold subscription has a
	 * future payment retry scheduled (the dunning window), by default.
	 */
	public function test_owner_keeps_access_during_payment_recovery() {
		$this->create_subscription(
			[
				'status' => 'on-hold',
				'times'  => [ 'payment_retry' => time() + HOUR_IN_SECONDS ],
			]
		);

		$has_access = Access_Rules::has_active_subscription( self::$owner_user_id, [ self::$product_id ] );

		$this->assertTrue( $has_access, 'Owner should keep access while a payment retry is scheduled for their on-hold subscription.' );
	}

	/**
	 * Test that an on-hold subscription with no scheduled payment retry does
	 * not grant access — the retry window has passed or never existed.
	 */
	public function test_owner_denied_when_on_hold_without_scheduled_retry() {
		$this->create_subscription( [ 'status' => 'on-hold' ] );

		$has_access = Access_Rules::has_active_subscription( self::$owner_user_id, [ self::$product_id ] );

		$this->assertFalse( $has_access, 'Owner should not have access when their on-hold subscription has no scheduled payment retry.' );
	}

	/**
	 * Test that an on-hold subscription with a payment retry scheduled in the
	 * past does not grant access.
	 */
	public function test_owner_denied_when_retry_date_is_past() {
		$this->create_subscription(
			[
				'status' => 'on-hold',
				'times'  => [ 'payment_retry' => time() - HOUR_IN_SECONDS ],
			]
		);

		$has_access = Access_Rules::has_active_subscription( self::$owner_user_id, [ self::$product_id ] );

		$this->assertFalse( $has_access, 'Owner should not have access when the last scheduled payment retry is in the past.' );
	}

	/**
	 * Test that the per-gate `payment_recovery_grace` setting disables the
	 * grace when evaluated with it off, and grants with it on.
	 */
	public function test_payment_recovery_grace_setting_controls_access() {
		$this->create_subscription(
			[
				'status' => 'on-hold',
				'times'  => [ 'payment_retry' => time() + HOUR_IN_SECONDS ],
			]
		);

		$subscription_access_rules = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ self::$product_id ],
				],
			],
		];

		$this->assertFalse(
			Access_Rules::evaluate_rules( $subscription_access_rules, self::$owner_user_id, [ 'payment_recovery_grace' => false ] ),
			'Grace disabled: an on-hold subscription in the retry window should not grant access.'
		);

		$this->assertTrue(
			Access_Rules::evaluate_rules( $subscription_access_rules, self::$owner_user_id, [ 'payment_recovery_grace' => true ] ),
			'Grace enabled: an on-hold subscription in the retry window should grant access.'
		);

		$this->assertTrue(
			Access_Rules::evaluate_rules( $subscription_access_rules, self::$owner_user_id ),
			'No context given: the grace should default to ON.'
		);
	}

	/**
	 * Test that the evaluation context does not leak out of evaluate_rules —
	 * a later call without context must fall back to the default (grace ON).
	 */
	public function test_evaluation_context_does_not_leak_between_calls() {
		$this->create_subscription(
			[
				'status' => 'on-hold',
				'times'  => [ 'payment_retry' => time() + HOUR_IN_SECONDS ],
			]
		);

		$subscription_access_rules = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ self::$product_id ],
				],
			],
		];

		Access_Rules::evaluate_rules( $subscription_access_rules, self::$owner_user_id, [ 'payment_recovery_grace' => false ] );

		$this->assertTrue(
			Access_Rules::evaluate_rules( $subscription_access_rules, self::$owner_user_id ),
			'A previous grace-off evaluation must not leak into a later default evaluation.'
		);
	}

	/**
	 * Test that a group member keeps access while the group subscription is in
	 * payment recovery.
	 */
	public function test_group_member_keeps_access_during_payment_recovery() {
		$subscription = $this->create_subscription(
			[
				'status' => 'on-hold',
				'times'  => [ 'payment_retry' => time() + HOUR_IN_SECONDS ],
			]
		);
		$this->enable_group_subscription( $subscription );
		$this->add_group_member( self::$member_user_id, $subscription->get_id() );

		$has_access = Access_Rules::has_active_subscription( self::$member_user_id, [ self::$product_id ] );

		$this->assertTrue( $has_access, 'Group member should keep access while the group subscription is in payment recovery.' );
	}

	/**
	 * Test that gates saved before the setting existed default to grace ON,
	 * and that a stored `false` is respected.
	 */
	public function test_custom_access_settings_payment_recovery_grace_default() {
		$legacy_gate_id = wp_insert_post(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_title'  => 'Legacy Gate',
				'post_status' => 'publish',
			]
		);

		// Simulate a gate saved before the setting existed.
		update_post_meta( $legacy_gate_id, 'custom_access', [ 'active' => true ] );
		$legacy_settings = Content_Gate::get_custom_access_settings( $legacy_gate_id );
		$this->assertTrue( $legacy_settings['payment_recovery_grace'], 'Gates lacking the setting key should default to grace ON.' );

		update_post_meta(
			$legacy_gate_id,
			'custom_access',
			[
				'active'                 => true,
				'payment_recovery_grace' => false,
			]
		);
		$opted_out_settings = Content_Gate::get_custom_access_settings( $legacy_gate_id );
		$this->assertFalse( $opted_out_settings['payment_recovery_grace'], 'A stored false must be respected.' );
	}
}
