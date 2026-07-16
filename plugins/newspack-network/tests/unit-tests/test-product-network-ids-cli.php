<?php
/**
 * Class TestProductNetworkIdsCLI
 *
 * @package Newspack_Network
 */

use Newspack_Network\CLI\Product_Network_Ids;

/**
 * Test the pure derivation and verification logic of the Product_Network_Ids CLI class.
 */
class TestProductNetworkIdsCLI extends WP_UnitTestCase {

	/**
	 * Two plans with disjoint products each yield their plan's Network ID, with no conflicts.
	 */
	public function test_derive_assignments_disjoint_plans() {
		$plans = [
			[
				'network_id'  => 'premium',
				'product_ids' => [ 10, 11 ],
			],
			[
				'network_id'  => 'basic',
				'product_ids' => [ 20 ],
			],
		];

		$derived = Product_Network_Ids::derive_assignments_from_plans( $plans );

		$this->assertSame(
			[
				10 => 'premium',
				11 => 'premium',
				20 => 'basic',
			],
			$derived['assignments']
		);
		$this->assertEmpty( $derived['conflicts'] );
	}

	/**
	 * Plans with no Network ID or no products contribute nothing.
	 */
	public function test_derive_assignments_skips_empty_plans() {
		$plans = [
			[
				'network_id'  => '',
				'product_ids' => [ 10 ],
			],
			[
				'network_id'  => 'premium',
				'product_ids' => [],
			],
			[
				'network_id'  => 'premium',
				'product_ids' => [ 30 ],
			],
		];

		$derived = Product_Network_Ids::derive_assignments_from_plans( $plans );

		$this->assertSame( [ 30 => 'premium' ], $derived['assignments'] );
		$this->assertEmpty( $derived['conflicts'] );
	}

	/**
	 * A product listed by two plans with the same Network ID is not a conflict.
	 */
	public function test_derive_assignments_same_network_id_is_not_a_conflict() {
		$plans = [
			[
				'network_id'  => 'premium',
				'product_ids' => [ 10 ],
			],
			[
				'network_id'  => 'premium',
				'product_ids' => [ 10 ],
			],
		];

		$derived = Product_Network_Ids::derive_assignments_from_plans( $plans );

		$this->assertSame( [ 10 => 'premium' ], $derived['assignments'] );
		$this->assertEmpty( $derived['conflicts'] );
	}

	/**
	 * A product listed by two plans with different Network IDs is a conflict and is not assigned.
	 */
	public function test_derive_assignments_conflicting_network_ids() {
		$plans = [
			[
				'network_id'  => 'premium',
				'product_ids' => [ 10, 11 ],
			],
			[
				'network_id'  => 'basic',
				'product_ids' => [ 11 ],
			],
		];

		$derived = Product_Network_Ids::derive_assignments_from_plans( $plans );

		// Product 10 is unambiguous and still assigned; 11 is a conflict and withheld.
		$this->assertSame( [ 10 => 'premium' ], $derived['assignments'] );
		$this->assertArrayHasKey( 11, $derived['conflicts'] );
		$this->assertContains( 'premium', $derived['conflicts'][11] );
		$this->assertContains( 'basic', $derived['conflicts'][11] );
	}

	/**
	 * The synced product map is indexed by Network ID, listing the sites that carry each ID.
	 */
	public function test_index_network_products_by_network_id() {
		$network_products = [
			'http://site1' => [
				100 => [
					'id'         => 100,
					'network_id' => 'premium',
				],
				101 => [
					'id'         => 101,
					'network_id' => 'basic',
				],
				102 => [
					'id'         => 102,
					'network_id' => '', // Untagged, ignored.
				],
			],
			'http://site2' => [
				200 => [
					'id'         => 200,
					'network_id' => 'premium',
				],
			],
		];

		$index = Product_Network_Ids::index_network_products_by_network_id( $network_products );

		// The index is a per-Network-ID site-presence map: which sites carry each ID.
		$this->assertSame( [ 'http://site1', 'http://site2' ], array_keys( $index['premium'] ) );
		$this->assertTrue( $index['premium']['http://site1'] );
		$this->assertTrue( $index['premium']['http://site2'] );
		$this->assertSame( [ 'http://site1' ], array_keys( $index['basic'] ) );
		$this->assertArrayNotHasKey( '', $index );
	}

	/**
	 * Verification reports cross-site linkage per product and flags the NPPD-2057 field failures:
	 * a product tagged with a Network ID no other site carries, or a product not tagged at all.
	 * The current site is excluded from the linkage count so a product is not counted as linked to
	 * itself: site1 self-includes product 100 ( 'premium' ) in the map, yet 100's linked_sites is
	 * [ site2 ] only -- proving the self-entry is dropped, not that it is absent.
	 */
	public function test_verify_products() {
		$current_site = 'http://site1';

		$local_products = [
			100 => 'premium', // Linked on site2 -> healthy.
			101 => 'basic',   // No other site carries it -> unlinked.
			103 => 'gold',    // No other site carries it -> unlinked.
			104 => '',        // Untagged (explicit --products path) -> nothing resolves.
		];

		$network_products = [
			'http://site1' => [
				100 => [
					'id'         => 100,
					'network_id' => 'premium',
				],
				101 => [
					'id'         => 101,
					'network_id' => 'basic',
				],
			],
			'http://site2' => [
				200 => [
					'id'         => 200,
					'network_id' => 'premium',
				],
			],
		];

		$findings = Product_Network_Ids::verify_products( $local_products, $network_products, $current_site );

		// Product 100: linked to site2. The current site's own entry is ignored (never counts as a link).
		$this->assertSame( [ 'http://site2' ], $findings[100]['linked_sites'] );

		// Product 101: no other site shares 'basic'.
		$this->assertEmpty( $findings[101]['linked_sites'] );

		// Product 103: no other site shares 'gold'.
		$this->assertEmpty( $findings[103]['linked_sites'] );

		// Product 104: untagged - resolves to nothing.
		$this->assertSame( '', $findings[104]['network_id'] );
		$this->assertEmpty( $findings[104]['linked_sites'] );
	}

	/**
	 * --map parsing coerces JSON string keys to integer product IDs and trims Network ID values.
	 *
	 * ( The empty-value branch calls WP_CLI::warning, which is unavailable under PHPUnit, so it is
	 * exercised via the CLI end-to-end rather than here. )
	 */
	public function test_parse_map() {
		$parse_map_method = new ReflectionMethod( Product_Network_Ids::class, 'parse_map' );
		$parse_map_method->setAccessible( true );

		$parsed = $parse_map_method->invoke( null, '{"5":"premium","6":" basic "}' );

		// JSON object keys arrive as strings; they must become integer product IDs.
		$this->assertSame( [ 5, 6 ], array_keys( $parsed ) );
		$this->assertSame( 'premium', $parsed[5] );
		// Values are trimmed.
		$this->assertSame( 'basic', $parsed[6] );
	}
}
