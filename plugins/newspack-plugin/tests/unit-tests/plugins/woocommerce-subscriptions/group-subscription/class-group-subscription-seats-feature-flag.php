<?php
/**
 * Tests for Group_Subscription_Seats with the content-gates feature turned off.
 *
 * Its own file because the feature flag is a constant: once any other test class
 * has defined NEWSPACK_CONTENT_GATES it can never become undefined again, and the
 * main seats test class defines it in set_up(). Each case here therefore runs in
 * a separate process, where the constant is undefined as it is on a site that
 * never turned the feature on.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription_Seats;
use Newspack\Group_Subscription_Settings;

/**
 * Test Group_Subscription_Seats with the feature disabled.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Test_Group_Subscription_Seats_Feature_Flag extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Include WC mocks.
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset the mock products database between cases.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database;
		$products_database = [];
	}

	/**
	 * Reset the mock products database between cases.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = [];
		parent::tear_down();
	}

	/**
	 * The seats field is not offered at all on a site that has the feature off.
	 *
	 * `init()` unregisters every hook this class owns when the feature is off, but
	 * `get_field_args()` is also called directly — by the tier picker — and it reads
	 * persisted product meta, which a site that once had the feature on still
	 * carries. Without this the picker would render a seats input whose bounds
	 * nothing enforces, and whose quantity nothing clamps.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_seat_field_when_the_feature_is_disabled() {
		// NEWSPACK_CONTENT_GATES is undefined in the default test env.
		$product = wc_create_mock_product(
			[
				'id'   => 960,
				'meta' => [
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled'      => 'yes',
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'pricing_mode' => Group_Subscription_Settings::PRICING_MODE_PER_SEAT,
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'min_seats'    => 2,
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'max_seats'    => 10,
				],
			]
		);

		$this->assertNull( Group_Subscription_Seats::get_field_args( $product ) );
		$this->assertNull(
			Group_Subscription_Seats::modal_quantity_field( null, $product ),
			'The modal checkout keeps whatever answer it already had.'
		);
		$this->assertSame(
			[ 'existing' => true ],
			Group_Subscription_Seats::available_variation_args( [ 'existing' => true ], $product, $product ),
			'A variation publishes no seat bounds to WooCommerce\'s variation script.'
		);
	}
}
