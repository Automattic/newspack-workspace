<?php
/**
 * Tests for Group_Subscription_Settings.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Invite;
use Newspack\Group_Subscription_Seats;
use Newspack\Group_Subscription_Settings;

/**
 * Test Group_Subscription_Settings.
 *
 * @group WooCommerce_Subscriptions_Integration
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
		wc_mocks_reset_meta_box_errors();
		Group_Subscription::reset_cache();
	}

	/**
	 * Tear down: reset subscriptions and products databases.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		wc_mocks_reset_meta_box_errors();
		Group_Subscription::reset_cache();
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

	/*
	 * --- metabox registration (HPOS vs legacy CPT) ---
	 */

	/**
	 * Register the metabox via the handler and report whether it landed.
	 *
	 * @param mixed $hook_arg The second `add_meta_boxes` argument (WP_Post on the classic
	 *                        editor, WC_Subscription under HPOS).
	 * @return bool Whether the group subscription metabox was registered.
	 */
	private function register_metabox_returns_registered( $hook_arg ) {
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		global $wp_meta_boxes;
		$original_meta_boxes = $wp_meta_boxes;
		$wp_meta_boxes       = [];
		Group_Subscription_Settings::add_group_subscription_meta_box( 'shop_subscription', $hook_arg );
		$registered    = isset( $wp_meta_boxes['shop_subscription']['normal']['high']['newspack-group-subscription'] );
		$wp_meta_boxes = $original_meta_boxes;
		return $registered;
	}

	/**
	 * On the legacy (non-HPOS) order editor WP core passes a WP_Post as the second
	 * `add_meta_boxes` argument. The metabox must still register for subscriptions.
	 */
	public function test_metabox_registers_on_legacy_cpt_editor() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$post         = new WP_Post(
			(object) [
				'ID'        => $subscription->get_id(),
				'post_type' => 'shop_subscription',
			]
		);

		$this->assertTrue(
			$this->register_metabox_returns_registered( $post ),
			'Group subscription metabox should register on the legacy CPT editor (WP_Post hook argument).'
		);
	}

	/**
	 * Under HPOS the second `add_meta_boxes` argument is the WC_Subscription object.
	 */
	public function test_metabox_registers_on_hpos_editor() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );

		$this->assertTrue(
			$this->register_metabox_returns_registered( $subscription ),
			'Group subscription metabox should register under HPOS (WC_Subscription hook argument).'
		);
	}

	/**
	 * A post that is not a subscription must never get the metabox, in either mode.
	 */
	public function test_metabox_not_registered_for_non_subscription_post() {
		$this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		$not_a_subscription = new WP_Post(
			(object) [
				'ID'        => 999999,
				'post_type' => 'shop_order',
			]
		);

		$this->assertFalse(
			$this->register_metabox_returns_registered( $not_a_subscription ),
			'Metabox must not register for a post that is not a subscription.'
		);
	}

	/**
	 * Under HPOS a subscription's ID lives in a separate space from wp_posts and can coincide
	 * with an ordinary post/product ID. Editing that unrelated post must NOT resolve to the
	 * subscription or register the metabox on the wrong screen.
	 */
	public function test_metabox_not_registered_for_non_subscription_post_with_colliding_id() {
		$subscription = $this->make_subscription_with_product( [ 'enabled' => 'yes' ] );
		// A product whose ID happens to match the subscription's ID (the HPOS collision case).
		$colliding_product = new WP_Post(
			(object) [
				'ID'        => $subscription->get_id(),
				'post_type' => 'product',
			]
		);

		$this->assertFalse(
			$this->register_metabox_returns_registered( $colliding_product ),
			'Metabox must not register on a non-subscription post even when its ID collides with a subscription ID.'
		);
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

	/*
	 * --- pricing mode and seat bounds ---
	 */

	/**
	 * With no pricing meta set, a product defaults to flat (per-team) pricing
	 * with a one-seat minimum and no maximum.
	 */
	public function test_product_settings_default_to_per_team() {
		$product = wc_create_mock_product(
			[
				'id'   => 901,
				'type' => 'subscription',
			]
		);

		$settings = Group_Subscription_Settings::get_product_settings( $product );

		$this->assertSame( Group_Subscription_Settings::PRICING_MODE_PER_TEAM, $settings['pricing_mode'] );
		$this->assertSame( 1, $settings['min_seats'] );
		$this->assertSame( 0, $settings['max_seats'] );
	}

	/**
	 * Product-level pricing mode and seat bound meta are read into settings, and
	 * is_per_seat() reports true once both 'enabled' and 'per_seat' are set.
	 */
	public function test_product_settings_read_per_seat_meta() {
		$prefix  = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;
		$product = wc_create_mock_product(
			[
				'id'   => 902,
				'type' => 'subscription',
				'meta' => [
					$prefix . 'enabled'      => 'yes',
					$prefix . 'pricing_mode' => 'per_seat',
					$prefix . 'min_seats'    => '3',
					$prefix . 'max_seats'    => '10',
				],
			]
		);

		$settings = Group_Subscription_Settings::get_product_settings( $product );

		$this->assertSame( 'per_seat', $settings['pricing_mode'] );
		$this->assertSame( 3, $settings['min_seats'] );
		$this->assertSame( 10, $settings['max_seats'] );
		$this->assertTrue( Group_Subscription_Settings::is_per_seat( $product ) );
	}

	/**
	 * A per-seat subscription's capacity is derived from the line item quantity,
	 * not from the (ignored) product limit meta.
	 */
	public function test_per_seat_subscription_capacity_is_line_item_quantity() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled'      => 'yes',
				'pricing_mode' => 'per_seat',
				'limit'        => '50', // Ignored in per-seat mode.
			],
			[],
			[
				'items' => [
					new WC_Order_Item_Product(
						[
							'id'         => 9031,
							'product_id' => 123,
							'quantity'   => 6,
						]
					),
				],
			]
		);

		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		$this->assertSame( 6, $settings['limit'], 'Per-seat limit should equal the purchased seat count (the line item quantity).' );
		$this->assertSame( 6, Group_Subscription::get_member_capacity( $subscription ), 'Per-seat member capacity should equal the line item quantity.' );
		$this->assertSame( 'per_seat', $settings['pricing_mode'] );
	}

	/**
	 * A per-seat subscription ignores an explicit subscription-level limit meta
	 * override; the line item quantity is the sole source of truth for capacity.
	 */
	public function test_per_seat_subscription_ignores_limit_meta_override() {
		$subscription = $this->make_subscription_with_product(
			[
				'enabled'      => 'yes',
				'pricing_mode' => 'per_seat',
			],
			[ 'limit' => 9 ],
			[
				'items' => [
					new WC_Order_Item_Product(
						[
							'id'         => 9041,
							'product_id' => 123,
							'quantity'   => 2,
						]
					),
				],
			]
		);

		$this->assertSame( 2, Group_Subscription::get_member_capacity( $subscription ), 'Per-seat capacity should ignore the subscription limit meta override and use the line item quantity instead.' );
	}

	/*
	 * --- product editor pricing options ---
	 */

	/**
	 * The product editor's pricing options include the mode select (with a
	 * per-seat choice) and the seat-bound number fields, and the existing
	 * member-limit field is scoped to per-team mode.
	 */
	public function test_pricing_options_include_mode_and_seat_bounds() {
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}

		$options = Group_Subscription_Settings::add_custom_product_pricing_options( [] );

		$this->assertSame( 'select', $options['newspack_group_subscription_pricing_mode']['type'] );
		$this->assertArrayHasKey( 'per_seat', $options['newspack_group_subscription_pricing_mode']['options'] );
		$this->assertSame( 'number', $options['newspack_group_subscription_min_seats']['type'] );
		$this->assertStringContainsString( 'show_if_newspack_group_subscription_per_seat', $options['newspack_group_subscription_max_seats']['wrapper_class'] );
		$this->assertStringContainsString( 'show_if_newspack_group_subscription_per_team', $options['newspack_group_subscription_limit']['wrapper_class'] );
	}

	/*
	 * --- admin seat override ---
	 */

	/**
	 * Build a per-seat group subscription with a priced seat line item, and
	 * optionally seed the group with members and pending invitations.
	 *
	 * Mirrors the shape the seats test class uses, kept local so the two files
	 * stay independent.
	 *
	 * @param int   $id   Subscription ID. The product and line item IDs are derived from it.
	 * @param array $args quantity, subtotal, total, members (count), pending_invites (count),
	 *                    per_seat (bool), items (raw override, e.g. [] for no line item).
	 *
	 * @return WC_Subscription
	 */
	private function make_per_seat_subscription( $id, $args = [] ) {
		$args = array_merge(
			[
				'quantity'        => 1,
				'subtotal'        => 0,
				'total'           => 0,
				'members'         => 0,
				'pending_invites' => 0,
				'per_seat'        => true,
			],
			$args
		);

		$prefix     = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;
		$product_id = $id + 1000;
		wc_create_mock_product(
			[
				'id'   => $product_id,
				'type' => 'subscription',
				'meta' => [
					$prefix . 'enabled'      => 'yes',
					$prefix . 'pricing_mode' => $args['per_seat']
						? Group_Subscription_Settings::PRICING_MODE_PER_SEAT
						: Group_Subscription_Settings::PRICING_MODE_PER_TEAM,
					$prefix . 'limit'        => '10',
				],
			]
		);

		$invites = [];
		for ( $i = 0; $i < $args['pending_invites']; $i++ ) {
			$invites[ 'pending-' . $i ] = [
				'email'      => 'pending-' . $i . '@example.com',
				'expiration' => time() + HOUR_IN_SECONDS,
			];
		}

		$items = isset( $args['items'] ) ? $args['items'] : [
			new WC_Order_Item_Product(
				[
					'id'         => $id + 5000,
					'product_id' => $product_id,
					'quantity'   => $args['quantity'],
					'subtotal'   => $args['subtotal'],
					'total'      => $args['total'],
				]
			),
		];

		$subscription = wcs_create_subscription(
			[
				'id'          => $id,
				'customer_id' => self::factory()->user->create(),
				'status'      => 'active',
				'meta'        => [ Group_Subscription_Invite::META => $invites ],
				'items'       => $items,
			]
		);

		for ( $i = 0; $i < $args['members']; $i++ ) {
			add_user_meta( self::factory()->user->create(), Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $id );
		}
		Group_Subscription::reset_cache();

		return $subscription;
	}

	/**
	 * Render the metabox for a subscription and return its markup.
	 *
	 * @param WC_Subscription $subscription The subscription to render.
	 *
	 * @return string The rendered markup.
	 */
	private function render_metabox( $subscription ) {
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		ob_start();
		Group_Subscription_Settings::add_group_subscription_options( $subscription );
		return ob_get_clean();
	}

	/**
	 * Raising the seat count rescales the line item in place: the quantity, the
	 * subtotal and the total all move together, on the same subscription, and the
	 * new quantity is the group's new capacity.
	 */
	public function test_set_seat_quantity_rescales_line_item() {
		$subscription = $this->make_per_seat_subscription(
			941,
			[
				'quantity' => 4,
				'subtotal' => 40,
				'total'    => 40,
			]
		);

		$this->assertTrue( Group_Subscription_Settings::set_seat_quantity( $subscription, 6 ) );

		$item = Group_Subscription_Settings::get_seat_line_item( $subscription );
		$this->assertSame( 6, $item->get_quantity(), 'The line item quantity is the purchased seat count.' );
		$this->assertSame( 60.0, (float) $item->get_subtotal(), 'The subtotal rescales at the same unit price.' );
		$this->assertSame( 60.0, (float) $item->get_total(), 'The total rescales at the same unit price.' );
		$this->assertSame( 6, Group_Subscription::get_member_capacity( $subscription ), 'Capacity follows the seat count.' );
	}

	/**
	 * Seats cannot be cut below the people already sitting in them: the owner,
	 * every member, and every invitation still waiting to be accepted.
	 */
	public function test_set_seat_quantity_rejects_below_occupancy() {
		$subscription = $this->make_per_seat_subscription(
			942,
			[
				'quantity' => 4,
				'subtotal' => 40,
				'total'    => 40,
				'members'  => 3,
			]
		);

		// Owner + 3 members = 4 seats in use.
		$this->assertWPError( Group_Subscription_Settings::set_seat_quantity( $subscription, 3 ) );
		$this->assertSame( 4, Group_Subscription_Settings::get_seat_line_item( $subscription )->get_quantity(), 'A rejected change leaves the line item alone.' );
	}

	/**
	 * A group always has at least the owner's seat, so a zero or negative count
	 * floors at one rather than erroring.
	 */
	public function test_set_seat_quantity_floors_at_one() {
		$subscription = $this->make_per_seat_subscription(
			943,
			[
				'quantity' => 4,
				'subtotal' => 40,
				'total'    => 40,
			]
		);

		$this->assertTrue( Group_Subscription_Settings::set_seat_quantity( $subscription, 0 ) );
		$this->assertSame( 1, Group_Subscription_Settings::get_seat_line_item( $subscription )->get_quantity() );
		$this->assertSame( 10.0, (float) Group_Subscription_Settings::get_seat_line_item( $subscription )->get_subtotal() );
	}

	/**
	 * There is nothing to rescale on a subscription with no line item, so the
	 * caller gets an error rather than a silent no-op.
	 */
	public function test_set_seat_quantity_errors_without_a_line_item() {
		$subscription = $this->make_per_seat_subscription( 944, [ 'items' => [] ] );

		$result = Group_Subscription_Settings::set_seat_quantity( $subscription, 5 );

		$this->assertWPError( $result );
		$this->assertSame( Group_Subscription_Seats::ERROR_CODE, $result->get_error_code() );
	}

	/**
	 * A per-seat group's metabox offers the seat count, not the member limit:
	 * capacity is what was bought, so there is no limit to override.
	 */
	public function test_metabox_renders_seats_field_for_per_seat_subscription() {
		$subscription = $this->make_per_seat_subscription(
			945,
			[
				'quantity' => 5,
				'members'  => 2,
			]
		);
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		$markup = $this->render_metabox( $subscription );

		$this->assertStringContainsString( 'name="' . $prefix . 'seats"', $markup, 'The seats field is rendered.' );
		$this->assertStringContainsString( 'name="' . $prefix . 'seats_baseline" value="5"', $markup, 'The baseline carries the seat count the field was rendered with.' );
		$this->assertStringNotContainsString( 'name="' . $prefix . 'limit"', $markup, 'The limit field has no meaning in per-seat mode.' );
		$this->assertStringNotContainsString( 'name="' . $prefix . 'limit_baseline"', $markup, 'An unposted limit baseline would read as a change to 0 on save.' );
	}

	/**
	 * A flat group's metabox is unchanged: the member limit stays, and no seat
	 * field appears.
	 */
	public function test_metabox_keeps_limit_field_for_flat_subscription() {
		$subscription = $this->make_per_seat_subscription(
			946,
			[
				'quantity' => 1,
				'per_seat' => false,
			]
		);
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		$markup = $this->render_metabox( $subscription );

		$this->assertStringContainsString( 'name="' . $prefix . 'limit"', $markup, 'The limit field still drives a flat group.' );
		$this->assertStringContainsString( 'name="' . $prefix . 'limit_baseline"', $markup, 'The limit baseline still ships with it.' );
		$this->assertStringNotContainsString( 'name="' . $prefix . 'seats"', $markup, 'A flat group buys one price, not seats.' );
	}

	/**
	 * Saving a changed seat count rescales the subscription. No charge is raised:
	 * readers buy seats through the switch, and this is the support-side correction.
	 */
	public function test_save_rescales_seats_when_changed_from_baseline() {
		$subscription = $this->make_per_seat_subscription(
			947,
			[
				'quantity' => 4,
				'subtotal' => 40,
				'total'    => 40,
			]
		);
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		$this->run_meta_box_save(
			$subscription,
			[
				$prefix . 'enabled'          => 'yes',
				$prefix . 'enabled_baseline' => 'yes',
				$prefix . 'seats'            => '7',
				$prefix . 'seats_baseline'   => '4',
			]
		);

		$item = Group_Subscription_Settings::get_seat_line_item( $subscription );
		$this->assertSame( 7, $item->get_quantity(), 'The submitted seat count is applied.' );
		$this->assertSame( 70.0, (float) $item->get_subtotal(), 'The price rescales with the seats.' );
		$this->assertSame( 7, Group_Subscription::get_member_capacity( $subscription ) );
	}

	/**
	 * An untouched seats field must not rescale anything, even when the rendered
	 * baseline has drifted from the line item: only a real edit is a change.
	 */
	public function test_save_ignores_seats_when_unchanged_from_baseline() {
		$subscription = $this->make_per_seat_subscription(
			948,
			[
				'quantity' => 4,
				'subtotal' => 40,
				'total'    => 40,
			]
		);
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		$this->run_meta_box_save(
			$subscription,
			[
				$prefix . 'enabled'          => 'yes',
				$prefix . 'enabled_baseline' => 'yes',
				$prefix . 'seats'            => '6',
				$prefix . 'seats_baseline'   => '6',
			]
		);

		$this->assertSame( 4, Group_Subscription_Settings::get_seat_line_item( $subscription )->get_quantity(), 'A field the admin never touched leaves the line item alone.' );
	}

	/**
	 * A seat cut the group cannot absorb is refused, and the admin is told why
	 * rather than being left to wonder why the number sprang back.
	 */
	public function test_save_surfaces_error_when_seats_below_occupancy() {
		$subscription = $this->make_per_seat_subscription(
			949,
			[
				'quantity'        => 5,
				'subtotal'        => 50,
				'total'           => 50,
				'members'         => 2,
				'pending_invites' => 1,
			]
		);
		$prefix = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;

		// Owner + 2 members + 1 pending invitation = 4 seats in use.
		$this->run_meta_box_save(
			$subscription,
			[
				$prefix . 'enabled'          => 'yes',
				$prefix . 'enabled_baseline' => 'yes',
				$prefix . 'seats'            => '3',
				$prefix . 'seats_baseline'   => '5',
			]
		);

		$this->assertSame( 5, Group_Subscription_Settings::get_seat_line_item( $subscription )->get_quantity(), 'The rejected change leaves the line item alone.' );
		global $wc_mock_meta_box_errors;
		$this->assertCount( 1, $wc_mock_meta_box_errors, 'The admin is shown one error.' );
		$this->assertStringContainsString( '4', $wc_mock_meta_box_errors[0], 'The error names the seats in use.' );
	}
}
