<?php
/**
 * Subscription tiers under subscription plans, plus a legacy control.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscription_Products;
use Newspack\Subscriptions_Tiers;

/**
 * Subscription tiers for products sold on WooCommerce Subscriptions' subscription plans.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscriptions_Tiers_Plans extends WP_UnitTestCase {
	/**
	 * Load the subscription plans mocks once for the class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 3 ) . '/mocks/wcs-plans-mocks.php';
	}

	/**
	 * Re-hook the plans mocks' `woocommerce_is_subscription` filter, which WP_UnitTestCase's
	 * hook snapshot drops unless this class happens to run first (see
	 * class-subscription-products-plans.php).
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( 0 );
		register_post_type( 'product', [ 'public' => true ] );
		add_filter( 'woocommerce_is_subscription', [ 'WCS_ATT_Product_Schemes', 'filter_is_subscription' ], 10, 3 );
	}

	/**
	 * Reset the mock databases, the plans mocks, and the facade's per-request caches.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		WCS_ATT_Product_Schemes::mock_reset();
		Subscription_Products::flush_cache();
		wp_set_current_user( 0 );
		unset( $_REQUEST['switch-subscription'], $_REQUEST['item'], $_REQUEST['convert_to_sub_20'] );
		$links = new ReflectionProperty( Subscriptions_Tiers::class, 'switch_subscription_links' );
		$links->setAccessible( true );
		$links->setValue( null, [] );
		parent::tear_down();
	}

	/**
	 * A legacy variable subscription: two monthly variations and a yearly one.
	 */
	private function legacy_variable() {
		foreach ( [
			13 => [ 'month', '8' ],
			11 => [ 'month', '5' ],
			12 => [ 'year', '50' ],
		] as $id => $data ) {
			wc_create_mock_product(
				[
					'id'        => $id,
					'type'      => 'subscription_variation',
					'parent_id' => 10,
					'price'     => $data[1],
					'meta'      => [
						'_subscription_period'          => $data[0],
						'_subscription_period_interval' => '1',
						'_subscription_price'           => $data[1],
					],
				]
			);
		}
		return wc_create_mock_product(
			[
				'id'       => 10,
				'type'     => 'variable-subscription',
				'children' => [ 13, 11, 12 ],
			]
		);
	}

	/**
	 * A plain variable product sold on subscription plans, with two variations
	 * priced 10 and 20 (IDs parent * 10 + 1 and + 2).
	 *
	 * @param array $plans     Plans by key, as WCS_ATT_Product_Schemes::mock_register() takes them.
	 * @param int   $parent_id Parent product ID.
	 * @param bool  $forced    Whether the product sells on plans only.
	 */
	private function plan_variable( array $plans, int $parent_id = 20, bool $forced = true ) {
		$children = [ $parent_id * 10 + 1, $parent_id * 10 + 2 ];
		foreach ( array_combine( $children, [ '10', '20' ] ) as $id => $price ) {
			wc_create_mock_product(
				[
					'id'            => $id,
					'type'          => 'variation',
					'name'          => 'Tier ' . $id,
					'parent_id'     => $parent_id,
					'price'         => $price,
					'regular_price' => $price,
				]
			);
		}
		$product = wc_create_mock_product(
			[
				'id'       => $parent_id,
				'type'     => 'variable',
				'children' => $children,
			]
		);
		WCS_ATT_Product_Schemes::mock_register( $parent_id, $plans, $forced );
		return $product;
	}

	/**
	 * Render the tiers form and return its markup.
	 *
	 * @param \WC_Product|null $product     Product.
	 * @param array|null       $switch_data Switch data, or null for a plain purchase.
	 */
	private function render( $product, $switch_data = null ) {
		ob_start();
		Subscriptions_Tiers::render_form( $product, null, null, $switch_data );
		return ob_get_clean();
	}

	/**
	 * Every input posting on the given plan field.
	 *
	 * @param string $html  Markup.
	 * @param string $field Field name.
	 * @return string[]
	 */
	private function plan_inputs( $html, $field ) {
		preg_match_all( '/<input[^>]*name="' . preg_quote( $field, '/' ) . '"[^>]*>/s', $html, $inputs );
		return $inputs[0];
	}

	/**
	 * The checked inputs among the given ones.
	 *
	 * @param string[] $inputs Input tags.
	 * @return string[]
	 */
	private function checked( array $inputs ) {
		return array_values( array_filter( $inputs, fn( $input ) => false !== strpos( $input, 'checked' ) ) );
	}

	/**
	 * A logged-in reader's active subscription to a plan variation.
	 *
	 * @param int    $variation_id Variation held.
	 * @param int    $parent_id    Its parent.
	 * @param string $plan_key     Plan recorded on the line item, or '' for none.
	 * @param string $period       Subscription billing period.
	 * @return array Switch data for the line item.
	 */
	private function plan_subscription( $variation_id, $parent_id, $plan_key, $period ) {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$item         = new WC_Order_Item_Product(
			[
				'id'           => 900,
				'product_id'   => $parent_id,
				'variation_id' => $variation_id,
				'quantity'     => 1,
				'subtotal'     => 10,
				'total'        => 10,
				'meta'         => $plan_key ? [ '_wcsatt_scheme' => $plan_key ] : [],
			]
		);
		$subscription = wcs_create_subscription(
			[
				'customer_id'      => $user_id,
				'status'           => 'active',
				'items'            => [ $item ],
				'billing_period'   => $period,
				'billing_interval' => 1,
			]
		);
		return [
			'item_id'      => 900,
			'item'         => $item,
			'subscription' => $subscription,
		];
	}

	/**
	 * Legacy tiers bucket by frequency and sort by price exactly as before plans existed.
	 */
	public function test_legacy_tiers_are_unchanged() {
		$tiers = Subscriptions_Tiers::get_tiers_by_frequency( $this->legacy_variable() );
		$this->assertSame( [ 'month_1', 'year_1' ], array_keys( $tiers ) );
		$this->assertSame( [ 11, 13 ], array_map( fn( $p ) => $p->get_id(), $tiers['month_1'] ) );
		$this->assertSame( [ 12 ], array_map( fn( $p ) => $p->get_id(), $tiers['year_1'] ) );
	}

	/**
	 * An annual plan is bucketed as annual and priced from the plan, and every
	 * variation is sold on every plan.
	 */
	public function test_plan_frequency_and_price_come_from_the_plan() {
		$tiers = Subscriptions_Tiers::get_tiers_by_frequency(
			$this->plan_variable(
				[
					'1_month' => [
						'period'   => 'month',
						'interval' => 1,
						'price'    => 5,
					],
					'1_year'  => [
						'period'   => 'year',
						'interval' => 1,
						'price'    => 50,
					],
				]
			)
		);
		$this->assertSame( [ 'month_1', 'year_1' ], array_keys( $tiers ) );
		$this->assertCount( 2, $tiers['year_1'], 'Every variation is sold on every plan.' );
		$option = Subscription_Products::get_instance_option( $tiers['year_1'][0] );
		$this->assertSame( 'plan:1_year', $option->key );
		$this->assertSame( 50.0, $option->price );
	}

	/**
	 * The form posts the chosen plan on the parent's field, with exactly one plan
	 * selected, so buying a tier starts a subscription instead of charging once.
	 */
	public function test_form_posts_the_chosen_plan() {
		$html = $this->render(
			$this->plan_variable(
				[
					'1_month' => [ 'period' => 'month' ],
					'1_year'  => [ 'period' => 'year' ],
				]
			)
		);
		$inputs = $this->plan_inputs( $html, 'convert_to_sub_20' );
		$this->assertCount( 2, $inputs, 'One plan input per plan.' );
		$this->assertStringContainsString( 'type="radio"', $inputs[0] );
		$this->assertStringContainsString( 'value="1_month"', $inputs[0] );
		$this->assertStringContainsString( 'value="1_year"', $inputs[1] );
		$checked = $this->checked( $inputs );
		$this->assertCount( 1, $checked, 'One plan is always selected, so a plan is always posted.' );
		$this->assertStringContainsString( 'value="1_month"', $checked[0], 'The first, initially visible, plan is the selected one.' );
	}

	/**
	 * A product on a single plan has no plan control to render, so the form
	 * carries the plan in a hidden input.
	 */
	public function test_single_plan_still_posts_the_plan() {
		$html = $this->render( $this->plan_variable( [ '1_month' => [ 'period' => 'month' ] ] ) );
		$this->assertSame( [ '<input type="hidden" name="convert_to_sub_20" value="1_month">' ], $this->plan_inputs( $html, 'convert_to_sub_20' ) );
	}

	/**
	 * A simple product on two plans holds one tier per plan, which used to take the
	 * flat card layout: every plan's card printed while nothing posted which plan
	 * was chosen. More than one plan always renders the control that posts it.
	 */
	public function test_one_tier_per_plan_still_posts_the_chosen_plan() {
		wc_create_mock_product(
			[
				'id'    => 46,
				'type'  => 'simple',
				'price' => '5',
			]
		);
		WCS_ATT_Product_Schemes::mock_register(
			46,
			[
				'1_month' => [ 'period' => 'month' ],
				'1_year'  => [ 'period' => 'year' ],
			]
		);
		$inputs = $this->plan_inputs( $this->render( wc_get_product( 46 ) ), 'convert_to_sub_46' );
		$this->assertCount( 2, $inputs );
		foreach ( $inputs as $input ) {
			$this->assertStringContainsString( 'type="radio"', $input, 'The reader chooses the plan.' );
		}
		$this->assertCount( 1, $this->checked( $inputs ) );
	}

	/**
	 * Two plans sharing a billing period each keep their own bucket, in plan order,
	 * and each bucket still reads as that period.
	 */
	public function test_two_plans_same_frequency_get_separate_buckets() {
		$product = $this->plan_variable(
			[
				'monthly'          => [ 'period' => 'month' ],
				'monthly_discount' => [
					'period' => 'month',
					'price'  => 3,
				],
			]
		);
		$tiers   = Subscriptions_Tiers::get_tiers_by_frequency( $product );
		$this->assertSame( [ 'month_1', 'month_1_2' ], array_keys( $tiers ), 'Both plans stay visible.' );
		$keys = [];
		foreach ( $tiers as $products ) {
			$keys[] = Subscription_Products::get_instance_option( $products[0] )->plan_key;
		}
		$this->assertSame( [ 'monthly', 'monthly_discount' ], $keys );

		$html = $this->render( $product );
		$this->assertSame( 2, substr_count( $html, 'Monthly' ), 'The second monthly bucket is labelled as monthly too.' );
	}

	/**
	 * A grouped form carries one plan field per parent, so it cannot post a plan
	 * for a plan-based child; the child is left out rather than sold once.
	 */
	public function test_plan_children_of_grouped_products_are_skipped() {
		wc_create_mock_product(
			[
				'id'    => 31,
				'type'  => 'simple',
				'price' => '5',
			]
		);
		WCS_ATT_Product_Schemes::mock_register( 31, [ '1_month' => [ 'period' => 'month' ] ], true );
		$grouped = wc_create_mock_product(
			[
				'id'       => 30,
				'type'     => 'grouped',
				'children' => [ 31 ],
			]
		);
		$this->assertSame( [], Subscriptions_Tiers::get_tiers_by_frequency( $grouped ) );
	}

	/**
	 * Leaving out a grouped product's plan-based child keeps its legacy children,
	 * and the form posts no plan field keyed on the grouped parent.
	 */
	public function test_grouped_product_keeps_its_legacy_children() {
		wc_create_mock_product(
			[
				'id'    => 36,
				'type'  => 'subscription',
				'price' => '5',
				'meta'  => [
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);
		$this->plan_variable( [ '1_month' => [ 'period' => 'month' ] ], 37 );
		$grouped = wc_create_mock_product(
			[
				'id'       => 35,
				'type'     => 'grouped',
				'children' => [ 36, 37 ],
			]
		);
		$tiers   = Subscriptions_Tiers::get_tiers_by_frequency( $grouped );
		$this->assertSame( [ 'month_1' ], array_keys( $tiers ) );
		$this->assertSame( [ 36 ], array_map( fn( $p ) => $p->get_id(), $tiers['month_1'] ) );
		$this->assertStringNotContainsString( 'convert_to_sub', $this->render( $grouped ) );
	}

	/**
	 * A grouped product whose child was deleted renders without a fatal.
	 */
	public function test_deleted_grouped_child_does_not_fatal() {
		$grouped = wc_create_mock_product(
			[
				'id'       => 40,
				'type'     => 'grouped',
				'children' => [ 999999 ],
			]
		);
		$this->assertSame( [], Subscriptions_Tiers::get_tier_eligible_products( [ 'grouped' ] ) );
		$this->assertSame( [], Subscriptions_Tiers::get_tiers_by_frequency( $grouped ) );
	}

	/**
	 * Plan products are plain simple/variable products, which no type list finds,
	 * yet a subscription-only (forced) one is offered for tier configuration: its
	 * product-only pickers (countdown banner, gifting prompt) still start a
	 * subscription, since WooCommerce applies the forced default plan in the cart.
	 */
	public function test_plan_products_are_tier_eligible() {
		$id = self::factory()->post->create(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $id, '_wcsatt_schemes_status', 'override' );
		$this->plan_variable( [ '1_month' => [ 'period' => 'month' ] ], $id, true );
		$ids = array_map( fn( $p ) => $p->get_id(), Subscriptions_Tiers::get_tier_eligible_products() );
		$this->assertContains( $id, $ids );
	}

	/**
	 * A hybrid product, sold both one-time and on plans, is left out of
	 * get_tier_eligible_products(): its product-only pickers (countdown banner,
	 * gifting prompt) post only a product ID, and posting nothing for the plan
	 * would charge it once instead of starting a subscription. It keeps its plan
	 * tiers for get_tiers_by_frequency(), which the tiers modal uses and which
	 * does post a plan.
	 */
	public function test_hybrid_plan_products_are_not_tier_eligible() {
		$id = self::factory()->post->create(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $id, '_wcsatt_schemes_status', 'override' );
		$hybrid = $this->plan_variable( [ '1_month' => [ 'period' => 'month' ] ], $id, false );

		$ids = array_map( fn( $p ) => $p->get_id(), Subscriptions_Tiers::get_tier_eligible_products() );
		$this->assertNotContains( $id, $ids, 'A hybrid product needs its plan posted explicitly, which only the tiers modal does.' );

		$tiers = Subscriptions_Tiers::get_tiers_by_frequency( $hybrid );
		$this->assertSame( [ 'month_1' ], array_keys( $tiers ), 'The tiers modal still sells the hybrid product by its plan tiers.' );
	}

	/**
	 * With no product, the catalog-wide form offers plan products alongside legacy
	 * ones: a legacy bucket keeps its plain tab, and a plan bucket's tab posts its plan.
	 */
	public function test_catalog_form_offers_plan_products_beside_legacy_ones() {
		wc_create_mock_product(
			[
				'id'    => 45,
				'type'  => 'subscription',
				'price' => '5',
				'meta'  => [
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);
		$id = self::factory()->post->create(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $id, '_wcsatt_schemes_status', 'override' );
		wc_create_mock_product(
			[
				'id'    => $id,
				'type'  => 'simple',
				'price' => '7',
			]
		);
		WCS_ATT_Product_Schemes::mock_register(
			$id,
			[
				'1_month' => [ 'period' => 'month' ],
				'1_year'  => [ 'period' => 'year' ],
			]
		);

		$tiers = Subscriptions_Tiers::get_tiers_by_frequency();
		$this->assertSame( [ 'month_1', 'month_1_2', 'year_1' ], array_keys( $tiers ), 'A plan never shares a bucket with a legacy product.' );
		$this->assertSame( [ 45 ], array_map( fn( $p ) => $p->get_id(), $tiers['month_1'] ) );

		$html = $this->render( null );
		$this->assertStringContainsString( 'name="product_id" value="45"', $html, 'The legacy product is still offered.' );
		$this->assertCount( 2, $this->plan_inputs( $html, 'convert_to_sub_' . $id ), 'Each plan bucket posts its plan.' );
		$this->assertSame( 1, preg_match_all( '/<button type="button" class="newspack-ui__button newspack-ui__button--small/', $html ), 'The legacy bucket keeps its plain tab.' );
	}

	/**
	 * The same variation sits in every plan's bucket, so a subscription holding it is
	 * current only in the bucket of the plan it was bought on.
	 */
	public function test_current_tier_is_the_subscribed_plan() {
		$tiers = Subscriptions_Tiers::get_tiers_by_frequency(
			$this->plan_variable(
				[
					'1_month' => [ 'period' => 'month' ],
					'1_year'  => [ 'period' => 'year' ],
				]
			)
		);
		$this->plan_subscription( 201, 20, '1_year', 'month' );

		[ $frequency, $product ] = Subscriptions_Tiers::get_current_tier( $tiers );

		$this->assertSame( 'year_1', $frequency, 'The recorded plan wins over the billing period.' );
		$this->assertSame( 201, $product->get_id() );
	}

	/**
	 * A line item with no recorded plan falls back to the subscription's billing
	 * period.
	 */
	public function test_current_tier_falls_back_to_the_billing_period() {
		$tiers = Subscriptions_Tiers::get_tiers_by_frequency(
			$this->plan_variable(
				[
					'1_month' => [ 'period' => 'month' ],
					'1_year'  => [ 'period' => 'year' ],
				]
			)
		);
		$this->plan_subscription( 201, 20, '', 'year' );

		[ $frequency ] = Subscriptions_Tiers::get_current_tier( $tiers );

		$this->assertSame( 'year_1', $frequency );
	}

	/**
	 * A switch whose plan matches none of the tiers still selects a plan, so the
	 * switch posts one.
	 */
	public function test_switch_with_no_matching_plan_still_selects_one() {
		$product     = $this->plan_variable(
			[
				'1_month' => [ 'period' => 'month' ],
				'1_year'  => [ 'period' => 'year' ],
			]
		);
		$switch_data = $this->plan_subscription( 201, 20, 'retired', 'week' );

		$inputs = $this->plan_inputs( $this->render( $product, $switch_data ), 'convert_to_sub_20' );

		$this->assertCount( 1, $this->checked( $inputs ) );
	}

	/**
	 * A switch opens on the plan the reader holds, with that plan selected.
	 */
	public function test_switch_selects_the_current_plan() {
		$product     = $this->plan_variable(
			[
				'1_month' => [ 'period' => 'month' ],
				'1_year'  => [ 'period' => 'year' ],
			]
		);
		$switch_data = $this->plan_subscription( 202, 20, '1_year', 'year' );

		$html    = $this->render( $product, $switch_data );
		$checked = $this->checked( $this->plan_inputs( $html, 'convert_to_sub_20' ) );

		$this->assertCount( 1, $checked );
		$this->assertStringContainsString( 'value="1_year"', $checked[0] );
		$this->assertMatchesRegularExpression( '/<label class="newspack-ui__input-card current">(?:(?!<\/label>).)*value="202"/s', $html, 'The held variation is badged as current.' );
	}

	/**
	 * A tier card's price reads in its plan's period, not in the product's own
	 * `_subscription_*` data, which a plan product does not have.
	 */
	public function test_card_price_reads_in_the_plan_period() {
		$html = $this->render(
			$this->plan_variable(
				[
					'1_month' => [ 'period' => 'month' ],
					'2_year'  => [
						'period'   => 'year',
						'interval' => 2,
					],
				]
			)
		);
		$this->assertStringContainsString( '10 / month', $html );
		$this->assertStringContainsString( '10 every 2 years', $html );
	}

	/**
	 * Legacy frequency tabs stay buttons and post nothing.
	 */
	public function test_legacy_frequency_control_is_unchanged() {
		ob_start();
		Subscriptions_Tiers::render_frequency_control( [ 'month_1', 'year_1' ], 'month_1' );
		$html = ob_get_clean();
		$this->assertSame( 2, substr_count( $html, '<button type="button"' ) );
		$this->assertStringNotContainsString( '<input', $html );
	}

	/**
	 * Changing plan on the same variation is a real switch, not the no-op switch the
	 * backstop blocks.
	 */
	public function test_switching_plan_on_the_same_variation_is_allowed() {
		$this->plan_variable(
			[
				'1_month' => [ 'period' => 'month' ],
				'1_year'  => [ 'period' => 'year' ],
			]
		);
		$switch_data                     = $this->plan_subscription( 201, 20, '1_month', 'month' );
		$_REQUEST['switch-subscription'] = $switch_data['subscription']->get_id();
		$_REQUEST['item']                = 900;
		$_REQUEST['convert_to_sub_20']   = '1_year';

		$this->assertTrue( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 20, 1, 201 ) );
	}

	/**
	 * Re-selecting the plan already held on the same variation is still blocked.
	 */
	public function test_switching_to_the_same_plan_is_blocked() {
		$this->plan_variable(
			[
				'1_month' => [ 'period' => 'month' ],
				'1_year'  => [ 'period' => 'year' ],
			]
		);
		$switch_data                     = $this->plan_subscription( 201, 20, '1_month', 'month' );
		$_REQUEST['switch-subscription'] = $switch_data['subscription']->get_id();
		$_REQUEST['item']                = 900;
		$_REQUEST['convert_to_sub_20']   = '1_month';

		$this->assertFalse( Subscriptions_Tiers::prevent_switch_to_same_subscription( true, 20, 1, 201 ) );
	}

	/**
	 * A switch link on a plan variation opens the parent's tiers modal.
	 */
	public function test_switch_link_on_a_plan_product_prints_its_modal() {
		$this->plan_variable(
			[
				'1_month' => [ 'period' => 'month' ],
				'1_year'  => [ 'period' => 'year' ],
			]
		);
		$switch_data = $this->plan_subscription( 201, 20, '1_month', 'month' );
		Subscriptions_Tiers::register_switch_modal( 900, $switch_data['item'], $switch_data['subscription'] );

		ob_start();
		Subscriptions_Tiers::print_switch_subscription_link_modal();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-product-id="20"', $html );
		$this->assertStringContainsString( 'name="convert_to_sub_20"', $html );
	}

	/**
	 * A switch link whose product was since deleted prints nothing instead of fataling.
	 */
	public function test_switch_link_on_a_deleted_product_prints_nothing() {
		$switch_data = $this->plan_subscription( 0, 999999, '', 'month' );
		Subscriptions_Tiers::register_switch_modal( 900, $switch_data['item'], $switch_data['subscription'] );

		ob_start();
		Subscriptions_Tiers::print_switch_subscription_link_modal();
		$this->assertSame( '', ob_get_clean() );
	}
}
