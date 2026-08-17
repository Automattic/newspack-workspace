<?php
/**
 * Tests the one-time purchase access rule (NPPD-2053).
 *
 * Covers Access_Rules::has_one_time_purchase(): paid one-time (simple) products
 * granting gate access for a configured duration ("N days/months from purchase")
 * or forever (lifetime), anchored on the order's creation date.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Rules;
use Newspack\Content_Gate_API;

/**
 * Test one-time purchase access rule functionality.
 *
 * @group Access_Rules
 */
class Newspack_Test_Access_Rules_One_Time_Purchase extends WP_UnitTestCase {
	/**
	 * Test user ID for the purchaser.
	 *
	 * @var int
	 */
	private static $purchaser_user_id;

	/**
	 * Test user ID for a reader with no purchases.
	 *
	 * @var int
	 */
	private static $non_purchaser_user_id;

	/**
	 * Product ID of the one-time access product (e.g. a prepaid annual pass).
	 *
	 * @var int
	 */
	private static $prepaid_product_id = 60;

	/**
	 * Product ID of an unrelated product.
	 *
	 * @var int
	 */
	private static $unrelated_product_id = 61;

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

		// Reset the orders database and the per-request evaluation memo.
		global $orders_database;
		$orders_database = [];
		Access_Rules::flush_one_time_purchase_memo();

		self::$purchaser_user_id     = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		self::$non_purchaser_user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Helper to create a paid one-time order for the purchaser.
	 *
	 * @param array $args Order argument overrides.
	 * @return WC_Order
	 */
	private function create_one_time_order( $args = [] ) {
		$defaults = [
			'customer_id'  => self::$purchaser_user_id,
			'status'       => 'completed',
			'total'        => 100,
			'date_created' => gmdate( 'Y-m-d H:i:s' ),
			'items'        => [
				new WC_Order_Item_Product( [ 'product_id' => self::$prepaid_product_id ] ),
			],
		];

		return wc_create_order( array_merge( $defaults, $args ) );
	}

	/**
	 * Helper to build the rule value array.
	 *
	 * @param array $overrides Value overrides.
	 * @return array
	 */
	private function get_rule_value( $overrides = [] ) {
		return array_merge(
			[
				'product_ids'    => [ self::$prepaid_product_id ],
				'duration_value' => 30,
				'duration_unit'  => 'days',
			],
			$overrides
		);
	}

	/**
	 * The rule is registered with the default access rules.
	 */
	public function test_one_time_purchase_rule_is_registered() {
		$one_time_purchase_rule = Access_Rules::get_rule( 'one_time_purchase' );

		$this->assertNotNull( $one_time_purchase_rule, 'The one_time_purchase rule should be registered.' );
		$this->assertTrue( is_callable( $one_time_purchase_rule['callback'] ), 'The one_time_purchase rule should have a callable callback.' );
	}

	/**
	 * A completed purchase inside the configured duration grants access.
	 */
	public function test_purchase_within_duration_grants_access() {
		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ) ] );

		$has_access = Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() );

		$this->assertTrue( $has_access, 'A completed purchase 10 days ago should grant access with a 30-day duration.' );
	}

	/**
	 * A purchase older than the configured duration denies access.
	 */
	public function test_purchase_outside_duration_denies_access() {
		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-60 days' ) ) ] );

		$has_access = Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() );

		$this->assertFalse( $has_access, 'A purchase 60 days ago should not grant access with a 30-day duration.' );
	}

	/**
	 * Months-based duration grants access inside the window and denies outside it.
	 */
	public function test_months_duration_boundaries() {
		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-11 months' ) ) ] );

		$value_within_twelve_months = $this->get_rule_value(
			[
				'duration_value' => 12,
				'duration_unit'  => 'months',
			]
		);
		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $value_within_twelve_months ),
			'A purchase 11 months ago should grant access with a 12-month duration.'
		);

		$value_within_six_months = $this->get_rule_value(
			[
				'duration_value' => 6,
				'duration_unit'  => 'months',
			]
		);
		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $value_within_six_months ),
			'A purchase 11 months ago should not grant access with a 6-month duration.'
		);
	}

	/**
	 * A "forever" (lifetime) duration grants access regardless of purchase age.
	 */
	public function test_forever_duration_grants_access_for_old_purchase() {
		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-5 years' ) ) ] );

		$has_access = Access_Rules::has_one_time_purchase(
			self::$purchaser_user_id,
			$this->get_rule_value( [ 'duration_unit' => 'forever' ] )
		);

		$this->assertTrue( $has_access, 'A lifetime (forever) rule should grant access for a 5-year-old purchase.' );
	}

	/**
	 * A processing (paid, not yet fulfilled) order grants access.
	 */
	public function test_processing_order_grants_access() {
		$this->create_one_time_order( [ 'status' => 'processing' ] );

		$has_access = Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() );

		$this->assertTrue( $has_access, 'A processing order counts as paid and should grant access.' );
	}

	/**
	 * A refunded order does not grant access — for both finite and forever durations.
	 */
	public function test_refunded_order_denies_access() {
		$this->create_one_time_order( [ 'status' => 'refunded' ] );

		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() ),
			'A refunded order should not grant access with a finite duration.'
		);
		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value( [ 'duration_unit' => 'forever' ] ) ),
			'A refunded order should not grant access with a forever duration.'
		);
	}

	/**
	 * A cancelled order does not grant access.
	 */
	public function test_cancelled_order_denies_access() {
		$this->create_one_time_order( [ 'status' => 'cancelled' ] );

		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() ),
			'A cancelled order should not grant access.'
		);
		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value( [ 'duration_unit' => 'forever' ] ) ),
			'A cancelled order should not grant access with a forever duration.'
		);
	}

	/**
	 * A pending (unpaid) order does not grant access.
	 */
	public function test_pending_order_denies_access() {
		$this->create_one_time_order( [ 'status' => 'pending' ] );

		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() ),
			'A pending (unpaid) order should not grant access.'
		);
	}

	/**
	 * An order for an unrelated product does not grant access.
	 */
	public function test_wrong_product_denies_access() {
		$this->create_one_time_order(
			[
				'items' => [ new WC_Order_Item_Product( [ 'product_id' => self::$unrelated_product_id ] ) ],
			]
		);

		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() ),
			'An order for a different product should not grant access.'
		);
	}

	/**
	 * A purchase of a variation grants access when its parent product is selected.
	 */
	public function test_variation_purchase_grants_access_via_parent_product() {
		$this->create_one_time_order(
			[
				'items' => [
					new WC_Order_Item_Product(
						[
							'product_id'   => self::$prepaid_product_id,
							'variation_id' => 999,
						]
					),
				],
			]
		);

		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() ),
			'A variation purchase should grant access when the parent product is selected.'
		);
	}

	/**
	 * A user without any purchase does not get access.
	 */
	public function test_non_purchaser_denies_access() {
		$this->create_one_time_order();

		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$non_purchaser_user_id, $this->get_rule_value() ),
			'A user without a purchase should not get access.'
		);
		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$non_purchaser_user_id, $this->get_rule_value( [ 'duration_unit' => 'forever' ] ) ),
			'A user without a purchase should not get forever access.'
		);
	}

	/**
	 * An unconfigured rule (no products selected) denies access.
	 */
	public function test_empty_product_ids_denies_access() {
		$this->create_one_time_order();

		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value( [ 'product_ids' => [] ] ) ),
			'A rule with no products selected should not grant access.'
		);
	}

	/**
	 * A finite duration with a zero value is treated as misconfigured and denies access.
	 */
	public function test_zero_finite_duration_denies_access() {
		$this->create_one_time_order();

		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value( [ 'duration_value' => 0 ] ) ),
			'A finite duration of zero should be treated as misconfigured and deny access.'
		);
	}

	/**
	 * The rule works end-to-end through evaluate_rules() with the registered slug.
	 */
	public function test_evaluate_rules_with_one_time_purchase_slug() {
		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ) ] );

		$access_rules = [
			[
				[
					'slug'  => 'one_time_purchase',
					'value' => $this->get_rule_value(),
				],
			],
		];

		$this->assertTrue(
			Access_Rules::evaluate_rules( $access_rules, self::$purchaser_user_id ),
			'evaluate_rules should grant access to the purchaser via the one_time_purchase rule.'
		);
		$this->assertFalse(
			Access_Rules::evaluate_rules( $access_rules, self::$non_purchaser_user_id ),
			'evaluate_rules should deny access to a non-purchaser via the one_time_purchase rule.'
		);
	}

	/**
	 * The subscription rule ignores one-time orders — a one-time purchase must not
	 * satisfy the subscription rule (existing behavior stays unchanged).
	 */
	public function test_one_time_order_does_not_satisfy_subscription_rule() {
		global $subscriptions_database;
		$subscriptions_database = [];

		$this->create_one_time_order();

		$this->assertFalse(
			Access_Rules::has_active_subscription( self::$purchaser_user_id, [ self::$prepaid_product_id ] ),
			'A one-time purchase should not satisfy the subscription rule.'
		);
	}

	/**
	 * API sanitization preserves the composite value shape and strips junk.
	 */
	public function test_sanitize_access_rule_preserves_composite_value() {
		$sanitized_rule = Content_Gate_API::sanitize_access_rule(
			[
				'slug'  => 'one_time_purchase',
				'value' => [
					'product_ids'    => [ '60', 0, 'junk', 61 ],
					'duration_value' => '30',
					'duration_unit'  => 'days',
					'unexpected'     => 'dropped',
				],
			]
		);

		$this->assertSame(
			[
				'slug'  => 'one_time_purchase',
				'value' => [
					'product_ids'    => [ 60, 61 ],
					'duration_value' => 30,
					'duration_unit'  => 'days',
				],
			],
			$sanitized_rule,
			'Sanitization should keep the composite shape, cast product IDs to ints, and drop unknown keys.'
		);
	}

	/**
	 * API sanitization preserves an invalid duration unit as '' (never as a
	 * granting unit) so evaluation fails closed.
	 */
	public function test_sanitize_access_rule_marks_invalid_duration_unit_as_invalid() {
		$sanitized_rule = Content_Gate_API::sanitize_access_rule(
			[
				'slug'  => 'one_time_purchase',
				'value' => [
					'product_ids'    => [ 60 ],
					'duration_value' => 10,
					'duration_unit'  => 'fortnights',
				],
			]
		);

		$this->assertSame( '', $sanitized_rule['value']['duration_unit'], 'An invalid duration unit must sanitize to the invalid marker, not to a granting unit.' );
	}

	/**
	 * An unrecognized or missing duration unit denies access even with a
	 * qualifying purchase — malformed input must fail closed, never widen a
	 * finite grant into a lifetime one.
	 */
	public function test_invalid_or_missing_duration_unit_denies_access() {
		$this->create_one_time_order();

		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value( [ 'duration_unit' => 'fortnights' ] ) ),
			'An unrecognized duration unit should deny access despite a qualifying purchase.'
		);

		$value_without_unit = $this->get_rule_value();
		unset( $value_without_unit['duration_unit'] );
		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $value_without_unit ),
			'A missing duration unit should deny access despite a qualifying purchase.'
		);
	}

	/**
	 * A guest order (customer_id 0) matched by billing email grants access on
	 * both the finite-duration and forever paths.
	 */
	public function test_guest_order_grants_access_via_billing_email() {
		$purchaser_email = get_userdata( self::$purchaser_user_id )->user_email;
		$this->create_one_time_order(
			[
				'customer_id'   => 0,
				'billing_email' => $purchaser_email,
				'date_created'  => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
			]
		);

		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() ),
			'A guest order matching the reader billing email should grant access within a finite duration.'
		);
		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value( [ 'duration_unit' => 'forever' ] ) ),
			'A guest order matching the reader billing email should grant lifetime access.'
		);
	}

	/**
	 * A guest order whose billing email differs only in case still grants access:
	 * WooCommerce matches the email in SQL, under a case-insensitive collation.
	 */
	public function test_guest_order_matches_billing_email_case_insensitively() {
		$purchaser_email = get_userdata( self::$purchaser_user_id )->user_email;
		$this->create_one_time_order(
			[
				'customer_id'   => 0,
				'billing_email' => strtoupper( $purchaser_email ),
				'date_created'  => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
			]
		);

		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() ),
			'A differently-cased billing email should still match within a finite duration.'
		);
		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value( [ 'duration_unit' => 'forever' ] ) ),
			'A differently-cased billing email should still match for lifetime access.'
		);
	}

	/**
	 * With no user ID and no resolvable email there is nothing to match a purchase
	 * against, so the rule denies rather than querying every customer's orders.
	 *
	 * Each assertion pins the guard on its own path. Finite: without the guard the
	 * unconstrained order query would match another customer's order. Forever:
	 * wc_customer_bought_product() returns the value of the
	 * `woocommerce_pre_customer_bought_product` filter verbatim whenever it is
	 * non-null, ahead of its own identity check, so a third-party plugin hooking
	 * that filter can answer truthy for nobody in particular — see
	 * test_missing_identity_denies_access_against_pre_bought_filter().
	 */
	public function test_missing_identity_denies_access() {
		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ) ] );

		$this->assertFalse(
			Access_Rules::has_one_time_purchase( 0, $this->get_rule_value() ),
			'An anonymous evaluation should never match another customer\'s order within a finite duration.'
		);
		$this->assertFalse(
			Access_Rules::has_one_time_purchase( 0, $this->get_rule_value( [ 'duration_unit' => 'forever' ] ) ),
			'An anonymous evaluation should never match another customer\'s order for lifetime access.'
		);
	}

	/**
	 * A plugin hooking `woocommerce_pre_customer_bought_product` short-circuits
	 * wc_customer_bought_product() before it ever looks at the customer identity,
	 * so on the lifetime path only our own guard stands between an anonymous
	 * evaluation and a truthy answer. Drop the guard and this test fails.
	 */
	public function test_missing_identity_denies_access_against_pre_bought_filter() {
		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ) ] );

		$grant_to_anyone = '__return_true';
		add_filter( 'woocommerce_pre_customer_bought_product', $grant_to_anyone );

		$anonymous_has_lifetime_access = Access_Rules::has_one_time_purchase( 0, $this->get_rule_value( [ 'duration_unit' => 'forever' ] ) );

		remove_filter( 'woocommerce_pre_customer_bought_product', $grant_to_anyone );

		$this->assertFalse(
			$anonymous_has_lifetime_access,
			'A filter answering truthy should not grant lifetime access to an evaluation with no identity.'
		);
		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value( [ 'duration_unit' => 'forever' ] ) ),
			'The identified purchaser is still evaluated through wc_customer_bought_product(), filter or not.'
		);
	}

	/**
	 * The finite window compares strictly against its cutoff: an order created
	 * exactly at the cutoff is already outside it.
	 */
	public function test_cutoff_boundary_is_strict() {
		global $orders_database;
		$thirty_days_ago = strtotime( '-30 days' );

		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', $thirty_days_ago ) ] );
		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() ),
			'An order created exactly at the 30-day cutoff should be outside the window.'
		);

		$orders_database = [];
		Access_Rules::flush_one_time_purchase_memo();

		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', $thirty_days_ago + HOUR_IN_SECONDS ) ] );
		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $this->get_rule_value() ),
			'An order created an hour inside the cutoff should grant access.'
		);
	}

	/**
	 * The months window is anchored on `now - N months` — one cutoff shared by
	 * every order — rather than a per-order `purchase + N months` expiry. The two
	 * readings only diverge on month-end anchors (PHP rolls "+1 month" from
	 * January 31 forward through February 31 to March 3, while "-1 month" from
	 * March 1 lands on February 1), where this anchor is the deny-biased one.
	 */
	public function test_months_window_is_anchored_on_now_minus_duration() {
		global $orders_database;
		$twelve_months_ago = strtotime( '-12 months' );
		$twelve_month_rule = $this->get_rule_value(
			[
				'duration_value' => 12,
				'duration_unit'  => 'months',
			]
		);

		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', $twelve_months_ago ) ] );
		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $twelve_month_rule ),
			'An order created exactly 12 months ago should be outside the 12-month window.'
		);

		$orders_database = [];
		Access_Rules::flush_one_time_purchase_memo();

		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', $twelve_months_ago + DAY_IN_SECONDS ) ] );
		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $twelve_month_rule ),
			'An order created a day inside the 12-month cutoff should grant access.'
		);
	}

	/**
	 * The site timezone doesn't shift the window: the cutoff and the order date
	 * are both absolute Unix timestamps, never locally formatted dates.
	 */
	public function test_window_is_independent_of_site_timezone() {
		global $orders_database;
		// UTC+14 — a timezone-sensitive comparison would shift the window by
		// more than half a day, which the one-day durations below would expose.
		update_option( 'timezone_string', 'Pacific/Kiritimati' );
		$one_day_rule = $this->get_rule_value( [ 'duration_value' => 1 ] );

		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-25 hours' ) ) ] );
		$this->assertFalse(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $one_day_rule ),
			'A purchase 25 hours ago should be outside a one-day window whatever the site timezone.'
		);

		$orders_database = [];
		Access_Rules::flush_one_time_purchase_memo();

		$this->create_one_time_order( [ 'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-23 hours' ) ) ] );
		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $one_day_rule ),
			'A purchase 23 hours ago should be inside a one-day window whatever the site timezone.'
		);

		update_option( 'timezone_string', '' );
	}

	/**
	 * A variation ID stored directly in the rule value grants access on both
	 * duration paths. Variations aren't offered as product options (the rule
	 * lists simple and variable products), and the sanitizer doesn't whitelist
	 * IDs against them, so a value pointing at a variation is honored as written.
	 */
	public function test_variation_id_stored_in_rule_value_grants_access() {
		$variation_id = 999;
		$this->create_one_time_order(
			[
				'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
				'items'        => [
					new WC_Order_Item_Product(
						[
							'product_id'   => self::$prepaid_product_id,
							'variation_id' => $variation_id,
						]
					),
				],
			]
		);
		$variation_rule = $this->get_rule_value( [ 'product_ids' => [ $variation_id ] ] );

		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, $variation_rule ),
			'A stored variation ID should grant access within a finite duration.'
		);
		$this->assertTrue(
			Access_Rules::has_one_time_purchase( self::$purchaser_user_id, array_merge( $variation_rule, [ 'duration_unit' => 'forever' ] ) ),
			'A stored variation ID should grant lifetime access.'
		);
	}
}
