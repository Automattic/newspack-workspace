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
use Newspack\Recaptcha;

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
	 * Default per-IP hourly limit for this integration's rate-limit bucket.
	 * Sized for form traffic rather than explicit signup forms: capture fires
	 * on every opted-in submission across the site, and on hosts where
	 * REMOTE_ADDR is a proxy IP the bucket is effectively site-wide.
	 */
	const RATE_LIMIT_DEFAULT = 100;

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
	 * Register hooks. Called once per accepted instance by the registry, so a
	 * rejected duplicate registration never leaves live callbacks behind (which
	 * hooking from the constructor would).
	 */
	public function register_handlers() {
		\add_filter( 'newspack_reader_activation_send_magic_link_on_reregistration', [ $this, 'filter_send_magic_link' ], 10, 3 );
		\add_action( 'newspack_registered_reader', [ $this, 'handle_registered_reader' ], 10, 5 );
		\add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ], 20 );
		// Priority 5 so a publisher's own filter at default priority wins.
		\add_filter( 'newspack_frontend_registration_rate_limit', [ $this, 'filter_rate_limit' ], 5, 3 );
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
				'description' => __( 'CSS selectors (one per line) of forms to capture, in addition to any form with the newspack-form-capture class. Bare tag selectors (e.g. "form") are ignored — they would opt in every form on the site. Only opt in forms whose submissions should always create a reader account: capture runs even if the form tool itself later rejects the submission, so submissions its spam checks would discard still create readers and count toward your ESP contacts. Captures are rate-limited per visitor IP (100/hour by default).', 'newspack-plugin' ),
				'default'     => '',
			],
		];
	}

	/**
	 * Why capture cannot operate with the site's current reCAPTCHA configuration.
	 *
	 * The v2 flow renders an interactive widget and awaits a callback the page
	 * never delivers on a navigating form submit, so capture would silently
	 * produce nothing. Only v3, whose token can be pre-acquired, is compatible.
	 *
	 * @return string|null Reason string when reCAPTCHA v2 is active, null otherwise.
	 */
	public function get_unsupported_reason() {
		if ( Recaptcha::can_use_captcha() && ! Recaptcha::can_use_captcha( 'v3' ) ) {
			return __( 'Requires reCAPTCHA v3', 'newspack-plugin' );
		}
		return null;
	}

	/**
	 * The remedy for the v2 conflict: switch the reCAPTCHA version.
	 *
	 * @return string The action label.
	 */
	public function get_unsupported_action_label() {
		return __( 'Change reCAPTCHA version', 'newspack-plugin' );
	}

	/**
	 * Get the URL where reCAPTCHA is configured.
	 *
	 * @return string The Newspack settings page URL.
	 */
	public function get_setup_url() {
		return \admin_url( 'admin.php?page=newspack-settings' );
	}

	/**
	 * Size this integration's rate-limit bucket for form traffic. Hooked at
	 * priority 5 so a publisher's own filter at default priority wins.
	 *
	 * @param int    $limit  Maximum attempts per IP per hour.
	 * @param string $ip     The client IP address.
	 * @param string $bucket Bucket name.
	 *
	 * @return int The limit.
	 */
	public function filter_rate_limit( $limit, $ip, $bucket ) {
		if ( Reader_Registration::get_rate_limit_bucket_for( self::ID ) === $bucket ) {
			return self::RATE_LIMIT_DEFAULT;
		}
		return $limit;
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
	 * Frontend registration is available while the integration is enabled and
	 * the site's configuration supports capture. This gates the registration
	 * endpoint, the page-emitted key, and the capture script together.
	 *
	 * The unsupported check runs here, not only at enable time: a site that
	 * switches to reCAPTCHA v2 after enabling would otherwise keep emitting a
	 * key that capture can never use, and go on capturing nothing silently.
	 *
	 * @return bool
	 */
	public function supports_frontend_registration(): bool {
		return Integrations::is_enabled( self::ID ) && ! $this->get_unsupported_reason();
	}

	/**
	 * Get the configured form selectors, always including the marker class.
	 *
	 * Selectors that name only element types (`form`, `body form`, `div > form`)
	 * or the universal selector opt in every form on the page — comment forms,
	 * search, checkout — which is never what a per-form opt-in means. A line
	 * carrying one is dropped whole, including inside a comma-separated list,
	 * since `form, #signup` matches everything `form` does. Applied at read
	 * time so previously stored values are covered too.
	 *
	 * @return string[] CSS selectors.
	 */
	public function get_selectors() {
		$value     = (string) $this->get_settings_field_value( 'selectors' );
		$lines     = array_map( 'trim', preg_split( '/[\r\n]+/', $value ) );
		$selectors = array_filter( array_map( [ __CLASS__, 'normalize_selector' ], $lines ) );
		return array_values( array_unique( array_merge( [ '.' . self::MARKER_CLASS ], $selectors ) ) );
	}

	/**
	 * Reduce one configured line to the selector the client should run, or ''
	 * to drop it.
	 *
	 * Rebuilds the line from its non-empty parts rather than passing it through:
	 * a trailing comma is a plausible copy-paste from a CSS rule, and an empty
	 * slot makes the whole list invalid CSS — `querySelectorAll( '#signup,' )`
	 * throws, so the client discards it and one stray comma takes every
	 * selector on the line with it.
	 *
	 * @param string $line A configured line, possibly a comma-separated list.
	 *
	 * @return string The normalized selector, or '' when the line is rejected.
	 */
	private static function normalize_selector( string $line ): string {
		$parts = [];
		foreach ( explode( ',', $line ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( self::is_over_broad_selector( $part ) ) {
				return '';
			}
			$parts[] = $part;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Whether a single selector names nothing more specific than element types.
	 *
	 * Splits on combinators and checks every compound: a selector built only
	 * from tag names and `*` matches every form on the page whatever its depth,
	 * so `body form` and `div > form` are as broad as `form`. One class, id or
	 * attribute anywhere in the selector makes it specific enough to keep.
	 *
	 * Attribute selectors are kept: `[method]` is as broad as `form`, but
	 * `[data-newsletter-form]` is a precise opt-in and structurally identical,
	 * so the distinction is semantic rather than something the guard can read.
	 *
	 * @param string $selector A single CSS selector (no commas).
	 *
	 * @return bool Whether the selector is too broad to opt a form in.
	 */
	private static function is_over_broad_selector( string $selector ): bool {
		$compounds = preg_split( '/[\s>+~]+/', trim( $selector ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $compounds ) ) {
			return true;
		}
		foreach ( $compounds as $compound ) {
			if ( '*' !== $compound && ! preg_match( '/^[a-z][a-z0-9-]*$/i', $compound ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Enqueue the frontend capture script when the integration is active.
	 */
	public function enqueue_scripts() {
		if ( ! Reader_Activation::is_enabled() || ! $this->supports_frontend_registration() ) {
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
	 * frontend registration flow. Checks the enabled state so the behaviors
	 * scoped by this predicate (magic-link suppression, existing-reader sync)
	 * stay off when the integration is off, even if something else stamps the
	 * method string (a replayed job, a CLI backfill).
	 *
	 * @param array $metadata Registration metadata.
	 *
	 * @return bool Whether the registration is a form capture.
	 */
	private function is_capture_registration( $metadata ) {
		return Integrations::is_enabled( self::ID ) && ( $metadata['registration_method'] ?? '' ) === self::get_registration_method();
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
	 * "upgrade a known reader" path reaches the contact record. The sync is
	 * scheduled through Action Scheduler in this integration's group — off the
	 * request thread, retryable, and inspectable in the Activity Logs UI. A
	 * pending action for the same reader is reused, so repeat captures before
	 * the sync runs collapse into one push.
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
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$hook = 'newspack_scheduled_esp_sync';
		$args = [ $existing_user->ID, 'Form Capture registration (existing reader)' ];
		if ( false === \as_next_scheduled_action( $hook, $args, $this->get_action_group() ) ) {
			\as_schedule_single_action( time() + MINUTE_IN_SECONDS, $hook, $args, $this->get_action_group() );
		}
	}
}
