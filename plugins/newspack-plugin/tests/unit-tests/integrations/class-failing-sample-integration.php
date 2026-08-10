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
	 * Error message returned when $should_fail is true.
	 *
	 * @var string
	 */
	public static $fail_message = 'Mock push failed';

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
	 * Integration IDs that received a push, in call order.
	 *
	 * @var string[]
	 */
	public static $push_ids = [];

	/**
	 * Data returned by pull_contact_data. Usually an array; tests covering
	 * malformed provider payloads set a non-array value.
	 *
	 * @var mixed
	 */
	public static $pull_data = [];

	/**
	 * Whether pull_contact_data should fail.
	 *
	 * @var bool
	 */
	public static $pull_should_fail = false;

	/**
	 * WP_Error code returned when $pull_should_fail is true. Tests covering the
	 * provider-missing-contact classification set the canonical not-found code.
	 *
	 * @var string
	 */
	public static $pull_error_code = 'mock_pull_error';

	/**
	 * Count of get_enabled_incoming_fields() calls, so tests can pin that
	 * batch drivers resolve fields once per integration, not once per reader.
	 *
	 * @var int
	 */
	public static $enabled_incoming_fields_calls = 0;

	/**
	 * Value returned by is_set_up(). Tests that simulate an
	 * enabled-but-unconfigured integration set this to false.
	 *
	 * @var bool
	 */
	public static $is_set_up_value = true;

	/**
	 * Reason can_sync() should fail with. Null means the integration is syncable.
	 *
	 * @var string|null
	 */
	public static $cannot_sync_reason = null;

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
		self::$push_ids[] = $this->get_id();
		if ( self::$should_fail ) {
			return new \WP_Error( 'mock_error', self::$fail_message );
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
		if ( self::$pull_should_fail ) {
			return new \WP_Error( self::$pull_error_code, 'Mock pull failed' );
		}
		return self::$pull_data;
	}

	/**
	 * Count field resolutions on top of the parent behavior.
	 *
	 * @return \Newspack\Reader_Activation\Integrations\Incoming_Field[]
	 */
	public function get_enabled_incoming_fields() {
		self::$enabled_incoming_fields_calls++;
		return parent::get_enabled_incoming_fields();
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
		if ( null !== self::$cannot_sync_reason ) {
			return $return_errors ? new \WP_Error( 'mock_cannot_sync', self::$cannot_sync_reason ) : false;
		}
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
		self::$should_fail                   = false;
		self::$fail_message                  = 'Mock push failed';
		self::$push_count                    = 0;
		self::$pull_count                    = 0;
		self::$push_ids                      = [];
		self::$pull_data                     = [];
		self::$pull_should_fail              = false;
		self::$pull_error_code               = 'mock_pull_error';
		self::$enabled_incoming_fields_calls = 0;
		self::$is_set_up_value               = true;
		self::$cannot_sync_reason            = null;
	}
}
