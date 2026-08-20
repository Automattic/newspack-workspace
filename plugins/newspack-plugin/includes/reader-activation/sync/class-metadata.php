<?php
/**
 * Reader Activation Sync Metadata.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync;

use Newspack\Donations;
use Newspack\Logger;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Metadata Class.
 */
class Metadata {

	const DATE_FORMAT   = 'Y-m-d H:i:s';
	const PREFIX        = 'NP_';
	const PREFIX_OPTION = '_newspack_metadata_prefix';

	/**
	 * The option name for choosing which metadata fields to sync.
	 *
	 * @var string
	 */
	const FIELDS_OPTION = '_newspack_metadata_fields';

	/**
	 * Metadata keys that carry sync-control semantics rather than field data.
	 * They pass through syncing unprefixed and are never subject to outbound
	 * field selection.
	 *
	 * @var string[]
	 */
	const SYNC_CONTROL_KEYS = [ 'status', 'status_if_new' ];

	/**
	 * Raw keys whose fields are emitted as a family of suffixed sub-keys
	 * (`<raw_key>_source`, `<raw_key>_medium`, …) rather than a single key.
	 * Only these keys get suffix-match semantics; every other field matches
	 * exactly, so a label that happens to prefix another can never carry that
	 * other field past the selection.
	 *
	 * The same fields carry `dynamic_suffix` in their class's
	 * get_fields_config(); this constant is the raw-key form the helpers here
	 * (get_utm_key()) work in.
	 *
	 * @var string[]
	 */
	const UTM_RAW_KEYS = [ 'signup_page_utm', 'payment_page_utm' ];

	/**
	 * Get the metadata classes to be used for syncing contact metadata to the ESP.
	 *
	 * All schema versions' classes are always registered; per-integration
	 * enabled fields decide what actually syncs.
	 *
	 * @return array List of metadata classes.
	 */
	protected static function get_metadata_classes() {
		$classes = [
			'Legacy_Basic',
			'Legacy_Payment',
			'Identity',
			'Registration',
			'Engagement',
			'Subscription',
			'Donation',
			'Content_Gate',
		];

		$classnames = [];
		foreach ( $classes as $class ) {
			$classname = __NAMESPACE__ . '\\Contact_Metadata\\' . $class;
			if ( class_exists( $classname ) ) {
				$classnames[] = $classname;
			}
		}
		return $classnames;
	}

	/**
	 * Get the metadata keys map for Reader Activation.
	 *
	 * @return array List of fields.
	 */
	public static function get_keys() {
		return self::get_all_fields( true );
	}

	/**
	 * Fetch the prefix for synced metadata fields.
	 * Default is NP_ but it can be configured in the Reader Activation settings page.
	 *
	 * This method is deprecated. Now, each integration has its own metadata prefix, which can be retrieved with Integration::get_metadata_prefix().
	 * As a fallback, this method returns the metadata prefix for the ESP Integration.
	 *
	 * It resolves more than the per-integration accessor does — the
	 * `newspack_ras_metadata_prefix` filter and the pre-init PREFIX_OPTION
	 * fallback — so a caller that needs the site-wide prefix rather than one
	 * integration's must keep using this.
	 *
	 * @deprecated Use Integration::get_metadata_prefix() instead.
	 *
	 * @return string
	 */
	public static function get_prefix() {
		$esp_integration = Integrations::get_integration( 'esp' );
		if ( $esp_integration ) {
			$prefix = $esp_integration->get_metadata_prefix();
			if ( ! empty( $prefix ) ) {
				/** This filter is documented below. */
				return apply_filters( 'newspack_ras_metadata_prefix', $prefix );
			}
		}

		// Fallback for edge case where integration isn't registered yet (before init priority 5).
		$prefix = \get_option( self::PREFIX_OPTION, self::PREFIX );

		// Guard against empty strings and falsy values.
		if ( empty( $prefix ) ) {
			return self::PREFIX;
		}

		/**
		 * Filters the string used to prefix custom fields synced.
		 *
		 * @param string $prefix Prefix to prepend the field name.
		 */
		return apply_filters( 'newspack_ras_metadata_prefix', $prefix );
	}

	/**
	 * Update the prefix for synced metadata fields.
	 *
	 * @param string $prefix Value to set.
	 *
	 * @return boolean True if updated, false otherwise.
	 */
	public static function update_prefix( $prefix ) {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->update_metadata_prefix( $prefix ) : false;
	}

	/**
	 * Get the list of possible fields to be synced.
	 *
	 * @return string[] List of fields.
	 */
	public static function get_default_fields() {
		return array_values( array_unique( array_values( self::get_keys() ) ) );
	}

	/**
	 * Get the default outgoing-field selection for a site that never saved one.
	 *
	 * Scoped to the schema era the site comes from, derived fresh on every
	 * read and never persisted: a wrong guess can't be frozen, and fields
	 * whose class becomes available later (e.g. WooCommerce activated) join
	 * on the next read. The full catalog stays get_default_fields() — this
	 * is only the effective default *selection*.
	 *
	 * @return string[] List of field names.
	 */
	public static function get_default_enabled_fields() {
		$era     = self::detect_default_schema_era();
		$catalog = self::get_keys();
		$names   = [];

		$era_classes = 'v1' === $era
			? [ Contact_Metadata\Legacy_Basic::class, Contact_Metadata\Legacy_Payment::class ]
			: [ Contact_Metadata\Identity::class, Contact_Metadata\Registration::class, Contact_Metadata\Engagement::class, Contact_Metadata\Subscription::class, Contact_Metadata\Donation::class ];
		$era_classes[] = Contact_Metadata\Content_Gate::class;

		$class_raw_keys = [];
		foreach ( $era_classes as $class ) {
			if ( ! class_exists( $class ) || ! $class::is_available() ) {
				continue;
			}
			foreach ( array_keys( $class::get_fields() ) as $raw_key ) {
				$class_raw_keys[ $raw_key ] = true;
				if ( isset( $catalog[ $raw_key ] ) ) {
					$names[] = $catalog[ $raw_key ];
				}
			}
		}

		// Filter-added extras (raw keys belonging to no era class) are the
		// site's own custom fields: era-neutral, so always part of defaults.
		$all_class_raw_keys = [];
		foreach ( self::get_metadata_classes() as $class ) {
			foreach ( array_keys( $class::get_fields() ) as $raw_key ) {
				$all_class_raw_keys[ $raw_key ] = true;
			}
		}
		foreach ( $catalog as $raw_key => $name ) {
			if ( ! isset( $all_class_raw_keys[ $raw_key ] ) ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Which schema era supplies a never-configured site's default selection.
	 *
	 * Evidence order: dev/QA constant override; the legacy global fields
	 * option (predates per-integration selection); a set-up ESP with no
	 * stored selection (a legacy site on dynamic defaults, not a fresh
	 * install). Anything else is a fresh install on the new schema.
	 *
	 * @return string 'v1' or 'v2'.
	 */
	private static function detect_default_schema_era() {
		if ( defined( 'NEWSPACK_SYNC_METADATA_VERSION' ) ) {
			return 'legacy' === NEWSPACK_SYNC_METADATA_VERSION ? 'v1' : 'v2';
		}
		if ( defined( 'NEWSPACK_SYNC_METADATA_VERSION_1' ) && NEWSPACK_SYNC_METADATA_VERSION_1 ) {
			return 'v2';
		}
		if ( false !== \get_option( self::FIELDS_OPTION, false ) ) {
			return 'v1';
		}
		$esp = Integrations::get_integration( 'esp' );
		if ( ! $esp && class_exists( \Newspack\Reader_Activation\Integrations\ESP::class ) ) {
			$esp = new \Newspack\Reader_Activation\Integrations\ESP();
		}
		if ( $esp && $esp->is_set_up() ) {
			return 'v1';
		}
		return 'v2';
	}

	/**
	 * Get payment-related metadata fields (v1 map).
	 *
	 * Used by the WooCommerce sync class when clearing payment fields.
	 *
	 * @return array List of fields.
	 */
	public static function get_payment_fields() {
		return Contact_Metadata\Legacy_Payment::get_fields();
	}

	/**
	 * Get the UTM key from a raw or prefixed key.
	 *
	 * Validates the UTM family against the fields enabled for the ESP
	 * integration and returns the prefixed, suffixed ESP name (e.g.
	 * "NP_Signup UTM: source"), or false.
	 *
	 * Only the legacy schema declares these dynamic-suffix fields; the new
	 * schema splits them into discrete source/medium/campaign fields.
	 *
	 * @param string $key Key to check.
	 *
	 * @return string|false
	 */
	public static function get_utm_key( $key ) {
		$utm_keys = self::UTM_RAW_KEYS;
		$raw_keys = self::get_raw_keys();
		foreach ( $utm_keys as $utm_key ) {
			if ( ! in_array( $utm_key, $raw_keys, true ) ) {
				continue;
			}
			$prefixed_key = self::get_key( $utm_key );
			if ( 0 === strpos( $key, $utm_key ) ) {
				$suffix = str_replace( $utm_key . '_', '', $key );
				return ! empty( trim( $suffix ) ) && $suffix !== $key ? $prefixed_key . $suffix : false;
			}
			if ( 0 === strpos( $key, $prefixed_key ) && $key !== $prefixed_key ) {
				return $key;
			}
		}
		return false;
	}

	/**
	 * Get the list of fields to be synced.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields, which can be retrieved with Integration::get_enabled_outgoing_fields().
	 * As a fallback, this method returns the fields enabled for the ESP Integration.
	 *
	 * This is the single definition of "the ESP's effective selection" — the set
	 * every other integration inherits until it saves one of its own (see
	 * Integration::get_inherited_outgoing_fields()). The registry-miss chain
	 * below mirrors Esp::get_enabled_outgoing_fields() minus that method's
	 * lazy-migration write, which stays on the ESP integration itself.
	 *
	 * @deprecated Use Integration::get_enabled_outgoing_fields() instead.
	 * @return string[] List of fields to be synced.
	 */
	public static function get_fields() {
		$esp_integration = Integrations::get_integration( 'esp' );
		if ( $esp_integration ) {
			return $esp_integration->get_enabled_outgoing_fields();
		}

		// Registry miss (pre-init, or a directly constructed integration —
		// integrations register on init priority 5). Fall back to the legacy
		// global option and then the era-scoped default selection, rather than
		// failing closed to an empty selection, which would strip every field
		// where the pre-selection behavior was full passthrough.
		$legacy = \get_option( self::FIELDS_OPTION, null );
		if ( null !== $legacy && is_array( $legacy ) ) {
			return array_values( $legacy );
		}

		return self::get_default_enabled_fields();
	}

	/**
	 * Get enabled fields which match provided keys.
	 * Will return key-value pairs of enabled fields which match the keys provided.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields.
	 * As a fallback, this method delegates to the ESP Integration.
	 *
	 * @deprecated Use Integration::filter_enabled_outgoing_fields() instead.
	 * @param string[] $keys Array of keys to match.
	 */
	public static function filter_enabled_fields( $keys ) {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->filter_enabled_outgoing_fields( $keys ) : [];
	}

	/**
	 * Update the list of fields to be synced.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields.
	 * As a fallback, this method will update the fields enabled for the ESP Integration.
	 *
	 * @param array $fields List of fields to sync.
	 *
	 * @deprecated Use Integration::update_enabled_outgoing_fields() instead.
	 * @return boolean True if updated, false otherwise.
	 */
	public static function update_fields( $fields ) {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->update_enabled_outgoing_fields( $fields ) : false;
	}

	/**
	 * Get the "raw" unprefixed metadata keys. Only return fields selected to sync.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields.
	 * As a fallback, this method delegates to the ESP Integration.
	 *
	 * @deprecated Use Integration::get_enabled_outgoing_fields_keys() instead.
	 * @return string[] List of raw metadata keys.
	 */
	public static function get_raw_keys() {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->get_enabled_outgoing_fields_keys() : [];
	}

	/**
	 * Get the "prefixed" metadata keys. Only return fields selected to sync.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields.
	 * As a fallback, this method delegates to the ESP Integration.
	 *
	 * @deprecated Use Integration::get_enabled_outgoing_fields_keys() instead.
	 * @return string[] List of prefixed metadata keys.
	 */
	public static function get_prefixed_keys() {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->get_enabled_outgoing_fields_keys( true ) : [];
	}

	/**
	 * Get all "prefixed" metadata keys.
	 *
	 * @return string[] List of prefixed metadata keys.
	 */
	public static function get_all_prefixed_keys() {
		$prefixed_keys = [];

		foreach ( self::get_keys() as $raw_key => $field_name ) {
			$prefixed_keys[] = self::get_key( $raw_key );
		}

		return array_unique( $prefixed_keys );
	}

	/**
	 * Given a field name, prepend it with the metadata field prefix.
	 *
	 * @param string $key Metadata field to fetch.
	 *
	 * @return string Prefixed field name.
	 */
	public static function get_key( $key ) {
		if ( ! isset( self::get_keys()[ $key ] ) ) {
			return false;
		}

		$prefix = self::get_prefix();
		$name   = self::get_keys()[ $key ];
		$key    = $prefix . $name;

		/**
		 * Filters the full, prefixed field name of each custom field synced to the ESP.
		 *
		 * @param string $key Full, prefixed key.
		 * @param string $prefix The prefix part of the key.
		 * @param string $name The unprefixed part of the key.
		 */
		return apply_filters( 'newspack_ras_metadata_key', $key, $prefix, $name );
	}

	/**
	 * Get the list of possible fields to be synced, grouped by section.
	 *
	 * Returns an array of groups, each with a 'section' label and 'fields' array.
	 * Only includes classes with a section name. Each class's fields are resolved
	 * from the filtered map by its own raw keys, not by label text — labels can
	 * collide across schemas (legacy `account` and new `Account` are both
	 * "Account"), so matching by label would keep a class alive on a sibling
	 * class's field. Fields added by the `newspack_ras_metadata_keys` filter that
	 * don't belong to any class are collected in an "Additional" group.
	 *
	 * @return array<int, array{section: string, fields: list<string>}> List of
	 *   groups, each with a non-empty section label and an ordered list of field
	 *   names. May be filtered by `newspack_ras_grouped_metadata_fields`.
	 */
	public static function get_grouped_default_fields(): array {
		$classes          = self::get_metadata_classes();
		$label_map        = self::get_all_fields( true );
		$available_fields = array_values( array_unique( array_values( $label_map ) ) );
		$groups           = [];
		$grouped_fields   = [];

		foreach ( $classes as $class ) {
			if ( $class::is_available() ) {
				$section = $class::get_section_name();
				if ( empty( $section ) ) {
					continue;
				}

				$fields = [];
				foreach ( array_keys( $class::get_fields() ) as $raw_key ) {
					if ( isset( $label_map[ $raw_key ] ) ) {
						$fields[] = $label_map[ $raw_key ];
					}
				}
				$fields = array_values( array_unique( $fields ) );

				if ( empty( $fields ) ) {
					continue;
				}

				$groups[]       = [
					'section' => $section,
					'fields'  => $fields,
				];
				$grouped_fields = array_merge( $grouped_fields, $fields );
			}
		}

		$ungrouped_fields = array_values( array_diff( $available_fields, array_unique( $grouped_fields ) ) );
		if ( ! empty( $ungrouped_fields ) ) {
			$groups[] = [
				'section' => __( 'Additional', 'newspack-plugin' ),
				'fields'  => $ungrouped_fields,
			];
		}

		/**
		 * Filters the list of possible metadata fields to be synced, grouped by section.
		 *
		 * @param array[]  $groups           Array of [ 'section' => string, 'fields' => string[] ].
		 * @param string[] $available_fields Flat list of filtered available metadata field names.
		 */
		return \apply_filters( 'newspack_ras_grouped_metadata_fields', $groups, $available_fields );
	}

	/**
	 * Get all metadata fields.
	 *
	 * The merged, all-versions raw_key => label map — a plain superset, since
	 * both schemas' raw keys and ESP names are distinct.
	 *
	 * @param boolean $only_available Whether to return only available fields or all fields.
	 * @return array List of fields.
	 */
	public static function get_all_fields( $only_available = false ) {
		$classes = self::get_metadata_classes();
		$keys    = [];
		foreach ( $classes as $class ) {
			if ( ! $only_available || $class::is_available() ) {
				$fields = $class::get_fields();
				$keys = array_merge( $keys, $fields );
			}
		}
		/**
		 * Filters the list of key/value pairs for metadata fields to be synced to the connected ESP.
		 *
		 * @param array $keys The list of key/value pairs for metadata fields to be synced to the connected ESP.
		 * @param boolean $only_available Whether the list of fields is filtered to only available fields or not.
		 */
		return \apply_filters( 'newspack_ras_metadata_keys', $keys, $only_available );
	}



	/**
	 * Resolve a list of user-supplied field tokens (raw keys or display labels,
	 * any case) to their canonical display labels.
	 *
	 * The label is the canonical unit for field scoping because the final ESP
	 * key is always `prefix + label` in both metadata modes, and because
	 * synonymous raw keys (e.g. `registration_page` / `current_page_url`) share
	 * one label — resolving to labels dedupes them.
	 *
	 * @param string[] $inputs Field tokens to resolve.
	 *
	 * @return string[]|\WP_Error Ordered, de-duplicated labels, or a WP_Error for
	 *                            the first token that is unknown
	 *                            (`newspack_esp_sync_unknown_field`) or known but
	 *                            currently unavailable
	 *                            (`newspack_esp_sync_unavailable_field`).
	 */
	public static function resolve_field_labels( array $inputs ): array|\WP_Error {
		$available_fields = self::get_all_fields( true );
		$all_fields       = self::get_all_fields( false );
		$available_lookup = self::build_field_lookup( $available_fields );
		$all_lookup       = self::build_field_lookup( $all_fields );

		$labels = [];
		foreach ( $inputs as $input ) {
			$raw_key = trim( (string) $input );
			$token   = strtolower( $raw_key );
			if ( '' === $token ) {
				continue;
			}

			// Exact-case raw key first. No raw key differs from another only by
			// case while carrying a different label anymore — the four v2 fields
			// that once did (payment page, total paid, and the two subscription
			// payment fields) now have their own distinct raw keys. The only
			// remaining case-only pairs are the value-equivalent ones (e.g.
			// `account` / `Account`), which share one label, so a case-insensitive
			// match can't land on the wrong field there. Kept as a cheap guard
			// against a future field reintroducing a same-case, different-label
			// collision.
			$label = $available_fields[ $raw_key ] ?? $available_lookup[ $token ] ?? null;
			if ( null !== $label ) {
				if ( ! in_array( $label, $labels, true ) ) {
					$labels[] = $label;
				}
				continue;
			}

			// Known field, but its metadata class is not currently available. Give a
			// targeted hint for the two common causes.
			if ( isset( $all_fields[ $raw_key ] ) || isset( $all_lookup[ $token ] ) ) {
				return new \WP_Error(
					'newspack_esp_sync_unavailable_field',
					sprintf(
						// Translators: %s is the field name supplied by the operator.
						__( 'The field "%s" is known but not currently available. Its metadata class may depend on a feature flag or plugin (for example, Content Access fields require the NEWSPACK_CONTENT_GATES feature flag; payment fields require WooCommerce).', 'newspack-plugin' ),
						$input
					)
				);
			}

			return new \WP_Error(
				'newspack_esp_sync_unknown_field',
				sprintf(
					// Translators: %s is the field name supplied by the operator.
					__( 'Unknown field "%s". Pass a raw field key or its display label.', 'newspack-plugin' ),
					$input
				)
			);
		}

		return $labels;
	}

	/**
	 * Build a case-insensitive lookup of both raw keys and labels to their
	 * canonical display label.
	 *
	 * The fallback pass, consulted only after an exact-case raw-key match
	 * fails: lowercasing collapses raw keys the two schemas distinguish by
	 * case, and last-write-wins hands those to the new schema.
	 *
	 * @param array $fields Map of raw_key => label.
	 *
	 * @return array<string, string> Lowercased raw-key or label => canonical label.
	 */
	private static function build_field_lookup( array $fields ): array {
		$lookup = [];
		foreach ( $fields as $raw_key => $label ) {
			// Trim the lookup keys to match resolve_field_labels()'s trimmed tokens,
			// so a label carrying trailing whitespace (the UTM labels, e.g.
			// "Signup UTM: ") still resolves when passed literally.
			$lookup[ strtolower( trim( (string) $raw_key ) ) ] = $label;
			$lookup[ strtolower( trim( (string) $label ) ) ]   = $label;
		}
		return $lookup;
	}

	/**
	 * Get a contact array with email and metadata for the given user, customer or order.
	 *
	 * @param \WP_User|\WC_Customer|\WC_Order|int $user_customer_or_order WP_User, WC_Customer, WC_Order object or ID.
	 * @param string[]|null                       $fields                 Optional. Canonical field labels to
	 *                                                                    restrict computation to. When provided,
	 *                                                                    metadata classes whose fields don't
	 *                                                                    intersect the list are skipped (avoiding
	 *                                                                    their queries). `null` computes every
	 *                                                                    available field (existing behavior).
	 *
	 * @return array Contact array with 'email' and 'metadata' keys.
	 */
	public static function get_contact_with_metadata( $user_customer_or_order, $fields = null ) {
		$core_contact = new Contact_Metadata\Core_Contact( $user_customer_or_order );
		$classes      = self::get_metadata_classes();
		$metadata     = [];

		if ( null === $fields ) {
			$fields = self::get_push_enabled_fields_union();
		}

		foreach ( $classes as $class ) {
			if ( ! $class::is_available() ) {
				continue;
			}
			if ( is_array( $fields ) && ! self::class_handles_any_field( $class, $fields ) ) {
				continue;
			}
			$instance = new $class( $user_customer_or_order );
			$metadata = array_merge( $metadata, $instance->get_metadata() );
		}

		$contact = [
			'email'    => $core_contact->get_email(),
			'metadata' => $metadata,
		];

		// Omit the key rather than sending an empty name: connectors branch on
		// isset(), so an empty string is written over whatever name the contact
		// already has at the provider — including one that arrived by list
		// import. Mirrors Sync\WooCommerce::get_contact_from_customer().
		$name = $core_contact->get_full_name();
		if ( '' !== $name ) {
			$contact['name'] = $name;
		}

		return self::normalize_contact_data( $contact );
	}

	/**
	 * The union of enabled outgoing field names across every push-enabled
	 * active integration — the compute-scoping list for a full sync.
	 *
	 * Scoped per class via class_handles_any_field(), this skips the
	 * (often WooCommerce-heavy) classes of a schema no integration pushes:
	 * a legacy site's selection holds no v2-only names, so the five new
	 * classes — and their order/subscription queries — never run. Null when
	 * the integrations registry is empty (pre-init callers compute
	 * everything); an empty union from all-empty selections legitimately
	 * computes nothing, since nothing would be pushed.
	 *
	 * @return string[]|null
	 */
	private static function get_push_enabled_fields_union() {
		$integrations = Integrations::get_active_configured_integrations();
		if ( empty( $integrations ) ) {
			return null;
		}
		$union = [];
		foreach ( $integrations as $integration ) {
			if ( ! $integration->is_push_enabled() ) {
				continue;
			}
			$union = array_merge( $union, $integration->get_enabled_outgoing_fields() );
		}
		return array_values( array_unique( $union ) );
	}

	/**
	 * Whether a metadata class computes any of the requested field labels.
	 *
	 * Special case: `Legacy_Basic::get_metadata()` populates ALL legacy fields —
	 * both its own basic fields and the payment/LTV fields declared by
	 * `Legacy_Payment` (which computes nothing itself). So `Legacy_Basic` must be
	 * matched against the full legacy field set, or requesting a payment field
	 * would silently skip the only class that produces its value.
	 *
	 * @param string   $class  Fully-qualified metadata class name.
	 * @param string[] $fields Requested field labels.
	 *
	 * @return bool
	 */
	private static function class_handles_any_field( $class, array $fields ): bool {
		if ( Contact_Metadata\Legacy_Basic::class === $class ) {
			$class_raw_keys = array_keys( array_merge( Contact_Metadata\Legacy_Basic::get_fields(), Contact_Metadata\Legacy_Payment::get_fields() ) );
		} else {
			$class_raw_keys = array_keys( $class::get_fields() );
		}

		// Resolve the class's raw keys to their canonical labels through the same
		// filtered map (`get_all_fields()`) that resolve_field_labels() and the CLI
		// pre-flight use, so a site that renames a label via the
		// `newspack_ras_metadata_keys` filter stays consistent across resolution and
		// this compute-side skip check.
		$label_map    = self::get_all_fields();
		$class_labels = [];
		foreach ( $class_raw_keys as $raw_key ) {
			if ( isset( $label_map[ $raw_key ] ) ) {
				$class_labels[] = $label_map[ $raw_key ];
			}
		}

		return ! empty( array_intersect( $fields, $class_labels ) );
	}

	/**
	 * Check if a metadata key exists in the given metadata.
	 *
	 * This method checks for both raw and prefixed keys.
	 *
	 * @param string $key      Metadata key to check.
	 * @param array  $metadata Metadata to check.
	 *
	 * @return boolean
	 */
	public static function has_key( $key, $metadata ) {
		return isset( $metadata[ $key ] ) || isset( $metadata[ self::get_key( $key ) ] );
	}

	/**
	 * Get a metadata key value from the given metadata.
	 *
	 * This method checks for both raw and prefixed keys.
	 *
	 * @param string $key      Metadata key to fetch.
	 * @param array  $metadata Metadata to fetch from.
	 *
	 * @return mixed|null Metadata value or null if not found.
	 */
	public static function get_key_value( $key, $metadata ) {
		if ( isset( $metadata[ $key ] ) ) {
			return $metadata[ $key ];
		}
		if ( isset( $metadata[ self::get_key( $key ) ] ) ) {
			return $metadata[ self::get_key( $key ) ];
		}
		return null;
	}

	/**
	 * Add user's registration-related data to the given metadata, as raw keys.
	 *
	 * Values are looked up from user meta when absent, never overwritten, and
	 * no prefixing or filtering is performed here.
	 *
	 * @param array $metadata Metadata to add to.
	 *
	 * @return array Metadata with registration data added.
	 */
	public static function add_registration_data_raw( $metadata ) {
		$user = self::has_key( 'account', $metadata ) ? \get_user_by( 'id', self::get_key_value( 'account', $metadata ) ) : false;
		if ( ! $user ) {
			return $metadata;
		}

		$registration_method = self::has_key( 'registration_method', $metadata ) ? self::get_key_value( 'registration_method', $metadata ) : \get_user_meta( $user->ID, Reader_Activation::REGISTRATION_METHOD, true );
		if ( ! empty( $registration_method ) ) {
			$metadata['registration_method'] = $registration_method;
		}

		$registration_page = self::has_key( 'registration_page', $metadata ) ? self::get_key_value( 'registration_page', $metadata ) : \get_user_meta( $user->ID, Reader_Activation::REGISTRATION_PAGE, true );
		if ( ! empty( $registration_page ) ) {
			$metadata['registration_page'] = $registration_page;
		}

		$connected_account = self::has_key( 'connected_account', $metadata ) ? self::get_key_value( 'connected_account', $metadata ) : \get_user_meta( $user->ID, Reader_Activation::CONNECTED_ACCOUNT, true );
		if ( ! empty( $connected_account ) && in_array( $connected_account, Reader_Activation::SSO_REGISTRATION_METHODS, true ) ) {
			$metadata['connected_account'] = $connected_account;
		} elseif ( ! empty( $registration_method ) && in_array( $registration_method, Reader_Activation::SSO_REGISTRATION_METHODS, true ) ) {
			$metadata['connected_account'] = $registration_method;
		}

		return $metadata;
	}

	/**
	 * Expand UTM parameters from page URLs into raw suffixed keys.
	 *
	 * Emits keys like `signup_page_utm_source` / `payment_page_utm_campaign`
	 * instead of prefixed ESP names. Existing values are never overwritten.
	 *
	 * @param array $metadata Metadata to add to.
	 *
	 * @return array Metadata with UTM fields added.
	 */
	public static function add_utm_data_raw( $metadata ) {
		$has_page = self::has_key( 'current_page_url', $metadata ) || self::has_key( 'registration_page', $metadata ) || self::has_key( 'payment_page', $metadata );
		if ( ! $has_page ) {
			return $metadata;
		}

		$payment_page = self::has_key( 'payment_page', $metadata ) ? self::get_key_value( 'payment_page', $metadata ) : false;
		if ( ! empty( $payment_page ) ) {
			$raw_url = $payment_page;
		} elseif ( self::has_key( 'current_page_url', $metadata ) ) {
			$raw_url = self::get_key_value( 'current_page_url', $metadata );
		} else {
			$raw_url = self::get_key_value( 'registration_page', $metadata );
		}

		$parsed_url = \wp_parse_url( $raw_url );
		if ( empty( $parsed_url['query'] ) ) {
			return $metadata;
		}

		$utm_key_prefix = ! empty( $payment_page ) ? 'payment_page_utm' : 'signup_page_utm';
		$params         = [];
		\wp_parse_str( $parsed_url['query'], $params );
		foreach ( $params as $param => $value ) {
			$param = \sanitize_text_field( $param );
			if ( 'utm' !== substr( $param, 0, 3 ) ) {
				continue;
			}
			$key = $utm_key_prefix . '_' . str_replace( 'utm_', '', $param );
			if ( empty( $metadata[ $key ] ) ) {
				$metadata[ $key ] = $value;
			}
		}

		return $metadata;
	}

	/**
	 * Normalizes contact metadata before syncing: raw-key enrichment only.
	 *
	 * Filtering and prefixing happen per integration in
	 * Integration::prepare_contact().
	 *
	 * @param array $contact Contact data.
	 * @return array Normalized contact data.
	 */
	public static function normalize_contact_data( $contact ) {
		if ( ! isset( $contact['metadata'] ) ) {
			$contact['metadata'] = [];
		}
		$contact['metadata'] = self::add_registration_data_raw( $contact['metadata'] );
		$contact['metadata'] = self::add_utm_data_raw( $contact['metadata'] );

		Logger::log( 'Normalizing contact data for reader ESP sync:' );
		Logger::log( $contact );

		/**
		 * Filters the normalized contact data before syncing to the ESP.
		 *
		 * Metadata is raw-keyed here (e.g. `account`) — prefixing happens later
		 * in Integration::prepare_contact(). Raw keys added here sync only if
		 * registered and enabled for the integration; already-prefixed keys
		 * pass through unless tied to a disabled registered field.
		 *
		 * @param array $contact Contact data.
		 */
		return apply_filters( 'newspack_esp_sync_normalize_contact', $contact );
	}
}
