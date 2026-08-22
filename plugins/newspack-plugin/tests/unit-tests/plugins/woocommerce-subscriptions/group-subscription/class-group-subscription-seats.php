<?php
/**
 * Tests for Group_Subscription_Seats.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

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
	 * Set up: reset the products database, recorded notices, and the
	 * is_product() mock, and enable the content-gates feature flag the class's
	 * init() checks.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database;
		$products_database = [];
		wc_mocks_reset_notices();
		wc_mocks_set_is_product( false );
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Tear down: reset the products database and the is_product() mock.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = [];
		wc_mocks_set_is_product( false );
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
	 * leaves flat products' args untouched.
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

		$flat          = $this->make_flat_product( 922 );
		$original_args = [
			'min_value' => 1,
			'max_value' => 99,
		];
		$this->assertSame( $original_args, Group_Subscription_Seats::quantity_input_args( $original_args, $flat ) );
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
}
