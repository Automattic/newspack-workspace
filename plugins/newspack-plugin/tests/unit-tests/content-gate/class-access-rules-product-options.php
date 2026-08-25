<?php
/**
 * Tests the product option lists behind the paid access rules.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Rules;

/**
 * A gate rule may name a variation: both paid matchers test an order line item's
 * product_id *and* its variation_id, so a rule naming a variation grants only that
 * variation — the same narrowing a WooCommerce Memberships plan expressed by listing
 * one. The pickers therefore have to offer variations, and have to label them
 * distinctly, because the editor resolves a selection back to a rule value by label.
 *
 * @group Access_Rules
 */
class Newspack_Test_Access_Rules_Product_Options extends WP_UnitTestCase {
	/**
	 * Load the WooCommerce mocks once for the class.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Seed one variable product and one variable subscription, each with two
	 * variations, plus a simple product that has none.
	 */
	public function set_up() {
		parent::set_up();

		// The one-time list is memoized per request, so another test class reading it
		// first would otherwise leave this one asserting that class's catalog.
		Access_Rules::flush_product_options_memo();

		global $products_database;
		$products_database = [
			new WC_Product(
				[
					'id'       => 10,
					'name'     => 'Archive Pass',
					'type'     => 'variable',
					'children' => [ 11, 12 ],
				]
			),
			new WC_Product(
				[
					'id'         => 11,
					'type'       => 'variation',
					'parent_id'  => 10,
					'attributes' => [ 'term' => 'Monthly' ],
				]
			),
			new WC_Product(
				[
					'id'         => 12,
					'type'       => 'variation',
					'parent_id'  => 10,
					'attributes' => [ 'term' => 'Annual' ],
				]
			),
			new WC_Product(
				[
					'id'   => 20,
					'name' => 'Single Article',
					'type' => 'simple',
				]
			),
			new WC_Product(
				[
					'id'       => 30,
					'name'     => 'Member Plan',
					'type'     => 'variable-subscription',
					'children' => [ 31, 32 ],
				]
			),
			new WC_Product(
				[
					'id'         => 31,
					'type'       => 'subscription_variation',
					'parent_id'  => 30,
					'attributes' => [ 'tier' => 'Basic' ],
				]
			),
			new WC_Product(
				[
					'id'         => 32,
					'type'       => 'subscription_variation',
					'parent_id'  => 30,
					'attributes' => [ 'tier' => 'Premium' ],
				]
			),
		];
	}

	/**
	 * Reset the mock product database.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = [];
		Access_Rules::flush_product_options_memo();
		parent::tear_down();
	}

	/**
	 * Each variation follows its own parent and carries the parent's name plus its own
	 * attributes. Both lists are asserted together because the separation between them
	 * is part of the claim: an option offered by the wrong picker would name a rule its
	 * buyers could never satisfy.
	 */
	public function test_pickers_list_variations_under_their_own_parent() {
		$subscription_options = Access_Rules::get_subscription_products_options();
		$one_time_options     = Access_Rules::get_one_time_purchase_products_options();

		$this->assertSame(
			[
				30 => 'Member Plan',
				31 => 'Member Plan — Tier: Basic',
				32 => 'Member Plan — Tier: Premium',
			],
			array_column( $subscription_options, 'label', 'value' )
		);

		$this->assertSame(
			[
				10 => 'Archive Pass',
				11 => 'Archive Pass — Term: Monthly',
				12 => 'Archive Pass — Term: Annual',
				20 => 'Single Article',
			],
			array_column( $one_time_options, 'label', 'value' )
		);
	}

	/**
	 * A variation whose attributes name nothing still needs a label of its own: the
	 * editor maps a selected label back to a rule value, so two options sharing a
	 * label write the wrong product ID into the gate.
	 */
	public function test_attributeless_variation_still_gets_a_distinct_label() {
		global $products_database;
		$products_database = [
			new WC_Product(
				[
					'id'       => 40,
					'name'     => 'Legacy Plan',
					'type'     => 'variable-subscription',
					'children' => [ 41, 42 ],
				]
			),
			new WC_Product(
				[
					'id'        => 41,
					'type'      => 'subscription_variation',
					'parent_id' => 40,
				] 
			),
			new WC_Product(
				[
					'id'        => 42,
					'type'      => 'subscription_variation',
					'parent_id' => 40,
				] 
			),
		];

		$labels = array_column( Access_Rules::get_subscription_products_options(), 'label', 'value' );

		$this->assertSame(
			[
				40 => 'Legacy Plan',
				41 => 'Legacy Plan (variation #41)',
				42 => 'Legacy Plan (variation #42)',
			],
			$labels
		);
	}
}
