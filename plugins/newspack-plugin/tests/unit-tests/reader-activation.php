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

	/**
	 * Test that get_verification_payload() returns the stable shape (both keys
	 * always present) and that the verification_nonce is bound to the just-
	 * registered user's session, so a subsequent OTP send from that session
	 * verifies the nonce successfully.
	 *
	 * Reproduces the scenario flagged in pr-test review (S10): when entry points
	 * like the Newsletter Subscription block call register_reader(..., true, ...)
	 * and then immediately compute the verification payload, the nonce must work
	 * for the new user — not be silently bound to user 0.
	 */
	public function test_get_verification_payload_nonce_binds_to_new_user() {
		// Simulate logged-out request.
		wp_set_current_user( 0 );
		$this->assertFalse( is_user_logged_in() );

		// Register a new reader. Third arg `true` authenticates the user in this request.
		$user_id = Reader_Activation::register_reader( self::$reader_email, self::$reader_name, true );
		$this->assertIsInt( $user_id );
		$this->assertEquals( $user_id, get_current_user_id(), 'register_reader should set the current user.' );

		// The payload must contain both keys (stable shape contract).
		$payload = Reader_Activation::get_verification_payload( $user_id );
		$this->assertArrayHasKey( 'verified', $payload );
		$this->assertArrayHasKey( 'verification_nonce', $payload );
		$this->assertFalse( $payload['verified'], 'New reader should be unverified.' );
		$this->assertNotEmpty( $payload['verification_nonce'], 'Unverified new reader should receive a nonce.' );

		// The nonce must verify in the just-registered session.
		$this->assertEquals(
			1,
			wp_verify_nonce( $payload['verification_nonce'], 'newspack_reader_registration_verification' ),
			'Verification nonce must verify in the just-registered user session.'
		);

		wp_delete_user( $user_id ); // Clean up.
	}

	/**
	 * Test that get_verification_payload() returns the empty-sentinel shape
	 * for invalid input — both keys present, `verified` null, `verification_nonce`
	 * an empty string — so cross-plugin callers can pattern-match without
	 * `isset()` ladders.
	 */
	public function test_get_verification_payload_shape_for_invalid_user() {
		$payload = Reader_Activation::get_verification_payload( 0 );
		$this->assertSame(
			[
				'verified'           => null,
				'verification_nonce' => '',
			],
			$payload
		);

		$payload = Reader_Activation::get_verification_payload( 999999999 );
		$this->assertSame(
			[
				'verified'           => null,
				'verification_nonce' => '',
			],
			$payload
		);
	}

	/**
	 * Test that an already-verified reader receives `verified => true` and an
	 * empty nonce (no verification flow to trigger).
	 */
	public function test_get_verification_payload_for_verified_reader() {
		$user_id = self::register_sample_reader();
		Reader_Activation::set_reader_verified( get_user_by( 'id', $user_id ) );

		$payload = Reader_Activation::get_verification_payload( $user_id );
		$this->assertTrue( $payload['verified'] );
		$this->assertSame( '', $payload['verification_nonce'], 'Verified reader should receive an empty nonce.' );

		wp_delete_user( $user_id ); // Clean up.
	}

	/**
	 * Test that the prerequisites status no longer exposes skip-related keys.
	 */
	public function test_prerequisites_status_has_no_skip_keys() {
		$prerequisites = Reader_Activation::get_prerequisites_status();
		$this->assertNotEmpty( $prerequisites );
		foreach ( $prerequisites as $slug => $prerequisite ) {
			$this->assertArrayNotHasKey( 'skippable', $prerequisite, "Prerequisite '$slug' should not expose 'skippable'." );
			$this->assertArrayNotHasKey( 'is_skipped', $prerequisite, "Prerequisite '$slug' should not expose 'is_skipped'." );
			$this->assertArrayNotHasKey( 'action_enabled', $prerequisite, "Prerequisite '$slug' should not expose 'action_enabled'." );
			$this->assertArrayNotHasKey( 'disabled_text', $prerequisite, "Prerequisite '$slug' should not expose 'disabled_text'." );
		}
	}

	/**
	 * Test that the auto-enable and skip helpers have been removed.
	 */
	public function test_auto_enable_and_skip_helpers_removed() {
		$this->assertFalse( method_exists( Reader_Activation::class, 'is_ras_ready_to_configure' ), 'is_ras_ready_to_configure() should be removed.' );
		$this->assertFalse( method_exists( Reader_Activation::class, 'skip' ), 'skip() should be removed.' );
		$this->assertFalse( method_exists( Reader_Activation::class, 'is_skipped' ), 'is_skipped() should be removed.' );
	}

	/**
	 * Test that removed prerequisites are gone and ordering is correct.
	 */
	public function test_prerequisites_status_cleanup() {
		$prerequisites = Reader_Activation::get_prerequisites_status();

		$this->assertArrayNotHasKey( 'reader_revenue', $prerequisites, 'Reader Revenue prerequisite should be removed.' );
		$this->assertArrayNotHasKey( 'ras_campaign', $prerequisites, 'Campaign defaults prerequisite should be removed.' );

		// First three are always present and ordered.
		$keys = array_keys( $prerequisites );
		$this->assertSame( 'emails', $keys[0], 'Transactional Emails should be first.' );
		$this->assertSame( 'terms_conditions', $keys[1], 'Legal Pages should be second.' );
		$this->assertSame( 'recaptcha', $keys[2], 'reCAPTCHA should be third.' );

		// ESP is gated on Newspack Newsletters; in the test env it is absent.
		if ( class_exists( '\Newspack_Newsletters' ) ) {
			$this->assertArrayHasKey( 'esp', $prerequisites, 'ESP should be present when Newsletters exists.' );
			$this->assertSame( 'esp', $keys[3], 'ESP should be fourth when present.' );
		} else {
			$this->assertArrayNotHasKey( 'esp', $prerequisites, 'ESP should be absent without Newsletters.' );
		}
	}

	/**
	 * Test the reader-revenue platform first-run signal.
	 */
	public function test_is_platform_selected() {
		delete_option( 'newspack_reader_revenue_platform' );
		$this->assertFalse(
			\Newspack\Donations::is_platform_selected(),
			'Platform should report not selected when the option was never saved.'
		);

		\Newspack\Donations::set_platform_slug( 'wc' );
		$this->assertTrue(
			\Newspack\Donations::is_platform_selected(),
			'Platform should report selected after an explicit save.'
		);

		delete_option( 'newspack_reader_revenue_platform' );
	}
}
