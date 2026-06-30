<?php
/**
 * Tests for Insights Preset_Windows.
 *
 * @package Newspack
 */

use Newspack\Insights\Preset_Windows;

/**
 * Tests for Preset_Windows.
 *
 * @group insights
 */
class Test_Insights_Preset_Windows extends WP_UnitTestCase {

	/** Anchor: Wed 2026-06-17 in UTC. */
	private function anchor(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-06-17 09:30:00', new DateTimeZone( 'UTC' ) );
	}

	/** Returns exactly 5 presets in canonical order. */
	public function test_returns_five_presets_in_order() {
		$rows    = Preset_Windows::all( $this->anchor() );
		$presets = array_column( $rows, 'preset' );
		$this->assertSame( [ 'last-7', 'last-30', 'last-90', 'this-month', 'last-month' ], $presets );
	}

	/** Last-7 is an inclusive 7-day window ending today with correct boundary times. */
	public function test_last_7_is_inclusive_seven_day_window_ending_today() {
		$rows = Preset_Windows::all( $this->anchor() );
		$row  = $rows[0];
		$this->assertSame( '2026-06-11', $row['start']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-06-17', $row['end']->format( 'Y-m-d' ) );
		$this->assertSame( '00:00:00', $row['start']->format( 'H:i:s' ) );
		$this->assertSame( '23:59:59', $row['end']->format( 'H:i:s' ) );
	}

	/** Last-30 starts 29 days ago; last-90 starts 89 days ago (inclusive windows). */
	public function test_last_30_and_90_offsets() {
		$rows = Preset_Windows::all( $this->anchor() );
		$this->assertSame( '2026-05-19', $rows[1]['start']->format( 'Y-m-d' ) ); // last-30: -29 days.
		$this->assertSame( '2026-03-20', $rows[2]['start']->format( 'Y-m-d' ) ); // last-90: -89 days.
	}

	/** This-month starts on the 1st and ends today. */
	public function test_this_month_starts_first_of_month_ends_today() {
		$rows = Preset_Windows::all( $this->anchor() );
		$this->assertSame( '2026-06-01', $rows[3]['start']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-06-17', $rows[3]['end']->format( 'Y-m-d' ) );
	}

	/** Last-month covers the full previous calendar month. */
	public function test_last_month_is_full_previous_month() {
		$rows = Preset_Windows::all( $this->anchor() );
		$this->assertSame( '2026-05-01', $rows[4]['start']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-05-31', $rows[4]['end']->format( 'Y-m-d' ) );
	}

	/** Returned DateTimeImmutable objects preserve the input timezone. */
	public function test_preserves_timezone() {
		$tz   = new DateTimeZone( 'America/New_York' );
		$rows = Preset_Windows::all( new DateTimeImmutable( '2026-06-17 12:00:00', $tz ) );
		$this->assertSame( $tz->getName(), $rows[0]['start']->getTimezone()->getName() );
	}
}
