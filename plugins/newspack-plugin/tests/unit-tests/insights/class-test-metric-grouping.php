<?php
/**
 * Tests for the shared Insights grouping helper (NEWS-2591 / NEWS-2580).
 *
 * @package Newspack
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Metric_Grouping;
use WP_UnitTestCase;

/**
 * Pure-function tests for Metric_Grouping::group_records_by_key().
 */
class Test_Metric_Grouping extends WP_UnitTestCase {

	/**
	 * Groups by key, counts rows, and sums the amount column.
	 */
	public function test_groups_and_sums() {
		$rows = [
			[
				'utm_campaign' => 'alpha',
				'revenue'      => 10.0,
			],
			[
				'utm_campaign' => 'alpha',
				'revenue'      => 5.0,
			],
			[
				'utm_campaign' => 'beta',
				'revenue'      => 3.0,
			],
		];

		$result = Metric_Grouping::group_records_by_key( $rows, 'utm_campaign', 'revenue' );

		$this->assertCount( 2, $result );
		$this->assertSame( 'alpha', $result[0]['value'] );
		$this->assertSame( 2, $result[0]['count'] );
		$this->assertSame( 15.0, $result[0]['amount'] );
		$this->assertFalse( $result[0]['is_untagged'] );
		$this->assertSame( 'beta', $result[1]['value'] );
		$this->assertSame( 1, $result[1]['count'] );
		$this->assertSame( 3.0, $result[1]['amount'] );
	}

	/**
	 * Default trim() normalization collapses values that differ only by
	 * surrounding whitespace into a single group.
	 */
	public function test_trim_collapses_whitespace_variants() {
		$rows = [
			[ 'utm_campaign' => 'buffer' ],
			[ 'utm_campaign' => 'buffer ' ],
			[ 'utm_campaign' => ' buffer' ],
		];

		$result = Metric_Grouping::group_records_by_key( $rows, 'utm_campaign' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'buffer', $result[0]['value'] );
		$this->assertSame( 3, $result[0]['count'] );
	}

	/**
	 * Whitespace-only values and rows missing the group key route to the
	 * untagged bucket, which renders last and is flagged is_untagged.
	 */
	public function test_untagged_bucket_last_and_flagged() {
		$rows = [
			[
				'utm_campaign' => 'alpha',
				'revenue'      => 4.0,
			],
			[
				'utm_campaign' => '   ',
				'revenue'      => 2.0,
			], // whitespace-only → untagged.
			[ 'revenue' => 1.0 ],                          // missing key → untagged.
		];

		$result = Metric_Grouping::group_records_by_key(
			$rows,
			'utm_campaign',
			'revenue',
			[ 'untagged_label' => '(no campaign)' ]
		);

		$this->assertCount( 2, $result );
		// Tagged row first.
		$this->assertSame( 'alpha', $result[0]['value'] );
		$this->assertFalse( $result[0]['is_untagged'] );
		// Untagged row always last, flagged, with the two untagged rows folded in.
		$last = $result[ count( $result ) - 1 ];
		$this->assertSame( '(no campaign)', $last['value'] );
		$this->assertTrue( $last['is_untagged'] );
		$this->assertSame( 2, $last['count'] );
		$this->assertSame( 3.0, $last['amount'] );
	}

	/**
	 * When no untagged_label is given, untagged rows are dropped entirely.
	 */
	public function test_untagged_dropped_when_no_label() {
		$rows = [
			[ 'utm_campaign' => 'alpha' ],
			[ 'utm_campaign' => '' ],
			[ 'utm_campaign' => '  ' ],
		];

		$result = Metric_Grouping::group_records_by_key( $rows, 'utm_campaign' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'alpha', $result[0]['value'] );
	}

	/**
	 * Count-only mode (empty amount_key) yields 0.0 amounts.
	 */
	public function test_count_only_yields_zero_amounts() {
		$rows = [
			[
				'utm_campaign' => 'alpha',
				'revenue'      => 99.0,
			],
			[
				'utm_campaign' => 'alpha',
				'revenue'      => 1.0,
			],
		];

		$result = Metric_Grouping::group_records_by_key( $rows, 'utm_campaign', '' );

		$this->assertCount( 1, $result );
		$this->assertSame( 2, $result[0]['count'] );
		$this->assertSame( 0.0, $result[0]['amount'] );
	}

	/**
	 * Ranking: count desc, then amount desc, then value asc.
	 */
	public function test_sort_order() {
		$rows = [
			// count 2, amount 10.
			[
				'utm_campaign' => 'x',
				'revenue'      => 6.0,
			],
			[
				'utm_campaign' => 'x',
				'revenue'      => 4.0,
			],
			// count 2, amount 20 (higher amount → ranks above x on the count tie).
			[
				'utm_campaign' => 'y',
				'revenue'      => 12.0,
			],
			[
				'utm_campaign' => 'y',
				'revenue'      => 8.0,
			],
			// count 3 (ranks first on count).
			[
				'utm_campaign' => 'z',
				'revenue'      => 1.0,
			],
			[
				'utm_campaign' => 'z',
				'revenue'      => 1.0,
			],
			[
				'utm_campaign' => 'z',
				'revenue'      => 3.0,
			],
		];

		$result = Metric_Grouping::group_records_by_key( $rows, 'utm_campaign', 'revenue' );

		$this->assertSame( [ 'z', 'y', 'x' ], array_column( $result, 'value' ) );
	}

	/**
	 * Value-ascending tiebreak when count and amount are equal.
	 */
	public function test_value_asc_tiebreak() {
		$rows = [
			[
				'utm_campaign' => 'beta',
				'revenue'      => 5.0,
			],
			[
				'utm_campaign' => 'alpha',
				'revenue'      => 5.0,
			],
		];

		$result = Metric_Grouping::group_records_by_key( $rows, 'utm_campaign', 'revenue' );

		$this->assertSame( [ 'alpha', 'beta' ], array_column( $result, 'value' ) );
	}
}
