<?php
/**
 * Test the per-product "Donations by tier" aggregation.
 *
 * Regression guard for the bare-parent collision: a VARIABLE donation
 * product that also has a bare-parent line item (a donation recorded against
 * the parent product with `_variation_id = 0` — common because Newspack
 * donations are name-your-price variable products purchased at the parent
 * level via Modal_Checkout, which adds the parent with variation_id = 0) used
 * to emit BOTH a set of variation rows (keyed to the parent product id) AND a
 * standalone row (keyed to the same product id). The standalone branch
 * hard-overwrote the accumulated parent bucket, so the dominant donation
 * product collapsed to just the bare gift's numbers and dropped all of its
 * variations.
 *
 * `aggregate_tier_rows()` is a pure transformation over the flat SQL rows — no
 * DB, no wpdb — so this exercises the exact code that carried the bug with
 * synthetic rows, independent of any publisher data. It is the data-independent
 * acceptance gate. Mirrors {@see Test_Subscribers_Performance}, the Tab 6 sibling.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\HPOS_Donors_Storage;
use Newspack\Insights\Legacy_Donors_Storage;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Donations-by-tier aggregation test.
 *
 * @group insights
 */
class Test_Donors_Performance extends WP_UnitTestCase {

	/**
	 * Invoke the private aggregate_tier_rows() on a storage backend.
	 *
	 * @param object $storage HPOS_Donors_Storage or Legacy_Donors_Storage instance.
	 * @param array  $rows    Flat per-variation rows (SQL shape).
	 * @return array<int, array<string, mixed>>
	 */
	private function aggregate( $storage, array $rows ): array {
		$method = new ReflectionMethod( $storage, 'aggregate_tier_rows' );
		$method->setAccessible( true );
		return $method->invoke( $storage, $rows );
	}

	/**
	 * One flat row in the shape the tier SQL returns.
	 *
	 * @param array $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides ): array {
		return array_merge(
			[
				'variation_id'                => 0,
				'variation_name'              => '',
				'parent_id'                   => 0,
				'parent_name'                 => '',
				'sub_period'                  => '',
				'active_recurring_donors'     => 0,
				'lapsed_donors_in_window'     => 0,
				'new_donors_in_window'        => 0,
				'one_time_gifts_in_window'    => 0,
				'recurring_revenue_in_window' => 0.0,
				'lifetime_donation_revenue'   => 0.0,
			],
			$overrides
		);
	}

	/**
	 * The collision scenario rows: variable parent 810333 "Reader Donation"
	 * with Annual (810345) + Monthly (810344) recurring variations, PLUS one
	 * bare-parent one-time gift (variation_id = 810333, parent_id = 0). A
	 * control variable product "Member Gift" (810339) with a variation but NO
	 * bare-parent gift, and a true standalone one-time donation product (820000).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collision_rows(): array {
		return [
			// Variable parent 810333 — Annual recurring variation (the big one).
			$this->row(
				[
					'variation_id'                => 810345,
					'variation_name'              => 'Reader Donation - Annual',
					'parent_id'                   => 810333,
					'parent_name'                 => 'Reader Donation',
					'sub_period'                  => 'year',
					'active_recurring_donors'     => 200,
					'lapsed_donors_in_window'     => 5,
					'new_donors_in_window'        => 30,
					'one_time_gifts_in_window'    => 0,
					'recurring_revenue_in_window' => 24000.0,
					'lifetime_donation_revenue'   => 100000.0,
				]
			),
			// Variable parent 810333 — Monthly recurring variation.
			$this->row(
				[
					'variation_id'                => 810344,
					'variation_name'              => 'Reader Donation - Monthly',
					'parent_id'                   => 810333,
					'parent_name'                 => 'Reader Donation',
					'sub_period'                  => 'month',
					'active_recurring_donors'     => 90,
					'lapsed_donors_in_window'     => 4,
					'new_donors_in_window'        => 10,
					'one_time_gifts_in_window'    => 0,
					'recurring_revenue_in_window' => 4500.0,
					'lifetime_donation_revenue'   => 30000.0,
				]
			),
			// Bare-parent one-time gift on 810333 (variation_id = 810333,
			// parent_id = 0 → resolves to the parent product id; no sub_period).
			// The row that used to clobber the whole parent bucket.
			$this->row(
				[
					'variation_id'                => 810333,
					'variation_name'              => 'Reader Donation',
					'parent_id'                   => 0,
					'parent_name'                 => '',
					'sub_period'                  => '',
					'active_recurring_donors'     => 0,
					'lapsed_donors_in_window'     => 0,
					'new_donors_in_window'        => 0,
					'one_time_gifts_in_window'    => 7,
					'recurring_revenue_in_window' => 0.0,
					'lifetime_donation_revenue'   => 500.0,
				]
			),
			// Control: a different variable product with a variation but no bare gift.
			$this->row(
				[
					'variation_id'                => 810350,
					'variation_name'              => 'Member Gift - Annual',
					'parent_id'                   => 810339,
					'parent_name'                 => 'Member Gift',
					'sub_period'                  => 'year',
					'active_recurring_donors'     => 25,
					'lapsed_donors_in_window'     => 1,
					'new_donors_in_window'        => 3,
					'one_time_gifts_in_window'    => 0,
					'recurring_revenue_in_window' => 3000.0,
					'lifetime_donation_revenue'   => 9000.0,
				]
			),
			// Control: a true standalone one-time simple donation product.
			$this->row(
				[
					'variation_id'                => 820000,
					'variation_name'              => 'One-Time Gift',
					'parent_id'                   => 0,
					'parent_name'                 => '',
					'sub_period'                  => '',
					'active_recurring_donors'     => 0,
					'lapsed_donors_in_window'     => 0,
					'new_donors_in_window'        => 0,
					'one_time_gifts_in_window'    => 15,
					'recurring_revenue_in_window' => 0.0,
					'lifetime_donation_revenue'   => 1500.0,
				]
			),
		];
	}

	/**
	 * Index the aggregated output by product_id for assertions.
	 *
	 * @param array $out aggregate_tier_rows() output.
	 * @return array<int, array<string, mixed>>
	 */
	private function by_product_id( array $out ): array {
		$indexed = [];
		foreach ( $out as $entry ) {
			$indexed[ (int) $entry['product_id'] ] = $entry;
		}
		return $indexed;
	}

	/**
	 * The data providers run every assertion against both storage backends.
	 *
	 * @return array<string, array{0:object}>
	 */
	public function backend_provider(): array {
		return [
			'HPOS'   => [ new HPOS_Donors_Storage( [] ) ],
			'legacy' => [ new Legacy_Donors_Storage( [] ) ],
		];
	}

	/**
	 * The bare-parent gift must MERGE into the variable parent's bucket — the
	 * parent retains its summed variation totals and gains a synthetic
	 * "(no variation)" row, instead of being overwritten down to the bare gift's
	 * numbers. The parent's recurring billing_model survives the one-time merge.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param object $storage Storage backend.
	 */
	public function test_bare_parent_gift_merges_into_parent_bucket( $storage ) {
		$out     = $this->aggregate( $storage, $this->collision_rows() );
		$indexed = $this->by_product_id( $out );

		$this->assertArrayHasKey( 810333, $indexed, 'The variable parent must be present, not dropped.' );
		$parent = $indexed[810333];

		$this->assertTrue( $parent['is_parent'], 'A product with variation rows is a parent.' );
		$this->assertSame( 'Reader Donation', $parent['name'], 'Parent keeps the canonical product title, not the bare row label.' );
		$this->assertSame( 'recurring', $parent['billing_model'], 'Parent stays recurring (any recurring variation promotes it) despite the one-time bare gift.' );

		// 200 + 90 + 0 = 290 — the omission is gone.
		$this->assertSame( 290, $parent['active_recurring_donors'], 'Active recurring donors sum all variations plus the bare-parent row.' );
		$this->assertSame( 9, $parent['lapsed_donors_in_window'], 'Lapsed donors sum the variations (5 + 4); the bare row adds 0.' );
		$this->assertSame( 40, $parent['new_donors_in_window'], 'New donors sum the variations (30 + 10); the bare row adds 0.' );
		$this->assertSame( 7, $parent['one_time_gifts_in_window'], 'One-time gifts come from the bare-parent gift (0 + 0 + 7).' );
		$this->assertEqualsWithDelta( 28500.0, $parent['recurring_revenue_in_window'], 0.001, 'Recurring revenue sums 24000 + 4500.' );
		$this->assertEqualsWithDelta( 130500.0, $parent['lifetime_donation_revenue'], 0.001, 'Lifetime revenue sums 100000 + 30000 + 500.' );
	}

	/**
	 * The bare-parent gift appears as a synthetic "(no variation)" variation row,
	 * and the renderer's invariant — parent aggregates equal the SUM of its
	 * variation rows — holds after the merge.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param object $storage Storage backend.
	 */
	public function test_synthetic_no_variation_row_and_sum_invariant( $storage ) {
		$out     = $this->aggregate( $storage, $this->collision_rows() );
		$indexed = $this->by_product_id( $out );
		$parent  = $indexed[810333];

		$this->assertCount( 3, $parent['variations'], 'Two real variations plus the synthetic bare-parent row.' );

		$labels = wp_list_pluck( $parent['variations'], 'label' );
		$this->assertContains( '(no variation)', $labels, 'The bare-parent gift surfaces as a "(no variation)" row.' );

		// The renderer relies on: parent totals == sum of variation rows.
		$sum_active    = array_sum( wp_list_pluck( $parent['variations'], 'active_recurring_donors' ) );
		$sum_lapsed    = array_sum( wp_list_pluck( $parent['variations'], 'lapsed_donors_in_window' ) );
		$sum_new       = array_sum( wp_list_pluck( $parent['variations'], 'new_donors_in_window' ) );
		$sum_one_time  = array_sum( wp_list_pluck( $parent['variations'], 'one_time_gifts_in_window' ) );
		$sum_recurring = array_sum( wp_list_pluck( $parent['variations'], 'recurring_revenue_in_window' ) );
		$sum_ltv       = array_sum( wp_list_pluck( $parent['variations'], 'lifetime_donation_revenue' ) );

		$this->assertSame( $parent['active_recurring_donors'], $sum_active, 'Parent active_recurring_donors equals the sum of its variation rows.' );
		$this->assertSame( $parent['lapsed_donors_in_window'], $sum_lapsed, 'Parent lapsed_donors_in_window equals the sum of its variation rows.' );
		$this->assertSame( $parent['new_donors_in_window'], $sum_new, 'Parent new_donors_in_window equals the sum of its variation rows.' );
		$this->assertSame( $parent['one_time_gifts_in_window'], $sum_one_time, 'Parent one_time_gifts_in_window equals the sum of its variation rows.' );
		$this->assertEqualsWithDelta( $parent['recurring_revenue_in_window'], $sum_recurring, 0.001, 'Parent recurring_revenue_in_window equals the sum of its variation rows.' );
		$this->assertEqualsWithDelta( $parent['lifetime_donation_revenue'], $sum_ltv, 0.001, 'Parent lifetime_donation_revenue equals the sum of its variation rows.' );

		// The synthetic row carries the bare gift's numbers and is one-time.
		$no_variation = null;
		foreach ( $parent['variations'] as $variation ) {
			if ( '(no variation)' === $variation['label'] ) {
				$no_variation = $variation;
				break;
			}
		}
		$this->assertNotNull( $no_variation );
		$this->assertSame( 7, $no_variation['one_time_gifts_in_window'], 'The synthetic row holds the bare-parent one-time gifts.' );
		$this->assertSame( 'one_time', $no_variation['billing_model'], 'The bare-parent gift has no sub period, so it is one-time.' );
	}

	/**
	 * Order-independence: feeding the bare-parent row FIRST (before its
	 * variations) must produce the same merged parent bucket. Guards against a
	 * fix that only works because the SQL happens to order the rows a certain way.
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

		$this->assertArrayHasKey( 810333, $indexed );
		$parent = $indexed[810333];
		$this->assertTrue( $parent['is_parent'], 'Bucket is a parent regardless of row order.' );
		$this->assertSame( 'recurring', $parent['billing_model'], 'billing_model promotes to recurring even when the one-time bare row comes first.' );
		$this->assertSame( 290, $parent['active_recurring_donors'], 'Active recurring donors merge the same way when the bare row comes first.' );
		$this->assertCount( 3, $parent['variations'], 'All three rows survive regardless of order.' );
	}

	/**
	 * A variable product WITHOUT a bare-parent gift is unaffected, and a true
	 * standalone one-time donation product still renders as a single non-parent
	 * row with no variations scaffold.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param object $storage Storage backend.
	 */
	public function test_control_products_unaffected( $storage ) {
		$out     = $this->aggregate( $storage, $this->collision_rows() );
		$indexed = $this->by_product_id( $out );

		// Control variable product — single variation, no bare gift.
		$this->assertArrayHasKey( 810339, $indexed );
		$member_gift = $indexed[810339];
		$this->assertTrue( $member_gift['is_parent'] );
		$this->assertSame( 'recurring', $member_gift['billing_model'] );
		$this->assertSame( 25, $member_gift['active_recurring_donors'] );
		$this->assertCount( 1, $member_gift['variations'] );

		// True standalone one-time donation product — non-parent, no variations key.
		$this->assertArrayHasKey( 820000, $indexed );
		$one_time = $indexed[820000];
		$this->assertFalse( $one_time['is_parent'] );
		$this->assertSame( 'one_time', $one_time['billing_model'] );
		$this->assertSame( 15, $one_time['one_time_gifts_in_window'] );
		$this->assertArrayNotHasKey( 'variations', $one_time, 'Standalone products carry no variations scaffold.' );
	}
}
