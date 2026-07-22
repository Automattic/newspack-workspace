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

		Access_Rules::flush_one_time_purchase_memo();

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
	 * API sanitization falls back to "forever" for an invalid duration unit.
	 */
	public function test_sanitize_access_rule_defaults_invalid_duration_unit_to_forever() {
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

		$this->assertSame( 'forever', $sanitized_rule['value']['duration_unit'], 'An invalid duration unit should fall back to forever.' );
	}
}
