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
	 * The z statistic is the one behind confidence(), with the same nulls.
	 */
	public function test_z_matches_confidence() {
		$this->assertNull( Newspack_Popups_AB_Stats::z( 29, 10, 100, 10 ) );
		$this->assertNull( Newspack_Popups_AB_Stats::z( 100, 0, 100, 0 ) );
		$this->assertSame( 0.0, Newspack_Popups_AB_Stats::z( 1000, 50, 1000, 50 ) );
		$this->assertEqualsWithDelta( 0.6966, Newspack_Popups_AB_Stats::z( 400, 8, 400, 11 ), 0.0001 );
	}

	/**
	 * At 2.00% against 2.75% on 800 readers, the readers still to go scale with
	 * (target z / current z)², worked by hand: 368 to 80%, 3,662 to 95%.
	 */
	public function test_readers_to_each_label() {
		$this->assertSame( 368, Newspack_Popups_AB_Stats::readers_to( 'directional', 400, 8, 400, 11 ) );
		$this->assertSame( 3662, Newspack_Popups_AB_Stats::readers_to( 'reliable', 400, 8, 400, 11 ) );
		// The same whichever arm is ahead.
		$this->assertSame( 368, Newspack_Popups_AB_Stats::readers_to( 'directional', 400, 11, 400, 8 ) );
	}

	/**
	 * No estimate once a label is reached, without a confidence figure, when
	 * the rates are level, or for an unknown label.
	 */
	public function test_readers_to_has_no_estimate_when_there_is_nothing_to_project() {
		// 88.4%: past an Early signal, short of Reliable.
		$this->assertNull( Newspack_Popups_AB_Stats::readers_to( 'directional', 1000, 19, 1000, 27 ) );
		$this->assertSame( 1800, Newspack_Popups_AB_Stats::readers_to( 'reliable', 1000, 19, 1000, 27 ) );
		$this->assertNull( Newspack_Popups_AB_Stats::readers_to( 'reliable', 1000, 19, 1000, 32 ) );
		$this->assertNull( Newspack_Popups_AB_Stats::readers_to( 'reliable', 29, 1, 100, 5 ) );
		$this->assertNull( Newspack_Popups_AB_Stats::readers_to( 'reliable', 1000, 50, 1000, 50 ) );
		$this->assertNull( Newspack_Popups_AB_Stats::readers_to( 'certain', 400, 8, 400, 11 ) );
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
