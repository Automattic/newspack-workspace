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
		parent::tear_down();
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
