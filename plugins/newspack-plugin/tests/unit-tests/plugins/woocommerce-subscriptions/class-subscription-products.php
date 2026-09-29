<?php
/**
 * Tests for the subscription products layer: legacy model and facade contract.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscription_Products;
use Newspack\Subscription_Products\Purchase_Option;

/**
 * Legacy subscription types keep answering exactly as they do today.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscription_Products extends WP_UnitTestCase {
	/**
	 * Load the WooCommerce mocks once for the class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 3 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset the mock products database and the facade's per-request caches.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Subscription_Products::flush_cache();
		parent::tear_down();
	}

	/**
	 * A legacy `subscription` product.
	 *
	 * @param int $id Product ID.
	 */
	private function legacy_simple( $id = 101 ) {
		return wc_create_mock_product(
			[
				'id'    => $id,
				'type'  => 'subscription',
				'price' => '10',
				'meta'  => [
					'_subscription_price'           => '10',
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);
	}

	/**
	 * A legacy `variable-subscription` product with two variations.
	 */
	private function legacy_variable() {
		wc_create_mock_product(
			[
				'id'        => 211,
				'type'      => 'subscription_variation',
				'parent_id' => 210,
				'meta'      => [
					'_subscription_price'           => '5',
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);
		wc_create_mock_product(
			[
				'id'        => 212,
				'type'      => 'subscription_variation',
				'parent_id' => 210,
				'meta'      => [
					'_subscription_price'           => '50',
					'_subscription_period'          => 'year',
					'_subscription_period_interval' => '1',
				],
			]
		);
		return wc_create_mock_product(
			[
				'id'       => 210,
				'type'     => 'variable-subscription',
				'children' => [ 211, 212 ],
			] 
		);
	}

	/**
	 * Every legacy subscription type, including a variation given by ID, offers a subscription.
	 */
	public function test_legacy_types_offer_a_subscription() {
		$this->assertTrue( Subscription_Products::offers_subscription( $this->legacy_simple() ) );
		$variable = $this->legacy_variable();
		$this->assertTrue( Subscription_Products::offers_subscription( $variable ) );
		$this->assertTrue( Subscription_Products::offers_subscription( 211 ), 'Accepts an ID, and a variation.' );
	}

	/**
	 * A plain, non-subscription product offers nothing and has no purchase options.
	 */
	public function test_plain_products_offer_nothing() {
		$simple = wc_create_mock_product(
			[
				'id'    => 300,
				'type'  => 'simple',
				'price' => '3',
			] 
		);
		$this->assertFalse( Subscription_Products::offers_subscription( $simple ) );
		$this->assertSame( [], Subscription_Products::get_purchase_options( $simple ) );
	}

	/**
	 * A false, zero, or unknown product ID resolves to nothing rather than erroring.
	 */
	public function test_unresolvable_products_offer_nothing() {
		$this->assertFalse( Subscription_Products::offers_subscription( false ) );
		$this->assertFalse( Subscription_Products::offers_subscription( 0 ) );
		$this->assertFalse( Subscription_Products::offers_subscription( 999999 ) );
		$this->assertSame( [], Subscription_Products::get_purchase_options( 999999 ) );
	}

	/**
	 * A legacy simple subscription has exactly one `legacy` option with its own data.
	 */
	public function test_legacy_simple_has_one_legacy_option() {
		$options = Subscription_Products::get_purchase_options( $this->legacy_simple() );
		$this->assertCount( 1, $options );
		$this->assertSame( Purchase_Option::KIND_LEGACY, $options[0]->kind );
		$this->assertSame( 'legacy', $options[0]->key );
		$this->assertSame( 101, $options[0]->product_id );
		$this->assertSame( 'month_1', $options[0]->get_frequency() );
		$this->assertSame( [], $options[0]->get_plan_request_args(), 'Legacy products need no plan field.' );
	}

	/**
	 * A legacy variable subscription has one option per variation, each pointing at its parent.
	 */
	public function test_legacy_variable_has_one_option_per_variation() {
		$options = Subscription_Products::get_purchase_options( $this->legacy_variable() );
		$this->assertSame( [ 211, 212 ], wp_list_pluck( $options, 'product_id' ) );
		$this->assertSame( [ 'month_1', 'year_1' ], array_map( fn( $o ) => $o->get_frequency(), $options ) );
		$this->assertSame( [ 210, 210 ], wp_list_pluck( $options, 'parent_id' ) );
	}

	/**
	 * A legacy product is purchased as a subscription; a plain product is not.
	 */
	public function test_is_purchased_as_subscription_matches_wcs_for_legacy() {
		$this->assertTrue( Subscription_Products::is_purchased_as_subscription( $this->legacy_simple() ) );
		$simple = wc_create_mock_product(
			[
				'id'   => 301,
				'type' => 'simple',
			] 
		);
		$this->assertFalse( Subscription_Products::is_purchased_as_subscription( $simple ) );
	}

	/**
	 * `find_products()` returns only top-level legacy subscription products, never variations
	 * or plain products.
	 */
	public function test_find_products_returns_top_level_legacy_products() {
		$this->legacy_simple();
		$this->legacy_variable();
		wc_create_mock_product(
			[
				'id'   => 302,
				'type' => 'simple',
			] 
		);
		$ids = array_map( fn( $p ) => $p->get_id(), Subscription_Products::find_products() );
		sort( $ids );
		$this->assertSame( [ 101, 210 ], $ids );
	}

	/**
	 * A legacy product's own instance option is its `legacy` option.
	 */
	public function test_instance_option_for_legacy_is_its_own_option() {
		$option = Subscription_Products::get_instance_option( $this->legacy_simple() );
		$this->assertInstanceOf( Purchase_Option::class, $option );
		$this->assertSame( Purchase_Option::KIND_LEGACY, $option->kind );
	}

	/**
	 * A legacy subscription type never offers a one-time option, so it is always
	 * subscription-only; a plain product offers nothing, let alone only a
	 * subscription; an unresolvable product answers false rather than erroring.
	 */
	public function test_is_subscription_only_matches_expectations() {
		$this->assertTrue( Subscription_Products::is_subscription_only( $this->legacy_simple() ) );

		$simple = wc_create_mock_product(
			[
				'id'    => 303,
				'type'  => 'simple',
				'price' => '5',
			]
		);
		$this->assertFalse( Subscription_Products::is_subscription_only( $simple ) );

		$this->assertFalse( Subscription_Products::is_subscription_only( 999999 ) );
		$this->assertFalse( Subscription_Products::is_subscription_only( false ) );
	}
}
