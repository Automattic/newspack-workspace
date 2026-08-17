<?php
/**
 * Tests Registration contact metadata.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Registration;

/**
 * Test the Registration metadata class.
 *
 * @group Registration_Metadata
 */
class Test_Registration_Metadata extends WP_UnitTestCase {

	/**
	 * User ID for tests.
	 *
	 * @var int
	 */
	private static $user_id;

	/**
	 * Site timezone options as they were before set_site_timezone(), if called.
	 *
	 * @var array|null
	 */
	private $original_timezone = null;

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$user_id = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		delete_user_meta( self::$user_id, Reader_Activation::REGISTRATION_PAGE );
		delete_user_meta( self::$user_id, Reader_Activation::REGISTRATION_METHOD );
		delete_user_meta( self::$user_id, Reader_Activation::REGISTRATION_UTM_SOURCE );
		delete_user_meta( self::$user_id, Reader_Activation::REGISTRATION_UTM_MEDIUM );
		delete_user_meta( self::$user_id, Reader_Activation::REGISTRATION_UTM_CAMPAIGN );
		$this->restore_site_timezone();
		parent::tear_down();
	}

	/**
	 * Set the site timezone, remembering the prior value for restore_site_timezone().
	 *
	 * @param string $timezone_string Timezone string (e.g. 'America/New_York'), or '' to use gmt_offset.
	 * @param int    $gmt_offset      GMT offset in hours, used when $timezone_string is ''.
	 */
	private function set_site_timezone( string $timezone_string, $gmt_offset ) {
		if ( null === $this->original_timezone ) {
			$this->original_timezone = [
				'timezone_string' => get_option( 'timezone_string' ),
				'gmt_offset'      => get_option( 'gmt_offset' ),
			];
		}
		update_option( 'timezone_string', $timezone_string );
		update_option( 'gmt_offset', $gmt_offset );
	}

	/**
	 * Put back whatever set_site_timezone() replaced, if anything.
	 */
	private function restore_site_timezone() {
		if ( null === $this->original_timezone ) {
			return;
		}
		update_option( 'timezone_string', $this->original_timezone['timezone_string'] );
		update_option( 'gmt_offset', $this->original_timezone['gmt_offset'] );
		$this->original_timezone = null;
	}

	/**
	 * Test registration date is formatted correctly.
	 */
	public function test_registration_date_formatted() {
		$metadata = ( new Registration( self::$user_id ) )->get_metadata();
		$this->assertNotEmpty( $metadata['Registration_Date'] );
		// Should match Y-m-d H:i:s format.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $metadata['Registration_Date'] );
	}

	/**
	 * On a non-UTC site, Registration_Date must match the legacy field's
	 * site-local value, not the raw UTC timestamp.
	 */
	public function test_registration_date_matches_site_local_timezone() {
		// 23:30 UTC is 19:30 the same day in a UTC-4 zone (America/New_York, DST).
		$this->set_site_timezone( 'America/New_York', 0 );
		wp_update_user(
			[
				'ID'              => self::$user_id,
				'user_registered' => '2024-06-15 23:30:00',
			]
		);

		$metadata = ( new Registration( self::$user_id ) )->get_metadata();

		$this->assertSame( '2024-06-15 19:30:00', $metadata['Registration_Date'] );
		// The pre-fix behavior would have emitted the un-adjusted UTC value.
		$this->assertNotSame( '2024-06-15 23:30:00', $metadata['Registration_Date'] );
	}

	/**
	 * Test registration page from user meta.
	 */
	public function test_registration_page() {
		update_user_meta( self::$user_id, Reader_Activation::REGISTRATION_PAGE, 'https://example.com/newsletter' );
		$metadata = ( new Registration( self::$user_id ) )->get_metadata();
		$this->assertSame( 'https://example.com/newsletter', $metadata['Registration_Page'] );
	}

	/**
	 * Test registration strategy from user meta.
	 */
	public function test_registration_strategy() {
		update_user_meta( self::$user_id, Reader_Activation::REGISTRATION_METHOD, 'newsletter' );
		$metadata = ( new Registration( self::$user_id ) )->get_metadata();
		$this->assertSame( 'newsletter', $metadata['Registration_Strategy'] );
	}

	/**
	 * Test UTM values from user meta.
	 */
	public function test_utm_from_user_meta() {
		update_user_meta( self::$user_id, Reader_Activation::REGISTRATION_UTM_SOURCE, 'facebook' );
		update_user_meta( self::$user_id, Reader_Activation::REGISTRATION_UTM_MEDIUM, 'social' );
		update_user_meta( self::$user_id, Reader_Activation::REGISTRATION_UTM_CAMPAIGN, 'spring2024' );
		$metadata = ( new Registration( self::$user_id ) )->get_metadata();
		$this->assertSame( 'facebook', $metadata['Registration_UTM_Source'] );
		$this->assertSame( 'social', $metadata['Registration_UTM_Medium'] );
		$this->assertSame( 'spring2024', $metadata['Registration_UTM_Campaign'] );
	}

	/**
	 * Test UTM empty when not set.
	 */
	public function test_utm_empty_when_not_set() {
		$metadata = ( new Registration( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Registration_UTM_Source'] );
		$this->assertSame( '', $metadata['Registration_UTM_Medium'] );
		$this->assertSame( '', $metadata['Registration_UTM_Campaign'] );
	}

	/**
	 * Test empty fields by default.
	 */
	public function test_empty_fields_by_default() {
		$metadata = ( new Registration( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Registration_Page'] );
		$this->assertSame( '', $metadata['Registration_Strategy'] );
	}

	/**
	 * Test returns empty without user.
	 */
	public function test_returns_empty_without_user() {
		$metadata = ( new Registration( 0 ) )->get_metadata();
		$this->assertSame( [], $metadata );
	}
}
