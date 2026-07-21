<?php
/**
 * Inbound Form Capture integration.
 *
 * Captures email submissions from publisher-designated frontend forms (built
 * with any form tool) and registers them as readers via the frontend
 * registration endpoint. Inbound-only: it is not a sync destination.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

use Newspack\Newspack;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Contact_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Inbound Form Capture integration class.
 */
class Form_Capture extends Integration {
	/**
	 * The integration ID.
	 */
	const ID = 'form-capture';

	/**
	 * CSS class that always opts a form into capture.
	 */
	const MARKER_CLASS = 'newspack-form-capture';

	/**
	 * Handle for the frontend capture script.
	 */
	const SCRIPT_HANDLE = 'newspack-form-capture';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			self::ID,
			__( 'Inbound Form Capture', 'newspack-plugin' ),
			__( 'Register readers from email signup forms built with any form tool.', 'newspack-plugin' )
		);
	}

	/**
	 * The registration method string the frontend registration endpoint
	 * stamps on registrations from this integration.
	 *
	 * @return string
	 */
	public static function get_registration_method() {
		return 'integration-registration-' . self::ID;
	}

	/**
	 * Register settings fields.
	 *
	 * @return array Array of settings field declarations.
	 */
	public function register_settings_fields() {
		return [
			[
				'key'         => 'selectors',
				'type'        => 'textarea',
				'label'       => __( 'Form selectors', 'newspack-plugin' ),
				'description' => __( 'CSS selectors (one per line) of forms to capture, in addition to any form with the newspack-form-capture class.', 'newspack-plugin' ),
				'default'     => '',
			],
			[
				'key'         => 'lists',
				'type'        => 'text',
				'label'       => __( 'Newsletter list IDs', 'newspack-plugin' ),
				'description' => __( 'Comma-separated newsletter list IDs to subscribe captured contacts to. Leave empty to only sync the contact.', 'newspack-plugin' ),
				'default'     => '',
			],
		];
	}

	/**
	 * Whether contacts can be synced. Inbound-only integration: there are no
	 * prerequisites to gate, so this never errors — the inbound-only intent is
	 * expressed by the no-op push_contact_data(), not by failing this gate.
	 *
	 * @param bool $return_errors Optional. Whether to return a WP_Error object. Default false.
	 *
	 * @return bool|\WP_Error True, or an empty WP_Error when $return_errors is true.
	 */
	public function can_sync( $return_errors = false ) {
		$errors = new \WP_Error();
		if ( $return_errors ) {
			return $errors;
		}
		return true;
	}

	/**
	 * Push contact data. Inbound-only integration: deliberate no-op.
	 *
	 * @param array      $contact          The contact data to push.
	 * @param string     $context          Optional. The context of the sync.
	 * @param array|null $existing_contact Optional. Existing contact data if available.
	 *
	 * @return true
	 */
	public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
		return true;
	}

	/**
	 * Frontend registration is available only while the integration is enabled.
	 * This gates the registration endpoint, the page-emitted key, and the
	 * capture script together.
	 *
	 * @return bool
	 */
	public function supports_frontend_registration(): bool {
		return Integrations::is_enabled( self::ID );
	}

	/**
	 * Get the configured form selectors, always including the marker class.
	 *
	 * @return string[] CSS selectors.
	 */
	public function get_selectors() {
		$value     = (string) $this->get_settings_field_value( 'selectors' );
		$selectors = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $value ) ) );
		return array_values( array_unique( array_merge( [ '.' . self::MARKER_CLASS ], $selectors ) ) );
	}

	/**
	 * Get the configured newsletter list IDs.
	 *
	 * @return string[] List IDs.
	 */
	public function get_lists() {
		$value = (string) $this->get_settings_field_value( 'lists' );
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}
}
