<?php
/**
 * Tests for the promotional-URL generator config.
 *
 * @package Newspack\Tests
 */

use Newspack\Promo_Url_Config;

/**
 * Promo URL config test case.
 */
class Promo_Url_Config_Test extends WP_UnitTestCase {

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
	 * Test that an expired coupon is invalid regardless of product context.
	 */
	public function test_evaluate_coupon_expired_is_invalid() {
		$result = Promo_Url_Config::evaluate_coupon( $this->coupon_data( [ 'expired' => true ] ) );
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * Test that a coupon past its usage limit is invalid.
	 */
	public function test_evaluate_coupon_usage_exceeded_is_invalid() {
		$result = Promo_Url_Config::evaluate_coupon( $this->coupon_data( [ 'usage_exceeded' => true ] ) );
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * Test that a coupon restricted to product IDs intersecting the promoted
	 * plan's family is valid.
	 */
	public function test_evaluate_coupon_allowed_product_ids_intersecting_family_is_valid() {
		$result = Promo_Url_Config::evaluate_coupon(
			$this->coupon_data( [ 'product_ids' => [ 100, 200 ] ] ),
			[ 'family_ids' => [ 200, 201 ] ]
		);
		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test that a coupon restricted to product IDs that don't intersect the
	 * promoted plan's family is invalid, with a reason.
	 */
	public function test_evaluate_coupon_allowed_product_ids_not_intersecting_family_is_invalid() {
		$result = Promo_Url_Config::evaluate_coupon(
			$this->coupon_data( [ 'product_ids' => [ 100, 200 ] ] ),
			[ 'family_ids' => [ 300 ] ]
		);
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * Test that a coupon excluding every member of the promoted plan's family
	 * is invalid.
	 */
	public function test_evaluate_coupon_excluded_ids_covering_whole_family_is_invalid() {
		$result = Promo_Url_Config::evaluate_coupon(
			$this->coupon_data( [ 'excluded_ids' => [ 100, 101 ] ] ),
			[ 'family_ids' => [ 100, 101 ] ]
		);
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * Test that a coupon restricted to product categories the promoted plan
	 * isn't in is invalid.
	 */
	public function test_evaluate_coupon_category_restriction_not_intersecting_is_invalid() {
		$result = Promo_Url_Config::evaluate_coupon(
			$this->coupon_data( [ 'category_ids' => [ 5 ] ] ),
			[
				'family_ids'          => [ 100 ],
				'family_category_ids' => [ 6 ],
			]
		);
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * Test that a minimum-amount coupon is invalid when the promoted plan's
	 * reference price is below the minimum.
	 */
	public function test_evaluate_coupon_minimum_amount_above_reference_price_is_invalid() {
		$result = Promo_Url_Config::evaluate_coupon(
			$this->coupon_data( [ 'minimum_amount' => 50.0 ] ),
			[
				'family_ids'      => [ 100 ],
				'reference_price' => 10.0,
			]
		);
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * Test that the minimum-amount check is skipped (coupon stays valid) when
	 * no reference price could be resolved for the promoted plan.
	 */
	public function test_evaluate_coupon_minimum_amount_skipped_when_reference_price_null() {
		$result = Promo_Url_Config::evaluate_coupon(
			$this->coupon_data( [ 'minimum_amount' => 50.0 ] ),
			[
				'family_ids'      => [ 100 ],
				'reference_price' => null,
			]
		);
		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test that a clean coupon is valid when no product context is given at
	 * all — product-dependent checks (even restrictive ones) are skipped
	 * entirely rather than evaluated against an empty family.
	 */
	public function test_evaluate_coupon_empty_product_context_is_valid() {
		$result = Promo_Url_Config::evaluate_coupon(
			$this->coupon_data(
				[
					'product_ids'    => [ 999 ],
					'minimum_amount' => 1000.0,
				]
			)
		);
		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test that a coupon excluding a category the promoted plan is in is
	 * invalid — the mirror of the allowed-categories check.
	 */
	public function test_evaluate_coupon_excluded_category_intersecting_family_is_invalid() {
		$result = Promo_Url_Config::evaluate_coupon(
			$this->coupon_data( [ 'excluded_category_ids' => [ 6, 7 ] ] ),
			[
				'family_ids'          => [ 100 ],
				'family_category_ids' => [ 6 ],
			]
		);
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * Test that an excluded category the plan is not in leaves the coupon valid.
	 */
	public function test_evaluate_coupon_excluded_category_not_intersecting_is_valid() {
		$result = Promo_Url_Config::evaluate_coupon(
			$this->coupon_data( [ 'excluded_category_ids' => [ 9 ] ] ),
			[
				'family_ids'          => [ 100 ],
				'family_category_ids' => [ 6 ],
			]
		);
		$this->assertTrue( $result['valid'] );
	}
}
