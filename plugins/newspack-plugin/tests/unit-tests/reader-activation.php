<?php
/**
 * Tests the Reader Activation functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;

/**
 * Tests the Reader Activation functionality.
 */
class Newspack_Test_Reader_Activation extends WP_UnitTestCase {
	/**
	 * Test reader email.
	 *
	 * @var string
	 */
	private static $reader_email = 'reader@test.com';

	/**
	 * Test reader name.
	 *
	 * @var string
	 */
	private static $reader_name = 'Reader Test';

	/**
	 * Helper function to register sample reader
	 */
	private static function register_sample_reader() {
		return Reader_Activation::register_reader( self::$reader_email, self::$reader_name );
	}

	/**
	 * Test that registering a reader creates and authenticates a user with reader
	 * meta.
	 */
	public function test_register_reader() {
		$user_id = self::register_sample_reader();
		$this->assertIsInt( $user_id );
		$this->assertInstanceOf( 'WP_User', get_user_by( 'email', self::$reader_email ) );
		$this->assertInstanceOf( 'WP_User', get_user_by( 'id', $user_id ) );
		$this->assertTrue( (bool) get_user_meta( $user_id, Reader_Activation::READER, true ) );
		$this->assertTrue( is_user_logged_in() );
		$this->assertEquals( $user_id, get_current_user_id() );
		wp_delete_user( $user_id ); // Clean up.
	}

	/**
	 * Test that verifying a reader register the proper meta.
	 */
	public function test_verify_reader_email() {
		$user_id = self::register_sample_reader();
		$user    = get_user_by( 'id', $user_id );
		$this->assertFalse( Reader_Activation::is_reader_verified( $user ) );
		$verified = Reader_Activation::set_reader_verified( $user );
		$this->assertTrue( $verified );
		$this->assertTrue( Reader_Activation::is_reader_verified( $user ) );
		wp_delete_user( $user_id ); // Clean up.
	}

	/**
	 * Test that registering an existing reader returns false and does not
	 * authenticate.
	 */
	public function test_register_existing_reader() {
		$user_id = self::register_sample_reader();
		wp_logout();
		$result = self::register_sample_reader(); // Reregister the same email.
		$this->assertFalse( $result );
		$this->assertFalse( is_user_logged_in() );
		wp_delete_user( $user_id ); // Clean up.
	}

	/**
	 * Test method that validates if user is a reader.
	 */
	public function test_is_user_reader() {
		$reader_id = self::register_sample_reader();
		$this->assertTrue( Reader_Activation::is_user_reader( get_user_by( 'id', $reader_id ) ) );
		wp_delete_user( $reader_id ); // Clean up.

		// Admin should not be a reader.
		$admin_id = wp_insert_user(
			[
				'user_login' => 'sample-admin',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'test@test.com',
				'role'       => 'administrator',
			]
		);
		$this->assertFalse( Reader_Activation::is_user_reader( get_user_by( 'id', $admin_id ) ) );
		wp_delete_user( $admin_id ); // Clean up.

		// Subscriber should be a reader.
		$subscriber_id = wp_insert_user(
			[
				'user_login' => 'sample-subscriber',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'subscriber@test.com',
			]
		);
		$this->assertTrue( Reader_Activation::is_user_reader( get_user_by( 'id', $subscriber_id ) ) );
		wp_delete_user( $subscriber_id ); // Clean up.
	}

	/**
	 * Test strict argument on method that validates if user is a reader.
	 */
	public function test_strict_reader() {
		$reader_id = self::register_sample_reader();
		$this->assertTrue( Reader_Activation::is_user_reader( get_user_by( 'id', $reader_id ), true ) );
		wp_delete_user( $reader_id ); // Clean up.

		$subscriber_id = wp_insert_user(
			[
				'user_login' => 'sample-subscriber',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'subscriber@test.com',
			]
		);
		$this->assertFalse( Reader_Activation::is_user_reader( get_user_by( 'id', $subscriber_id ), true ) );
		wp_delete_user( $subscriber_id ); // Clean up.
	}

	/**
	 * Test restricted roles for reader.
	 */
	public function test_restricted_roles() {
		$reader_id = self::register_sample_reader();
		$user      = get_user_by( 'id', $reader_id );
		$this->assertTrue( Reader_Activation::is_user_reader( $user ) );
		// Editors cannot be readers.
		$user->set_role( 'editor' );
		$this->assertFalse( Reader_Activation::is_user_reader( $user ) );
		// Authors can be readers.
		$user->set_role( 'author' );
		$this->assertTrue( Reader_Activation::is_user_reader( $user ) );
		// Admins cannot be readers.
		$user->set_role( 'administrator' );
		$this->assertFalse( Reader_Activation::is_user_reader( $user ) );
		wp_delete_user( $reader_id ); // Clean up.
	}

	/**
	 * Test is_oauth_redirect detection.
	 */
	public function test_is_oauth_redirect() {
		$this->assertTrue( Reader_Activation::is_oauth_redirect( 'https://example.com/oauth/authorize?client_id=123' ) );
		$this->assertFalse( Reader_Activation::is_oauth_redirect( 'https://example.com/my-account/' ) );
		$this->assertFalse( Reader_Activation::is_oauth_redirect( 'https://example.com/' ) );
		$this->assertFalse( Reader_Activation::is_oauth_redirect( '' ) );
	}

	/**
	 * Test is_oauth_redirect filter can extend routes.
	 */
	public function test_is_oauth_redirect_filter() {
		// Add a custom OAuth route via filter.
		add_filter(
			'newspack_ras_oauth_redirect_routes',
			function ( $routes ) {
				$routes[] = '/custom-oauth/';
				return $routes;
			}
		);

		$this->assertTrue(
			Reader_Activation::is_oauth_redirect( 'https://example.com/custom-oauth/?param=value' ),
			'Custom OAuth route should be detected after adding via filter.'
		);
	}
}
