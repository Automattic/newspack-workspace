<?php
/**
 * Test the per-product "Subscriptions by product" aggregation.
 *
 * Regression guard for the bare-parent collision: a VARIABLE subscription
 * product that also has a bare-parent line item (a subscription recorded
 * against the parent product with `_variation_id = 0` — e.g. a
 * name-your-price / gift parent-level purchase) used to emit BOTH a set of
 * variation rows (keyed to the parent product id) AND a standalone row (keyed
 * to the same product id). The standalone branch hard-overwrote the
 * accumulated parent bucket, so the dominant product collapsed to just the
 * bare sub's numbers and dropped all of its variations (and their new_subs /
 * churn).
 *
 * `aggregate_performance_rows()` is a pure transformation over the flat SQL
 * rows — no DB, no wpdb — so this exercises the exact code that carried the
 * bug with synthetic rows, independent of any publisher data.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\HPOS_Storage;
use Newspack\Insights\Legacy_Storage;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Subscriptions-by-product aggregation test.
 *
 * @group insights
 */
class Test_Subscribers_Performance extends WP_UnitTestCase {

	/**
	 * Invoke the private aggregate_performance_rows() on a storage backend.
	 *
	 * @param object $storage HPOS_Storage or Legacy_Storage instance.
	 * @param array  $rows    Flat per-variation rows (SQL shape).
	 * @return array<int, array<string, mixed>>
	 */
	private function aggregate( $storage, array $rows ): array {
		$method = new ReflectionMethod( $storage, 'aggregate_performance_rows' );
		$method->setAccessible( true );
		return $method->invoke( $storage, $rows );
	}

	/**
	 * One flat row in the shape the performance SQL returns.
	 *
	 * @param array $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides ): array {
		return array_merge(
			[
				'variation_id'     => 0,
				'variation_name'   => '',
				'parent_id'        => 0,
				'parent_name'      => '',
				'sub_period'       => '',
				'active_subs'      => 0,
				'new_subs'         => 0,
				'churned_subs'     => 0,
				'active_value'     => 0.0,
				'lifetime_revenue' => 0.0,
			],
			$overrides
		);
	}

	/**
	 * The collision scenario rows: variable parent 710333 "Fan Membership"
	 * with Annual (710345) + Monthly (710344) variations, PLUS one bare-parent
	 * sub (variation_id = 710333, parent_id = 0). A control variable product
	 * "Fan Duo" (710339) with a variation but NO bare-parent sub, and a true
	 * standalone simple product (720000).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collision_rows(): array {
		return [
			// Variable parent 710333 — Annual variation (the big one).
			$this->row(
				[
					'variation_id'     => 710345,
					'variation_name'   => 'Fan Membership - Annual',
					'parent_id'        => 710333,
					'parent_name'      => 'Fan Membership',
					'sub_period'       => 'year',
					'active_subs'      => 653,
					'new_subs'         => 40,
					'churned_subs'     => 6,
					'active_value'     => 6530.0,
					'lifetime_revenue' => 65300.0,
				]
			),
			// Variable parent 710333 — Monthly variation.
			$this->row(
				[
					'variation_id'     => 710344,
					'variation_name'   => 'Fan Membership - Monthly',
					'parent_id'        => 710333,
					'parent_name'      => 'Fan Membership',
					'sub_period'       => 'month',
					'active_subs'      => 278,
					'new_subs'         => 24,
					'churned_subs'     => 3,
					'active_value'     => 1390.0,
					'lifetime_revenue' => 13900.0,
				]
			),
			// Bare-parent sub on 710333 (variation_id = 0 → resolves to the parent
			// product id; post_parent = 0). The 1-active row that used to clobber
			// the whole parent bucket because it sorts last under active_subs DESC.
			$this->row(
				[
					'variation_id'     => 710333,
					'variation_name'   => 'Fan Membership',
					'parent_id'        => 0,
					'parent_name'      => '',
					'sub_period'       => '',
					'active_subs'      => 1,
					'new_subs'         => 0,
					'churned_subs'     => 0,
					'active_value'     => 12.0,
					'lifetime_revenue' => 48.0,
				]
			),
			// Control: a different variable product with a variation but no bare sub.
			$this->row(
				[
					'variation_id'     => 710350,
					'variation_name'   => 'Fan Duo - Annual',
					'parent_id'        => 710339,
					'parent_name'      => 'Fan Duo',
					'sub_period'       => 'year',
					'active_subs'      => 40,
					'new_subs'         => 6,
					'churned_subs'     => 1,
					'active_value'     => 800.0,
					'lifetime_revenue' => 8000.0,
				]
			),
			// Control: a true standalone simple subscription product.
			$this->row(
				[
					'variation_id'     => 720000,
					'variation_name'   => 'Supporter',
					'parent_id'        => 0,
					'parent_name'      => '',
					'sub_period'       => 'month',
					'active_subs'      => 10,
					'new_subs'         => 3,
					'churned_subs'     => 2,
					'active_value'     => 100.0,
					'lifetime_revenue' => 1000.0,
				]
			),
		];
	}

	/**
	 * Index the aggregated output by product_id for assertions. product_id is
	 * already an int, so array_column's numeric keys need no further casting.
	 *
	 * @param array $out aggregate_performance_rows() output.
	 * @return array<int, array<string, mixed>>
	 */
	private function by_product_id( array $out ): array {
		return array_column( $out, null, 'product_id' );
	}

	/**
	 * The data providers run every assertion against both storage backends.
	 *
	 * @return array<string, array{0:object}>
	 */
	public function backend_provider(): array {
		return [
			'HPOS'   => [ new HPOS_Storage( [] ) ],
			'legacy' => [ new Legacy_Storage( [] ) ],
		];
	}

	/**
	 * The bare-parent sub must MERGE into the variable parent's bucket — the
	 * parent retains its summed variation totals (including new_subs, the exact
	 * column the bug dropped) instead of being overwritten down to 1/0.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param object $storage Storage backend.
	 */
	public function test_bare_parent_sub_merges_into_parent_bucket( $storage ) {
		$out     = $this->aggregate( $storage, $this->collision_rows() );
		$indexed = $this->by_product_id( $out );

		$this->assertArrayHasKey( 710333, $indexed, 'The variable parent must be present, not dropped.' );
		$parent = $indexed[710333];

		$this->assertTrue( $parent['is_parent'], 'A product with variation rows is a parent.' );
		$this->assertSame( 'Fan Membership', $parent['name'], 'Parent keeps the canonical product title, not the bare row label.' );

		// 653 + 278 + 1 = 932 active; 40 + 24 + 0 = 64 new; 6 + 3 + 0 = 9 churn.
		$this->assertSame( 932, $parent['active_subs'], 'Active subs sum all variations plus the bare-parent sub.' );
		$this->assertSame( 64, $parent['new_subs'], 'New subs sum the variations (40 + 24); the bug reported this as 0.' );
		$this->assertSame( 9, $parent['churned_subs'], 'Churn sums the variation churn (6 + 3); the bare row adds 0.' );
		$this->assertEqualsWithDelta( 7932.0, $parent['active_value'], 0.001, 'Active value sums 6530 + 1390 + 12.' );
		$this->assertEqualsWithDelta( 79248.0, $parent['lifetime_revenue'], 0.001, 'Lifetime revenue sums 65300 + 13900 + 48.' );
	}

	/**
	 * The bare-parent sub appears as a synthetic "(no variation)" variation row,
	 * and the renderer's invariant — parent aggregates equal the SUM of its
	 * variation rows — holds after the merge, for every numeric column.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param object $storage Storage backend.
	 */
	public function test_synthetic_no_variation_row_and_sum_invariant( $storage ) {
		$out     = $this->aggregate( $storage, $this->collision_rows() );
		$indexed = $this->by_product_id( $out );
		$parent  = $indexed[710333];

		$this->assertCount( 3, $parent['variations'], 'Two real variations plus the synthetic bare-parent row.' );

		$labels = wp_list_pluck( $parent['variations'], 'label' );
		$this->assertContains( '(no variation)', $labels, 'The bare-parent sub surfaces as a "(no variation)" row.' );

		// The renderer relies on: parent totals == sum of variation rows.
		foreach ( [ 'active_subs', 'new_subs', 'churned_subs' ] as $col ) {
			$this->assertSame(
				$parent[ $col ],
				array_sum( wp_list_pluck( $parent['variations'], $col ) ),
				"Parent $col equals the sum of its variation rows."
			);
		}
		$this->assertEqualsWithDelta( $parent['active_value'], array_sum( wp_list_pluck( $parent['variations'], 'active_value' ) ), 0.001 );
		$this->assertEqualsWithDelta( $parent['lifetime_revenue'], array_sum( wp_list_pluck( $parent['variations'], 'lifetime_revenue' ) ), 0.001 );

		// The synthetic row carries the bare sub's numbers.
		$no_variation = null;
		foreach ( $parent['variations'] as $variation ) {
			if ( '(no variation)' === $variation['label'] ) {
				$no_variation = $variation;
				break;
			}
		}
		$this->assertNotNull( $no_variation );
		$this->assertSame( 1, $no_variation['active_subs'], 'The synthetic row holds the single bare-parent sub.' );
	}

	/**
	 * Order-independence: feeding the bare-parent row FIRST (before its
	 * variations) must produce the same merged parent bucket. Guards against a
	 * fix that only works because the SQL happens to ORDER BY active_subs DESC.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param object $storage Storage backend.
	 */
	public function test_merge_is_order_independent( $storage ) {
		$rows = $this->collision_rows();
		// Move the bare-parent row (index 2) to the front.
		$bare = $rows[2];
		unset( $rows[2] );
		array_unshift( $rows, $bare );

		$out     = $this->aggregate( $storage, array_values( $rows ) );
		$indexed = $this->by_product_id( $out );

		$this->assertArrayHasKey( 710333, $indexed );
		$parent = $indexed[710333];
		$this->assertTrue( $parent['is_parent'], 'Bucket is a parent regardless of row order.' );
		$this->assertSame( 932, $parent['active_subs'], 'Active subs merge the same way when the bare row comes first.' );
		$this->assertSame( 64, $parent['new_subs'], 'New subs merge the same way regardless of order.' );
		$this->assertCount( 3, $parent['variations'], 'All three rows survive regardless of order.' );
	}

	/**
	 * A variable product WITHOUT a bare-parent sub is unaffected, and a true
	 * standalone simple product still renders as a single non-parent row with
	 * no variations scaffold.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param object $storage Storage backend.
	 */
	public function test_control_products_unaffected( $storage ) {
		$out     = $this->aggregate( $storage, $this->collision_rows() );
		$indexed = $this->by_product_id( $out );

		// Control variable product — single variation, no bare sub.
		$this->assertArrayHasKey( 710339, $indexed );
		$fan_duo = $indexed[710339];
		$this->assertTrue( $fan_duo['is_parent'] );
		$this->assertSame( 40, $fan_duo['active_subs'] );
		$this->assertSame( 6, $fan_duo['new_subs'] );
		$this->assertCount( 1, $fan_duo['variations'] );

		// True standalone simple product — non-parent, no variations key.
		$this->assertArrayHasKey( 720000, $indexed );
		$supporter = $indexed[720000];
		$this->assertFalse( $supporter['is_parent'] );
		$this->assertSame( 10, $supporter['active_subs'] );
		$this->assertArrayNotHasKey( 'variations', $supporter, 'Standalone products carry no variations scaffold.' );
	}
}
