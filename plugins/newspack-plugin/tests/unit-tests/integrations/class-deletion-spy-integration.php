<?php
/**
 * Test fixture: records calls to delete_contact() and push_contact_data().
 *
 * @package Newspack\Tests\Unit\Integrations
 */

use Newspack\Reader_Activation\Integration;

/**
 * Spy Integration used to verify routing in Contact_Sync::handle_account_deletion().
 */
class Deletion_Spy_Integration extends Integration {

	/**
	 * Captured push_contact_data() calls.
	 *
	 * @var array
	 */
	public $push_calls = [];

	/**
	 * Captured delete_contact() calls.
	 *
	 * @var array
	 */
	public $delete_calls = [];

	/**
	 * Result that delete_contact() returns.
	 *
	 * @var true|\WP_Error
	 */
	public $delete_result = true;

	/**
	 * Result that push_contact_data() returns.
	 *
	 * @var true|\WP_Error
	 */
	public $push_result = true;

	/**
	 * Register settings fields (test implementation).
	 */
	public function register_settings_fields() {
		// No settings fields for this test implementation.
		return [];
	}

	/**
	 * The spy implements delete_contact(), so it advertises hard-delete capability.
	 * This keeps it consistent with the framework's expectation that
	 * `supports_hard_delete()` mirrors whether `delete_contact()` does meaningful
	 * work, and lets routing tests exercise both `delete` and `flag` modes
	 * without the field-options filter hiding `delete` from the integration.
	 *
	 * @return bool
	 */
	public function supports_hard_delete(): bool {
		return true;
	}

	/**
	 * Whether contacts can be synced to the ESP.
	 *
	 * @param bool $return_errors Optional. Whether to return a WP_Error object. Default false.
	 *
	 * @return bool|WP_Error True if contacts can be synced, false otherwise. WP_Error if return_errors is true.
	 */
	public function can_sync( $return_errors = false ) {
		return $return_errors ? new \WP_Error() : true;
	}

	/**
	 * Push contact data (records the call).
	 *
	 * @param array      $contact          The contact data.
	 * @param string     $context          The sync context.
	 * @param array|null $existing_contact Existing contact data if available.
	 * @return true
	 */
	public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
		$this->push_calls[] = [
			'contact'          => $contact,
			'context'          => $context,
			'existing_contact' => $existing_contact,
		];
		return $this->push_result;
	}

	/**
	 * Delete contact (records the call).
	 *
	 * @param string $email The email address to delete.
	 * @return true|\WP_Error
	 */
	public function delete_contact( $email ) {
		$this->delete_calls[] = [ 'email' => $email ];
		return $this->delete_result;
	}

	/**
	 * Get incoming available contact fields from the integration.
	 *
	 * @return Integrations\Incoming_Field[]|\WP_Error Array of incoming contact field objects or WP_Error on failure.
	 */
	public function get_available_incoming_fields() {
		return [];
	}
}
