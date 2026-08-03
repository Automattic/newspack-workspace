<?php
/**
 * Mock Integration that can be toggled to fail.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

use Newspack\Reader_Activation\Integration;

/**
 * Test integration with controllable failure behavior.
 */
class Failing_Sample_Integration extends Integration {
	/**
	 * Whether push_contact_data should fail.
	 *
	 * @var bool
	 */
	public static $should_fail = false;

	/**
	 * Count of push_contact_data calls.
	 *
	 * @var int
	 */
	public static $push_count = 0;

	/**
	 * Count of pull_contact_data calls.
	 *
	 * @var int
	 */
	public static $pull_count = 0;

	/**
	 * Value returned by is_set_up(). Tests that simulate an
	 * enabled-but-unconfigured integration set this to false.
	 *
	 * @var bool
	 */
	public static $is_set_up_value = true;

	/**
	 * Register settings fields (test implementation).
	 */
	public function register_settings_fields() {
		// No settings fields for this test implementation.
		return [];
	}

	/**
	 * Push contact data (test implementation).
	 *
	 * @param array      $contact The contact data.
	 * @param string     $context The sync context.
	 * @param array|null $existing_contact Existing contact data if available.
	 * @return true|\WP_Error
	 */
	public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
		self::$push_count++;
		if ( self::$should_fail ) {
			return new \WP_Error( 'mock_error', 'Mock push failed' );
		}
		return true;
	}

	/**
	 * Pull contact data (test implementation).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array
	 */
	public function pull_contact_data( $user_id ) {
		self::$pull_count++;
		return [];
	}

	/**
	 * Whether this integration's external prerequisites are configured.
	 *
	 * @return bool
	 */
	public function is_set_up() {
		return self::$is_set_up_value;
	}

	/**
	 * Whether contacts can be synced.
	 *
	 * @param bool $return_errors Whether to return WP_Error.
	 * @return bool|WP_Error
	 */
	public function can_sync( $return_errors = false ) {
		return $return_errors ? new \WP_Error() : true;
	}

	/**
	 * Get incoming available contact fields (test implementation).
	 *
	 * @return array
	 */
	public function get_available_incoming_fields() {
		return [];
	}

	/**
	 * Reset state between tests.
	 */
	public static function reset() {
		self::$should_fail     = false;
		self::$push_count      = 0;
		self::$pull_count      = 0;
		self::$is_set_up_value = true;
	}
}
