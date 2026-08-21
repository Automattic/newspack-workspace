<?php
/**
 * Class Test Ads Date Display
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Ads;

/**
 * `start_date` / `expiry_date` are whole calendar days with no time and no
 * timezone — `is_ad_active()` compares them as `Y-m-d` strings against the
 * newsletter's own date. Rendering them therefore has to yield the day that
 * was stored, on every site.
 *
 * The regression this guards: `strtotime( 'Y-m-d' )` resolves against PHP's
 * default zone (UTC under WordPress), and `wp_date()` then converts into the
 * site zone, so an ad stored as `2026-08-04` displayed as August 3 on every
 * site at a negative UTC offset.
 */
class Ads_Date_Display_Test extends WP_UnitTestCase {

	/**
	 * Restore the default timezone between tests.
	 */
	public function tear_down() {
		update_option( 'timezone_string', 'UTC' );
		parent::tear_down();
	}

	/**
	 * Site timezones spanning the usable UTC offset range.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function timezone_provider() {
		return [
			'UTC'                 => [ 'UTC' ],
			'UTC-10 (Honolulu)'   => [ 'Pacific/Honolulu' ],
			'UTC-7 (Los Angeles)' => [ 'America/Los_Angeles' ],
			'UTC-4 (New York)'    => [ 'America/New_York' ],
			'UTC+2 (Paris)'       => [ 'Europe/Paris' ],
			'UTC+9 (Tokyo)'       => [ 'Asia/Tokyo' ],
			'UTC+14 (Kiritimati)' => [ 'Pacific/Kiritimati' ],
		];
	}

	/**
	 * The rendered date is the stored date, whatever the site timezone.
	 *
	 * @dataProvider timezone_provider
	 * @param string $timezone Site timezone string.
	 */
	public function test_stored_day_survives_every_site_timezone( $timezone ) {
		update_option( 'timezone_string', $timezone );

		$this->assertSame(
			'August 4, 2026',
			Ads::format_ad_date( '2026-08-04' ),
			"Stored date shifted in {$timezone}."
		);
	}

	/**
	 * Month and year boundaries are where an off-by-one is most damaging.
	 *
	 * @dataProvider timezone_provider
	 * @param string $timezone Site timezone string.
	 */
	public function test_month_and_year_boundaries_hold( $timezone ) {
		update_option( 'timezone_string', $timezone );

		$this->assertSame( 'January 1, 2026', Ads::format_ad_date( '2026-01-01' ) );
		$this->assertSame( 'March 1, 2026', Ads::format_ad_date( '2026-03-01' ) );
	}

	/**
	 * A DST transition day still renders as itself.
	 */
	public function test_dst_transition_day_holds() {
		update_option( 'timezone_string', 'America/New_York' );

		// US DST starts 2026-03-08; local midnight exists, but the offset changes.
		$this->assertSame( 'March 8, 2026', Ads::format_ad_date( '2026-03-08' ) );
		// Chile shifts at local midnight, so midnight itself does not exist.
		update_option( 'timezone_string', 'America/Santiago' );
		$this->assertSame( 'September 6, 2026', Ads::format_ad_date( '2026-09-06' ) );
	}

	/**
	 * A legacy ISO datetime value is reduced to its date.
	 */
	public function test_legacy_datetime_value_is_reduced_to_its_date() {
		update_option( 'timezone_string', 'America/New_York' );

		$this->assertSame( 'August 4, 2026', Ads::format_ad_date( '2026-08-04T23:59:59' ) );
	}

	/**
	 * Empty and malformed values render as nothing, so callers can show a dash.
	 */
	public function test_empty_and_invalid_values_return_empty_string() {
		$this->assertSame( '', Ads::format_ad_date( '' ) );
		$this->assertSame( '', Ads::format_ad_date( null ) );
		$this->assertSame( '', Ads::format_ad_date( 'not-a-date' ) );
		$this->assertSame( '', Ads::format_ad_date( '2026-13-45' ) );
	}
}
