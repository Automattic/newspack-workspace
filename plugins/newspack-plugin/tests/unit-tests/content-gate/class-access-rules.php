<?php
/**
 * Tests the Access Rules class with group subscription support.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Rules;
use Newspack\Block_Visibility;
use Newspack\Content_Gate;
use Newspack\Content_Gate_API;
use Newspack\Content_Restriction_Control;
use Newspack\Group_Subscription;
use Newspack\Reader_Activation;
use Newspack\User_Gate_Access;
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
		// Gating is flag-gated, and these tests exercise enforcement. Defined here
		// rather than relied on from another class: constants are process-wide, so
		// without this the group passes only when an alphabetically-earlier class
		// happens to define it first, and `n test-php --group ...` fails on its own.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}

		// Include WC mocks.
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Reset the subscriptions and products databases.
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];

		// Create test users.
		self::$owner_user_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		self::$member_user_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
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
	 * A `$product_ids` value that isn't an array is configuration nobody can
	 * interpret — a free-text string saved before rule values were validated. It
	 * must deny rather than fall through to "any subscription qualifies", which is
	 * what an empty value legitimately means. `'0'` and `0` are included because
	 * an `empty()` guard would wave them through.
	 */
	public function test_has_active_subscription_fails_closed_for_a_malformed_product_filter() {
		$this->create_subscription();

		$this->assertTrue(
			Access_Rules::has_active_subscription( self::$owner_user_id, [] ),
			'Premise: with no product filter, this reader\'s active subscription qualifies.'
		);

		foreach ( [ 'Premium Membership', '0', 0 ] as $malformed_product_ids ) {
			$this->assertFalse(
				Access_Rules::has_active_subscription( self::$owner_user_id, $malformed_product_ids ),
				'A non-array product filter must deny even a reader who has an active subscription.'
			);
		}
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
	 * Test that an on-hold subscription with no payment retry date does not
	 * grant access. Woo Subscriptions deletes the date once a retry resolves
	 * without a successor, so its absence means retries are done (or the retry
	 * system never engaged) and the recovery window is closed.
	 */
	public function test_owner_denied_when_on_hold_without_scheduled_retry() {
		$this->create_subscription( [ 'status' => 'on-hold' ] );

		$has_access = Access_Rules::has_active_subscription( self::$owner_user_id, [ self::$product_id ] );

		$this->assertFalse( $has_access, 'Owner should not have access when their on-hold subscription has no scheduled payment retry.' );
	}

	/**
	 * Test that an overdue payment retry still grants access. Action Scheduler
	 * can run minutes or hours behind on a busy site; the retry date outliving
	 * its due time means the retry has not run yet, not that recovery ended —
	 * and denying here would gate the reader at exactly the boundary this grace
	 * exists to cover.
	 */
	public function test_owner_keeps_access_when_retry_is_overdue() {
		$this->create_subscription(
			[
				'status' => 'on-hold',
				'times'  => [ 'payment_retry' => time() - HOUR_IN_SECONDS ],
			]
		);

		$has_access = Access_Rules::has_active_subscription( self::$owner_user_id, [ self::$product_id ] );

		$this->assertTrue( $has_access, 'Owner should keep access while an overdue payment retry is still pending.' );
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

	/**
	 * Reset Content_Restriction_Control's static per-post caches so consecutive
	 * is_post_restricted() calls in one test re-read gate settings.
	 */
	private function reset_restriction_cache() {
		foreach ( [ 'post_gate_id_map', 'post_gate_layout_id_map', 'post_gates_map', 'term_descendants_map' ] as $static_cache_prop ) {
			$reflection_prop = new \ReflectionProperty( Content_Restriction_Control::class, $static_cache_prop );
			$reflection_prop->setAccessible( true );
			$reflection_prop->setValue( null, [] );
		}
	}

	/**
	 * Call-site plumbing test: the front-end restriction path must build its
	 * evaluation context from the gate's STORED `payment_recovery_grace`
	 * setting. Every fallback in the chain is grace-ON, so if a call site
	 * dropped its context argument the engine tests would still pass while the
	 * publisher's off-switch silently stopped working — this test pins it.
	 */
	public function test_stored_grace_off_restricts_via_content_restriction_control() {
		// Reader's subscription is on-hold inside the retry window.
		$this->create_subscription(
			[
				'status' => 'on-hold',
				'times'  => [ 'payment_retry' => time() + HOUR_IN_SECONDS ],
			]
		);

		$gated_post_id = $this->factory->post->create( [ 'post_type' => 'post' ] );

		// create_gate() (rather than a bare wp_insert_post) so the gate gets its
		// default layouts — is_post_restricted() only records a restriction when
		// the gate resolves a layout to render.
		$plumbing_gate_id = Content_Gate::create_gate( [ 'title' => 'Plumbing Gate' ] );
		Content_Gate::update_gate_settings(
			$plumbing_gate_id,
			[
				'status'        => 'publish',
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'custom_access' => [
					'active'                 => true,
					'access_rules'           => [
						[
							[
								'slug'  => 'subscription',
								'value' => [ self::$product_id ],
							],
						],
					],
					'payment_recovery_grace' => false,
				],
			]
		);

		$this->reset_restriction_cache();
		$restricted_with_grace_off = Content_Restriction_Control::is_post_restricted( false, $gated_post_id, self::$owner_user_id );
		$this->assertTrue(
			$restricted_with_grace_off,
			'A gate with stored payment_recovery_grace=false must restrict an on-hold-in-retry reader through the front-end path.'
		);

		// Flip only the stored setting; the same reader must now pass — proving
		// the call site reads the stored value rather than a hardcoded default.
		Content_Gate::update_custom_access_settings( $plumbing_gate_id, [ 'payment_recovery_grace' => true ] );
		$this->reset_restriction_cache();
		$restricted_with_grace_on = Content_Restriction_Control::is_post_restricted( false, $gated_post_id, self::$owner_user_id );
		$this->assertFalse(
			$restricted_with_grace_on,
			'Flipping the stored setting to grace-ON must let the same on-hold-in-retry reader through.'
		);

		wp_delete_post( $plumbing_gate_id, true );
	}

	/**
	 * Create a published gate whose Paid access rules require the test product
	 * and whose payment-recovery grace is stored as OFF.
	 *
	 * @param string $title Gate title.
	 * @return int Gate ID.
	 */
	private function create_grace_off_gate( $title ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		update_post_meta(
			$gate_id,
			'custom_access',
			[
				'active'                 => true,
				'access_rules'           => [
					[
						[
							'slug'  => 'subscription',
							'value' => [ self::$product_id ],
						],
					],
				],
				'payment_recovery_grace' => false,
			]
		);
		return $gate_id;
	}

	/**
	 * Call-site plumbing test, member-content block path: a block gated by a
	 * gate with stored grace OFF must stay hidden from an on-hold-in-retry
	 * reader, and appear once the stored setting is flipped ON.
	 *
	 * Same rationale as the front-end restriction plumbing test: every fallback
	 * in the chain is grace-ON, so this call site dropping its context argument
	 * would leave the engine tests green while the publisher's off-switch did
	 * nothing here.
	 */
	public function test_stored_grace_off_hides_block_via_block_visibility() {
		$this->create_subscription(
			[
				'status' => 'on-hold',
				'times'  => [ 'payment_retry' => time() + HOUR_IN_SECONDS ],
			]
		);

		$plumbing_gate_id = $this->create_grace_off_gate( 'Block Plumbing Gate' );
		$block            = [
			'blockName' => 'core/group',
			'attrs'     => [
				'newspackAccessControlMode'    => 'gate',
				'newspackAccessControlGateIds' => [ $plumbing_gate_id ],
			],
			'innerHTML' => '<div>members only</div>',
		];

		wp_set_current_user( self::$owner_user_id );

		Block_Visibility::reset_cache_for_tests();
		$this->assertSame(
			'',
			Block_Visibility::filter_render_block( '<div>members only</div>', $block ),
			'A gate with stored payment_recovery_grace=false must hide its gated block from an on-hold-in-retry reader.'
		);

		// Flip only the stored setting; the same reader must now see the block.
		Content_Gate::update_custom_access_settings( $plumbing_gate_id, [ 'payment_recovery_grace' => true ] );
		Block_Visibility::reset_cache_for_tests();
		$this->assertSame(
			'<div>members only</div>',
			Block_Visibility::filter_render_block( '<div>members only</div>', $block ),
			'Flipping the stored setting to grace-ON must reveal the block to the same reader.'
		);

		Block_Visibility::reset_cache_for_tests();
		wp_set_current_user( 0 );
		wp_delete_post( $plumbing_gate_id, true );
	}

	/**
	 * Call-site plumbing test, user-profile panel path: the gate-bypass report
	 * shown on a reader's wp-admin profile must reflect the gate's stored grace
	 * setting rather than the grace-ON default, so it doesn't tell an admin the
	 * reader can bypass a gate that in fact restricts them.
	 */
	public function test_stored_grace_off_denies_bypass_via_user_gate_access() {
		$this->create_subscription(
			[
				'status' => 'on-hold',
				'times'  => [ 'payment_retry' => time() + HOUR_IN_SECONDS ],
			]
		);

		$plumbing_gate_id = $this->create_grace_off_gate( 'Profile Panel Plumbing Gate' );

		$evaluation_with_grace_off = User_Gate_Access::evaluate_gate_for_user( Content_Gate::get_gate( $plumbing_gate_id ), self::$owner_user_id );
		$this->assertFalse(
			$evaluation_with_grace_off['can_bypass'],
			'A gate with stored payment_recovery_grace=false must report no bypass for an on-hold-in-retry reader.'
		);

		// Flip only the stored setting; the same reader must now be reported as bypassing.
		Content_Gate::update_custom_access_settings( $plumbing_gate_id, [ 'payment_recovery_grace' => true ] );
		$evaluation_with_grace_on = User_Gate_Access::evaluate_gate_for_user( Content_Gate::get_gate( $plumbing_gate_id ), self::$owner_user_id );
		$this->assertTrue(
			$evaluation_with_grace_on['can_bypass'],
			'Flipping the stored setting to grace-ON must report the same reader as bypassing the gate.'
		);

		wp_delete_post( $plumbing_gate_id, true );
	}

	/**
	 * Create a real `product_variation` post, the way WooCommerce stores one: the generated
	 * title in `post_title` and the attribute summary in `post_excerpt`.
	 *
	 * The variation options are read from the post rows rather than from hydrated products,
	 * so these have to be real posts for the tests to exercise the query that ships.
	 *
	 * @param int    $parent_id The variable subscription's product ID.
	 * @param string $title     The variation's generated title.
	 * @param string $summary   The attribute summary, if any.
	 * @param string $status    The post status.
	 *
	 * @return int The variation post ID.
	 */
	private function create_variation_post( $parent_id, $title, $summary = '', $status = 'publish' ) {
		return $this->factory->post->create(
			[
				'post_type'    => 'product_variation',
				'post_parent'  => $parent_id,
				'post_title'   => $title,
				'post_excerpt' => $summary,
				'post_status'  => $status,
			]
		);
	}

	/**
	 * A variable subscription's variations are selectable in their own right, so a gate can
	 * require one tier of it without requiring the others.
	 *
	 * The rule already evaluates variation IDs — `WC_Subscription::has_product()` matches a
	 * line item's `variation_id` as well as its `product_id` — so leaving them out of the
	 * options made a rule the system honours impossible to configure, or to read back once
	 * migrated data had put one in a gate.
	 *
	 * @group Access_Rules
	 */
	public function test_get_subscription_products_options_includes_variations() {
		wc_create_mock_product(
			[
				'id'   => 900,
				'type' => 'subscription',
				'name' => 'Supporter',
			]
		);
		wc_create_mock_product(
			[
				'id'   => 901,
				'type' => 'variable-subscription',
				'name' => 'Membership',
			]
		);
		$monthly_variation_id = $this->create_variation_post( 901, 'Membership - Monthly' );
		$annual_variation_id  = $this->create_variation_post( 901, 'Membership - Annual' );

		$options_by_value = array_column( Access_Rules::get_subscription_products_options(), 'label', 'value' );

		$this->assertSame(
			[
				900                   => 'Supporter',
				901                   => 'Membership',
				$monthly_variation_id => 'Membership - Monthly',
				$annual_variation_id  => 'Membership - Annual',
			],
			$options_by_value,
			'Options should list simple subscriptions, variable subscription parents, and each parent\'s variations.'
		);
	}

	/**
	 * A private variation is listed, a draft one is not.
	 *
	 * A publisher can hide a tier without the readers still paying for it losing their
	 * subscription, so a rule has to be able to name it. A draft variation has never been
	 * purchasable, so listing it would only offer a rule that matches nothing.
	 *
	 * @group Access_Rules
	 */
	public function test_get_subscription_products_options_includes_private_variations_only() {
		wc_create_mock_product(
			[
				'id'   => 910,
				'type' => 'variable-subscription',
				'name' => 'Membership',
			]
		);
		$private_variation_id = $this->create_variation_post( 910, 'Membership - Retired', '', 'private' );
		$this->create_variation_post( 910, 'Membership - Draft', '', 'draft' );

		$values = array_column( Access_Rules::get_subscription_products_options(), 'value' );

		$this->assertSame( [ 910, $private_variation_id ], $values, 'A private variation should be listed; a draft one should not.' );
	}

	/**
	 * A variation belonging to a product that is not a variable subscription is not listed,
	 * so an unrelated variable product's tiers cannot leak into the subscription rule.
	 *
	 * @group Access_Rules
	 */
	public function test_get_subscription_products_options_ignores_non_subscription_variations() {
		wc_create_mock_product(
			[
				'id'   => 915,
				'type' => 'variable',
				'name' => 'Tote bag',
			]
		);
		$this->create_variation_post( 915, 'Tote bag - Large' );

		$values = array_column( Access_Rules::get_subscription_products_options(), 'value' );

		$this->assertSame( [], $values, 'A plain variable product and its variations should not be listed.' );
	}

	/**
	 * WooCommerce drops the attribute suffix from a variation's generated title when the
	 * parent carries three or more attributes (or two or more where an attribute name is
	 * multi-word), leaving the variation titled exactly like its parent.
	 *
	 * A picker listing "Membership" four times tells a publisher nothing about which tier
	 * each entry is, so recover the attributes from the variation's summary. Where there is
	 * no summary to recover, the bare parent title stands: the pickers render every option
	 * as `<name> (#<id>)`, so the entries stay individually selectable either way.
	 *
	 * @group Access_Rules
	 */
	public function test_get_subscription_products_options_names_variations_titled_like_their_parent() {
		wc_create_mock_product(
			[
				'id'   => 920,
				'type' => 'variable-subscription',
				'name' => 'Membership',
			]
		);
		// Titled exactly like the parent, but carrying an attribute summary.
		$monthly_variation_id = $this->create_variation_post( 920, 'Membership', 'Term: Monthly' );
		$annual_variation_id  = $this->create_variation_post( 920, 'Membership', 'Term: Annual' );
		// Titled like the parent with no attribute summary to fall back on.
		$bare_variation_id = $this->create_variation_post( 920, 'Membership' );

		$options_by_value = array_column( Access_Rules::get_subscription_products_options(), 'label', 'value' );

		$this->assertSame(
			[
				920                   => 'Membership',
				$monthly_variation_id => 'Membership - Term: Monthly',
				$annual_variation_id  => 'Membership - Term: Annual',
				// No attribute summary to recover, so the generated title stands.
				$bare_variation_id    => 'Membership',
			],
			$options_by_value,
			'A variation titled like its parent should take its attribute summary where it has one.'
		);
	}

	/**
	 * A drafted subscription product stays selectable, because unpublishing a product does
	 * not end the subscriptions bought through it: the rule matches the order's line item,
	 * which still names the product. Dropping it from the options would leave the readers
	 * still paying for that product ungateable.
	 *
	 * `wc_get_products()` gives this for free — its default status set is draft, pending,
	 * private and publish — so the point of the assertion is that the picker does not
	 * narrow it back to published, the way the institution options deliberately do.
	 *
	 * @group Access_Rules
	 */
	public function test_get_subscription_products_options_includes_unpublished_products() {
		wc_create_mock_product(
			[
				'id'     => 930,
				'type'   => 'subscription',
				'name'   => 'Retired tier',
				'status' => 'draft',
			]
		);
		wc_create_mock_product(
			[
				'id'     => 931,
				'type'   => 'subscription',
				'name'   => 'Deleted tier',
				'status' => 'trash',
			]
		);

		$values = array_column( Access_Rules::get_subscription_products_options(), 'value' );

		$this->assertSame( [ 930 ], $values, 'A draft subscription should be listed; a trashed one should not.' );
	}

	/**
	 * The options are built once per request. `get_access_rules()` resolves every registered
	 * rule's options callback on every call, and more than one admin screen localizes it, so
	 * without the memo a request reaching it twice runs the full-catalog product query and
	 * its variation query twice.
	 *
	 * @group Access_Rules
	 */
	public function test_get_subscription_products_options_is_memoized_per_request() {
		wc_create_mock_product(
			[
				'id'   => 940,
				'type' => 'subscription',
				'name' => 'Supporter',
			]
		);
		$first = Access_Rules::get_subscription_products_options();

		wc_create_mock_product(
			[
				'id'   => 941,
				'type' => 'subscription',
				'name' => 'Patron',
			]
		);

		$this->assertSame( $first, Access_Rules::get_subscription_products_options(), 'A second call within the request should reuse the built options.' );

		Access_Rules::flush_product_options_memos();

		$this->assertSame(
			[ 940, 941 ],
			array_column( Access_Rules::get_subscription_products_options(), 'value' ),
			'Flushing the memo should rebuild the options.'
		);
	}

	/**
	 * WooCommerce rewrites a variation's title when its parent is renamed through the CRUD
	 * path, but an importer or a direct `wp_update_post()` does not, leaving the title on
	 * the old name. The label then names a product the publisher can no longer find — and
	 * where the parent has three or more attributes, the stale title is the parent's old
	 * name alone, which is the indistinguishable-siblings defect all over again.
	 *
	 * @group Access_Rules
	 */
	public function test_get_subscription_products_options_names_variations_of_a_renamed_parent() {
		wc_create_mock_product(
			[
				'id'   => 925,
				'type' => 'variable-subscription',
				'name' => 'Membership',
			]
		);
		// Titles generated while the parent was still called "Supporter".
		$monthly_variation_id = $this->create_variation_post( 925, 'Supporter', 'Term: Monthly' );
		$annual_variation_id  = $this->create_variation_post( 925, 'Supporter - Annual', 'Term: Annual' );

		$options_by_value = array_column( Access_Rules::get_subscription_products_options(), 'label', 'value' );

		$this->assertSame(
			[
				925                   => 'Membership',
				$monthly_variation_id => 'Membership - Term: Monthly',
				$annual_variation_id  => 'Membership - Term: Annual',
			],
			$options_by_value,
			'A stale title should give way to the parent\'s current name and the variation\'s attributes.'
		);
	}

	/**
	 * The point of listing variations: selecting one narrows the rule to that tier, while
	 * selecting the parent still admits every tier under it.
	 *
	 * `WC_Subscription::has_product()` matches a line item on its `variation_id` as well as
	 * its `product_id`, so this is the evaluation the options exist to make configurable.
	 *
	 * @group Access_Rules
	 */
	public function test_a_variation_rule_admits_only_that_variation() {
		$annual_variation_id  = 9501;
		$monthly_variation_id = 9502;
		$this->create_subscription(
			[
				// No `products` shorthand: the line item is what carries the parent/variation
				// split this test is about.
				'products' => [],
				'items'    => [
					new WC_Order_Item_Product(
						[
							'product_id'   => self::$product_id,
							'variation_id' => $annual_variation_id,
						]
					),
				],
			]
		);

		$this->assertTrue(
			Access_Rules::has_active_subscription( self::$owner_user_id, [ $annual_variation_id ] ),
			'A rule naming the purchased variation should grant access.'
		);
		$this->assertFalse(
			Access_Rules::has_active_subscription( self::$owner_user_id, [ $monthly_variation_id ] ),
			'A rule naming a sibling variation should not.'
		);
		$this->assertTrue(
			Access_Rules::has_active_subscription( self::$owner_user_id, [ self::$product_id ] ),
			'A rule naming the parent should still admit any of its variations.'
		);
	}

	/**
	 * Sanitizing a gate's rules reads the registered rules, not the resolved ones, so it
	 * neither runs a rule's options query nor depends on what that query returns. An empty
	 * catalog used to make the subscription rule read as though it had no options, sending
	 * its ID list down the plain-string branch.
	 *
	 * @group Access_Rules
	 */
	public function test_sanitize_access_rule_keeps_product_ids_with_an_empty_catalog() {
		$sanitized_rule = Content_Gate_API::sanitize_access_rule(
			[
				'slug'  => 'subscription',
				'value' => [ '188250', 9501 ],
			]
		);

		$this->assertSame(
			[
				'slug'  => 'subscription',
				'value' => [ 188250, 9501 ],
			],
			$sanitized_rule,
			'A subscription rule\'s IDs should survive sanitizing whether or not the shop has products.'
		);
	}

	/**
	 * Test that a subscriber loses access when their group also carries an
	 * institution rule naming nothing.
	 *
	 * The one case where reading an unconfigured rule as "matches nobody" takes
	 * access away from readers who are paying for it. Rules AND within a group, so
	 * the subscription rule no longer decides on its own. The gate save refuses
	 * this shape, which leaves rows written before that check — and the block
	 * attributes and CLI paths that never reach it.
	 *
	 * @group Access_Rules
	 */
	public function test_a_subscriber_is_denied_when_the_group_also_names_no_institution() {
		$this->create_subscription();

		$subscription_only = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ self::$product_id ],
				],
			],
		];
		$this->assertTrue(
			Access_Rules::evaluate_rules( $subscription_only, self::$owner_user_id ),
			'The subscriber passes a group holding only their subscription rule.'
		);

		$with_unconfigured_institution = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ self::$product_id ],
				],
				[
					'slug'  => 'institution',
					'value' => [],
				],
			],
		];
		$this->assertFalse(
			Access_Rules::evaluate_rules( $with_unconfigured_institution, self::$owner_user_id ),
			'ANDing an institution rule that names nothing withholds access from the same subscriber.'
		);
	}

	/**
	 * Test that a rule the site no longer registers is skipped, not denied.
	 *
	 * A slug disappears from the registry whenever the integration that supplied
	 * it is switched off — `Promoted_Fields::register()` adds one rule per enabled
	 * field of each active integration. Reading such a rule as a failed condition
	 * would take a gate the publisher cannot see or edit and have it deny every
	 * reader, so the group is decided by the rules that are still real.
	 *
	 * Asserting the group passes is what separates "skipped" from "denied": a test
	 * expecting false would be satisfied by either.
	 *
	 * @group Access_Rules
	 */
	public function test_an_unregistered_rule_is_skipped_rather_than_failing_its_group() {
		$this->create_subscription();

		$this->assertTrue(
			Access_Rules::evaluate_rules(
				[
					[
						[
							'slug'  => 'subscription',
							'value' => [ self::$product_id ],
						],
						[
							'slug'  => 'field_from_a_disabled_integration',
							'value' => 'anything',
						],
					],
				],
				self::$owner_user_id
			),
			'The registered rule the reader passes decides the group.'
		);
	}

	/**
	 * Test that a logged-out visitor is judged by the anonymous evaluator.
	 *
	 * The two evaluators agree on every registered rule a gate can hold today, so
	 * what this pins is the three shapes where they part. Two are shapes neither
	 * the wizard nor the REST sanitizer produces and block attributes are never
	 * checked for: a group with nothing in it, and a rule carrying no slug. The
	 * third is a rule from an integration the site has switched off, which reaches
	 * `evaluate_rule()`'s missing-callback branch — and that branch returns true
	 * ahead of the anonymous check. In all three `evaluate_rules()` has no
	 * condition to fail and reads the group as satisfied, which admits every
	 * visitor. The anonymous evaluator treats all three as unconfigured.
	 *
	 * The unregistered case is deliberately asymmetric: skipped for a signed-in
	 * reader (asserted in
	 * test_an_unregistered_rule_is_skipped_rather_than_failing_its_group), denying
	 * for a logged-out one. Pinned here so a later reconciliation of the two is a
	 * decision rather than a tidy-up.
	 *
	 * @group Access_Rules
	 */
	public function test_evaluate_rules_for_visitor_judges_a_logged_out_visitor_anonymously() {
		foreach ( [
			'an empty group'                         => [ [] ],
			'a rule carrying no slug'                => [ [ [ 'value' => 'orphan' ] ] ],
			'a rule from a switched-off integration' => [
				[
					[
						'slug'  => 'field_from_a_disabled_integration',
						'value' => 'anything',
					],
				],
			],
		] as $description => $rules ) {
			$this->assertTrue(
				Access_Rules::evaluate_rules( $rules, 0 ),
				"The direct evaluator reads {$description} as satisfied, which is what this routing keeps off the gating surfaces."
			);
			$this->assertFalse(
				Access_Rules::evaluate_rules_for_visitor( $rules, 0 ),
				"A logged-out visitor is denied by {$description}."
			);
		}

		$institution_rule = [
			[
				[
					'slug'  => 'institution',
					'value' => [],
				],
			],
		];
		$this->assertFalse(
			Access_Rules::evaluate_rules_for_visitor( $institution_rule, 0 ),
			'An institution rule naming nothing denies a logged-out visitor.'
		);
		$this->assertFalse(
			Access_Rules::evaluate_rules_for_visitor( $institution_rule, self::$owner_user_id ),
			'And denies a logged-in reader, which is the asymmetry this issue is about.'
		);
	}
}
