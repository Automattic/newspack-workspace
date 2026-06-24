<?php
/**
 * Tests for Group_Subscription_Settings.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * Test Group_Subscription_Settings.
 */
class Test_Group_Subscription_Settings extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Include WC mocks.
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Set up: reset subscriptions and products databases.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
	}

	/**
	 * Tear down: reset subscriptions and products databases.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		parent::tear_down();
	}

	/**
	 * Build a subscription linked to a product, optionally setting group subscription
	 * meta on either side and arbitrary subscription data.
	 *
	 * Meta keys are passed without the GROUP_SUBSCRIPTION_META_PREFIX; the helper
	 * applies the prefix.
	 *
	 * @param array $product_meta      Map of meta key => value to set on the product.
	 * @param array $subscription_meta Map of meta key => value to set on the subscription.
	 * @param array $subscription_args Extra arguments merged into the subscription data
	 *                                 (e.g. billing_first_name, billing_last_name).
	 * @param array $product_args      Extra arguments merged into the mock product data
	 *                                 (e.g. name).
	 *
	 * @return WC_Subscription
	 */
	private function make_subscription_with_product( $product_meta = [], $subscription_meta = [], $subscription_args = [], $product_args = [] ) {
		$product_id            = 123;
		$prefixed_product_meta = [];
		foreach ( $product_meta as $key => $value ) {
			$prefixed_product_meta[ Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . $key ] = $value;
		}
		wc_create_mock_product(
			array_merge(
				[
					'id'   => $product_id,
					'meta' => $prefixed_product_meta,
				],
				$product_args
			)
		);

		$subscription = wcs_create_subscription(
			array_merge(
				[
					'customer_id'    => 1,
					'status'         => 'active',
					'billing_period' => 'month',
					'items'          => [
						new WC_Order_Item_Product( [ 'product_id' => $product_id ] ),
					],
				],
				$subscription_args
			)
		);

		foreach ( $subscription_meta as $key => $value ) {
			$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . $key, $value );
		}

		return $subscription;
	}

	/*
	 * --- 'limit' setting ---
	 */

	/**
	 * When a subscription has no limit override, the inherited product limit applies.
	 */
	public function test_inherits_product_limit_when_subscription_override_unset() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled' => 'yes',
				'limit'   => '10',
			]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 10, $settings['limit'], 'Limit should be inherited from the product when no subscription override is set.' );
	}

	/**
	 * A subscription limit override of 0 takes precedence over a non-zero product limit.
	 */
	public function test_zero_subscription_limit_overrides_product_limit() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled' => 'yes',
				'limit'   => '10',
			],
			[ 'limit' => '0' ] // String, as stored by WooCommerce meta.
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 0, $settings['limit'], 'A subscription limit of 0 should override the product limit of 10.' );
	}

	/**
	 * A non-zero subscription limit override takes precedence over the product limit.
	 */
	public function test_nonzero_subscription_limit_overrides_product_limit() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled' => 'yes',
				'limit'   => '10',
			],
			[ 'limit' => '5' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 5, $settings['limit'], 'A subscription limit of 5 should override the product limit of 10.' );
	}

	/*
	 * --- 'enabled' setting ---
	 */

	/**
	 * When a subscription has no enabled override, the inherited product value applies.
	 */
	public function test_inherits_product_enabled_when_subscription_override_unset() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertTrue( $settings['enabled'], 'Enabled should be inherited from the product when no subscription override is set.' );
	}

	/**
	 * A subscription enabled override of 'no' takes precedence over a product 'yes'.
	 */
	public function test_no_subscription_enabled_overrides_product_yes() {
		$subscription = $this->make_subscription_with_product(
			[ 'enabled' => 'yes' ],
			[ 'enabled' => 'no' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertFalse( $settings['enabled'], 'A subscription enabled value of "no" should override the product value of "yes".' );
	}

	/**
	 * A subscription enabled override of 'yes' takes effect when the product has no value set.
	 */
	public function test_yes_subscription_enabled_overrides_product_unset() {
		$subscription = $this->make_subscription_with_product(
			[],
			[ 'enabled' => 'yes' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertTrue( $settings['enabled'], 'A subscription enabled value of "yes" should take effect when the product has no value set.' );
	}

	/*
	 * --- 'name' setting ---
	 */

	/**
	 * An explicit subscription name meta value is used as the group name.
	 */
	public function test_explicit_subscription_name_meta_wins() {
		$subscription = $this->make_subscription_with_product(
			[ 'enabled' => 'yes' ],
			[ 'name' => 'My Custom Group' ],
			[
				'billing_first_name' => 'Jane',
				'billing_last_name'  => 'Doe',
			]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 'My Custom Group', $settings['name'], 'Explicit subscription name meta should be used as the group name even when an owner name is available.' );
	}

	/**
	 * Without an explicit name, the group name falls back to the product name.
	 */
	public function test_name_falls_back_to_product_name() {
		$subscription = $this->make_subscription_with_product(
			[ 'enabled' => 'yes' ],
			[],
			[],
			[ 'name' => 'Daily Reader' ]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 'Daily Reader', $settings['name'], 'When no name meta is set, the group name should fall back to the product name.' );
	}

	/**
	 * Without an explicit name or a product name, the group name falls back to the publisher singular group label.
	 */
	public function test_name_falls_back_to_singular_group_label() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( Group_Subscription::get_label( 'singular' ), $settings['name'], 'When neither name meta nor a product name is set, the group name should fall back to the publisher singular group label.' );
	}
}
