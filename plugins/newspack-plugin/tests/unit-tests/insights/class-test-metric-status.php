<?php
/**
 * Test Metric_Status::derive() (NEWS-2603 Task 2).
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Metric_Status;
use WP_UnitTestCase;

/**
 * Metric_Status test class.
 *
 * @group insights
 */
class Test_Metric_Status extends WP_UnitTestCase {

	/**
	 * Any errored metric-scalar, however deeply nested, wins over a warming
	 * one elsewhere in the envelope — error precedence beats warming.
	 */
	public function test_derive_incomplete_when_any_metric_errored() {
		$env = [
			'a' => [ 'state' => 'populated' ],
			'b' => [ 'nested' => [ 'state' => 'error' ] ],
		];
		$this->assertSame( 'incomplete', Metric_Status::derive( $env ) );
	}

	/**
	 * With no errors present, any warming metric-scalar makes the whole
	 * envelope 'warming'.
	 */
	public function test_derive_warming_when_any_metric_warming_and_none_errored() {
		$env = [
			'a' => [ 'state' => 'populated' ],
			'b' => [ 'state' => 'warming' ],
		];
		$this->assertSame( 'warming', Metric_Status::derive( $env ) );
	}

	/**
	 * All metric-scalars populated (or otherwise non-error/non-warming)
	 * yields 'complete'.
	 */
	public function test_derive_complete_when_all_populated() {
		$env = [
			'a' => [ 'state' => 'populated' ],
			'b' => [ 'state' => 'populated' ],
		];
		$this->assertSame( 'complete', Metric_Status::derive( $env ) );
	}

	/**
	 * An envelope with no `state`-bearing nodes anywhere (e.g. all metrics
	 * predate the state-envelope convention) defaults to 'complete' — no
	 * state at all is not the same as an error or a warming signal.
	 */
	public function test_derive_complete_when_no_state_keys_present() {
		$env = [
			'a' => [ 'value' => 1 ],
			'b' => [ 'nested' => [ 'value' => 2 ] ],
		];
		$this->assertSame( 'complete', Metric_Status::derive( $env ) );
	}

	/**
	 * Once a node carries a `state` key it is treated as a leaf scalar — its
	 * own children are not descended into, even if they happen to also carry
	 * `state` keys of their own.
	 */
	public function test_does_not_recurse_past_a_scalar_node() {
		$env = [
			'a' => [
				'state' => 'populated',
				// This nested 'error' must be ignored: 'a' is already a
				// scalar (it has its own top-level 'state' key), so its
				// children are not walked.
				'child' => [ 'state' => 'error' ],
			],
		];
		$this->assertSame( 'complete', Metric_Status::derive( $env ) );
	}

	/**
	 * Error precedence holds even when the warming node is discovered before
	 * the errored node during the walk.
	 */
	public function test_error_precedence_regardless_of_walk_order() {
		$env = [
			'a' => [ 'state' => 'warming' ],
			'b' => [ 'state' => 'populated' ],
			'c' => [ 'state' => 'error' ],
		];
		$this->assertSame( 'incomplete', Metric_Status::derive( $env ) );
	}

	/**
	 * An empty envelope has no metric-scalars at all, so it is 'complete'.
	 */
	public function test_derive_complete_for_empty_envelope() {
		$this->assertSame( 'complete', Metric_Status::derive( [] ) );
	}
}
