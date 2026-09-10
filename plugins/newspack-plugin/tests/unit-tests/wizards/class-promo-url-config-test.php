<?php
/**
 * Tests for the promotional-URL generator config.
 *
 * @package Newspack\Tests
 */

use Newspack\Promo_Url_Config;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Promo URL config test case.
 */
class Promo_Url_Config_Test extends WP_UnitTestCase {

	/**
	 * Each test builds its own product fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['products_database'] = [];
	}

	/**
	 * Helper to build a test donate configuration.
	 *
	 * @param array $overrides Optional overrides to apply.
	 * @return array Donate configuration.
	 */
	private function donate_configuration( $overrides = [] ) {
		return array_merge(
			[
				'is_tier_based_layout' => false,
				'tiered'               => true,
				'frequencies'          => [
					'month' => 'Monthly',
					'year'  => 'Annually',
				],
				'amounts'              => [
					'once'  => [ 9, 20, 90, 20 ],
					'month' => [ 7, 15, 30, 15 ],
					'year'  => [ 84, 180, 360, 180 ],
				],
				'defaultFrequency'     => 'month',
				'minimumDonation'      => 5,
			],
			$overrides
		);
	}

	/**
	 * Test mapping tiers-based layout.
	 */
	public function test_map_tiers_based_layout() {
		$config = Promo_Url_Config::map_donate_configuration(
			$this->donate_configuration( [ 'is_tier_based_layout' => true ] ),
			true
		);
		$this->assertSame( 'tiered', $config['layout_param'] );
		$this->assertTrue( $config['frequencies']['month']['enabled'] );
		$this->assertFalse( $config['frequencies']['once']['enabled'] );
		$this->assertSame( [ 7.0, 15.0, 30.0 ], $config['frequencies']['month']['amounts'] );
		$this->assertFalse( $config['frequencies']['month']['supports_custom'] );
	}

	/**
	 * Test mapping frequency-based tiered layout.
	 */
	public function test_map_frequency_based_tiered_layout() {
		$config = Promo_Url_Config::map_donate_configuration( $this->donate_configuration(), true );
		$this->assertSame( 'frequency', $config['layout_param'] );
		$this->assertTrue( $config['frequencies']['month']['supports_custom'] );
		$config_no_nyp = Promo_Url_Config::map_donate_configuration( $this->donate_configuration(), false );
		$this->assertFalse( $config_no_nyp['frequencies']['month']['supports_custom'] );
	}

	/**
	 * Test mapping untiered layout with and without NYP.
	 */
	public function test_map_untiered_layout_with_and_without_nyp() {
		$config = Promo_Url_Config::map_donate_configuration(
			$this->donate_configuration( [ 'tiered' => false ] ),
			true
		);
		$this->assertSame( 'untiered', $config['layout_param'] );
		$this->assertTrue( $config['frequencies']['month']['supports_custom'] );
		$this->assertSame( 15.0, $config['frequencies']['month']['suggested'] );
		$this->assertSame( [], $config['frequencies']['month']['amounts'] );

		$config_no_nyp = Promo_Url_Config::map_donate_configuration(
			$this->donate_configuration( [ 'tiered' => false ] ),
			false
		);
		$this->assertSame( 'frequency', $config_no_nyp['layout_param'] );
		$this->assertSame( [ 15.0 ], $config_no_nyp['frequencies']['month']['amounts'] );
		$this->assertFalse( $config_no_nyp['frequencies']['month']['supports_custom'] );
	}

	/**
	 * Test that default frequency and minimum are carried through.
	 */
	public function test_map_carries_default_frequency_and_minimum() {
		$config = Promo_Url_Config::map_donate_configuration( $this->donate_configuration(), true );
		$this->assertSame( 'month', $config['default_frequency'] );
		$this->assertSame( 5.0, $config['minimum'] );
	}

	/**
	 * Test that evaluate_donate_configuration gates on WP_Error and on a
	 * non-WooCommerce platform before mapping, and maps through otherwise.
	 */
	public function test_evaluate_donate_configuration_gates() {
		$this->assertNull( Promo_Url_Config::evaluate_donate_configuration( new WP_Error( 'error', 'Error' ), true ) );
		$this->assertNull(
			Promo_Url_Config::evaluate_donate_configuration(
				$this->donate_configuration( [ 'platform' => 'stripe' ] ),
				true
			)
		);
		$config = Promo_Url_Config::evaluate_donate_configuration(
			$this->donate_configuration( [ 'platform' => 'wc' ] ),
			true
		);
		$this->assertArrayHasKey( 'layout_param', $config );
	}

	/**
	 * Test the promo-targets endpoint's permission check, type validation, and
	 * response shape. The wizard's registration is hooked explicitly so this does
	 * not depend on constructor timing.
	 */
	public function test_promo_targets_endpoint_permissions_and_validation() {
		$wizard = new Newspack\Audience_Subscription_Products();
		add_action( 'rest_api_init', [ $wizard, 'register_api_endpoints' ] );
		do_action( 'rest_api_init' );
		$route = '/newspack/v1/wizard/newspack-audience-subscription-products/promo-targets';

		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'donate' );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		get_user_by( 'id', $admin )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $admin );

		// checkout_button needs a product to resolve plan children.
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'checkout_button' );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'checkout_button' );
		$request->set_param( 'product_id', 12345 );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertNotEmpty( $data['homepage']['url'] );
		$this->assertArrayHasKey( 'eligible_children', $data );
		$this->assertArrayHasKey( 'offerable_children', $data );
		$this->assertArrayHasKey( 'parent_offerable', $data );
		// No page scanning: there are no targets to enumerate.
		$this->assertArrayNotHasKey( 'targets', $data );

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'donate' );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertNotEmpty( $data['homepage']['url'] );
		$this->assertArrayHasKey( 'donate_config', $data );
		$this->assertArrayNotHasKey( 'eligible_children', $data );
	}

	/**
	 * Test that the homepage choice trusts page_on_front only when a static
	 * front page is actually being served (show_on_front === 'page'): the
	 * option survives switching Reading settings back to latest posts, and the
	 * label would otherwise name a page home_url( '/' ) no longer serves.
	 */
	public function test_promo_targets_homepage_honors_show_on_front() {
		$wizard = new Newspack\Audience_Subscription_Products();
		add_action( 'rest_api_init', [ $wizard, 'register_api_endpoints' ] );
		do_action( 'rest_api_init' );
		$route = '/newspack/v1/wizard/newspack-audience-subscription-products/promo-targets';

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		get_user_by( 'id', $admin )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $admin );

		$page_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Legacy front page',
			]
		);
		update_option( 'page_on_front', $page_id );
		update_option( 'show_on_front', 'posts' );

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'donate' );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( 0, $data['homepage']['id'] );
		$this->assertSame( 'Homepage', $data['homepage']['title'] );

		update_option( 'show_on_front', 'page' );
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'type', 'donate' );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( $page_id, $data['homepage']['id'] );
		$this->assertSame( 'Legacy front page', $data['homepage']['title'] );
	}

	/**
	 * Test that frequencies whose donation child product is missing are
	 * disabled — the checkout bails without a cart for them — and that a config
	 * with no product-backed frequency left collapses to null.
	 */
	public function test_filter_frequencies_without_products() {
		$config = Promo_Url_Config::map_donate_configuration( $this->donate_configuration(), true );

		// All enabled frequencies have products: unchanged.
		$intact = Promo_Url_Config::filter_frequencies_without_products(
			$config,
			[
				'month' => 201,
				'year'  => 202,
			]
		);
		$this->assertTrue( $intact['frequencies']['month']['enabled'] );
		$this->assertTrue( $intact['frequencies']['year']['enabled'] );

		// A missing product disables its frequency but keeps the config.
		$partial = Promo_Url_Config::filter_frequencies_without_products(
			$config,
			[
				'month' => 201,
				'year'  => false,
			]
		);
		$this->assertTrue( $partial['frequencies']['month']['enabled'] );
		$this->assertFalse( $partial['frequencies']['year']['enabled'] );

		// No product-backed frequency at all: no config, so the UI reports
		// donations as unavailable instead of emitting a dead link.
		$this->assertNull( Promo_Url_Config::filter_frequencies_without_products( $config, [] ) );
		$this->assertNull( Promo_Url_Config::filter_frequencies_without_products( null, [ 'month' => 201 ] ) );
	}

	/**
	 * Test that each variation is gated on its own resolvable price. The render
	 * gate this mirrors collapses a locked pair onto the variation and refuses
	 * the form when the variation's own price is empty — the parent's synced
	 * price says nothing about an individual child, since WooCommerce syncs it
	 * from priced siblings.
	 */
	public function test_offerable_children_gate_each_variation_on_its_own_price() {
		wc_create_mock_product(
			[
				'id'       => 510,
				'type'     => 'variable-subscription',
				'status'   => 'publish',
				'price'    => '10',
				'children' => [ 511, 512 ],
			]
		);
		wc_create_mock_product(
			[
				'id'        => 511,
				'type'      => 'subscription_variation',
				'status'    => 'publish',
				'price'     => '10',
				'parent_id' => 510,
			]
		);
		wc_create_mock_product(
			[
				'id'        => 512,
				'type'      => 'subscription_variation',
				'status'    => 'publish',
				'price'     => '',
				'parent_id' => 510,
			]
		);
		$family = Promo_Url_Config::get_product_family( 510 );
		$this->assertSame( [ 511 ], Promo_Url_Config::get_offerable_children( $family ) );
	}

	/**
	 * Test that eligible children span variations for a variable plan and only
	 * picker-servable members for a grouped one.
	 */
	public function test_get_eligible_children() {
		$this->assertSame(
			[ 101, 102 ],
			Promo_Url_Config::get_eligible_children(
				[
					'parent'         => 100,
					'variations'     => [ 101, 102 ],
					'members'        => [],
					'picker_members' => [],
				]
			)
		);
		$this->assertSame(
			[ 201 ],
			Promo_Url_Config::get_eligible_children(
				[
					'parent'         => 200,
					'variations'     => [],
					'members'        => [ 201, 202 ],
					'picker_members' => [ 201 ],
				]
			)
		);
	}

	/**
	 * Helper to build a clean (unrestricted, non-expired, non-exceeded)
	 * coupon_data array for evaluate_coupon() tests.
	 *
	 * @param array $overrides Optional overrides to apply.
	 * @return array Coupon data.
	 */
	private function coupon_data( $overrides = [] ) {
		return array_merge(
			[
				'expired'        => false,
				'usage_exceeded' => false,
				'product_ids'    => [],
				'excluded_ids'   => [],
				'category_ids'   => [],
				'minimum_amount' => 0.0,
			],
			$overrides
		);
	}

	/**
	 * The pre-check answers what the checkout will: WooCommerce matches a
	 * coupon's product lists against the cart line's product and its parent,
	 * never a sibling variation, so the promoted product's `item_ids` is that
	 * pair.
	 *
	 * @dataProvider coupon_verdicts
	 *
	 * @param array       $coupon_overrides Coupon state on top of a clean coupon.
	 * @param array       $product_context  Promoted-product context.
	 * @param bool        $expected_valid   Whether the coupon should be usable.
	 * @param string|null $expected_reason  The reason given when it is not.
	 */
	public function test_evaluate_coupon_mirrors_the_checkout_verdict( $coupon_overrides, $product_context, $expected_valid, $expected_reason = null ) {
		$result = Promo_Url_Config::evaluate_coupon( $this->coupon_data( $coupon_overrides ), $product_context );

		$this->assertSame( $expected_valid, $result['valid'] );
		$this->assertSame( $expected_reason, $result['reason'] ?? null );
	}

	/**
	 * Rows promote variation 200 of parent 100; 201 is its sibling.
	 *
	 * @return array[]
	 */
	public function coupon_verdicts() {
		$variation = [
			'item_ids'            => [ 200, 100 ],
			'family_category_ids' => [ 6 ],
			'reference_price'     => 10.0,
		];
		return [
			'expired'                                    => [ [ 'expired' => true ], [], false, 'This coupon has expired.' ],
			'usage limit reached'                        => [ [ 'usage_exceeded' => true ], [], false, 'This coupon has reached its usage limit.' ],
			'restricted to the promoted variation'       => [ [ 'product_ids' => [ 200 ] ], $variation, true ],
			'restricted to the parent'                   => [ [ 'product_ids' => [ 100 ] ], $variation, true ],
			'restricted to a sibling variation'          => [ [ 'product_ids' => [ 201 ] ], $variation, false, 'This coupon does not apply to this plan.' ],
			'excludes the promoted variation'            => [ [ 'excluded_ids' => [ 200 ] ], $variation, false, 'This coupon excludes this plan.' ],
			'excludes the parent'                        => [ [ 'excluded_ids' => [ 100 ] ], $variation, false, 'This coupon excludes this plan.' ],
			'excludes a sibling variation'               => [ [ 'excluded_ids' => [ 201 ] ], $variation, true ],
			'limited to a category the plan is not in'   => [ [ 'category_ids' => [ 5 ] ], $variation, false, 'This coupon is limited to product categories this plan is not in.' ],
			'excludes a category the plan is in'         => [ [ 'excluded_category_ids' => [ 6, 7 ] ], $variation, false, 'This coupon excludes a product category this plan is in.' ],
			'excludes a category the plan is not in'     => [ [ 'excluded_category_ids' => [ 9 ] ], $variation, true ],
			'minimum spend above the plan price'         => [ [ 'minimum_amount' => 50.0 ], $variation, false, 'The plan price is below this coupon’s minimum spend.' ],
			'minimum spend with no price to compare'     => [ [ 'minimum_amount' => 50.0 ], array_merge( $variation, [ 'reference_price' => null ] ), true ],
			'restrictive coupon with no product context' => [
				[
					'product_ids'    => [ 999 ],
					'minimum_amount' => 1000.0,
				],
				[],
				true,
			],
		];
	}

	/**
	 * A promoted variation is checked as its own id plus the parent's, with the
	 * categories and price WooCommerce reads for its cart line: terms from the
	 * parent, price from the variation.
	 */
	public function test_coupon_product_context_pairs_a_variation_with_its_parent() {
		wc_create_mock_product(
			[
				'id'           => 100,
				'type'         => 'variable_subscription',
				'children'     => [ 200, 201 ],
				'category_ids' => [ 6 ],
			]
		);
		wc_create_mock_product(
			[
				'id'        => 200,
				'type'      => 'subscription_variation',
				'parent_id' => 100,
				'price'     => '10',
			]
		);

		$this->assertSame(
			[
				'item_ids'            => [ 200, 100 ],
				'family_category_ids' => [ 6 ],
				'reference_price'     => 10.0,
			],
			Promo_Url_Config::get_coupon_product_context( 200 )
		);
	}

	/**
	 * A product with no parent stands alone. One WooCommerce cannot load is
	 * still checked by id, so a restricted coupon is not reported as applying
	 * to it.
	 */
	public function test_coupon_product_context_without_a_parent_is_the_product_alone() {
		wc_create_mock_product(
			[
				'id'           => 100,
				'type'         => 'subscription',
				'category_ids' => [ 6 ],
				'price'        => '25',
			]
		);

		$this->assertSame(
			[
				'item_ids'            => [ 100 ],
				'family_category_ids' => [ 6 ],
				'reference_price'     => 25.0,
			],
			Promo_Url_Config::get_coupon_product_context( 100 )
		);
		$this->assertSame( [ 999 ], Promo_Url_Config::get_coupon_product_context( 999 )['item_ids'] );
	}
}
