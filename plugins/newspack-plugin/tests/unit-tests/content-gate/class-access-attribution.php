<?php
/**
 * Tests the shared access-source attribution class.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Attribution;

/**
 * Test access source label mapping and precedence.
 *
 * @group Access_Attribution
 */
class Newspack_Test_Access_Attribution extends WP_UnitTestCase {

	/**
	 * Reset the request memo so counts start clean in each test.
	 */
	public function set_up() {
		parent::set_up();
		Access_Attribution::reset_memo();
	}

	/**
	 * With no labels there is nothing to attribute.
	 */
	public function test_pick_primary_returns_empty_string_for_no_labels() {
		$this->assertSame( '', Access_Attribution::pick_primary( [] ) );
	}

	/**
	 * A product name is the most specific answer available, so it outranks
	 * every generic source label.
	 */
	public function test_product_name_outranks_every_generic_label() {
		$labels = [ 'group', 'institution', 'Digital All-Access', 'domain' ];
		$this->assertSame( 'Digital All-Access', Access_Attribution::pick_primary( $labels ) );
	}

	/**
	 * Each adjacent pair in the precedence order, asserted separately so a
	 * reordering failure names the exact pair that broke.
	 *
	 * @dataProvider adjacent_precedence_pairs
	 *
	 * @param string $stronger The label expected to win.
	 * @param string $weaker   The label expected to lose.
	 */
	public function test_adjacent_precedence_pairs( $stronger, $weaker ) {
		$this->assertSame( $stronger, Access_Attribution::pick_primary( [ $weaker, $stronger ] ) );
	}

	/**
	 * Adjacent pairs from the documented precedence order.
	 *
	 * @return array[]
	 */
	public function adjacent_precedence_pairs() {
		return [
			'subscription over one_time_purchase' => [ 'subscription', 'one_time_purchase' ],
			'one_time_purchase over group'        => [ 'one_time_purchase', 'group' ],
			'group over institution'              => [ 'group', 'institution' ],
			'institution over domain'             => [ 'institution', 'domain' ],
			'domain over reader_data'             => [ 'domain', 'reader_data' ],
		];
	}

	/**
	 * Several product names sort deterministically so the same reader and gate
	 * always report the same value.
	 */
	public function test_multiple_product_names_are_deterministic() {
		$this->assertSame( 'Annual Pass', Access_Attribution::pick_primary( [ 'Monthly Pass', 'Annual Pass' ] ) );
	}

	/**
	 * An email-domain rule attributes to the domain source.
	 */
	public function test_email_domain_rule_maps_to_domain() {
		$this->assertSame( [ 'domain' ], Access_Attribution::get_source_labels( 'email_domain', [ 'example.com' ], 1 ) );
	}

	/**
	 * An institution rule attributes to the institution source.
	 */
	public function test_institution_rule_maps_to_institution() {
		$this->assertSame( [ 'institution' ], Access_Attribution::get_source_labels( 'institution', [ 42 ], 1 ) );
	}

	/**
	 * A reader-data rule attributes to the reader_data source.
	 */
	public function test_reader_data_rule_maps_to_reader_data() {
		$this->assertSame( [ 'reader_data' ], Access_Attribution::get_source_labels( 'reader_data', 'is_donor', 1 ) );
	}

	/**
	 * An unregistered slug has nothing to attribute rather than guessing.
	 */
	public function test_unknown_slug_maps_to_nothing() {
		$this->assertSame( [], Access_Attribution::get_source_labels( 'not_a_rule', 'anything', 1 ) );
	}

	/**
	 * Without WooCommerce there is no product to name, so the subscription rule
	 * falls back to its bare slug rather than reporting no source at all.
	 */
	public function test_subscription_rule_falls_back_to_slug_without_woocommerce() {
		$this->assertSame( [ 'subscription' ], Access_Attribution::get_source_labels( 'subscription', 'not-an-array', 1 ) );
	}

	/**
	 * Same for one-time purchases: ownership is established even when the
	 * product cannot be resolved to a name.
	 */
	public function test_one_time_purchase_rule_falls_back_to_slug_without_products() {
		$this->assertSame( [ 'one_time_purchase' ], Access_Attribution::get_source_labels( 'one_time_purchase', [], 1 ) );
	}

	/**
	 * Resolving which of several products granted access must not re-query per
	 * product. Without this the mapping degrades to N+2 full subscription loads
	 * on every logged-in pageview, and the regression is invisible in output.
	 */
	public function test_subscription_labels_resolve_with_a_single_ownership_lookup() {
		$calls = 0;
		add_filter(
			'newspack_access_rules_has_active_subscription',
			function ( $has, $user_id, $product_ids, $strict ) use ( &$calls ) {
				$calls++;
				return $has;
			},
			10,
			4
		);

		Access_Attribution::get_source_labels( 'subscription', [ 101, 102, 103, 104 ], 1, [] );

		remove_all_filters( 'newspack_access_rules_has_active_subscription' );

		$this->assertLessThanOrEqual( 2, $calls, 'Product attribution must not probe once per product.' );
	}
}
