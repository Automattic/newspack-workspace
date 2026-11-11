<?php
/**
 * Tests for the A/B test statistics.
 *
 * @package Newspack_Popups
 */

/**
 * Two-proportion confidence and the labels derived from it.
 *
 * @group ab-tests
 */
class ABStatsTest extends WP_UnitTestCase {
	/**
	 * No readers means no rate, not a division by zero.
	 */
	public function test_rate_is_null_without_readers() {
		$this->assertNull( Newspack_Popups_AB_Stats::rate( 0, 0 ) );
	}

	/**
	 * Rate is conversions per hundred readers, two decimals.
	 */
	public function test_rate_is_per_hundred_two_decimals() {
		$this->assertSame( 4.07, Newspack_Popups_AB_Stats::rate( 1180, 48 ) );
	}

	/**
	 * Lift is undefined against a zero or missing control rate.
	 */
	public function test_lift_is_null_when_control_is_zero_or_missing() {
		$this->assertNull( Newspack_Popups_AB_Stats::lift( 0.0, 4.0 ) );
		$this->assertNull( Newspack_Popups_AB_Stats::lift( null, 4.0 ) );
		$this->assertNull( Newspack_Popups_AB_Stats::lift( 3.0, null ) );
	}

	/**
	 * Lift is the challenger's rate relative to control, one decimal.
	 */
	public function test_lift_against_control() {
		$this->assertSame( 35.7, Newspack_Popups_AB_Stats::lift( 3.0, 4.07 ) );
	}

	/**
	 * Below thirty readers in either arm there is no confidence figure.
	 */
	public function test_confidence_needs_thirty_readers_per_arm() {
		$this->assertNull( Newspack_Popups_AB_Stats::confidence( 29, 10, 100, 10 ) );
		$this->assertNull( Newspack_Popups_AB_Stats::confidence( 100, 10, 29, 10 ) );
		$this->assertNotNull( Newspack_Popups_AB_Stats::confidence( 30, 10, 30, 2 ) );
	}

	/**
	 * A pooled rate of 0 or 1 has no variance to test against.
	 */
	public function test_confidence_is_null_at_pooled_rate_zero_or_one() {
		$this->assertNull( Newspack_Popups_AB_Stats::confidence( 100, 0, 100, 0 ) );
		$this->assertNull( Newspack_Popups_AB_Stats::confidence( 100, 100, 100, 100 ) );
	}

	/**
	 * Identical arms sit at the one-sided midpoint.
	 */
	public function test_equal_arms_give_fifty_percent() {
		$this->assertSame( 50.0, Newspack_Popups_AB_Stats::confidence( 1000, 50, 1000, 50 ) );
	}

	/**
	 * A doubled conversion rate on a thousand readers per arm is reliable.
	 */
	public function test_clear_split_is_reliable() {
		$this->assertGreaterThan( 95.0, Newspack_Popups_AB_Stats::confidence( 1000, 30, 1000, 60 ) );
	}

	/**
	 * Labels change at exactly 80 and 95.
	 */
	public function test_labels_at_the_thresholds() {
		$this->assertSame( 'collecting', Newspack_Popups_AB_Stats::label( null ) );
		$this->assertSame( 'collecting', Newspack_Popups_AB_Stats::label( 79.9 ) );
		$this->assertSame( 'directional', Newspack_Popups_AB_Stats::label( 80.0 ) );
		$this->assertSame( 'directional', Newspack_Popups_AB_Stats::label( 94.9 ) );
		$this->assertSame( 'reliable', Newspack_Popups_AB_Stats::label( 95.0 ) );
	}
}
