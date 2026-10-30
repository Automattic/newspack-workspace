<?php
/**
 * Tests the shared access-source attribution class.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Attribution;
use Newspack\Access_Rules;

/**
 * Test access source label mapping and precedence.
 *
 * @group Access_Attribution
 */
class Newspack_Test_Access_Attribution extends WP_UnitTestCase {

	/**
	 * Load the WooCommerce mocks once for the class. Resolving a rule to a
	 * product name is the interesting half of this mapping, and it is dead code
	 * without them.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset the mock stores and every request memo, so no test inherits
	 * another's subscriptions, orders or counts.
	 */
	public function set_up() {
		parent::set_up();

		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];

		Access_Attribution::reset_memo();
		Access_Rules::flush_one_time_purchase_memo();
	}

	/**
	 * Clear the memos again on the way out: they are static, so a test that
	 * leaves one populated would speak for the next class in the process.
	 */
	public function tear_down() {
		Access_Attribution::reset_memo();
		Access_Rules::flush_one_time_purchase_memo();
		parent::tear_down();
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
	 * With no product list to resolve against there is no product to name, so
	 * the subscription rule falls back to its bare slug rather than reporting no
	 * source at all.
	 */
	public function test_subscription_rule_falls_back_to_slug_for_a_non_array_value() {
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
		$user_id     = $this->factory->user->create();
		$product_ids = [ 101, 102, 103, 104 ];
		foreach ( $product_ids as $product_id ) {
			\wc_create_mock_product(
				[
					'id'   => $product_id,
					'name' => 'Plan ' . $product_id,
				]
			);
		}
		// The reader must actually own one of them: the strict ownership check
		// guards the per-product loop, and without a subscription behind it the
		// loop this test exists to protect never runs at all.
		\wcs_create_subscription(
			[
				'customer_id'    => $user_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'products'       => [ 103 ],
			]
		);

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

		$labels = Access_Attribution::get_source_labels( 'subscription', $product_ids, $user_id, [] );

		remove_all_filters( 'newspack_access_rules_has_active_subscription' );

		$this->assertSame(
			[ 'Plan 103' ],
			$labels,
			'The per-product loop must have resolved the owned product, or the call count below measures nothing.'
		);
		$this->assertLessThanOrEqual( 2, $calls, 'Product attribution must not probe once per product.' );
	}

	/**
	 * A subscription bought on a sibling site has no local record for the
	 * owned-subscriptions intersection to find: newspack-network answers the
	 * ownership filter on its behalf. The reader is still a subscriber to a
	 * product the publisher sells, so the label must be the product's name,
	 * not the bare `subscription` fallback the intersection alone produces.
	 */
	public function test_network_granted_subscription_resolves_the_product_name() {
		$user_id = $this->factory->user->create();
		\wc_create_mock_product(
			[
				'id'   => 301,
				'name' => 'Sibling Site Plan',
			]
		);

		// Stands in for newspack-network's Access::has_active_subscription().
		// No local subscription is created: that is the point.
		add_filter( 'newspack_access_rules_has_active_subscription', '__return_true' );

		$labels = Access_Attribution::get_source_labels( 'subscription', [ 301 ], $user_id, [] );

		remove_all_filters( 'newspack_access_rules_has_active_subscription' );

		$this->assertSame(
			[ 'Sibling Site Plan' ],
			$labels,
			'A network-granted subscription must resolve to the product name, not the generic slug.'
		);
	}

	/**
	 * A one-time purchase resolves to the name of the product the reader
	 * actually bought, not to the bare slug and not to the whole rule's product
	 * list. The generic fallback is well covered; this is the branch that puts a
	 * publisher-recognisable value in the report.
	 */
	public function test_one_time_purchase_labels_name_only_the_purchased_product() {
		$user_id = $this->factory->user->create();
		\wc_create_mock_product(
			[
				'id'   => 201,
				'name' => 'Day Pass',
			]
		);
		\wc_create_mock_product(
			[
				'id'   => 202,
				'name' => 'Weekend Pass',
			]
		);
		\wc_create_order(
			[
				'customer_id'  => $user_id,
				'status'       => 'completed',
				'total'        => 100,
				'date_created' => gmdate( 'Y-m-d H:i:s' ),
				'items'        => [ new \WC_Order_Item_Product( [ 'product_id' => 201 ] ) ],
			]
		);

		$labels = Access_Attribution::get_source_labels(
			'one_time_purchase',
			[
				'product_ids'    => [ 201, 202 ],
				'duration_value' => 30,
				'duration_unit'  => 'days',
			],
			$user_id
		);

		$this->assertSame( [ 'Day Pass' ], $labels );
	}

	/**
	 * HTML entities are decoded, so a publisher reading the report sees the
	 * product name they typed rather than its escaped form.
	 */
	public function test_one_time_purchase_product_name_is_entity_decoded() {
		$user_id = $this->factory->user->create();
		\wc_create_mock_product(
			[
				'id'   => 203,
				'name' => 'Readers&#8217; Pass',
			]
		);
		\wc_create_order(
			[
				'customer_id'  => $user_id,
				'status'       => 'completed',
				'total'        => 100,
				'date_created' => gmdate( 'Y-m-d H:i:s' ),
				'items'        => [ new \WC_Order_Item_Product( [ 'product_id' => 203 ] ) ],
			]
		);

		$labels = Access_Attribution::get_source_labels(
			'one_time_purchase',
			[
				'product_ids'    => [ 203 ],
				'duration_value' => 1,
				'duration_unit'  => 'months',
			],
			$user_id
		);

		$this->assertSame( [ 'Readers’ Pass' ], $labels );
	}
}
