<?php
/**
 * Mock Integration with a custom key scheme that inherits the base validator.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

use Newspack\Reader_Activation\Integration;

/**
 * Test integration that replaces get_registration_key() with a scheme of its
 * own while inheriting validate_registration_request() — the shape the legacy
 * registration key's transition allowance must not extend to.
 *
 * Distinct from Test_Custom_Key_Integration in reader-registration-endpoint.php,
 * which overrides the validator too and so never reaches the base implementation.
 */
class Inherited_Validator_Integration extends Integration {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'custom-key-integration', 'Custom Key Integration' );
	}

	/**
	 * Whether this integration supports frontend registration.
	 *
	 * @return bool
	 */
	public function supports_frontend_registration(): bool {
		return true;
	}

	/**
	 * A key scheme of the integration's own, replacing the framework's.
	 *
	 * @return string
	 */
	public function get_registration_key(): string {
		return 'custom-scheme-key';
	}

	/**
	 * Register settings fields.
	 *
	 * @return array
	 */
	public function register_settings_fields() {
		return [];
	}

	/**
	 * Whether this integration can sync.
	 *
	 * @param bool $return_errors Whether to return errors.
	 * @return bool
	 */
	public function can_sync( $return_errors = false ) {
		return false;
	}

	/**
	 * Push contact data.
	 *
	 * @param array  $contact          Contact data.
	 * @param string $context          Context.
	 * @param array  $existing_contact Existing contact data.
	 * @return bool
	 */
	public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
		return true;
	}
}
