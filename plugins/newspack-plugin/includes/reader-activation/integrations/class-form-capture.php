<?php
/**
 * Gravity Forms integration (inbound form capture).
 *
 * Registers readers from Gravity Forms submissions. The way in is a
 * "Register readers" toggle on the Gravity Forms block: the block attribute
 * is carried to the page as the newspack-form-capture class on the form
 * tag, which the capture script matches. While Gravity Forms is active, any
 * other form can opt in the same way by carrying the class itself, a route
 * the help docs cover. A form's CSS Class Name setting applies to every
 * placement of that form, whatever each block's toggle says. There are no
 * settings: CSS selectors saved by the former Form selectors setting are not
 * read. Capture-only: neither a sync destination nor a pull source (see
 * supports_push()/supports_pull()).
 *
 * Capture semantics publishers must understand before opting a form in:
 * - Capture fires on the browser's submit event (native validity checked)
 *   and is decoupled from the form tool's own validation and outcome — a
 *   submission the vendor's JS or server later rejects may still have
 *   registered the reader.
 * - Programmatic HTMLFormElement.submit() dispatches no submit event and
 *   is not captured. Gravity Forms — which submits every form this way —
 *   is captured through its own submission filter bus instead.
 * - Forms that collect somebody else's email address (e.g. "email a
 *   friend") must never be opted in.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

use Newspack\Newspack;
use Newspack\Plugin_Manager;
use Newspack\Reader_Activation;
use Newspack\Reader_Registration;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Recaptcha;

defined( 'ABSPATH' ) || exit;

/**
 * Gravity Forms integration class.
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
	 * Handle for the block editor extension that adds the toggle to the
	 * Gravity Forms block.
	 */
	const EDITOR_SCRIPT_HANDLE = 'newspack-form-capture-editor';

	/**
	 * Gravity Forms block attribute that opts a placement into capture. Declared
	 * in the editor extension and registered with GF's block schema here.
	 */
	const BLOCK_ATTRIBUTE = 'newspackFormCapture';

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
			__( 'Gravity Forms', 'newspack-plugin' ),
			__( 'Register readers from Gravity Forms submissions, and from other forms you opt in.', 'newspack-plugin' )
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
		// GF applies this filter while constructing its block on `init` at
		// priority 10; integrations register at priority 5, so it is in place.
		\add_filter( 'gform_form_block_attributes', [ $this, 'register_block_attribute' ] );
		// After GF's own render filter at 10, which relocates custom-CSS classes.
		\add_filter( 'render_block_gravityforms/form', [ $this, 'mark_captured_block_form' ], 20, 2 );
		\add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
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
	 * None: forms opt in through the block toggle or the marker class, so the
	 * Integrations card offers no settings page.
	 *
	 * @return array Array of settings field declarations.
	 */
	public function register_settings_fields() {
		return [];
	}

	/**
	 * The how-to the Integrations card opens from its How it works menu item.
	 * The toggle lives in the block editor, so the guide has to say where to
	 * look and what opting a form in commits the publisher to. The last step
	 * links the help page, which covers forms placed without the block.
	 *
	 * @return array List of associative arrays with keys `title`, `description`, and an optional `link` (`label`, `url`).
	 */
	public function get_guide() {
		return [
			[
				'title'       => __( 'Add the form with the Gravity Forms block', 'newspack-plugin' ),
				'description' => __( 'Place the form on a page, post, or prompt with the Gravity Forms block.', 'newspack-plugin' ),
			],
			[
				'title'       => __( 'Turn on Register readers in the block settings', 'newspack-plugin' ),
				'description' => __( 'Select the block, open its settings sidebar, and switch on Register readers under Newspack. The switch belongs to the block, so the same form can register readers in one place and not in another.', 'newspack-plugin' ),
			],
			[
				'title'       => __( 'Submissions register readers', 'newspack-plugin' ),
				'description' => __( 'Each submission registers a reader account with the submitted email address and name, or updates the existing reader without emailing a login link. Only turn this on for forms whose submissions should always create a reader account: registration happens as the form is submitted, so a submission Gravity Forms later rejects has still registered the reader. Never turn it on for a form that collects someone else\'s email address.', 'newspack-plugin' ),
				'link'        => [
					'label' => __( 'Learn how to opt in forms placed without the block', 'newspack-plugin' ),
					'url'   => 'https://help.newspack.com/integrations/gravity-forms/',
				],
			],
		];
	}

	/**
	 * Gravity Forms is the way in: the toggle lives in its block, so without
	 * it the card would offer an Enable that captures nothing. Reported in the
	 * shape the Integrations card reads (see Integration::get_required_plugins()).
	 *
	 * @return array List of associative arrays with keys `slug`, `name`, `is_active`, `is_installed`.
	 */
	public function get_required_plugins() {
		$status = Plugin_Manager::get_managed_plugin_status( 'gravityforms' );
		return [
			[
				'slug'         => 'gravityforms',
				'name'         => __( 'Gravity Forms', 'newspack-plugin' ),
				'is_active'    => 'active' === $status,
				'is_installed' => 'uninstalled' !== $status,
			],
		];
	}

	/**
	 * Whether Gravity Forms is active. The capture switch reads this on every
	 * front-end request, which is why it checks for Gravity Forms' main class
	 * instead of Plugin_Manager's status lookup, a scan of the installed plugins.
	 *
	 * @return bool
	 */
	protected function is_gravity_forms_active() {
		return class_exists( 'GFForms' );
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
	 * Frontend registration is available while the integration is enabled,
	 * Gravity Forms is active, and the site's configuration supports capture.
	 * This gates the registration endpoint, the page-emitted key, and the
	 * capture script together.
	 *
	 * Both checks run here, not only at enable time. A site that switches to
	 * reCAPTCHA v2 after enabling would otherwise keep emitting a key that
	 * capture can never use, and go on capturing nothing silently. A site
	 * without Gravity Forms, because it enabled capture for another tool's
	 * forms or removed Gravity Forms later, would otherwise keep registering
	 * readers behind a card that requires Gravity Forms and, with it
	 * uninstalled, offers no Disable.
	 *
	 * @return bool
	 */
	public function supports_frontend_registration(): bool {
		return Integrations::is_enabled( self::ID ) && $this->is_gravity_forms_active() && ! $this->get_unsupported_reason();
	}

	/**
	 * The selectors the capture script matches: the marker class alone, which
	 * the block toggle adds and any other form can carry. Selectors saved by
	 * the former Form selectors setting are not read.
	 *
	 * @return string[] CSS selectors.
	 */
	public function get_selectors() {
		return [ '.' . self::MARKER_CLASS ];
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
	 * Load the block editor extension wherever the Gravity Forms block can be
	 * placed. Gated on Gravity Forms alone, without which there is no block to
	 * extend. Neither the integration nor Reader Activation gates it: the
	 * panel is where a placement opts in, so the toggle stays visible and
	 * saves while either is off, and the notice reports through `active` that
	 * a toggled form registers nobody yet.
	 */
	public function enqueue_editor_assets() {
		if ( ! $this->is_gravity_forms_active() ) {
			return;
		}
		$asset_file   = NEWSPACK_ABSPATH . 'dist/form-capture-editor.asset.php';
		$asset        = file_exists( $asset_file ) ? include $asset_file : [];
		$dependencies = $asset['dependencies'] ?? [ 'react-jsx-runtime', 'wp-block-editor', 'wp-components', 'wp-hooks', 'wp-i18n' ];
		\wp_enqueue_script(
			self::EDITOR_SCRIPT_HANDLE,
			Newspack::plugin_url() . '/dist/form-capture-editor.js',
			$dependencies,
			Newspack::asset_version( 'form-capture-editor' ),
			true
		);
		\wp_localize_script(
			self::EDITOR_SCRIPT_HANDLE,
			'newspack_form_capture_editor',
			[
				'active'           => Reader_Activation::is_enabled() && $this->supports_frontend_registration(),
				'integrations_url' => \admin_url( 'admin.php?page=newspack-audience-integrations' ),
			]
		);
	}

	/**
	 * Register the capture toggle with Gravity Forms' block schema.
	 *
	 * As of Gravity Forms 3.1 this filter feeds both halves of its block. The
	 * server-side registration, which keeps only each attribute's type, is
	 * what the REST block renderer validates the editor preview against, so
	 * without it every preview of a toggled block fails. The editor config,
	 * which keeps the default too, is where GF's block script takes its
	 * attributes from, so it is also what keeps the toggle through a save.
	 *
	 * @param array $attributes Block attributes declared by Gravity Forms.
	 *
	 * @return array Attributes with the capture toggle declared.
	 */
	public function register_block_attribute( $attributes ) {
		$attributes[ self::BLOCK_ATTRIBUTE ] = [
			'type'    => 'boolean',
			'default' => false,
		];
		return $attributes;
	}

	/**
	 * Carry the block toggle to the page as the marker class on the form tag,
	 * which is what the capture script matches. Runs whether or not the
	 * integration is enabled: the class is inert on its own and the script is
	 * the switch, so a placement's markup does not change with the setting.
	 *
	 * @param string $content The rendered block HTML.
	 * @param array  $block   The parsed block, including its attributes.
	 *
	 * @return string The block HTML, with the form tag marked when opted in.
	 */
	public function mark_captured_block_form( $content, $block ) {
		if ( empty( $block['attrs'][ self::BLOCK_ATTRIBUTE ] ) ) {
			return $content;
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag( 'form' ) ) {
			return $content;
		}
		$tags->add_class( self::MARKER_CLASS );
		return $tags->get_updated_html();
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
