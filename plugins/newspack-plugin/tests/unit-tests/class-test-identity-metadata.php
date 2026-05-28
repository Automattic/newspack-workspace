<?php
/**
 * Tests Identity contact metadata.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Identity;

/**
 * Test the Identity metadata class.
 *
 * @group Identity_Metadata
 */
class Test_Identity_Metadata extends WP_UnitTestCase {

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
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
			]
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		delete_user_meta( self::$user_id, Reader_Activation::EMAIL_VERIFIED );
		delete_user_meta( self::$user_id, Reader_Activation::CONNECTED_ACCOUNT );
		parent::tear_down();
	}

	/**
	 * Test basic identity fields.
	 */
	public function test_basic_identity_fields() {
		$metadata = ( new Identity( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Jane', $metadata['first_name'] );
		$this->assertSame( 'Doe', $metadata['last_name'] );
		$this->assertSame( 'reader@example.com', $metadata['email'] );
		$this->assertSame( (string) self::$user_id, $metadata['Account'] );
		$this->assertSame( 'subscriber', $metadata['User_Role'] );
	}

	/**
	 * Test verified is false by default.
	 */
	public function test_verified_false_by_default() {
		$metadata = ( new Identity( self::$user_id ) )->get_metadata();
		$this->assertFalse( $metadata['verified'] );
	}

	/**
	 * Test verified is true when set.
	 */
	public function test_verified_true_when_set() {
		update_user_meta( self::$user_id, Reader_Activation::EMAIL_VERIFIED, true );
		$metadata = ( new Identity( self::$user_id ) )->get_metadata();
		$this->assertTrue( $metadata['verified'] );
	}

	/**
	 * Test connected account is empty by default.
	 */
	public function test_connected_account_empty_by_default() {
		$metadata = ( new Identity( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Connected_Account'] );
	}

	/**
	 * Test connected account when set.
	 */
	public function test_connected_account_when_set() {
		update_user_meta( self::$user_id, Reader_Activation::CONNECTED_ACCOUNT, 'google' );
		$metadata = ( new Identity( self::$user_id ) )->get_metadata();
		$this->assertSame( 'google', $metadata['Connected_Account'] );
	}

	/**
	 * Test returns empty without user.
	 */
	public function test_returns_empty_without_user() {
		$metadata = ( new Identity( 0 ) )->get_metadata();
		$this->assertSame( [], $metadata );
	}
}
