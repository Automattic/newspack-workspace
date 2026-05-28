<?php
/**
 * Tests for the Metering class.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Metering;
use Newspack\Reader_Activation;

/**
 * Tests for the Metering class.
 */
class Test_Metering extends \WP_UnitTestCase {

	/**
	 * Gate IDs for cleanup.
	 *
	 * @var int[]
	 */
	protected $gate_ids = [];

	/**
	 * Post IDs for cleanup.
	 *
	 * @var int[]
	 */
	protected $post_ids = [];

	/**
	 * User IDs for cleanup.
	 *
	 * @var int[]
	 */
	protected $user_ids = [];

	/**
	 * Test reader email.
	 *
	 * @var string
	 */
	private static $reader_email = 'reader@metering-test.com';

	/**
	 * Teardown after tests.
	 */
	public function tear_down() {
		foreach ( $this->gate_ids as $gate_id ) {
			wp_delete_post( $gate_id, true );
		}
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Helper to create a gate with metering and verification settings.
	 *
	 * @param array $args {
	 *     Optional. Gate configuration.
	 *
	 *     @type bool   $require_verification Whether verification is required.
	 *     @type bool   $metering_enabled     Whether metering is enabled.
	 *     @type int    $metering_count       Number of metered views allowed.
	 *     @type string $metering_period      Metering period (day, week, month).
	 * }
	 * @return int Gate ID.
	 */
	private function create_gate_with_settings( $args = [] ) {
		$defaults = [
			'require_verification' => false,
			'metering_enabled'     => true,
			'metering_count'       => 3,
			'metering_period'      => 'month',
		];
		$args = wp_parse_args( $args, $defaults );

		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Gate' ] );
		$this->gate_ids[] = $gate_id;

		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Test Gate',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => $args['registration'] ?? [
					'active'               => true,
					'metering'             => [
						'enabled' => $args['metering_enabled'],
						'count'   => $args['metering_count'],
						'period'  => $args['metering_period'],
					],
					'require_verification' => $args['require_verification'],
					'gate_id'              => 0,
				],
				'custom_access' => $args['custom_access'] ?? [
					'active'       => true,
					'metering'     => [
						'enabled' => $args['metering_enabled'],
						'count'   => $args['metering_count'],
						'period'  => $args['metering_period'],
					],
					'gate_id'      => 0,
					'access_rules' => [],
				],
			]
		);

		return $gate_id;
	}

	/**
	 * Helper to register a reader user.
	 *
	 * @param string $email Reader email.
	 * @return int User ID.
	 */
	private function register_reader( $email = null ) {
		if ( ! $email ) {
			$email = self::$reader_email;
		}
		$user_id = Reader_Activation::register_reader( $email, 'Test Reader' );
		if ( $user_id && ! is_wp_error( $user_id ) ) {
			$this->user_ids[] = $user_id;
		}
		return $user_id;
	}

	/**
	 * Helper to create an admin user.
	 *
	 * @return int User ID.
	 */
	private function create_admin_user() {
		$user_id = wp_insert_user(
			[
				'user_login' => 'test-admin-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'admin-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Helper to create an editor user.
	 *
	 * @return int User ID.
	 */
	private function create_editor_user() {
		$user_id = wp_insert_user(
			[
				'user_login' => 'test-editor-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'editor-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'editor',
			]
		);
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Test that metering is blocked when gate requires verification and user is not verified.
	 */
	public function test_metering_blocked_when_unverified() {
		// Create a gate that requires verification.
		$gate_id = $this->create_gate_with_settings(
			[
				'require_verification' => true,
				'metering_enabled'     => true,
				'metering_count'       => 5,
			]
		);

		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Register a reader but don't verify them.
		$user_id = $this->register_reader();
		wp_set_current_user( $user_id );

		$user = wp_get_current_user();

		// Verify the user is a reader and not verified.
		$this->assertTrue( Reader_Activation::is_user_reader( $user ), 'User should be a reader' );
		$this->assertFalse( Reader_Activation::is_reader_verified( $user ), 'Reader should not be verified' );

		// Verify the gate requires verification.
		$this->assertTrue( Content_Gate::requires_account_verification( $gate_id ), 'Gate should require verification' );

		// Apply the filter to control the gate context for testing.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $gate_id ) {
				return $gate_id;
			}
		);

		// Metering should be blocked (return false).
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertFalse( $result, 'Metering should be blocked when gate requires verification and user is not verified' );
	}

	/**
	 * Test that metering works correctly when user is verified.
	 */
	public function test_metering_allowed_when_verified() {
		// Create a gate that requires verification.
		$gate_id = $this->create_gate_with_settings(
			[
				'require_verification' => true,
				'metering_enabled'     => true,
				'metering_count'       => 5,
			]
		);

		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Register and verify the reader.
		$user_id = $this->register_reader( 'verified-reader@test.com' );
		wp_set_current_user( $user_id );

		$user = wp_get_current_user();
		Reader_Activation::set_reader_verified( $user );

		// Verify the user is verified.
		$this->assertTrue( Reader_Activation::is_reader_verified( $user ), 'Reader should be verified' );

		// Apply the filter to control the gate context for testing.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $gate_id ) {
				return $gate_id;
			}
		);

		// Metering should be allowed.
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertTrue( $result, 'Metering should be allowed when user is verified' );
	}

	/**
	 * Test that metering works when verification is not required.
	 */
	public function test_metering_allowed_when_verification_not_required() {
		// Create a gate that does NOT require verification.
		$gate_id = $this->create_gate_with_settings(
			[
				'require_verification' => false,
				'metering_enabled'     => true,
				'metering_count'       => 5,
			]
		);

		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Register a reader but don't verify them.
		$user_id = $this->register_reader( 'unverified-no-req@test.com' );
		wp_set_current_user( $user_id );

		$user = wp_get_current_user();

		// Verify the user is not verified.
		$this->assertFalse( Reader_Activation::is_reader_verified( $user ), 'Reader should not be verified' );

		// Verify the gate does not require verification.
		$this->assertFalse( Content_Gate::requires_account_verification( $gate_id ), 'Gate should not require verification' );

		// Apply the filter to control the gate context for testing.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $gate_id ) {
				return $gate_id;
			}
		);

		// Metering should be allowed since verification is not required.
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertTrue( $result, 'Metering should be allowed when verification is not required' );
	}

	/**
	 * Test that non-reader users (administrators) are exempt from verification requirement.
	 *
	 * Following the pattern in WooCommerce_My_Account::is_user_verified(), non-reader users
	 * should be allowed through without verification since they have full access through
	 * other mechanisms.
	 */
	public function test_metering_allowed_for_admin_users() {
		// Create a gate that requires verification.
		$gate_id = $this->create_gate_with_settings(
			[
				'require_verification' => true,
				'metering_enabled'     => true,
				'metering_count'       => 5,
			]
		);

		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Create an admin user.
		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$user = wp_get_current_user();

		// Verify the user is NOT a reader.
		$this->assertFalse( Reader_Activation::is_user_reader( $user ), 'Admin should not be a reader' );

		// is_reader_verified returns null for non-readers.
		$this->assertNull( Reader_Activation::is_reader_verified( $user ), 'is_reader_verified should return null for non-readers' );

		// Apply the filter to control the gate context for testing.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $gate_id ) {
				return $gate_id;
			}
		);

		// Non-reader users are exempt from verification requirement.
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertTrue( $result, 'Metering should be allowed for non-reader users (exempt from verification)' );
	}

	/**
	 * Test that non-reader users (editors) are exempt from verification requirement.
	 */
	public function test_metering_allowed_for_editor_users() {
		// Create a gate that requires verification.
		$gate_id = $this->create_gate_with_settings(
			[
				'require_verification' => true,
				'metering_enabled'     => true,
				'metering_count'       => 5,
			]
		);

		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Create an editor user.
		$editor_id = $this->create_editor_user();
		wp_set_current_user( $editor_id );

		$user = wp_get_current_user();

		// Verify the user is NOT a reader.
		$this->assertFalse( Reader_Activation::is_user_reader( $user ), 'Editor should not be a reader' );

		// is_reader_verified returns null for non-readers.
		$this->assertNull( Reader_Activation::is_reader_verified( $user ), 'is_reader_verified should return null for non-readers' );

		// Apply the filter to control the gate context for testing.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $gate_id ) {
				return $gate_id;
			}
		);

		// Non-reader users are exempt from verification requirement.
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertTrue( $result, 'Metering should be allowed for editor users (exempt from verification)' );
	}

	/**
	 * Test metering behavior when gate_id is invalid (non-existent).
	 */
	public function test_metering_with_invalid_gate_id() {
		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Register a reader.
		$user_id = $this->register_reader( 'reader-invalid-gate@test.com' );
		wp_set_current_user( $user_id );

		// Use a non-existent gate ID.
		$invalid_gate_id = 999999;

		// Apply the filter with invalid gate ID.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $invalid_gate_id ) {
				return $invalid_gate_id;
			}
		);

		// With invalid gate, requires_account_verification should return false (default).
		$this->assertFalse( Content_Gate::requires_account_verification( $invalid_gate_id ), 'Invalid gate should not require verification' );

		// Metering settings should have default/empty values for invalid gate.
		$settings = Metering::get_registered_settings( $invalid_gate_id );
		$this->assertFalse( $settings['enabled'], 'Metering should be disabled for invalid gate' );

		// Metering should be blocked because settings show it's not enabled.
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertFalse( $result, 'Metering should be blocked when gate does not exist' );
	}

	/**
	 * Test metering when metering is disabled.
	 */
	public function test_metering_disabled() {
		// Create a gate with metering disabled.
		$gate_id = $this->create_gate_with_settings(
			[
				'require_verification' => false,
				'metering_enabled'     => false,
				'metering_count'       => 0,
			]
		);

		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Register a reader.
		$user_id = $this->register_reader( 'reader-disabled@test.com' );
		wp_set_current_user( $user_id );

		// Apply the filter to control the gate context for testing.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $gate_id ) {
				return $gate_id;
			}
		);

		// Metering should be blocked because it's disabled.
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertFalse( $result, 'Metering should be blocked when disabled' );
	}

	/**
	 * Test metering with zero count.
	 */
	public function test_metering_with_zero_count() {
		// Create a gate with metering enabled but count is 0.
		$gate_id = $this->create_gate_with_settings(
			[
				'require_verification' => false,
				'metering_enabled'     => true,
				'metering_count'       => 0,
			]
		);

		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Register a reader.
		$user_id = $this->register_reader( 'reader-zero-count@test.com' );
		wp_set_current_user( $user_id );

		// Apply the filter to control the gate context for testing.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $gate_id ) {
				return $gate_id;
			}
		);

		// Metering should be blocked because count is 0.
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertFalse( $result, 'Metering should be blocked when count is zero' );
	}

	/**
	 * Test that metering respects the short-circuit filter.
	 */
	public function test_metering_short_circuit_filter() {
		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Register a reader.
		$user_id = $this->register_reader( 'reader-short-circuit@test.com' );
		wp_set_current_user( $user_id );

		// Apply the short-circuit filter to bypass metering.
		// The short-circuit runs before any gate checks, so no gate setup needed.
		add_filter(
			'newspack_content_gate_metering_short_circuit',
			function() {
				return true; // Any non-null value short-circuits.
			}
		);

		// Metering should be bypassed (return false) due to short-circuit.
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertFalse( $result, 'Metering should be bypassed when short-circuit filter returns non-null' );

		// Clean up the filter.
		remove_all_filters( 'newspack_content_gate_metering_short_circuit' );
	}

	/**
	 * Test that anonymous users are not allowed logged-in metering.
	 */
	public function test_metering_blocked_for_anonymous_users() {
		// Create a gate with metering enabled.
		$gate_id = $this->create_gate_with_settings(
			[
				'require_verification' => false,
				'metering_enabled'     => true,
				'metering_count'       => 5,
			]
		);

		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		// Apply the filter to control the gate context for testing.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $gate_id ) {
				return $gate_id;
			}
		);

		// Logged-in metering should be blocked for anonymous users.
		$result = Metering::is_logged_in_metering_allowed( $post_id );
		$this->assertFalse( $result, 'Logged-in metering should be blocked for anonymous users' );
	}

	/**
	 * Test that front-end metering settings fall back to registered settings if anonymous settings are not enabled.
	 */
	public function test_metering_settings_fall_back_to_registered_settings() {
		// Create a gate with metering enabled.
		$gate_id = $this->create_gate_with_settings(
			[
				'registration'  => [
					'active'   => false,
					'metering' => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
				],
				'custom_access' => [
					'active'   => true,
					'metering' => [
						'enabled' => true,
						'count'   => 5,
						'period'  => 'month',
					],
				],
			]
		);

		// Create a post.
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;

		// Set query to current post.
		global $wp_query;
		$wp_query = new \WP_Query( [ 'p' => $post_id ] );
		add_filter( 'newspack_is_post_restricted', '__return_true' );

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		// Apply the filter to control the gate context for testing.
		add_filter(
			'newspack_content_gate_post_id',
			function() use ( $gate_id ) {
				return $gate_id;
			}
		);

		// Anonymous metering is not enabled.
		$anonymous_settings = Metering::get_anonymous_settings( $gate_id );
		$this->assertFalse( $anonymous_settings['enabled'], 'Anonymous settings should not be enabled' );

		// Registered metering is enabled.
		$registered_settings = Metering::get_registered_settings( $gate_id );
		$this->assertTrue( $registered_settings['enabled'], 'Registered settings should be enabled' );

		// Front-end metering should fall back to registered settings if anonymous settings are not enabled.
		$this->assertTrue( Metering::is_frontend_metering(), 'Front-end metering should fall back to registered settings if anonymous settings are not enabled' );
		$this->assertEquals( $registered_settings['count'], Metering::get_total_metered_views(), 'Total metered views should be the same as registered settings' );
	}
}
