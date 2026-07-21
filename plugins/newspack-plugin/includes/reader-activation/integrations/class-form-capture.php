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
use Newspack\Reader_Activation\Contact_Sync;

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

		\add_filter( 'newspack_register_reader_metadata', [ $this, 'filter_registration_metadata' ], 10, 3 );
		\add_filter( 'newspack_reader_activation_send_magic_link_on_reregistration', [ $this, 'filter_send_magic_link' ], 10, 3 );
		\add_action( 'newspack_registered_reader', [ $this, 'handle_registered_reader' ], 10, 5 );
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

	/**
	 * Inject configured newsletter lists into registration metadata for
	 * registrations originating from this integration.
	 *
	 * Lists are injected server-side (never taken from the client payload) so
	 * the capture script cannot dictate list membership.
	 *
	 * @param array          $metadata      Registration metadata.
	 * @param int|false      $user_id       The created user id, or false for existing users.
	 * @param false|\WP_User $existing_user The existing user object, if any.
	 *
	 * @return array Metadata.
	 */
	public function filter_registration_metadata( $metadata, $user_id, $existing_user ) {
		if ( ( $metadata['registration_method'] ?? '' ) !== self::get_registration_method() ) {
			return $metadata;
		}
		$lists = $this->get_lists();
		if ( ! empty( $lists ) && empty( $metadata['lists'] ) ) {
			$metadata['lists'] = $lists;
		}
		return $metadata;
	}

	/**
	 * Suppress the magic link email for repeat capture submissions — capture
	 * is invisible, so an existing reader re-submitting an opted-in form must
	 * not be emailed a login link every time.
	 *
	 * @param bool     $should_send   Whether the magic link would be sent.
	 * @param \WP_User $existing_user The existing reader account.
	 * @param array    $metadata      Registration metadata.
	 *
	 * @return bool Whether to send the magic link.
	 */
	public function filter_send_magic_link( $should_send, $existing_user, $metadata ) {
		if ( ( $metadata['registration_method'] ?? '' ) === self::get_registration_method() ) {
			return false;
		}
		return $should_send;
	}

	/**
	 * Whether a capture of an existing reader should trigger an explicit
	 * contact sync. The reader_registered data event skips existing users, and
	 * when lists are configured the newsletters subscription path already
	 * upserts the contact — so an explicit sync is only needed for existing
	 * readers with no lists configured.
	 *
	 * @param false|\WP_User $existing_user The existing user object, if any.
	 * @param array          $metadata      Registration metadata.
	 *
	 * @return bool Whether to sync the contact.
	 */
	public function should_sync_existing_reader( $existing_user, $metadata ) {
		if ( ( $metadata['registration_method'] ?? '' ) !== self::get_registration_method() ) {
			return false;
		}
		if ( ! $existing_user ) {
			return false;
		}
		if ( ! empty( $metadata['lists'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * After a capture registration, sync existing readers to the ESP so the
	 * "upgrade a known reader" path reaches the contact record.
	 *
	 * @param string         $email         Email address.
	 * @param bool           $authenticate  Whether the registration authenticates the session.
	 * @param false|int      $user_id       The created user id.
	 * @param false|\WP_User $existing_user The existing user object.
	 * @param array          $metadata      Registration metadata.
	 */
	public function handle_registered_reader( $email, $authenticate, $user_id, $existing_user, $metadata ) {
		if ( ! $this->should_sync_existing_reader( $existing_user, $metadata ) ) {
			return;
		}
		Contact_Sync::sync_contact( $existing_user->ID, 'Form Capture registration (existing reader)' );
	}
}
