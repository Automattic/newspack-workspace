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
	 * @var array<string, string>
	 */
	private static $legacy_option_map = [
		'mailchimp_audience_id'           => 'newspack_reader_activation_mailchimp_audience_id',
		'mailchimp_reader_default_status' => 'newspack_reader_activation_mailchimp_reader_default_status',
		'active_campaign_master_list'     => 'newspack_reader_activation_active_campaign_master_list',
		'constant_contact_list_id'        => 'newspack_reader_activation_constant_contact_list_id',
		'sync_esp_delete'                 => 'newspack_reader_activation_sync_esp_delete',
	];

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
	 * Generate the registration key for this integration.
	 *
	 * The default implementation uses HMAC-SHA256 with the site's auth salt.
	 * Subclasses can override this to implement custom key schemes
	 * (e.g., asymmetric key pairs, time-bounded tokens).
	 *
	 * @return string The registration key.
	 */
	public function get_registration_key(): string {
		return hash_hmac( 'sha256', $this->id, \wp_salt( 'auth' ) );
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
		return hash_equals( $this->get_registration_key(), $key );
	}

	/**
	 * Initialize the integration, performing any necessary setup or validation.
	 *
	 * Currently only initializes settings fields, but can be extended by child classes for additional setup.
	 */
	public function init() {
		$this->settings_fields = $this->register_settings_fields();
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
	 * @return string[] List of enabled field names.
	 */
	public function get_enabled_outgoing_fields() {
		return array_values( \get_option( self::OUTGOING_FIELDS_OPTION_PREFIX . $this->id, [] ) );
	}

	/**
	 * Update the enabled incoming fields for this integration.
	 *
	 * Accepts an array of field keys (as sent by the UI), fetches the full
	 * field data from the integration, and stores the matching raw field arrays.
	 *
	 * @param string[] $keys Array of field keys to enable.
	 *
	 * @return bool True if updated, false otherwise.
	 */
	public function update_enabled_incoming_fields( $keys ) {
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

		// Store as key => raw_data map.
		$fields_to_store = [];
		foreach ( $keys as $key ) {
			$raw_data = [];
			if ( isset( $available_by_key[ $key ] ) ) {
				$raw_data = $available_by_key[ $key ]->get_raw_data();
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
	 * Get the metadata fields declared by this integration.
	 *
	 * @return array Array of settings field declarations.
	 */
	public function get_metadata_fields() {
		return [
			[
				'key'         => 'metadata_prefix',
				'type'        => 'text',
				'label'       => __( 'Metadata field prefix', 'newspack-plugin' ),
				'description' => __( 'A string to prefix metadata fields synced to the integration. Required to ensure that metadata field names are unique. Default: NP_', 'newspack-plugin' ),
				'default'     => 'NP_',
			],
			[
				'key'     => 'outgoing_metadata_fields',
				'type'    => 'metadata',
				'label'   => __( 'Outgoing metadata fields', 'newspack-plugin' ),
				'default' => [],
			],
			[
				'key'     => 'incoming_metadata_fields',
				'type'    => 'metadata',
				'label'   => __( 'Incoming metadata fields', 'newspack-plugin' ),
				'default' => [],
			],
		];
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
	 * In legacy mode, metadata classes already return filtered and prefixed
	 * data, so the contact is returned unchanged.
	 *
	 * @param array $contact Contact data with raw metadata keys.
	 * @return array Contact data with filtered, prefixed metadata.
	 */
	public function prepare_contact( $contact ) {
		if ( 'legacy' === Sync\Metadata::get_version() ) {
			return $contact;
		}

		if ( empty( $contact['metadata'] ) ) {
			return $contact;
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
	 * @return array Array of settings field declarations.
	 */
	public function get_settings_fields() {
		return array_merge(
			$this->settings_fields,
			$this->get_metadata_fields()
		);
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
			return array_map(
				function( $field ) {
					return $field->get_key();
				},
				$this->get_enabled_incoming_fields()
			);
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
						$key  = $incoming_field->get_key();
						$name = $incoming_field->get_name();
						return [
							'value' => $key,
							'label' => '' !== $name ? $name : $key,
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
