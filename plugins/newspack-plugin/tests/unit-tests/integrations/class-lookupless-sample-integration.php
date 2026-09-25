<?php
/**
 * A push-enabled integration that inherits the base contact_exists(): the shape
 * of any integration written before the --existing-only seam existed.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Integration;

/**
 * Push-capable integration with no existence lookup of its own.
 */
class Lookupless_Sample_Integration extends Integration {
	/**
	 * Pushes received.
	 *
	 * @var int
	 */
	public static $push_count = 0;

	/**
	 * No settings fields.
	 *
	 * @return array
	 */
	public function register_settings_fields() {
		return [];
	}

	/**
	 * Always set up.
	 *
	 * @return bool
	 */
	public function is_set_up() {
		return true;
	}

	/**
	 * Always able to sync.
	 *
	 * @param bool $return_errors Whether to return a WP_Error.
	 * @return bool|\WP_Error
	 */
	public function can_sync( $return_errors = false ) {
		return $return_errors ? new \WP_Error() : true;
	}

	/**
	 * Count the push.
	 *
	 * @param array      $contact          The contact data.
	 * @param string     $context          The sync context.
	 * @param array|null $existing_contact Existing contact data if available.
	 * @return true
	 */
	public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
		self::$push_count++;
		return true;
	}

	/**
	 * Reset the counter between tests.
	 */
	public static function reset() {
		self::$push_count = 0;
	}
}
