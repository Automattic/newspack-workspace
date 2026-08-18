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
		delete_user_meta( self::$user_id, Reader_Activation::REGISTRATION_METHOD );
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
		// Integer, matching the legacy twin (v1:account) this field is declared
		// value-equivalent to.
		$this->assertSame( self::$user_id, $metadata['Account'] );
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
	 * A reader who never used SSO gets no Connected Account key at all — the
	 * legacy twin omitted it, and an empty string would blank the ESP field.
	 */
	public function test_connected_account_omitted_by_default() {
		$metadata = ( new Identity( self::$user_id ) )->get_metadata();
		$this->assertArrayNotHasKey( 'Connected_Account', $metadata );
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
	 * A connected-account value that names no supported SSO provider is not a
	 * connected account — same rule the legacy enrichment applied.
	 */
	public function test_connected_account_ignores_unsupported_value() {
		update_user_meta( self::$user_id, Reader_Activation::CONNECTED_ACCOUNT, 'carrier-pigeon' );
		$metadata = ( new Identity( self::$user_id ) )->get_metadata();
		$this->assertArrayNotHasKey( 'Connected_Account', $metadata );
	}

	/**
	 * Readers who register through SSO get only the registration-method meta
	 * (see Reader_Activation::register_reader()), so it is the fallback source
	 * — as it was in the legacy enrichment.
	 */
	public function test_connected_account_falls_back_to_sso_registration_method() {
		update_user_meta( self::$user_id, Reader_Activation::REGISTRATION_METHOD, 'google' );
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
