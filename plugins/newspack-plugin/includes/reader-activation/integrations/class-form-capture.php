<?php
/**
 * Inbound Form Capture integration.
 *
 * Captures email submissions from publisher-designated frontend forms (built
 * with any form tool) and registers them as readers via the frontend
 * registration endpoint. Capture-only: neither a sync destination nor a
 * pull source (see supports_push()/supports_pull()).
 *
 * Capture semantics publishers must understand before opting a form in:
 * - Capture fires on the browser's submit event (native validity checked)
 *   and is decoupled from the form tool's own validation and outcome — a
 *   submission the vendor's JS or server later rejects may still have
 *   registered the reader.
 * - Programmatic HTMLFormElement.submit() dispatches no submit event and
 *   is not captured.
 * - Forms that collect somebody else's email address (e.g. "email a
 *   friend") must never be opted in.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

use Newspack\Newspack;
use Newspack\Reader_Activation;
use Newspack\Reader_Registration;
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

		\add_filter( 'newspack_reader_activation_send_magic_link_on_reregistration', [ $this, 'filter_send_magic_link' ], 10, 3 );
		\add_action( 'newspack_registered_reader', [ $this, 'handle_registered_reader' ], 10, 5 );
		\add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ], 20 );
	}

	/**
	 * The registration method string the frontend registration endpoint
	 * stamps on registrations from this integration. Derived from the same
	 * helper the endpoint uses, so the scoping predicates in this class
	 * cannot drift from what register_reader() actually receives.
	 *
	 * @return string
	 */
	public static function get_registration_method() {
		return Reader_Registration::get_registration_method_for( self::ID );
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
				'description' => __( 'CSS selectors (one per line) of forms to capture, in addition to any form with the newspack-form-capture class. Only opt in forms whose submissions should always create a reader account: capture runs even if the form tool itself later rejects the submission.', 'newspack-plugin' ),
				'default'     => '',
			],
		];
	}

	/**
	 * Whether contacts can be synced. There are no prerequisites to gate, so
	 * this never errors — the capture-only intent is expressed by
	 * supports_push()/supports_pull(), not by failing this gate.
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
	 * Push contact data. Deliberate no-op, kept only because the base class
	 * declares the method abstract; supports_push() declares the capability
	 * off.
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
	 * Whether this integration can push (outbound) contact data to an
	 * external destination. Form capture has none — push_contact_data() is a
	 * deliberate no-op — so declare no push capability: no outbound sync
	 * settings, no push dispatch, and no bearing on "has one syncable
	 * integration".
	 *
	 * @return bool True if the integration can push contact data.
	 */
	public function supports_push(): bool {
		return false;
	}

	/**
	 * Whether this integration can pull (inbound) contact data from an
	 * external source. Capture registers readers from on-site form
	 * submissions — there is no external source to pull from, and
	 * pull_contact_data()/get_available_incoming_fields() are not
	 * implemented.
	 *
	 * @return bool True if the integration can pull contact data.
	 */
	public function supports_pull(): bool {
		return false;
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
	 * Enqueue the frontend capture script when the integration is active.
	 */
	public function enqueue_scripts() {
		if ( ! Reader_Activation::is_enabled() || ! Integrations::is_enabled( self::ID ) ) {
			return;
		}
		\wp_enqueue_script(
			self::SCRIPT_HANDLE,
			Newspack::plugin_url() . '/dist/form-capture.js',
			[ Reader_Activation::SCRIPT_HANDLE ],
			Newspack::asset_version( 'form-capture' ),
			[
				'strategy'  => 'defer',
				'in_footer' => true,
			]
		);
		\wp_localize_script(
			self::SCRIPT_HANDLE,
			'newspack_form_capture',
			[
				'selectors' => $this->get_selectors(),
			]
		);
		\wp_script_add_data( self::SCRIPT_HANDLE, 'defer', true );
		\wp_script_add_data( self::SCRIPT_HANDLE, 'amp-plus', true );
	}

	/**
	 * Whether registration metadata originates from this integration's
	 * frontend registration flow.
	 *
	 * @param array $metadata Registration metadata.
	 *
	 * @return bool Whether the registration is a form capture.
	 */
	private function is_capture_registration( $metadata ) {
		return ( $metadata['registration_method'] ?? '' ) === self::get_registration_method();
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
		if ( $this->is_capture_registration( $metadata ) ) {
			return false;
		}
		return $should_send;
	}

	/**
	 * Whether a capture of an existing reader should trigger an explicit
	 * contact sync. The reader_registered data event skips existing users,
	 * so an explicit sync is the only way a repeat capture reaches the
	 * contact record.
	 *
	 * @param false|\WP_User $existing_user The existing user object, if any.
	 * @param array          $metadata      Registration metadata.
	 *
	 * @return bool Whether to sync the contact.
	 */
	public function should_sync_existing_reader( $existing_user, $metadata ) {
		if ( ! $this->is_capture_registration( $metadata ) ) {
			return false;
		}
		if ( ! $existing_user ) {
			return false;
		}
		return true;
	}

	/**
	 * After a capture registration, sync existing readers to the ESP so the
	 * "upgrade a known reader" path reaches the contact record. Schedules a
	 * contact sync so the ESP I/O stays off the request thread.
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
		Contact_Sync::schedule_sync( $existing_user->ID, 'Form Capture registration (existing reader)', 0 );
	}
}
