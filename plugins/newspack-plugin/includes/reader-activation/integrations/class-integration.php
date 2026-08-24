<?php
/**
 * Base integration class for contact data syncing.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Base Integration Class.
 *
 * This class should be extended by specific integration implementations.
 */
abstract class Integration {
	/**
	 * Map of ESP setting keys to their legacy option names.
	 *
	 * The account-deletion settings (`sync_account_deletion`, `account_deletion_handling`)
	 * are intentionally absent: they derive from the single legacy `sync_esp_delete`
	 * boolean with per-field logic rather than a straight value copy, so they are
	 * migrated by migrate_account_deletion_setting() instead.
	 *
	 * @var array<string, string>
	 */
	private static $legacy_option_map = [
		'mailchimp_audience_id'           => 'newspack_reader_activation_mailchimp_audience_id',
		'mailchimp_reader_default_status' => 'newspack_reader_activation_mailchimp_reader_default_status',
		'active_campaign_master_list'     => 'newspack_reader_activation_active_campaign_master_list',
		'constant_contact_list_id'        => 'newspack_reader_activation_constant_contact_list_id',
	];

	/**
	 * Legacy global option that the account-deletion settings migrate from.
	 *
	 * @var string
	 */
	const LEGACY_SYNC_DELETE_OPTION = 'newspack_reader_activation_sync_esp_delete';

	/**
	 * Option name prefix for storing enabled incoming metadata fields per integration.
	 *
	 * @var string
	 */
	const INCOMING_FIELDS_OPTION_PREFIX = 'newspack_integration_incoming_fields_';

	/**
	 * Option name prefix for storing enabled outgoing metadata fields per integration.
	 *
	 * @var string
	 */
	const OUTGOING_FIELDS_OPTION_PREFIX = 'newspack_integration_outgoing_fields_';

	/**
	 * Option name prefix for storing all integration settings.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION_PREFIX = 'newspack_integration_settings_';

	/**
	 * Option name prefix for storing metadata prefix per integration.
	 *
	 * @var string
	 */
	const METADATA_PREFIX_OPTION_PREFIX = 'newspack_integration_metadata_prefix_';

	/**
	 * WP_Error code pull_contact_data() should return when the provider has no
	 * contact for the reader. Not a failure: no re-run can make an absent
	 * contact appear, so batch drivers count these readers as skipped rather
	 * than errored.
	 *
	 * @var string
	 */
	const CONTACT_NOT_FOUND_ERROR_CODE = 'ras_contact_not_found';

	/**
	 * The unique identifier for this integration.
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * The display name for this integration.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * A short description for this integration.
	 *
	 * @var string
	 */
	protected $description = '';

	/**
	 * Settings fields for this integration.
	 *
	 * @var array
	 */
	protected $settings_fields = [];

	/**
	 * Memoized return value of get_settings_fields().
	 *
	 * The declarations are stable for the life of the instance — the subclass
	 * half is already frozen in $settings_fields, and the base-class groups are
	 * built from per-instance capability flags — but rebuilding them costs a
	 * __() call per label and description. The direction toggles resolve through
	 * this array on every is_push_enabled()/is_pull_enabled() call, which run
	 * once per contact in sync loops, so the array is built once and reused.
	 * Reset by init(), the only place $settings_fields can change.
	 *
	 * @var array|null
	 */
	private $settings_fields_cache = null;

	/**
	 * Constructor.
	 *
	 * @param string $id          The unique identifier for this integration.
	 * @param string $name        The display name for this integration.
	 * @param string $description Optional. A short description for this integration.
	 */
	public function __construct( $id, $name, $description = '' ) {
		$this->id          = $id;
		$this->name        = $name;
		$this->description = $description;

		$this->settings_fields = $this->register_settings_fields();
	}

	/**
	 * Get the integration ID.
	 *
	 * @return string The integration ID.
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get the integration name.
	 *
	 * @return string The integration name.
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get the integration description.
	 *
	 * @return string The integration description.
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Whether this integration's external prerequisites are configured.
	 *
	 * Child classes should override this to check whether the third-party
	 * service or plugin the integration depends on is set up (e.g., API
	 * key entered, provider selected). Returns true by default.
	 *
	 * @return bool True if set up, false otherwise.
	 */
	public function is_set_up() {
		return true;
	}

	/**
	 * Whether the external service this integration depends on is connected.
	 *
	 * Distinct from is_set_up(): "connected" covers only the third-party
	 * prerequisite configured at its source (provider chosen, API key
	 * entered), while is_set_up() additionally requires the integration's
	 * own settings to be complete. The Integrations UI routes the card's
	 * primary action on this: not connected sends the user to get_setup_url(),
	 * connected-but-not-set-up sends them to the integration's settings view.
	 * Like is_set_up(), this is a stored-state check by contract — no live
	 * API calls. Returns true by default.
	 *
	 * @return bool True if connected, false otherwise.
	 */
	public function is_connected() {
		return true;
	}

	/**
	 * Why this integration cannot operate with the site's current configuration.
	 *
	 * A non-null string marks the integration as unsupported: the Integrations
	 * UI shows the string verbatim as the card's error badge and routes the
	 * primary action to get_setup_url(), and the REST layer refuses to enable
	 * the integration. Distinct from is_connected(): connected-but-unsupported
	 * means the external prerequisite exists but is incompatible with this
	 * integration (e.g. the newsletters provider is "manual", which has no API
	 * to sync contacts against). Returns null by default.
	 *
	 * @return string|null Reason the integration is unsupported, or null.
	 */
	public function get_unsupported_reason() {
		return null;
	}

	/**
	 * The primary action label to offer when get_unsupported_reason() returns a reason.
	 *
	 * Child classes that report an unsupported reason should override this to name
	 * the remedy, so the integrations UI does not have to carry per-integration copy.
	 * Only read when get_unsupported_reason() is non-null.
	 *
	 * @return string The action label.
	 */
	public function get_unsupported_action_label() {
		return __( 'Open settings', 'newspack-plugin' );
	}

	/**
	 * Get the URL where the user can set up this integration.
	 *
	 * Child classes should override this to return the admin page where
	 * the integration's prerequisites can be configured.
	 *
	 * @return string The setup URL, or empty string if not applicable.
	 */
	public function get_setup_url() {
		return '';
	}

	/**
	 * Get the slug identifying which brand icon the integration card should show.
	 *
	 * Child classes override this to name the connected vendor (e.g. the active
	 * ESP provider). The integrations UI maps the slug to a brand mark; a null
	 * return keeps the integration's generic icon.
	 *
	 * @return string|null The icon slug, or null for the generic icon.
	 */
	public function get_provider_slug() {
		return null;
	}

	/**
	 * Get the plugins this integration depends on, with their active status.
	 *
	 * Child classes should override this to declare any plugins that must be
	 * active for the integration to function. The integrations UI uses this
	 * to surface a "requirements" affordance on the integration card.
	 *
	 * Each entry must include all of `slug`, `name`, `is_active`, and `is_installed` —
	 * the integrations UI treats a missing `is_installed` as uninstalled and renders
	 * a disabled "Requires …" card instead of the Activate action.
	 *
	 * @return array List of associative arrays with keys `slug`, `name`, `is_active`, `is_installed`.
	 */
	public function get_required_plugins() {
		return [];
	}

	/**
	 * Whether this integration supports frontend reader registration.
	 *
	 * Integrations that return true will have their key output to the page
	 * and will be accepted by the frontend registration endpoint.
	 *
	 * @return bool
	 */
	public function supports_frontend_registration(): bool {
		return false;
	}

	/**
	 * Option prefix for the per-integration registration key seed.
	 */
	const REGISTRATION_SEED_OPTION_PREFIX = 'newspack_integration_registration_seed_';

	/**
	 * Generate the registration key for this integration.
	 *
	 * The default implementation uses HMAC-SHA256 of the integration ID and a
	 * stored per-integration random seed with the site's auth salt. The seed
	 * makes the key revocable on its own: if a scripted client starts hammering
	 * the key, rotate_registration_key() invalidates it without rotating
	 * AUTH_SALT (which would log out every user on the site). Subclasses can
	 * override this to implement custom key schemes (e.g., asymmetric key
	 * pairs, time-bounded tokens).
	 *
	 * @return string The registration key.
	 */
	public function get_registration_key(): string {
		return $this->get_default_registration_key();
	}

	/**
	 * Create the registration key seed if it doesn't exist yet.
	 *
	 * Called when an integration is enabled, so the seed is in place before any
	 * page can emit the key — which keeps the write off the render path and out
	 * of concurrent first requests.
	 *
	 * @return void
	 */
	final public function ensure_registration_key_seed(): void {
		// Autoloaded on purpose, unlike this class's other options: the key is
		// read on nearly every frontend render, so a non-autoloaded seed would
		// add a query to each one.
		\add_option( self::REGISTRATION_SEED_OPTION_PREFIX . $this->id, \wp_generate_password( 32, false ), '', true );
	}

	/**
	 * Get the stored registration key seed, generating it on first use.
	 *
	 * Normally seeded by ensure_registration_key_seed() at enable time; this
	 * lazy path covers integrations enabled before the seed existed. The
	 * pre-check isn't atomic with the write, so two uncached first requests can
	 * both generate: the loser's page carries a key that stops validating once
	 * its cache expires. Narrow, self-healing, and avoided entirely for
	 * integrations enabled through Integrations::enable().
	 *
	 * @param bool $create Whether to create the seed when none is stored.
	 *                     Pass false on read-only paths; returns '' instead.
	 *
	 * @return string The seed, or '' when none is stored and $create is false.
	 */
	private function get_registration_key_seed( bool $create = true ): string {
		$option_name = self::REGISTRATION_SEED_OPTION_PREFIX . $this->id;
		$seed        = \get_option( $option_name );
		if ( ! is_string( $seed ) || '' === $seed ) {
			if ( ! $create ) {
				return '';
			}
			$this->ensure_registration_key_seed();
			$seed = (string) \get_option( $option_name );
		}
		return $seed;
	}

	/**
	 * Rotate the registration key by regenerating its stored seed.
	 *
	 * Invalidates the key on every page emitted so far — cached pages keep
	 * submitting the old key until their cache expires, and those requests are
	 * rejected. That is the point: this is the incident-response lever for a
	 * key being abused by scripted clients.
	 *
	 * PHP-only for now: there is no CLI command or admin surface, so operators
	 * reach it through `wp eval`. Anything that wires it to a request — an admin
	 * button, a REST route — must gate it on a capability check and a nonce
	 * first; this method performs neither.
	 *
	 * @return string The new registration key.
	 */
	final public function rotate_registration_key(): string {
		\update_option( self::REGISTRATION_SEED_OPTION_PREFIX . $this->id, \wp_generate_password( 32, false ), true );
		return $this->get_registration_key();
	}

	/**
	 * Validate a submitted registration key for this integration.
	 *
	 * The default implementation uses timing-safe comparison against
	 * the HMAC key. Subclasses can override this to implement custom
	 * validation (e.g., signature verification, token decryption).
	 *
	 * Note: The built-in JS client (newspackReaderActivation.register())
	 * always sends the value from get_registration_key(). Integrations
	 * that override this method to accept a different value must provide
	 * their own client-side code to compute and submit the correct key.
	 *
	 * The default implementation validates the HMAC key. Subclasses can override
	 * this method to perform additional checks on the request (e.g. verifying
	 * custom headers, validating metadata, or enforcing integration-specific rules).
	 *
	 * @param string           $key     The submitted key to validate.
	 * @param \WP_REST_Request $request The full registration request.
	 * @return bool Whether the registration request is valid.
	 */
	public function validate_registration_request( string $key, $request ): bool {
		$current = $this->get_registration_key();
		if ( hash_equals( $current, $key ) ) {
			return true;
		}
		// Only integrations still on the framework's own key scheme get the
		// transition allowance. A subclass with a custom scheme (a time-bounded
		// token, an asymmetric pair) that inherits or calls this validator would
		// otherwise accept the framework's static HMAC alongside its own, which
		// is a permanent bypass of whatever bound it was enforcing.
		//
		// Read-only: this runs on an unauthenticated path, and a custom-scheme
		// integration should not have a seed written for a key it never emits.
		// A default-scheme one always has one by now — get_registration_key()
		// above created it if needed — so a missing seed is itself the answer.
		$default = $this->get_default_registration_key( false );
		if ( '' === $default || ! hash_equals( $default, $current ) ) {
			return false;
		}
		return hash_equals( $this->get_legacy_registration_key(), $key );
	}

	/**
	 * The framework's own registration key derivation, as get_registration_key()
	 * computes it before any subclass override.
	 *
	 * @param bool $create_seed Whether to create the seed when none is stored.
	 *                          Pass false on read-only paths; returns '' instead.
	 *
	 * @return string The default-scheme registration key, or '' when no seed
	 *                exists and $create_seed is false.
	 */
	private function get_default_registration_key( bool $create_seed = true ): string {
		$seed = $this->get_registration_key_seed( $create_seed );
		if ( '' === $seed ) {
			return '';
		}
		return hash_hmac( 'sha256', $this->id . '|' . $seed, \wp_salt( 'auth' ) );
	}

	/**
	 * The pre-seed registration key, still accepted during the transition.
	 *
	 * Adding the seed to the HMAC input changes every integration's key once on
	 * upgrade. Pages already in a CDN or page cache carry the old key until
	 * their TTL expires, and the capture client treats an invalid key as
	 * permanent for the pageview — so without this the submission is lost
	 * rather than retried. This matters in production today: the ESP
	 * integration emits the legacy key on released sites and validates through
	 * this method. (newspack-manager's Fundraise Up handler emits it too, but
	 * replaces validate_registration_request() wholesale — it authenticates on
	 * verified supporter identifiers rather than the key — so it never reaches
	 * this branch and the allowance does nothing for it.)
	 *
	 * @todo Remove this method and its branch in validate_registration_request()
	 *       once the seeded key has been in production for a release cycle
	 *       (target: the first stable release after 2026-09-01). Until then
	 *       rotation narrows a key rather than fully revoking it.
	 *
	 * @return string The legacy registration key.
	 */
	private function get_legacy_registration_key(): string {
		return hash_hmac( 'sha256', $this->id, \wp_salt( 'auth' ) );
	}

	/**
	 * Initialize the integration, performing any necessary setup or validation.
	 *
	 * Currently only initializes settings fields, but can be extended by child classes for additional setup.
	 */
	public function init() {
		$this->settings_fields       = $this->register_settings_fields();
		$this->settings_fields_cache = null;
	}

	/**
	 * Register settings fields for this integration.
	 *
	 * Child classes should override this method to return static field
	 * declarations (key, type, default at minimum). No API calls, no conditional
	 * logic based on external state. Called directly in the constructor.
	 *
	 * @return array Array of settings field declarations.
	 */
	abstract public function register_settings_fields();

	/**
	 * Whether contacts can be synced to the ESP.
	 *
	 * @param bool $return_errors Optional. Whether to return a WP_Error object. Default false.
	 *
	 * @return bool|\WP_Error True if contacts can be synced, false otherwise. WP_Error if return_errors is true.
	 */
	abstract public function can_sync( $return_errors = false );

	/**
	 * Whether this integration can push (outbound) contact data to its external
	 * destination.
	 *
	 * Push-capable integrations get the Outbound settings section, the
	 * account-deletion sync fields and the metadata field prefix, and count
	 * toward Sync::has_one_syncable_integration(). Inbound-only integrations
	 * (those whose push_contact_data() is a deliberate no-op) should override
	 * this to return false so the settings UI shows no dead outbound controls
	 * and the sync framework skips the push path entirely.
	 *
	 * @return bool True if the integration can push contact data.
	 */
	public function supports_push(): bool {
		return true;
	}

	/**
	 * Whether this integration can pull (inbound) contact data from its
	 * external source.
	 *
	 * Pull-capable integrations get the Inbound settings section and are
	 * included in the Contact_Pull dispatch. Integrations that don't implement
	 * pull_contact_data()/get_available_incoming_fields() should override this
	 * to return false.
	 *
	 * @return bool True if the integration can pull contact data.
	 */
	public function supports_pull(): bool {
		return true;
	}

	/**
	 * Whether outbound (push) sync should currently run for this integration.
	 *
	 * Combines the push capability with the `outgoing_sync_enabled` toggle,
	 * which pauses the direction while preserving the configured outgoing
	 * field selection. Every push dispatch site must consult this — including
	 * account-deletion propagation, which travels the push pipeline.
	 *
	 * An undeclared toggle field (e.g. a subclass overriding
	 * get_settings_fields() without the base metadata group) reads as null and
	 * counts as enabled: the toggle can only ever pause sync explicitly,
	 * mirroring the frontend's missing-toggle-means-enabled rendering. A
	 * declared field never resolves to null — get_settings_field_value() falls
	 * back to the field default.
	 *
	 * The stored value is coerced with wp_validate_boolean() rather than a cast
	 * so this agrees with the wizard's toBool(): both read the strings `'false'`
	 * and `'0'` as off. The sanctioned write path can't produce `'false'` (the
	 * checkbox sanitizes to a real bool), but a hand-set option or external
	 * writer otherwise diverges in the worst direction — UI paused, dispatch
	 * still pushing.
	 *
	 * @return bool True if pushes should run.
	 */
	final public function is_push_enabled(): bool {
		if ( ! $this->supports_push() ) {
			return false;
		}
		$enabled = $this->get_settings_field_value( 'outgoing_sync_enabled' );
		return null === $enabled || \wp_validate_boolean( $enabled );
	}

	/**
	 * Whether inbound (pull) sync should currently run for this integration.
	 *
	 * Combines the pull capability with the `incoming_sync_enabled` toggle,
	 * which pauses the direction while preserving the configured incoming
	 * field selection. Every pull dispatch site must consult this.
	 *
	 * As with is_push_enabled(), an undeclared toggle field reads as null and
	 * counts as enabled — only an explicit stored value can pause the
	 * direction — and the stored value is coerced with wp_validate_boolean() so
	 * PHP and the wizard agree on the falsy string forms.
	 *
	 * @return bool True if pulls should run.
	 */
	final public function is_pull_enabled(): bool {
		if ( ! $this->supports_pull() ) {
			return false;
		}
		$enabled = $this->get_settings_field_value( 'incoming_sync_enabled' );
		return null === $enabled || \wp_validate_boolean( $enabled );
	}

	/**
	 * Push contact data to the integration destination.
	 *
	 * This method should be implemented by child classes to send
	 * contact data to their specific integration destination.
	 *
	 * @param array      $contact The contact data to push.
	 * @param string     $context Optional. The context of the sync.
	 * @param array|null $existing_contact Optional. Existing contact data if available.
	 *
	 * @return true|\WP_Error True on success or WP_Error on failure.
	 */
	abstract public function push_contact_data( $contact, $context = '', $existing_contact = null );

	/**
	 * Whether this integration can hard-delete a contact from its external system.
	 *
	 * When false, the account-deletion settings UI hides the "delete immediately"
	 * option and falls back to `flag` mode by default — so third-party integrations
	 * that only implement `push_contact_data()` aren't exposed as a delete-mode
	 * option that would just return `not_implemented` on every deletion.
	 *
	 * Override and return true alongside a `delete_contact()` implementation.
	 *
	 * @return bool True if the integration implements delete_contact().
	 */
	public function supports_hard_delete(): bool {
		return false;
	}

	/**
	 * Delete a contact from the integration's external system.
	 *
	 * Integrations that support hard deletion should override this AND
	 * `supports_hard_delete()`. The default returns a "not implemented" WP_Error
	 * so the dispatcher can log and skip.
	 *
	 * @param string $email Email address of the contact to delete.
	 * @return true|\WP_Error True on success, WP_Error otherwise.
	 */
	public function delete_contact( string $email ) {
		return new \WP_Error( 'not_implemented', __( 'This integration does not support hard deletion.', 'newspack-plugin' ) );
	}

	/**
	 * Handle a logged-in user attempting to register again via the frontend registration flow.
	 *
	 * Integrations can override this method to update user data or perform other actions when an existing user attempts to register again via the frontend registration flow. For example, an integration might want to link the existing user account to the integration, record a new donation for a returning donor, or log this event for analytics purposes.
	 *
	 * The default implementation is a no-op.
	 *
	 * @param \WP_User         $user    The currently logged-in user attempting to register again.
	 * @param \WP_REST_Request $request The original registration request.
	 */
	public function handle_logged_in_user_registration( $user, $request ) {
		// By default, do nothing. Integrations can override this to handle cases where a logged-in user attempts to register again via the frontend registration flow.
	}

	/**
	 * Register data event handlers for this integration.
	 *
	 * Called by Integrations after all integrations have been registered.
	 * Concrete classes should override this and call $this->register_handler()
	 * for each data event they need to handle.
	 */
	public function register_handlers() {}

	/**
	 * Register a data event handler for this integration.
	 *
	 * Delegates to Integrations which owns the handler map and
	 * registers a serializable static callable with Data Events.
	 *
	 * The referenced method must have the following signature:
	 *   public function $method( int $timestamp, array $data, string $client_id ): void
	 *
	 * @param string $action_name The data event action name.
	 * @param string $method      The instance method to call on this integration.
	 */
	final protected function register_handler( $action_name, $method ) {
		Integrations::register_data_event_handler( $this, static::class, $action_name, $method );
	}

	/**
	 * Static dispatcher called by Data Events.
	 *
	 * Thin trampoline that delegates to Integrations::dispatch_data_event_handler().
	 * This method must live on Integration so that late static binding
	 * (static::class) produces a unique serializable callable per concrete
	 * subclass, which Data Events needs for independent handler retries.
	 *
	 * @param int    $timestamp Timestamp of the event.
	 * @param array  $data      Data associated with the event.
	 * @param string $client_id Client ID.
	 *
	 * @throws \RuntimeException When the handler cannot be dispatched.
	 */
	final public static function dispatch_data_event_handler( $timestamp, $data, $client_id ) {
		Integrations::dispatch_data_event_handler( static::class, $timestamp, $data, $client_id );
	}

	/**
	 * Pull contact data from the integration for a given user.
	 *
	 * Integrations that support pulling contact data should implement this method.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array|\WP_Error Associative array of field_key => value pairs on success, WP_Error on failure.
	 */
	public function pull_contact_data( $user_id ) {
		return [];
	}

	/**
	 * Declare a WooCommerce My Account menu item for this integration.
	 *
	 * Return null (default) to opt out. Otherwise return:
	 *   [
	 *     'slug'     => 'rewards',              // endpoint slug, unique across integrations.
	 *     'label'    => __( 'Rewards', 'newspack-plugin' ),
	 *     'position' => 25,                     // optional, menu sort order.
	 *   ]
	 *
	 * The slug must not collide with a core My Account endpoint or one owned by
	 * another plugin's My Account bridge — `newsletters` is reserved by
	 * newspack-newsletters. A duplicate slug would render two tab bodies.
	 *
	 * @return array|null
	 */
	public function get_my_account_menu_item() {
		return null;
	}

	/**
	 * Render the My Account page body for this integration.
	 *
	 * Called inside the WooCommerce account template when the endpoint
	 * declared by get_my_account_menu_item() is the current view. Echo
	 * markup directly. Default is a no-op.
	 *
	 * @param mixed $value The endpoint query var value (usually empty).
	 */
	public function render_my_account_page( $value ) {}

	/**
	 * Get incoming available contact fields from the integration.
	 *
	 * This method should be implemented by child classes to return
	 * an array of available contact fields from their integration.
	 *
	 * Integrations that support pulling contact data should implement this method.
	 *
	 * @return Integrations\Incoming_Field[]|\WP_Error Array of incoming contact field objects or WP_Error on failure.
	 */
	public function get_available_incoming_fields() {
		return [];
	}

	/**
	 * Get filtered incoming contact fields from the integration.
	 *
	 * Filters out fields whose human-readable name matches one of the
	 * outgoing-sync prefixed keys, so admins don't re-select fields they
	 * are already pushing to the ESP. Comparison is against `name` (not
	 * `key`) because outgoing custom fields are created on the ESP under
	 * their prefixed *label*, which the ESP returns as the incoming
	 * field's `name` — while `key` is the ESP-assigned machine identifier
	 * (e.g. Mailchimp `tag`, ActiveCampaign `perstag`).
	 *
	 * @return Integrations\Incoming_Field[] Array of incoming contact field objects.
	 */
	public function get_filtered_incoming_fields() {
		$fields = $this->get_available_incoming_fields();
		if ( is_wp_error( $fields ) ) {
			return [];
		}
		$names_to_filter = Sync\Metadata::get_all_prefixed_keys();
		return array_values(
			array_filter(
				$fields,
				function( $field ) use ( $names_to_filter ) {
					foreach ( $names_to_filter as $name_to_filter ) {
						if ( strpos( $field->get_name(), $name_to_filter ) === 0 ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Test the live connection to the integration service.
	 *
	 * Subclasses should override this to perform a lightweight API call
	 * verifying credentials and reachability.
	 *
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection() {
		return true;
	}

	/**
	 * Run a full health check: settings validation + live connection test.
	 *
	 * @return true|\WP_Error True if healthy, WP_Error on failure.
	 */
	final public function health_check() {
		$errors = $this->can_sync( true );
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return $errors;
		}
		try {
			$connection = $this->test_connection();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'newspack_integration_connection_error', $e->getMessage() );
		}
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		return true;
	}

	/**
	 * Get the ActionScheduler group name for this integration.
	 *
	 * @return string The group name (e.g., 'newspack-integration-esp').
	 */
	final public function get_action_group() {
		return Integrations::get_action_group( $this->id );
	}

	/**
	 * Get ActionScheduler actions for this integration.
	 *
	 * @param array $args Optional. Query arguments (status, per_page, offset, orderby, order).
	 *
	 * @return array Array of action row objects.
	 */
	final public function get_scheduled_actions( $args = [] ) {
		$args['integration_id'] = $this->id;
		return Integrations::get_scheduled_actions( $args );
	}

	/**
	 * Schema keys that indicate a stored raw_data entry was saved with the
	 * post-rename integration schema. Entries missing every one of these are
	 * considered "legacy" and rebuilt from the live provider list on read.
	 *
	 * @var string[]
	 */
	private const SCHEMA_KEYS = [
		'name',
		'value_type',
		'matching_function',
		'options',
		'description',
		'is_access_rule',
		'is_segment_criteria',
	];

	/**
	 * Allowed matching functions (operators) for an incoming field's segment
	 * criterion. Enforced on every write path — REST sanitize and the storage
	 * setter — and re-applied on read; the single source of truth so those
	 * paths can't drift.
	 *
	 * @var string[]
	 */
	private const ALLOWED_INCOMING_MATCHING_FUNCTIONS = [ 'default', 'range', 'list__in', 'list__not_in' ];

	/**
	 * Get the enabled incoming fields for this integration.
	 *
	 * Reads stored field data (key => raw_data map saved by
	 * update_enabled_incoming_fields()) and constructs Incoming_Field objects
	 * for each entry. Each field is passed through configure_incoming_field()
	 * so the integration can enrich it with promotion configuration.
	 *
	 * Legacy entries (saved before the schema expansion) carry raw_data that
	 * predates the new keys. For those, fetch the live provider list once and
	 * merge in the enrichment so the field renders correctly without forcing
	 * the admin to re-save the integrations page after upgrade.
	 *
	 * @return Integrations\Incoming_Field[] Array of field objects.
	 */
	public function get_enabled_incoming_fields() {
		$stored = \get_option( self::INCOMING_FIELDS_OPTION_PREFIX . $this->id, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$has_legacy_entries = false;
		foreach ( $stored as $key => $raw_data ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( ! is_array( $raw_data ) || empty( array_intersect( self::SCHEMA_KEYS, array_keys( $raw_data ) ) ) ) {
				$has_legacy_entries = true;
				break;
			}
		}

		// Resolve the live provider list once, only when at least one entry needs it.
		// On API failure, fall back to the stored raw_data unchanged.
		$live_by_key = [];
		if ( $has_legacy_entries ) {
			$available = $this->get_available_incoming_fields();
			if ( ! is_wp_error( $available ) && is_array( $available ) ) {
				foreach ( $available as $available_field ) {
					if ( $available_field instanceof Integrations\Incoming_Field ) {
						$live_by_key[ $available_field->get_key() ] = $available_field->get_raw_data();
					}
				}
			}
		}

		$fields = [];
		foreach ( $stored as $key => $raw_data ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			$raw_data = is_array( $raw_data ) ? $raw_data : [];
			if ( empty( array_intersect( self::SCHEMA_KEYS, array_keys( $raw_data ) ) ) && isset( $live_by_key[ $key ] ) ) {
				// Stored entry is in the legacy shape — overlay the live schema while
				// preserving any non-schema keys the publisher may have stored.
				$raw_data = array_merge( $raw_data, $live_by_key[ $key ] );
			}
			$field = new Integrations\Incoming_Field( $key, $raw_data );
			$field = $this->configure_incoming_field( $field );
			if ( $field instanceof Integrations\Incoming_Field ) {
				// The publisher's stored operator choice is authoritative. Re-apply it after
				// configure_incoming_field(), which may (re)derive matching_function from the
				// provider schema and clobber the choice for non-ESP integrations.
				if (
					isset( $raw_data['matching_function'] )
					&& is_string( $raw_data['matching_function'] )
					&& in_array( $raw_data['matching_function'], self::ALLOWED_INCOMING_MATCHING_FUNCTIONS, true )
				) {
					$field->set_matching_function( $raw_data['matching_function'] );
				}
				$fields[] = $field;
			}
		}
		return $fields;
	}

	/**
	 * Configure an Incoming_Field after construction.
	 *
	 * Override this method to enrich incoming fields with promotion configuration
	 * so they can be registered as content gate access rules and/or popups
	 * segmentation criteria. The field's raw data (from the integration API) is
	 * available via $field->get_raw_data() and can inform the configuration.
	 *
	 * Example:
	 *
	 *     protected function configure_incoming_field( $field ) {
	 *         $raw = $field->get_raw_data();
	 *         if ( 'membership_level' === $field->get_key() ) {
	 *             $field->set_name( 'Membership Level' )
	 *                 ->set_is_access_rule( true )
	 *                 ->set_is_segment_criteria( true )
	 *                 ->set_matching_function( 'list__in' )
	 *                 ->set_options( $raw['options'] ?? [] );
	 *         }
	 *         if ( 'is_vip' === $field->get_key() ) {
	 *             $field->set_name( 'VIP' )
	 *                 ->set_is_access_rule( true )
	 *                 ->set_value_type( 'boolean' );
	 *         }
	 *         return $field;
	 *     }
	 *
	 * @param Integrations\Incoming_Field $field The field to configure.
	 *
	 * @return Integrations\Incoming_Field The configured field.
	 */
	protected function configure_incoming_field( $field ) {
		return $field;
	}

	/**
	 * Get the enabled outgoing metadata fields for this integration.
	 *
	 * In legacy metadata mode an integration with no saved selection of its
	 * own inherits the ESP integration's effective selection — the set the
	 * legacy pipeline filters by — so pre-existing legacy sites keep syncing
	 * exactly what they did before per-integration selection existed, and
	 * the Outbound UI reflects what is actually pushed. An explicitly saved
	 * selection (even an empty one) always wins (NPPD-2107).
	 *
	 * Two legacy-mode caveats, accepted for this transitional schema: the
	 * legacy pipeline upstream-filters by the ESP selection, so an explicit
	 * selection can only narrow that set (a field the ESP integration has
	 * disabled never syncs even when enabled here); and once a selection is
	 * saved, only deleting the integration's option restores inheritance.
	 *
	 * What gets inherited is overridable — see
	 * get_inherited_legacy_outgoing_fields().
	 *
	 * @return string[] List of enabled field names.
	 */
	public function get_enabled_outgoing_fields() {
		$stored = \get_option( self::OUTGOING_FIELDS_OPTION_PREFIX . $this->id, null );
		if ( null !== $stored && is_array( $stored ) ) {
			return array_values( $stored );
		}

		if ( 'legacy' === Sync\Metadata::get_version() && 'esp' !== $this->get_id() ) {
			return array_values( $this->get_inherited_legacy_outgoing_fields() );
		}

		return [];
	}

	/**
	 * The selection this integration inherits in legacy mode when it has never
	 * saved one of its own.
	 *
	 * Defaults to the ESP integration's effective selection — the set the legacy
	 * metadata pipeline filters by — so an un-migrated site keeps its existing
	 * payloads. Sync\Metadata::get_fields() is the single definition of that
	 * set, including its registry-miss fallbacks.
	 *
	 * Override to inherit something else, or return an empty array to opt out of
	 * inheritance entirely (an integration that does so pushes no metadata until
	 * an Outbound selection is saved).
	 *
	 * @return string[] List of inherited field names.
	 */
	protected function get_inherited_legacy_outgoing_fields() {
		return Sync\Metadata::get_fields();
	}

	/**
	 * Update the enabled incoming fields for this integration.
	 *
	 * Accepts an array of field keys (as sent by the UI), fetches the full
	 * field data from the integration, and stores the matching raw field arrays.
	 *
	 * @param array $fields Array of field keys, or a map of key => matching_function.
	 *
	 * @return bool True if updated, false otherwise.
	 */
	public function update_enabled_incoming_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			$fields = [];
		}

		$available = $this->get_available_incoming_fields();
		if ( is_wp_error( $available ) ) {
			$available = [];
		}

		// Build a lookup of available fields by key.
		$available_by_key = [];
		foreach ( $available as $field ) {
			if ( $field instanceof Integrations\Incoming_Field ) {
				$available_by_key[ $field->get_key() ] = $field;
			}
		}

		// Normalize input to a map of key => chosen matching function. Accept both a
		// sequential list of keys (legacy callers) and an associative map (typed UI).
		$key_operator_map = [];
		// PHP 8.0-safe array_is_list(): the array is a list iff re-indexing is a no-op.
		if ( $fields === array_values( $fields ) ) {
			foreach ( $fields as $key ) {
				$key = (string) $key;
				if ( '' === $key ) {
					continue;
				}
				$key_operator_map[ $key ] = null;
			}
		} else {
			foreach ( $fields as $key => $matching_function ) {
				$key = (string) $key;
				if ( '' === $key ) {
					continue;
				}
				$key_operator_map[ $key ] = is_string( $matching_function ) ? $matching_function : null;
			}
		}

		// Store as key => raw_data map, overriding matching_function when chosen.
		$fields_to_store = [];
		foreach ( $key_operator_map as $key => $matching_function ) {
			$raw_data = [];
			if ( isset( $available_by_key[ $key ] ) ) {
				$raw_data = $available_by_key[ $key ]->get_raw_data();
			}
			if ( null !== $matching_function && in_array( $matching_function, self::ALLOWED_INCOMING_MATCHING_FUNCTIONS, true ) ) {
				$raw_data['matching_function'] = $matching_function;
			}
			$fields_to_store[ $key ] = $raw_data;
		}

		return \update_option( self::INCOMING_FIELDS_OPTION_PREFIX . $this->id, $fields_to_store, false );
	}

	/**
	 * Update the enabled outgoing metadata fields for this integration.
	 *
	 * @param array $fields List of field names to enable.
	 * @return bool True if updated, false otherwise.
	 */
	public function update_enabled_outgoing_fields( $fields ) {
		// Only allow fields that are in the metadata keys map.
		$fields = array_intersect( Sync\Metadata::get_default_fields(), $fields );
		return \update_option( self::OUTGOING_FIELDS_OPTION_PREFIX . $this->id, array_values( $fields ), false );
	}

	/**
	 * Filter metadata keys to only those whose field name is enabled for outgoing sync.
	 *
	 * @param string[] $keys Array of raw metadata keys to filter.
	 * @return array Filtered key-value pairs from Metadata::get_keys().
	 */
	public function filter_enabled_outgoing_fields( $keys ) {
		$enabled_fields = $this->get_enabled_outgoing_fields();
		return array_filter(
			Sync\Metadata::get_keys(),
			function ( $val, $key ) use ( $keys, $enabled_fields ) {
				return in_array( $key, $keys, true ) && in_array( $val, $enabled_fields, true );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Get the metadata keys enabled for outgoing sync.
	 *
	 * @param bool $prefixed Optional. Whether to return prefixed keys instead of raw keys. Default false.
	 *
	 * @return string[] List of raw metadata keys.
	 */
	public function get_enabled_outgoing_fields_keys( $prefixed = false ) {
		$enabled_fields = $this->get_enabled_outgoing_fields();
		$keys           = [];

		foreach ( Sync\Metadata::get_keys() as $raw_key => $field_name ) {
			if ( in_array( $field_name, $enabled_fields, true ) ) {
				$keys[] = $prefixed ? $this->get_metadata_prefix() . $field_name : $raw_key;
			}
		}

		return array_unique( $keys );
	}

	/**
	 * Get the account-deletion fields declared by this integration.
	 *
	 * Auto-appended to push-capable integrations' settings (see
	 * get_settings_fields()): deletion propagates through the push pipeline, so
	 * for a push-less integration these would be dead controls. The first field
	 * is a top-level toggle; the second field is gated by the first via the
	 * `condition` predicate honored by the frontend renderer.
	 *
	 * @return array Array of settings field declarations.
	 */
	public function get_account_deletion_fields() {
		$supports_hard_delete = $this->supports_hard_delete();

		// When the integration supports hard delete, expose both options and default
		// to `delete` (matches the historical sync_esp_delete=true default for ESP).
		// Otherwise expose only `flag` and default to it — no point letting publishers
		// pick a mode that will just return `not_implemented` on every deletion.
		$handling_options = [
			[
				'value' => 'flag',
				'label' => __( 'Sync deletion metadata', 'newspack-plugin' ),
			],
		];
		if ( $supports_hard_delete ) {
			array_unshift(
				$handling_options,
				[
					'value' => 'delete',
					'label' => __( 'Delete contact immediately', 'newspack-plugin' ),
				]
			);
		}

		return [
			[
				'key'         => 'sync_account_deletion',
				'type'        => 'checkbox',
				'label'       => __( 'Sync user account deletion', 'newspack-plugin' ),
				'description' => __( 'When a reader account is deleted, propagate the deletion to this integration.', 'newspack-plugin' ),
				'default'     => true,
			],
			[
				'key'         => 'account_deletion_handling',
				'type'        => 'select',
				'label'       => __( 'How to sync deletion', 'newspack-plugin' ),
				'description' => __( 'Choose whether to delete the contact from the integration immediately, or sync reader data with deletion metadata to be handled at the integration level.', 'newspack-plugin' ),
				'default'     => $supports_hard_delete ? 'delete' : 'flag',
				'options'     => $handling_options,
				'condition'   => [
					'field'  => 'sync_account_deletion',
					'equals' => true,
				],
			],
		];
	}

	/**
	 * Get the metadata fields declared by this integration.
	 *
	 * Capability-aware: the outbound group (metadata prefix, outbound sync
	 * toggle, outgoing fields) is declared only for push-capable integrations —
	 * the prefix is only ever read on push paths (prepare_contact()) — and the
	 * inbound group (inbound sync toggle, incoming fields) only for
	 * pull-capable ones, so an integration lacking a direction gets no dead
	 * controls for it.
	 *
	 * @return array Array of settings field declarations.
	 */
	public function get_metadata_fields() {
		$fields = [];
		if ( $this->supports_push() ) {
			$fields[] = [
				'key'         => 'metadata_prefix',
				'type'        => 'text',
				'label'       => __( 'Metadata field prefix', 'newspack-plugin' ),
				'description' => __( 'A string to prefix metadata fields synced to the integration. Required to ensure that metadata field names are unique. Default: NP_', 'newspack-plugin' ),
				'default'     => 'NP_',
			];
			$fields[] = [
				'key'         => 'outgoing_sync_enabled',
				'type'        => 'checkbox',
				'label'       => __( 'Enable outbound sync', 'newspack-plugin' ),
				'description' => __( 'Sync reader data to this integration. Disabling pauses outbound sync, including account-deletion sync, and preserves the outgoing field selection. Changes and deletions that occur while paused are not sent retroactively on re-enable.', 'newspack-plugin' ),
				'default'     => true,
			];
			$fields[] = [
				'key'     => 'outgoing_metadata_fields',
				'type'    => 'metadata',
				'label'   => __( 'Outgoing metadata fields', 'newspack-plugin' ),
				'default' => [],
			];
		}
		if ( $this->supports_pull() ) {
			$fields[] = [
				'key'         => 'incoming_sync_enabled',
				'type'        => 'checkbox',
				'label'       => __( 'Enable inbound sync', 'newspack-plugin' ),
				'description' => __( 'Pull contact data from this integration. Disabling pauses inbound sync and preserves the incoming field selection.', 'newspack-plugin' ),
				'default'     => true,
			];
			$fields[] = [
				'key'     => 'incoming_metadata_fields',
				'type'    => 'metadata',
				'label'   => __( 'Incoming metadata fields', 'newspack-plugin' ),
				'default' => [],
			];
		}
		return $fields;
	}

	/**
	 * Get the metadata prefix for this integration.
	 *
	 * @return string The metadata prefix.
	 */
	public function get_metadata_prefix() {
		$value = \get_option( self::METADATA_PREFIX_OPTION_PREFIX . $this->id, null );
		if ( null !== $value && ! empty( $value ) ) {
			return $value;
		}
		// Lazy migrate from legacy global option.
		$legacy_value = \get_option( Sync\Metadata::PREFIX_OPTION, null );
		if ( null !== $legacy_value && ! empty( $legacy_value ) ) {
			// update option directly to avoid infinite loop.
			\update_option( self::METADATA_PREFIX_OPTION_PREFIX . $this->id, $legacy_value, false );
			return $legacy_value;
		}
		return 'NP_';
	}

	/**
	 * Prepare contact data for this integration by filtering to enabled
	 * outgoing fields and adding the metadata prefix.
	 *
	 * In legacy mode the metadata classes return data already filtered and
	 * prefixed — but filtered by the ESP integration's own field config, so
	 * only the `esp` integration takes it as-is. Every other integration
	 * still applies its enabled-outgoing selection via
	 * prepare_contact_legacy(); otherwise an integration with an empty
	 * Outbound selection would receive (and push) the full default field set.
	 *
	 * @param array $contact Contact data with raw metadata keys.
	 * @return array Contact data with filtered, prefixed metadata.
	 */
	public function prepare_contact( $contact ) {
		if ( empty( $contact['metadata'] ) ) {
			return $contact;
		}

		if ( 'legacy' === Sync\Metadata::get_version() ) {
			return $this->prepare_contact_legacy( $contact );
		}

		$enabled_fields = $this->get_enabled_outgoing_fields();
		$prefix         = $this->get_metadata_prefix();
		$keys_map       = Sync\Metadata::get_keys();
		$prepared       = [];

		foreach ( $contact['metadata'] as $key => $value ) {
			// If the key is already prefixed, keep it only when its field is both
			// enabled and currently available — guarding against stale enabled-field
			// names left over from a prior feature-flag-on period.
			if ( 0 === strpos( $key, $prefix ) ) {
				$field_name = substr( $key, strlen( $prefix ) );
				if ( in_array( $field_name, $enabled_fields, true ) && in_array( $field_name, $keys_map, true ) ) {
					$prepared[ $key ] = $value;
				}
				continue;
			}

			// Otherwise, prefix raw keys that are in the keys map and enabled.
			if ( isset( $keys_map[ $key ] ) && in_array( $keys_map[ $key ], $enabled_fields, true ) ) {
				$prepared[ $prefix . $keys_map[ $key ] ] = $value;
			}
		}

		$contact['metadata'] = $prepared;
		return $contact;
	}

	/**
	 * Apply this integration's outgoing-field selection to legacy-pipeline data.
	 *
	 * Legacy metadata arrives already prefixed and filtered — by the ESP
	 * integration's field config. The `esp` integration therefore takes it
	 * unchanged, but any other integration must still narrow the set to its
	 * own enabled outgoing fields: an explicitly saved empty Outbound
	 * selection means no metadata fields, not all of them. An integration
	 * that never saved a selection inherits the ESP integration's effective
	 * selection via get_enabled_outgoing_fields(), preserving pre-existing
	 * legacy behavior (NPPD-2107).
	 *
	 * Matching runs against whole keys rather than de-prefixed remainders, so a
	 * key reshaped by the `newspack_ras_metadata_key` filter still matches (see
	 * get_legacy_enabled_key_shapes()). Unprefixed sync-control keys
	 * (Legacy_Metadata::SYNC_CONTROL_KEYS — `status`, `status_if_new`) always
	 * pass through; any other unprefixed key is dropped, so future unprefixed
	 * metadata cannot bypass the outbound selection filter.
	 *
	 * @param array $contact Contact data with prefixed legacy metadata.
	 * @return array Contact data with metadata narrowed to enabled fields.
	 */
	private function prepare_contact_legacy( array $contact ): array {
		if ( 'esp' === $this->get_id() ) {
			return $contact;
		}

		[ $exact_keys, $utm_prefixes ] = $this->get_legacy_enabled_key_shapes();
		$prepared                      = [];

		foreach ( $contact['metadata'] as $key => $value ) {
			if ( in_array( $key, Sync\Legacy_Metadata::SYNC_CONTROL_KEYS, true ) ) {
				$prepared[ $key ] = $value;
				continue;
			}
			if ( isset( $exact_keys[ $key ] ) ) {
				$prepared[ $key ] = $value;
				continue;
			}
			foreach ( $utm_prefixes as $utm_prefix ) {
				// A UTM label only carries its own suffixed sub-keys, never a
				// bare re-match of itself (that is the exact-key case above).
				if ( 0 === strpos( $key, $utm_prefix ) && strlen( $key ) > strlen( $utm_prefix ) ) {
					$prepared[ $key ] = $value;
					break;
				}
			}
		}

		$contact['metadata'] = $prepared;
		return $contact;
	}

	/**
	 * Build the legacy-mode match shapes for this integration's enabled fields.
	 *
	 * Returns two sets of whole keys, both built with the legacy pipeline's own
	 * prefix (Sync\Metadata::get_prefix() — the one the data actually carries,
	 * not this integration's own, which may differ):
	 *
	 * - exact keys, matched by identity;
	 * - UTM prefixes, matched by prefix with a non-empty remainder.
	 *
	 * Each enabled label contributes both the key Sync\Metadata::get_key()
	 * produces (so a key reshaped by the `newspack_ras_metadata_key` filter
	 * still matches) and the plain `prefix . label` shape (so a label absent
	 * from the current key map still matches as it did before).
	 *
	 * Only the raw keys in Legacy_Metadata::UTM_RAW_KEYS get prefix-match
	 * semantics. `newspack_ras_metadata_keys` lets any plugin register labels,
	 * and a registered label ending in `': '` that happened to prefix another
	 * label would otherwise carry that other field past the selection.
	 *
	 * @return array{0: array<string, true>, 1: string[]} Exact-key set and UTM prefixes.
	 */
	private function get_legacy_enabled_key_shapes(): array {
		$enabled_fields = $this->get_enabled_outgoing_fields();
		$prefix         = Sync\Metadata::get_prefix();
		$keys_map       = Sync\Metadata::get_keys();
		$exact_keys     = [];
		$utm_prefixes   = [];

		$utm_labels = [];
		foreach ( Sync\Legacy_Metadata::UTM_RAW_KEYS as $utm_raw_key ) {
			if ( isset( $keys_map[ $utm_raw_key ] ) ) {
				$utm_labels[] = $keys_map[ $utm_raw_key ];
			}
		}

		foreach ( $keys_map as $raw_key => $label ) {
			if ( ! in_array( $label, $enabled_fields, true ) ) {
				continue;
			}
			$filtered_key = Sync\Metadata::get_key( $raw_key );
			if ( ! is_string( $filtered_key ) || '' === $filtered_key ) {
				continue;
			}
			if ( in_array( $raw_key, Sync\Legacy_Metadata::UTM_RAW_KEYS, true ) ) {
				$utm_prefixes[] = $filtered_key;
			} else {
				$exact_keys[ $filtered_key ] = true;
			}
		}

		foreach ( $enabled_fields as $label ) {
			if ( ! is_string( $label ) || '' === $label ) {
				continue;
			}
			if ( in_array( $label, $utm_labels, true ) ) {
				$utm_prefixes[] = $prefix . $label;
			} else {
				$exact_keys[ $prefix . $label ] = true;
			}
		}

		return [ $exact_keys, array_values( array_unique( $utm_prefixes ) ) ];
	}

	/**
	 * Update the metadata prefix for this integration.
	 *
	 * @param string $prefix The new prefix value.
	 * @return bool True if updated, false otherwise.
	 */
	public function update_metadata_prefix( $prefix ) {
		if ( empty( $prefix ) ) {
			$prefix = 'NP_';
		}
		return \update_option( self::METADATA_PREFIX_OPTION_PREFIX . $this->id, \sanitize_text_field( $prefix ), false );
	}

	/**
	 * Get the settings fields declared by this integration.
	 *
	 * The account-deletion group follows the push capability: deletion sync
	 * routes through push_contact_data()/delete_contact(), so a push-less
	 * integration gets neither field (and its `sync_account_deletion` value
	 * reads as null/falsy, which the deletion dispatcher treats as disabled).
	 * The metadata groups are capability-gated in get_metadata_fields().
	 *
	 * Memoized per instance — see $settings_fields_cache for why.
	 *
	 * @return array Array of settings field declarations.
	 */
	public function get_settings_fields() {
		if ( null !== $this->settings_fields_cache ) {
			return $this->settings_fields_cache;
		}
		$fields = $this->settings_fields;
		if ( $this->supports_push() ) {
			$fields = array_merge( $fields, $this->get_account_deletion_fields() );
		}
		$this->settings_fields_cache = array_merge( $fields, $this->get_metadata_fields() );
		return $this->settings_fields_cache;
	}

	/**
	 * Settings field types that are managed by server-side code (e.g., OAuth
	 * callbacks) and must not be writable from the admin settings REST endpoint.
	 *
	 * @var string[]
	 */
	const MANAGED_FIELD_TYPES = [ 'oauth', 'hidden' ];

	/**
	 * Whether a settings field is managed by server-side code and therefore
	 * read-only on the REST settings save path.
	 *
	 * @param string $key The field key.
	 * @return bool True if the field is a managed type, false otherwise.
	 */
	public function is_managed_settings_field( $key ) {
		$field = $this->get_settings_field_by_key( $key );
		if ( ! $field ) {
			return false;
		}
		return in_array( $field['type'] ?? 'text', self::MANAGED_FIELD_TYPES, true );
	}

	/**
	 * Get the value of a settings field.
	 *
	 * @param string $key The field key.
	 * @return mixed The field value, or the default if not set.
	 */
	public function get_settings_field_value( $key ) {
		// Route metadata fields to their dedicated getters.
		if ( 'metadata_prefix' === $key ) {
			return $this->get_metadata_prefix();
		}
		if ( 'outgoing_metadata_fields' === $key ) {
			return $this->get_enabled_outgoing_fields();
		}
		if ( 'incoming_metadata_fields' === $key ) {
			$map = [];
			// Read the operator from stored raw_data: the Incoming_Field constructor does not
			// apply it to the typed property, and some integrations' configure_incoming_field()
			// is a no-op, so get_matching_function() alone would return the default.
			foreach ( $this->get_enabled_incoming_fields() as $field ) {
				$raw                      = $field->get_raw_data();
				$map[ $field->get_key() ] = $raw['matching_function'] ?? $field->get_matching_function();
			}
			return $map;
		}

		$field = $this->get_settings_field_by_key( $key );
		if ( ! $field ) {
			return null;
		}
		$option_name = self::SETTINGS_OPTION_PREFIX . $this->id . '_' . $key;
		$value       = \get_option( $option_name, null );

		if ( null !== $value ) {
			return $value;
		}

		// Account-deletion settings derive from the single legacy `sync_esp_delete`
		// boolean with per-field logic, so they can't use the straight value-copy map.
		if ( 'sync_account_deletion' === $key || 'account_deletion_handling' === $key ) {
			$migrated = $this->migrate_account_deletion_setting( $key, $option_name );
			return null !== $migrated ? $migrated : ( $field['default'] ?? '' );
		}

		// Attempt to migrate old setting if the field is found in the key map.
		if ( isset( self::$legacy_option_map[ $key ] ) ) {
			// Lazy migrate from legacy option.
			$legacy_value = \get_option( self::$legacy_option_map[ $key ], null );
			if ( null !== $legacy_value ) {
				// update option directly to avoid infinite loop.
				\update_option( $option_name, $legacy_value, false );
				return $legacy_value;
			}
		}
		return $field['default'] ?? '';
	}

	/**
	 * Lazily migrate an account-deletion setting from the legacy `sync_esp_delete` option.
	 *
	 * The legacy flag was effectively three-way in behavior:
	 *   - `true`  → hard-delete the contact from the ESP.
	 *   - `false` → keep the contact but remove it from every list (still a deletion signal).
	 * Because *both* states propagated a deletion, a migrated site keeps deletion sync
	 * enabled (`sync_account_deletion = true`) regardless of the legacy value; the legacy
	 * boolean only selects the handling mode: `true → delete`, `false → flag`. Mapping
	 * legacy `false` to `flag` (rather than disabling sync) preserves the old
	 * "don't hard-delete, but still signal the deletion" posture for opted-out sites.
	 *
	 * The `delete` target is additionally gated on `supports_hard_delete()` — mirroring
	 * the field default in get_account_deletion_fields() — so a legacy `true` value never
	 * migrates an integration that can't hard-delete into a mode that would just return
	 * `not_implemented` on every deletion. Such integrations fall through to `flag`.
	 *
	 * Returns null when the legacy option was never set, so the caller falls back to the
	 * field default. The derived value is persisted so this runs once, not on every read.
	 *
	 * @param string $key         The account-deletion field key.
	 * @param string $option_name The option name to persist the migrated value to.
	 * @return mixed|null The migrated value, or null if there is no legacy option to migrate.
	 */
	private function migrate_account_deletion_setting( $key, $option_name ) {
		$legacy_value = \get_option( self::LEGACY_SYNC_DELETE_OPTION, null );
		if ( null === $legacy_value ) {
			return null;
		}
		$migrated = 'sync_account_deletion' === $key
			? true
			: ( \wp_validate_boolean( $legacy_value ) && $this->supports_hard_delete() ? 'delete' : 'flag' );
		// Persist directly to avoid re-running the migration on every read.
		\update_option( $option_name, $migrated );
		return $migrated;
	}

	/**
	 * Update the value of a settings field.
	 *
	 * @param string $key   The field key.
	 * @param mixed  $value The new value.
	 * @return bool True if updated, false otherwise.
	 */
	public function update_settings_field_value( $key, $value ) {
		$field = $this->get_settings_field_by_key( $key );
		if ( ! $field ) {
			return false;
		}
		$sanitized = $this->sanitize_settings_field_value( $field, $value );

		// Route metadata fields to their dedicated setters.
		if ( 'metadata_prefix' === $key ) {
			return $this->update_metadata_prefix( $sanitized );
		}
		if ( 'outgoing_metadata_fields' === $key ) {
			return $this->update_enabled_outgoing_fields( $sanitized );
		}
		if ( 'incoming_metadata_fields' === $key ) {
			return $this->update_enabled_incoming_fields( $sanitized );
		}

		$option_name = self::SETTINGS_OPTION_PREFIX . $this->id . '_' . $key;
		// WP's update_option() short-circuits when the new value equals the implicit
		// missing-option default of false. For a checkbox like sync_account_deletion
		// (default `true`), that means unchecking it on a fresh site never persists —
		// the option is never created, and the next read falls through to the
		// declared `true` default. Detect a missing option via a null sentinel and
		// create it with add_option in that case. Either way, keep these
		// per-integration settings out of the autoload cache (they aren't needed on
		// every request).
		if ( null === \get_option( $option_name, null ) ) {
			return \add_option( $option_name, $sanitized, '', false );
		}
		return \update_option( $option_name, $sanitized, false );
	}

	/**
	 * Get settings config with current values populated, for API responses.
	 *
	 * Child classes can override this method to return filtered or enriched settings.
	 *
	 * @return array Array of field declarations with current values.
	 */
	public function get_settings_config() {
		$fields = $this->get_settings_fields();
		$config = [];
		foreach ( $fields as $field ) {
			$field['value'] = $this->get_settings_field_value( $field['key'] );
			// Inject metadata options for metadata fields.
			if ( 'incoming_metadata_fields' === $field['key'] ) {
				$incoming_fields  = $this->get_filtered_incoming_fields();
				$field['options'] = array_map(
					function ( $incoming_field ) {
						$key     = $incoming_field->get_key();
						$name    = $incoming_field->get_name();
						$options = $incoming_field->get_options();
						return [
							'value'             => $key,
							'label'             => '' !== $name ? $name : $key,
							'value_type'        => $incoming_field->get_value_type(),
							'matching_function' => $incoming_field->get_matching_function(),
							'has_options'       => ! empty( $options ),
						];
					},
					is_wp_error( $incoming_fields ) ? [] : $incoming_fields
				);
			}
			if ( 'outgoing_metadata_fields' === $field['key'] ) {
				// TODO: Drop $field['options'] for outgoing_metadata_fields once consumers have migrated to grouped_options.
				$field['options']         = Sync\Metadata::get_default_fields();
				$field['grouped_options'] = Sync\Metadata::get_grouped_default_fields();
			}
			$config[] = $field;
		}
		return $config;
	}

	/**
	 * Get a settings field declaration by key.
	 *
	 * @param string $key The field key.
	 * @return array|null The field declaration or null if not found.
	 */
	private function get_settings_field_by_key( $key ) {
		foreach ( $this->get_settings_fields() as $field ) {
			if ( $field['key'] === $key ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Sanitize a settings field value based on its type.
	 *
	 * @param array $field The field declaration.
	 * @param mixed $value The value to sanitize.
	 * @return mixed The sanitized value.
	 */
	protected function sanitize_settings_field_value( $field, $value ) {
		$type = $field['type'] ?? 'text';
		switch ( $type ) {
			case 'hidden':
			case 'oauth':
				// Server-managed types. Admin POSTs to the settings REST endpoint
				// are filtered out upstream in Integrations::update_integration_settings(),
				// so values land here only via trusted PHP writers (e.g., an OAuth
				// callback). Assumes a single-line, tag-free scalar — sanitize_text_field
				// will strip tags and collapse whitespace, which is fine for opaque
				// tokens but would silently corrupt a multiline secret. Non-scalar
				// payloads fall back to the declared default.
				if ( ! is_scalar( $value ) ) {
					return $field['default'] ?? '';
				}
				return \sanitize_text_field( (string) $value );
			case 'checkbox':
				return (bool) $value;
			case 'number':
				return is_numeric( $value ) ? $value + 0 : ( $field['default'] ?? 0 );
			case 'select':
				$valid_values = array_column( $field['options'] ?? [], 'value' );
				if ( empty( $valid_values ) ) {
					return \sanitize_text_field( $value );
				}
				return in_array( $value, $valid_values, true ) ? $value : ( $field['default'] ?? '' );
			case 'metadata':
				if ( ! is_array( $value ) ) {
					return $field['default'] ?? [];
				}
				// Incoming metadata fields carry a per-field operator: key => matching_function.
				if ( 'incoming_metadata_fields' === ( $field['key'] ?? '' ) ) {
					$sanitized = [];
					// PHP 8.0-safe array_is_list(): the array is a list iff re-indexing is a no-op.
					if ( $value === array_values( $value ) ) {
						// Legacy plain list of enabled keys: keep it a list so
						// update_enabled_incoming_fields() preserves each field's provider-default
						// matching_function (no forced 'default' override).
						foreach ( $value as $key ) {
							$key = \sanitize_text_field( (string) $key );
							if ( '' === $key ) {
								continue;
							}
							$sanitized[] = $key;
						}
					} else {
						foreach ( $value as $key => $operator ) {
							$key = \sanitize_text_field( (string) $key );
							if ( '' === $key ) {
								continue;
							}
							// An operator outside the allowlist maps to null (no override) rather than
							// 'default', which is itself a valid operator: coercing would silently
							// downgrade a typed field's provider default (e.g. list__in for a
							// multiselect) to exact match, which never matches such a field.
							$sanitized[ $key ] = ( is_string( $operator ) && in_array( $operator, self::ALLOWED_INCOMING_MATCHING_FUNCTIONS, true ) ) ? $operator : null;
						}
					}
					return $sanitized;
				}
				return array_values( array_map( 'sanitize_text_field', $value ) );
			case 'textarea':
				return \sanitize_textarea_field( $value );
			case 'text':
			case 'password':
			default:
				return \sanitize_text_field( $value );
		}
	}
}
