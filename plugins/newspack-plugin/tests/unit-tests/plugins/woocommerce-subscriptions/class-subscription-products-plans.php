<?php
/**
 * Tests for the subscription products layer under subscription plans.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscription_Products;
use Newspack\Subscription_Products\Purchase_Option;

/**
 * Products carrying WooCommerce Subscriptions' subscription plans (WCS_ATT_*).
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscription_Products_Plans extends WP_UnitTestCase {
	/**
	 * Two plans shared by most of the tests below.
	 *
	 * @var array
	 */
	const PLANS = [
		'1_month' => [
			'period'   => 'month',
			'interval' => 1,
			'price'    => 8,
		],
		'1_year'  => [
			'period'   => 'year',
			'interval' => 1,
			'price'    => 80,
		],
	];

	/**
	 * Load the subscription plans mocks once for the class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 3 ) . '/mocks/wcs-plans-mocks.php';
	}

	/**
	 * Plans_Model::find_product_ids() queries real product posts, so each test needs the post type
	 * registered. Also re-hooks the plans mocks' `woocommerce_is_subscription` filter: WP_UnitTestCase
	 * snapshots $wp_filter once, at the very first test of the whole run, and restores that snapshot
	 * after every test's tear_down() — so a filter added once at require_once time (here, by
	 * wcs-plans-mocks.php, loaded only in setUpBeforeClass()) survives only if this class happens to be
	 * the first to run in the process. Re-adding it per test makes that independent of suite order.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type( 'product', [ 'public' => true ] );
		add_filter( 'woocommerce_is_subscription', [ 'WCS_ATT_Product_Schemes', 'filter_is_subscription' ], 10, 3 );
	}

	/**
	 * Reset the mock products database, the plans mocks, and the facade's per-request caches.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		WCS_ATT_Product_Schemes::mock_reset();
		WCS_ATT_Product::$mock_default_mode = 'disable';
		Subscription_Products::flush_cache();
		parent::tear_down();
	}

	/**
	 * A real product post (so find_products' post query sees it) plus its mock.
	 *
	 * @param array $data Overrides for wc_create_mock_product()'s data.
	 * @param array $meta Post meta to set on the underlying product post.
	 */
	private function product( array $data, array $meta = [] ) {
		$id = self::factory()->post->create(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
			] 
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return wc_create_mock_product(
			array_merge(
				[
					'id'            => $id,
					'type'          => 'simple',
					'regular_price' => '10',
					'price'         => '10',
				],
				$data 
			) 
		);
	}

	/**
	 * A product sold both one-time and on the two shared plans.
	 */
	private function hybrid() {
		$product = $this->product( [], [ '_wcsatt_schemes_status' => 'override' ] );
		WCS_ATT_Product_Schemes::mock_register( $product->get_id(), self::PLANS );
		return $product;
	}

	/**
	 * A hybrid product offers a subscription and lists one-time plus each plan, in order.
	 */
	public function test_hybrid_offers_one_time_and_each_plan() {
		$product = $this->hybrid();
		$this->assertTrue( Subscription_Products::offers_subscription( $product ) );
		$options = Subscription_Products::get_purchase_options( $product );
		$this->assertSame( [ 'one_time', 'plan:1_month', 'plan:1_year' ], wp_list_pluck( $options, 'key' ) );
		$this->assertSame( [ 'once_1', 'month_1', 'year_1' ], array_map( fn( $o ) => $o->get_frequency(), $options ) );
		$this->assertSame( [ 10.0, 8.0, 80.0 ], wp_list_pluck( $options, 'price' ) );
	}

	/**
	 * A product forced onto a plan offers no one-time option, only its plans.
	 */
	public function test_forced_product_has_no_one_time_option() {
		$product = $this->product( [], [ '_wcsatt_schemes_status' => 'override' ] );
		WCS_ATT_Product_Schemes::mock_register( $product->get_id(), self::PLANS, true );
		$this->assertSame( [ 'plan:1_month', 'plan:1_year' ], wp_list_pluck( Subscription_Products::get_purchase_options( $product ), 'key' ) );
	}

	/**
	 * `_wcsatt_disabled` turns off plans on the product regardless of its scheme configuration.
	 */
	public function test_disabled_plans_offer_nothing() {
		$product = $this->product( [ 'meta' => [ '_wcsatt_disabled' => 'yes' ] ] );
		WCS_ATT_Product_Schemes::mock_register( $product->get_id(), self::PLANS );
		$this->assertFalse( Subscription_Products::offers_subscription( $product ) );
	}

	/**
	 * A legacy subscription product type never sells on plans, even with plans registered for it.
	 */
	public function test_legacy_type_never_takes_the_plan_path() {
		$product = $this->product(
			[
				'type' => 'subscription',
				'meta' => [
					'_subscription_price'           => '10',
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);
		WCS_ATT_Product_Schemes::mock_register( $product->get_id(), self::PLANS );
		$this->assertSame( [ 'legacy' ], wp_list_pluck( Subscription_Products::get_purchase_options( $product ), 'key' ) );
	}

	/**
	 * A variation inherits its parent's plans and posts the plan field against the parent ID.
	 */
	public function test_variation_inherits_parent_plans() {
		$parent    = $this->product( [ 'type' => 'variable' ], [ '_wcsatt_schemes_status' => 'override' ] );
		$variation = wc_create_mock_product(
			[
				'id'            => $parent->get_id() + 1000,
				'type'          => 'variation',
				'parent_id'     => $parent->get_id(),
				'regular_price' => '12',
				'price'         => '12',
			] 
		);
		$parent    = wc_create_mock_product(
			[
				'id'       => $parent->get_id(),
				'type'     => 'variable',
				'children' => [ $variation->get_id() ],
			] 
		);
		WCS_ATT_Product_Schemes::mock_register( $parent->get_id(), self::PLANS );

		$this->assertTrue( Subscription_Products::offers_subscription( $variation ) );
		$via_parent    = Subscription_Products::get_purchase_options( $parent );
		$via_variation = Subscription_Products::get_purchase_options( $variation );
		$this->assertSame( wp_list_pluck( $via_parent, 'key' ), wp_list_pluck( $via_variation, 'key' ) );
		$this->assertSame( $variation->get_id(), $via_variation[1]->product_id );
		$this->assertSame(
			[ 'convert_to_sub_' . $parent->get_id() => '1_month' ],
			$via_variation[1]->get_plan_request_args(),
			'WooCommerce reads the plan from the parent product ID.'
		);
	}

	/**
	 * The one-time option posts '0'; a plan option posts its plan key.
	 */
	public function test_plan_request_args() {
		$product = $this->hybrid();
		$options = Subscription_Products::get_purchase_options( $product );
		$field   = 'convert_to_sub_' . $product->get_id();
		$this->assertSame( [ $field => '0' ], $options[0]->get_plan_request_args() );
		$this->assertSame( [ $field => '1_year' ], $options[2]->get_plan_request_args() );
	}

	/**
	 * A hybrid product is not a subscription until a plan is applied to an instance of it.
	 */
	public function test_is_purchased_as_subscription_follows_the_chosen_plan() {
		$product = $this->hybrid();
		$this->assertFalse( Subscription_Products::is_purchased_as_subscription( $product ), 'No plan chosen yet.' );
		$options  = Subscription_Products::get_purchase_options( $product );
		$instance = Subscription_Products::get_option_product( $options[1] );
		$this->assertTrue( Subscription_Products::is_purchased_as_subscription( $instance ) );
		$this->assertSame( 'plan:1_month', Subscription_Products::get_instance_option( $instance )->key );
	}

	/**
	 * A product forced onto a plan is not a subscription until a plan is actually applied to
	 * an instance of it: WooCommerce applies a forced product's default plan only in the cart,
	 * never to the bare catalog product.
	 */
	public function test_forced_bare_product_is_not_a_subscription_until_a_plan_is_applied() {
		$product = $this->product( [], [ '_wcsatt_schemes_status' => 'override' ] );
		WCS_ATT_Product_Schemes::mock_register( $product->get_id(), self::PLANS, true );
		$this->assertFalse( Subscription_Products::is_purchased_as_subscription( $product ), 'No plan applied yet.' );
		$options = Subscription_Products::get_purchase_options( $product );
		$this->assertSame( [ 'plan:1_month', 'plan:1_year' ], wp_list_pluck( $options, 'key' ), 'Forced: still no one-time option.' );
		$instance = Subscription_Products::get_option_product( $options[0] );
		$this->assertTrue( Subscription_Products::is_purchased_as_subscription( $instance ) );
	}

	/**
	 * A product forced onto its plans has no one-time option at all, so it is
	 * subscription-only even on the bare catalog instance; a product sold both
	 * ways keeps a one-time option and so is not.
	 */
	public function test_is_subscription_only_for_plan_products() {
		$forced = $this->product( [], [ '_wcsatt_schemes_status' => 'override' ] );
		WCS_ATT_Product_Schemes::mock_register( $forced->get_id(), self::PLANS, true );
		$this->assertTrue( Subscription_Products::is_subscription_only( $forced ) );

		$hybrid = $this->hybrid();
		$this->assertFalse( Subscription_Products::is_subscription_only( $hybrid ) );
	}

	/**
	 * `find_products()` includes a plan-based product but not a plain one.
	 */
	public function test_find_products_includes_plan_products() {
		$hybrid = $this->hybrid();
		$this->product( [] ); // Plain product.
		$this->assertSame( [ $hybrid->get_id() ], array_map( fn( $p ) => $p->get_id(), Subscription_Products::find_products() ) );
	}

	/**
	 * `_wcsatt_schemes_status = inherit` alone is not enough: without a store-wide plan
	 * actually registered for the product, it is not found.
	 */
	public function test_find_products_drops_inherit_without_storewide_plans() {
		$this->product( [], [ '_wcsatt_schemes_status' => 'inherit' ] ); // No plans registered.
		$this->assertSame( [], Subscription_Products::find_products() );
	}

	/**
	 * `_wcsatt_storewide_selection_mode` is the pre-9.0 meta key WooCommerce still
	 * falls back to for a product that never got `_wcsatt_schemes_status` written
	 * (its presence alone means "inherit", whatever its value): a candidate query
	 * that ignores it would silently drop such a product from `find_products()`.
	 */
	public function test_find_products_includes_legacy_storewide_selection_mode_meta() {
		$product = $this->product( [], [ '_wcsatt_storewide_selection_mode' => 'yes' ] );
		WCS_ATT_Product_Schemes::mock_register( $product->get_id(), self::PLANS );
		$this->assertSame( [ $product->get_id() ], array_map( fn( $p ) => $p->get_id(), Subscription_Products::find_products() ) );
	}

	/**
	 * `find_product_ids()` only widens to every product once the site's default scheme
	 * mode itself offers plans; a product with no plan meta at all is otherwise skipped.
	 */
	public function test_find_products_checks_all_products_when_default_mode_offers_plans() {
		$product = $this->product( [] ); // No plan meta at all.
		WCS_ATT_Product_Schemes::mock_register( $product->get_id(), self::PLANS );
		$this->assertSame( [], Subscription_Products::find_products(), 'Default mode off: no meta, not found.' );
		Subscription_Products::flush_cache();
		WCS_ATT_Product::$mock_default_mode = 'inherit';
		$this->assertSame( [ $product->get_id() ], array_map( fn( $p ) => $p->get_id(), Subscription_Products::find_products() ) );
	}

	/**
	 * The plan a reader bought comes from the order item's own meta, not the product.
	 */
	public function test_purchased_plan_key_comes_from_the_line_item() {
		$item = new WC_Order_Item_Product( [ 'meta' => [ '_wcsatt_scheme' => '1_year' ] ] );
		$this->assertSame( '1_year', Subscription_Products::get_purchased_plan_key( $item ) );
	}

	/**
	 * The option a request posted for a product: a variation's plan is read from
	 * its parent's field, as WooCommerce reads it at add-to-cart; nothing posted,
	 * the one-time choice, or an unknown plan is no option.
	 */
	public function test_posted_plan_option_reads_the_parent_field() {
		$parent    = $this->product(
			[
				'type'     => 'variable',
				'children' => [],
			],
			[ '_wcsatt_schemes_status' => 'override' ]
		);
		$parent_id = $parent->get_id();
		WCS_ATT_Product_Schemes::mock_register( $parent_id, self::PLANS );
		$variation = wc_create_mock_product(
			[
				'id'            => $parent_id + 1000,
				'type'          => 'variation',
				'parent_id'     => $parent_id,
				'regular_price' => '10',
			]
		);
		$field     = 'convert_to_sub_' . $parent_id;

		try {
			$this->assertNull( Subscription_Products::get_posted_plan_option( $variation ), 'Nothing posted.' );

			$_REQUEST[ $field ] = '1_year';
			$option             = Subscription_Products::get_posted_plan_option( $variation );
			$this->assertSame( 'plan:1_year', $option->key );
			$this->assertSame( $variation->get_id(), $option->product_id );

			$_REQUEST[ $field ] = '0';
			$this->assertNull( Subscription_Products::get_posted_plan_option( $variation ), 'One-time purchase.' );

			$_REQUEST[ $field ] = 'no_such_plan';
			$this->assertNull( Subscription_Products::get_posted_plan_option( $variation ), 'Unknown plan.' );
		} finally {
			unset( $_REQUEST[ $field ] );
		}
	}

	/**
	 * A legacy product has no plan to post, whatever the request carries.
	 */
	public function test_posted_plan_option_is_null_for_legacy_products() {
		$legacy = wc_create_mock_product(
			[
				'id'   => 777,
				'type' => 'subscription',
			]
		);
		WCS_ATT_Product_Schemes::mock_register( 777, self::PLANS );
		$_REQUEST['convert_to_sub_777'] = '1_month';
		try {
			$this->assertNull( Subscription_Products::get_posted_plan_option( $legacy ) );
		} finally {
			unset( $_REQUEST['convert_to_sub_777'] );
		}
	}

	/**
	 * The deprecated WooCommerce_Subscriptions::is_subscription_product() alias keeps the
	 * instance-level semantics it always had: false for a bare hybrid product, true once a
	 * plan is applied to an instance of it.
	 */
	public function test_deprecated_helper_keeps_instance_semantics() {
		$product = $this->hybrid();
		$this->assertFalse( \Newspack\WooCommerce_Subscriptions::is_subscription_product( $product ), 'Unchanged from main: bare hybrid is not a subscription purchase.' );
		$options  = Subscription_Products::get_purchase_options( $product );
		$instance = Subscription_Products::get_option_product( $options[1] );
		$this->assertTrue( \Newspack\WooCommerce_Subscriptions::is_subscription_product( $instance ), 'A plan applied to the instance makes it a subscription purchase.' );
	}
}
