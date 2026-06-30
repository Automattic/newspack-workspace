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

	/**
	 * Run the meta-box save handler with a simulated $_POST payload.
	 *
	 * @param WC_Subscription $subscription The subscription being saved.
	 * @param array           $post         POST fields (the save nonce is added automatically).
	 */
	private function run_meta_box_save( $subscription, array $post ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Test helper seeds $_POST to exercise save_group_subscription_meta(), which verifies the nonce itself.
		$prev_post = $_POST;
		$_POST     = array_merge(
			[ 'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ) ],
			$post
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		Group_Subscription_Settings::save_group_subscription_meta( $subscription->get_id(), $subscription );
		$_POST = $prev_post;
	}

	/**
	 * A manually-created subscription whose product enables
	 * groups must keep inheriting when the meta box was rendered unchecked (no product
	 * linked yet) and the admin never touched the control. Saving must not write a
	 * spurious `enabled = 'no'` override.
	 */
	public function test_save_keeps_inheritance_when_unchecked_box_matches_baseline() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$prefix       = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		// Meta box rendered unchecked (baseline 'no'); admin submits without toggling it.
		$this->run_meta_box_save(
			$subscription,
			[ $prefix . 'enabled_baseline' => 'no' ]
		);

		$this->assertSame( '', $subscription->get_meta( $prefix . 'enabled', true ), 'No own enabled override should be written when the unchecked box matches its rendered baseline.' );
		$this->assertTrue( Group_Subscription_Settings::get_subscription_settings( $subscription )['enabled'], 'The subscription should still inherit enabled=true from the product.' );
	}

	/**
	 * Intentional opt-out is preserved: unchecking a box that was rendered checked
	 * writes the explicit `enabled = 'no'` override.
	 */
	public function test_save_writes_override_when_unchecking_rendered_checked_box() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$prefix       = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		// Meta box rendered checked (baseline 'yes'); admin unchecks it (no 'enabled' key posted).
		$this->run_meta_box_save(
			$subscription,
			[ $prefix . 'enabled_baseline' => 'yes' ]
		);

		$this->assertSame( 'no', $subscription->get_meta( $prefix . 'enabled', true ), 'An explicit no override should be written when the admin unchecks a rendered-checked box.' );
		$this->assertFalse( Group_Subscription_Settings::get_subscription_settings( $subscription )['enabled'], 'The subscription should be disabled by the explicit override.' );
	}

	/**
	 * An unchanged limit (submitted value equals the rendered baseline) writes no override.
	 */
	public function test_save_does_not_override_limit_when_unchanged_from_baseline() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled' => 'yes',
				'limit'   => '10',
			]
		);
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		$this->run_meta_box_save(
			$subscription,
			[
				$prefix . 'enabled'          => 'yes',
				$prefix . 'enabled_baseline' => 'yes',
				$prefix . 'limit'            => '10',
				$prefix . 'limit_baseline'   => '10',
			]
		);

		$this->assertSame( '', $subscription->get_meta( $prefix . 'limit', true ), 'No own limit override should be written when the submitted limit matches its baseline.' );
		$this->assertSame( 10, Group_Subscription_Settings::get_subscription_settings( $subscription )['limit'], 'The subscription should still inherit the product limit.' );
	}

	/**
	 * A changed limit (submitted value differs from the rendered baseline) writes the override.
	 */
	public function test_save_writes_limit_override_when_changed_from_baseline() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled' => 'yes',
				'limit'   => '10',
			]
		);
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		$this->run_meta_box_save(
			$subscription,
			[
				$prefix . 'enabled'          => 'yes',
				$prefix . 'enabled_baseline' => 'yes',
				$prefix . 'limit'            => '5',
				$prefix . 'limit_baseline'   => '10',
			]
		);

		$this->assertSame( 5, Group_Subscription_Settings::get_subscription_settings( $subscription )['limit'], 'A changed limit should override the inherited product limit.' );
	}

	/**
	 * An unchanged name (submitted value equals the rendered baseline) writes no override.
	 */
	public function test_save_does_not_override_name_when_unchanged_from_baseline() {
		$subscription = $this->make_subscription_with_product(
			[ 'enabled' => 'yes' ],
			[],
			[],
			[ 'name' => 'Daily Reader' ]
		);
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		$this->run_meta_box_save(
			$subscription,
			[
				$prefix . 'enabled'          => 'yes',
				$prefix . 'enabled_baseline' => 'yes',
				$prefix . 'name'             => 'Daily Reader',
				$prefix . 'name_baseline'    => 'Daily Reader',
			]
		);

		$this->assertSame( '', $subscription->get_meta( $prefix . 'name', true ), 'No own name override should be written when the submitted name matches its baseline.' );
		$this->assertSame( 'Daily Reader', Group_Subscription_Settings::get_subscription_settings( $subscription )['name'], 'The subscription should still inherit the product name.' );
	}

	/**
	 * A changed name (submitted value differs from the rendered baseline) writes the override.
	 */
	public function test_save_writes_name_override_when_changed_from_baseline() {
		$subscription = $this->make_subscription_with_product(
			[ 'enabled' => 'yes' ],
			[],
			[],
			[ 'name' => 'Daily Reader' ]
		);
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		$this->run_meta_box_save(
			$subscription,
			[
				$prefix . 'enabled'          => 'yes',
				$prefix . 'enabled_baseline' => 'yes',
				$prefix . 'name'             => 'My Custom Group',
				$prefix . 'name_baseline'    => 'Daily Reader',
			]
		);

		$this->assertSame( 'My Custom Group', Group_Subscription_Settings::get_subscription_settings( $subscription )['name'], 'A changed name should override the inherited product name.' );
	}

	/**
	 * A no-op save on a subscription that inherits group-enabled status from its
	 * product still refreshes the cached group-subscription ID set, so the new
	 * subscription appears in the admin group filters without waiting for expiry.
	 */
	public function test_save_clears_group_ids_cache_when_subscription_inherits_enabled() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$prefix       = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;
		set_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT, [ 999 ], MINUTE_IN_SECONDS );

		$this->run_meta_box_save( $subscription, [ $prefix . 'enabled_baseline' => 'no' ] );

		$this->assertFalse( get_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT ), 'The cached group-subscription ID set should be cleared so the inheriting subscription is discoverable.' );
	}

	/**
	 * A no-op save on a non-group subscription leaves the cached group-subscription
	 * ID set intact, so unrelated subscription saves do not churn the cache.
	 */
	public function test_save_keeps_group_ids_cache_for_non_group_subscription() {
		$subscription = $this->make_subscription_with_product();
		$prefix       = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;
		set_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT, [ 999 ], MINUTE_IN_SECONDS );

		$this->run_meta_box_save( $subscription, [ $prefix . 'enabled_baseline' => 'no' ] );

		$this->assertSame( [ 999 ], get_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT ), 'A non-group subscription save should not bust the cache.' );
	}

	/**
	 * Checking the box on a create form whose product already makes the effective
	 * status enabled produces no meta write (the value already matches inheritance),
	 * so the cached group-subscription ID set must still be refreshed.
	 */
	public function test_save_clears_group_ids_cache_when_checked_enabled_matches_inherited() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$prefix       = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;
		set_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT, [ 999 ], MINUTE_IN_SECONDS );

		$this->run_meta_box_save(
			$subscription,
			[
				$prefix . 'enabled'          => 'yes',
				$prefix . 'enabled_baseline' => 'no',
			]
		);

		$this->assertFalse( get_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT ), 'The cache must be refreshed even when the checked value already matches the inherited state.' );
	}

	/**
	 * When a subscription that was a group subscription loses that status through its
	 * product (effective enabled goes from true to false) with the checkbox untouched,
	 * the cached group-subscription ID set must be refreshed so it drops out of filters.
	 */
	public function test_save_clears_group_ids_cache_when_inherited_status_turns_off() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$prefix       = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;
		set_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT, [ 999 ], MINUTE_IN_SECONDS );

		// The product is no longer group-enabled at save time; the box rendered checked (baseline 'yes') and was left untouched.
		wc_create_mock_product(
			[
				'id'   => 123,
				'meta' => [ $prefix . 'enabled' => 'no' ],
			]
		);

		$this->run_meta_box_save(
			$subscription,
			[
				$prefix . 'enabled'          => 'yes',
				$prefix . 'enabled_baseline' => 'yes',
			]
		);

		$this->assertFalse( get_transient( Group_Subscription_Settings::GROUP_SUBSCRIPTION_IDS_TRANSIENT ), 'The cache must be refreshed when inherited group status turns off.' );
	}
}
