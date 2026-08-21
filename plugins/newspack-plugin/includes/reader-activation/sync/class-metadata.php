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
	 * The option persisting which schema era supplies this site's default
	 * outgoing-field selection ('v1' or 'v2'). Stamped once — at activation
	 * for fresh installs, else on first read from era evidence — so the era
	 * can't flip when unrelated configuration (e.g. the ESP's master list)
	 * changes later, and so an operator can inspect or correct it.
	 *
	 * @var string
	 */
	const SCHEMA_ORIGIN_OPTION = 'newspack_sync_schema_origin';

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
	 * Beyond the ESP accessor, this also resolves the pre-init PREFIX_OPTION
	 * fallback — so a caller that needs the site-wide prefix before
	 * integrations register must keep using this. The ESP's own accessor
	 * applies the `newspack_ras_metadata_prefix` filter too, keeping the
	 * push and audit paths on the same prefix.
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
	 * Scoped to the schema era the site comes from (get_schema_origin(),
	 * stamped once and stored). The selection itself is still derived fresh
	 * on every read, so fields whose class becomes available later (e.g.
	 * WooCommerce activated) join on the next read. The full catalog stays
	 * get_default_fields() — this is only the effective default *selection*.
	 *
	 * @return string[] List of field names.
	 */
	public static function get_default_enabled_fields() {
		$era     = self::get_schema_origin();
		$catalog = self::get_keys();
		$names   = [];

		$era_classes = 'v1' === $era
			? [ Contact_Metadata\Legacy_Basic::class, Contact_Metadata\Legacy_Payment::class ]
			: [ Contact_Metadata\Identity::class, Contact_Metadata\Registration::class, Contact_Metadata\Engagement::class, Contact_Metadata\Subscription::class, Contact_Metadata\Donation::class ];
		$era_classes[] = Contact_Metadata\Content_Gate::class;

		foreach ( $era_classes as $class ) {
			if ( ! class_exists( $class ) || ! $class::is_available() ) {
				continue;
			}
			foreach ( array_keys( $class::get_fields() ) as $raw_key ) {
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
	 * Resolution order: dev/QA constant override (never persisted); the stored
	 * SCHEMA_ORIGIN_OPTION stamp; a one-time derivation from era evidence,
	 * persisted so the answer can't change when unrelated configuration does.
	 * Deriving per read looked self-healing but meant a site's payload shape
	 * followed its ESP setup state: configuring or unconfiguring the ESP could
	 * silently flip every push between schemas, with no record of when and no
	 * setting to correct it.
	 *
	 * @return string 'v1' or 'v2'.
	 */
	public static function get_schema_origin() {
		if ( defined( 'NEWSPACK_SYNC_METADATA_VERSION' ) ) {
			return 'legacy' === NEWSPACK_SYNC_METADATA_VERSION ? 'v1' : 'v2';
		}
		if ( defined( 'NEWSPACK_SYNC_METADATA_VERSION_1' ) && NEWSPACK_SYNC_METADATA_VERSION_1 ) {
			return 'v2';
		}
		$stored = \get_option( self::SCHEMA_ORIGIN_OPTION, false );
		if ( in_array( $stored, [ 'v1', 'v2' ], true ) ) {
			return $stored;
		}
		$era = self::derive_schema_origin();
		\update_option( self::SCHEMA_ORIGIN_OPTION, $era, true );
		return $era;
	}

	/**
	 * Derive the schema origin from era evidence, for the one-time stamp.
	 *
	 * Evidence of a legacy site, in order: the legacy global fields option
	 * (predates per-integration selection); a set-up ESP (a legacy site on
	 * dynamic defaults, not a fresh install); any other configured
	 * push-capable integration — e.g. a manager-supplied CRM or newsletter
	 * integration on a site that never configured the core ESP, whose field
	 * names the ESP-only check would flip on upgrade. All evidence is
	 * option-backed, so it survives plugin deactivation windows. Anything
	 * else is a fresh install on the new schema.
	 *
	 * @return string 'v1' or 'v2'.
	 */
	private static function derive_schema_origin() {
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
		foreach ( Integrations::get_active_configured_integrations() as $id => $integration ) {
			if ( 'esp' !== $id && $integration->supports_push() ) {
				return 'v1';
			}
		}
		return 'v2';
	}

	/**
	 * Stamp the schema origin at plugin activation, for fresh installs only.
	 *
	 * A fresh install must freeze 'v2' before the publisher configures an
	 * ESP — otherwise the first defaults read can land after ESP setup and
	 * stamp the legacy era onto a brand-new site. Sites that completed setup
	 * under an earlier version skip the stamp here (activation also fires on
	 * reactivation, where sibling plugins may be mid-toggle and the evidence
	 * incomplete) and stamp on first read instead, where the option-backed
	 * evidence is judged whole.
	 */
	public static function stamp_schema_origin_on_activation() {
		if ( false !== \get_option( self::SCHEMA_ORIGIN_OPTION, false ) ) {
			return;
		}
		if ( defined( 'NEWSPACK_SETUP_COMPLETE' ) && false !== \get_option( NEWSPACK_SETUP_COMPLETE, false ) ) {
			return;
		}
		\update_option( self::SCHEMA_ORIGIN_OPTION, self::derive_schema_origin(), true );
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
	 * Returns an array of groups, each with a 'section' label and 'fields'
	 * array, plus a 'field_details' map (display name => status/description)
	 * for whichever fields in the group have that data. Every class with a
	 * section name gets its own group, and filter-added fields that belong to
	 * no class land in "Additional". Adjacent groups sharing a label are
	 * folded together, so the two legacy classes render as one "Legacy" panel
	 * rather than two identically-titled ones. A field name appearing in more
	 * than one group renders only in the first — value-equivalent pair names
	 * live in their modern group rather than repeating in Legacy, so one
	 * stored selection entry never renders as two checkboxes.
	 *
	 * All-legacy groups (every field 'legacy' in its class's own field config)
	 * sort last, checked via that status rather than the section label so it
	 * survives label translation or renaming.
	 *
	 * Each class's fields — and field_details — are resolved from the
	 * filtered map by its own raw keys, not by label text — labels can
	 * collide across schemas (legacy `account` and new `Account` are both
	 * "Account"), so matching by label would keep a class alive on a sibling
	 * class's field. When two raw keys in the same class share one label
	 * (legacy `registration_page` / `current_page_url` are both "Registration
	 * Page"), the first raw key encountered supplies that label's
	 * field_details.
	 *
	 * @return array<int, array{section: string, fields: list<string>, field_details?: array<string, array{status?: string, description?: string}>}>
	 *   List of groups, each with a non-empty section label and an ordered
	 *   list of field names, all-legacy groups sorted last. May be filtered
	 *   by `newspack_ras_grouped_metadata_fields`.
	 */
	public static function get_grouped_default_fields(): array {
		$classes          = self::get_metadata_classes();
		$label_map        = self::get_all_fields( true );
		$available_fields = array_values( array_unique( array_values( $label_map ) ) );
		$status_by_class  = self::get_status_by_class_and_raw_key();
		$primary_groups   = [];
		$legacy_groups    = [];
		$grouped_fields   = [];

		foreach ( $classes as $class ) {
			if ( $class::is_available() ) {
				$section = $class::get_section_name();
				if ( empty( $section ) ) {
					continue;
				}

				$field_config  = $class::get_fields_config();
				$fields        = [];
				$field_details = [];
				$all_legacy    = true;
				foreach ( array_keys( $class::get_fields() ) as $raw_key ) {
					if ( ! isset( $label_map[ $raw_key ] ) ) {
						continue;
					}
					$label    = $label_map[ $raw_key ];
					$fields[] = $label;
					if ( 'legacy' !== ( $status_by_class[ $class ][ $raw_key ] ?? null ) ) {
						$all_legacy = false;
					}
					// First raw key to reach a shared label wins its details (see
					// the docblock note on registration_page/current_page_url).
					if ( ! isset( $field_details[ $label ] ) ) {
						$details = self::build_field_details( $field_config[ $raw_key ] ?? [] );
						if ( ! empty( $details ) ) {
							$field_details[ $label ] = $details;
						}
					}
				}
				$fields = array_values( array_unique( $fields ) );

				if ( empty( $fields ) ) {
					continue;
				}

				$group = [
					'section' => $section,
					'fields'  => $fields,
				];
				if ( ! empty( $field_details ) ) {
					$group['field_details'] = $field_details;
				}
				if ( $all_legacy ) {
					$legacy_groups[] = $group;
				} else {
					$primary_groups[] = $group;
				}
				$grouped_fields = array_merge( $grouped_fields, $fields );
			}
		}

		$ungrouped_fields = array_values( array_diff( $available_fields, array_unique( $grouped_fields ) ) );
		if ( ! empty( $ungrouped_fields ) ) {
			$primary_groups[] = [
				'section' => __( 'Additional', 'newspack-plugin' ),
				'fields'  => $ungrouped_fields,
			];
		}

		$groups = self::dedupe_group_fields( self::merge_adjacent_groups( array_merge( $primary_groups, $legacy_groups ) ) );

		/**
		 * Filters the list of possible metadata fields to be synced, grouped by section.
		 *
		 * @param array[]  $groups           Array of [ 'section' => string, 'fields' => string[], 'field_details' => array ].
		 * @param string[] $available_fields Flat list of filtered available metadata field names.
		 */
		return \apply_filters( 'newspack_ras_grouped_metadata_fields', $groups, $available_fields );
	}

	/**
	 * Build the { status, description } detail pair for one field's config
	 * entry, as found in a class's get_fields_config().
	 *
	 * The description key is omitted rather than set to an empty string when
	 * the field declares none, so the frontend can treat "no description" as
	 * a simple absent-key check.
	 *
	 * @param array $config One field's entry from get_fields_config(), or [].
	 *
	 * @return array{status?: string, description?: string} Empty when the
	 *   config carries neither a status nor a description.
	 */
	private static function build_field_details( array $config ): array {
		$details = [];
		if ( isset( $config['status'] ) ) {
			$details['status'] = $config['status'];
		}
		if ( ! empty( $config['description'] ) ) {
			$details['description'] = $config['description'];
		}
		return $details;
	}

	/**
	 * Fold neighbouring groups that carry the same section label into one.
	 *
	 * Both legacy classes declare the "Legacy" section, so a WooCommerce site
	 * would otherwise render two adjacent panels with the same title and no
	 * way to tell them apart. Only ADJACENT groups merge, so the sort order
	 * (all-legacy groups last) still means what it says.
	 *
	 * @param array<int, array{section: string, fields: list<string>, field_details?: array}> $groups Ordered groups.
	 *
	 * @return array<int, array{section: string, fields: list<string>, field_details?: array}>
	 */
	private static function merge_adjacent_groups( array $groups ): array {
		$merged = [];
		foreach ( $groups as $group ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $merged[ $last ]['section'] === $group['section'] ) {
				$merged[ $last ]['fields'] = array_values( array_unique( array_merge( $merged[ $last ]['fields'], $group['fields'] ) ) );
				if ( ! empty( $group['field_details'] ) ) {
					// `+` keeps the earlier group's entry on a label collision,
					// the same first-wins rule used within a single group.
					$merged[ $last ]['field_details'] = ( $merged[ $last ]['field_details'] ?? [] ) + $group['field_details'];
				}
				continue;
			}
			$merged[] = $group;
		}
		return $merged;
	}

	/**
	 * Drop repeat appearances of a field name across groups, keeping the first.
	 *
	 * Value-equivalent pair names ('Account', 'Connected Account',
	 * 'Registration Date', 'Registration Page') are declared by a class in
	 * each schema, so they would render twice — once in their modern group
	 * and once in the Legacy panel — as two checkboxes bound to one stored
	 * selection entry, each with its own help text. One field, one control:
	 * the first appearance stays (the modern one, since all-legacy groups
	 * sort last), and a group left with no fields is dropped.
	 *
	 * @param array<int, array{section: string, fields: list<string>, field_details?: array}> $groups Ordered groups.
	 *
	 * @return array<int, array{section: string, fields: list<string>, field_details?: array}>
	 */
	private static function dedupe_group_fields( array $groups ): array {
		$seen    = [];
		$deduped = [];
		foreach ( $groups as $group ) {
			$fields = [];
			foreach ( $group['fields'] as $field ) {
				if ( isset( $seen[ $field ] ) ) {
					unset( $group['field_details'][ $field ] );
					continue;
				}
				$seen[ $field ] = true;
				$fields[]       = $field;
			}
			if ( empty( $fields ) ) {
				continue;
			}
			$group['fields'] = $fields;
			if ( isset( $group['field_details'] ) && empty( $group['field_details'] ) ) {
				unset( $group['field_details'] );
			}
			$deduped[] = $group;
		}
		return $deduped;
	}

	/**
	 * Map of metadata class => raw key => field status, from each class's
	 * own config. Backs the all-legacy group check in
	 * get_grouped_default_fields(), so legacy-ness survives section-label
	 * translation or renaming.
	 *
	 * @return array<string, array<string, string|null>>
	 */
	private static function get_status_by_class_and_raw_key() {
		$map = [];
		foreach ( self::get_metadata_classes() as $class ) {
			foreach ( $class::get_fields_config() as $raw_key => $config ) {
				$map[ $class ][ $raw_key ] = $config['status'] ?? null;
			}
		}
		return $map;
	}

	/**
	 * Memo for get_all_fields(), keyed by variant — 'all', or 'available'
	 * plus the currently-available class list, so a mid-process availability
	 * change (a plugin activating, content gating toggling on) computes a
	 * fresh entry rather than serving a stale one.
	 *
	 * The merged catalog is read several times per pushed contact (compute
	 * scoping, prepare_contact() per integration), and each rebuild re-fires
	 * the public `newspack_ras_metadata_keys` filter — a per-sync hot path
	 * on bulk backfills. The availability probe kept in the key is cheap
	 * (class_exists plus option reads); the eight-class merge and the filter
	 * dispatch are what this memo avoids.
	 *
	 * @var array<string, array>
	 */
	private static $all_fields_cache = [];

	/**
	 * Flush the catalog memo — for tests that register catalog filters
	 * mid-process, where the hooks change but statics survive.
	 */
	public static function flush_fields_cache() {
		self::$all_fields_cache = [];
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
		if ( $only_available ) {
			$classes   = array_values(
				array_filter(
					$classes,
					static function ( $class ) {
						return $class::is_available();
					}
				)
			);
			$cache_key = 'available:' . implode( ',', $classes );
		} else {
			$cache_key = 'all';
		}
		if ( isset( self::$all_fields_cache[ $cache_key ] ) ) {
			return self::$all_fields_cache[ $cache_key ];
		}
		$keys = [];
		foreach ( $classes as $class ) {
			$fields = $class::get_fields();
			$keys   = array_merge( $keys, $fields );
		}
		/**
		 * Filters the list of key/value pairs for metadata fields to be synced to the connected ESP.
		 *
		 * @param array $keys The list of key/value pairs for metadata fields to be synced to the connected ESP.
		 * @param boolean $only_available Whether the list of fields is filtered to only available fields or not.
		 */
		self::$all_fields_cache[ $cache_key ] = \apply_filters( 'newspack_ras_metadata_keys', $keys, $only_available );
		return self::$all_fields_cache[ $cache_key ];
	}

	/**
	 * Resolve a list of user-supplied field tokens (raw keys or display labels,
	 * any case) to their canonical display labels.
	 *
	 * The label is the canonical unit for field scoping because the final ESP
	 * key is always `prefix + label`, and because synonymous raw keys (e.g.
	 * `registration_page` / `current_page_url`) share one label — resolving to
	 * labels dedupes them.
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
	 *                                                                    their queries). `null` derives the
	 *                                                                    scoping list from push-enabled
	 *                                                                    integrations' selections (see
	 *                                                                    get_push_enabled_fields_union()),
	 *                                                                    computing everything only when no
	 *                                                                    integrations are registered.
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

		$computing_classes = self::get_computing_classes( $fields );
		foreach ( $classes as $class ) {
			if ( ! in_array( $class, $computing_classes, true ) ) {
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
	 * Scoped per class via get_computing_classes(), this skips the (often
	 * WooCommerce-heavy) classes no requested label is claimed from: on a
	 * new-schema site Legacy_Basic never runs, and on a legacy site the
	 * Engagement/Subscription/Donation classes — and their
	 * order/subscription queries — never run (the cheap Identity and
	 * Registration classes do run there, claiming the value-equivalent pair
	 * labels). Null when the integrations registry is empty (pre-init
	 * callers compute everything); an empty union from all-empty selections
	 * legitimately computes nothing, since nothing would be pushed.
	 *
	 * Reading selections through the integration getters can fire the ESP's
	 * one-time lazy migration of the legacy global option — a deliberate,
	 * idempotent write that any read of the ESP's selection performs, not
	 * something this helper adds.
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
	 * The metadata classes to run for a requested field-label list.
	 *
	 * Each requested label is claimed by the first producing class in claim
	 * order — the non-legacy classes ahead of the legacy pair — and a class
	 * runs only when it claims at least one label. Both members of a
	 * value-equivalent pair (legacy `account`, new `Account`) produce the
	 * same value by contract, so the claim order routes pair labels to the
	 * modern producer and Legacy_Basic runs only for labels nothing else
	 * computes. Without the claiming, the pair names — present in both eras'
	 * default selections — kept Legacy_Basic running on every new-schema
	 * site, re-adding its WooCommerce order work and its first/last-name
	 * user-meta writes on paths that never needed them.
	 *
	 * Special case: `Legacy_Basic::get_metadata()` populates ALL legacy fields —
	 * both its own basic fields and the payment/LTV fields declared by
	 * `Legacy_Payment` (which computes nothing itself, and so never runs
	 * here). So `Legacy_Basic` claims across the full legacy field set, or
	 * requesting a payment field would silently skip the only class that
	 * produces its value.
	 *
	 * Labels resolve through the same filtered map (`get_all_fields()`) that
	 * resolve_field_labels() and the CLI pre-flight use, so a site that
	 * renames a label via the `newspack_ras_metadata_keys` filter stays
	 * consistent across resolution and this compute-side scoping.
	 *
	 * @param string[]|null $fields Requested field labels, or null to run
	 *                              every available class (pre-init callers).
	 *
	 * @return string[] Fully-qualified class names, in registration order.
	 */
	private static function get_computing_classes( $fields ): array {
		$available = [];
		foreach ( self::get_metadata_classes() as $class ) {
			if ( $class::is_available() ) {
				$available[] = $class;
			}
		}
		if ( ! is_array( $fields ) ) {
			return $available;
		}

		$legacy_classes = [ Contact_Metadata\Legacy_Basic::class, Contact_Metadata\Legacy_Payment::class ];
		$claim_order    = array_merge( array_diff( $available, $legacy_classes ), array_intersect( $available, $legacy_classes ) );
		$label_map      = self::get_all_fields();
		$unclaimed      = $fields;
		$computing      = [];

		foreach ( $claim_order as $class ) {
			if ( Contact_Metadata\Legacy_Payment::class === $class ) {
				continue;
			}
			if ( Contact_Metadata\Legacy_Basic::class === $class ) {
				$class_raw_keys = array_keys( array_merge( Contact_Metadata\Legacy_Basic::get_fields(), Contact_Metadata\Legacy_Payment::get_fields() ) );
			} else {
				$class_raw_keys = array_keys( $class::get_fields() );
			}
			$class_labels = [];
			foreach ( $class_raw_keys as $raw_key ) {
				if ( isset( $label_map[ $raw_key ] ) ) {
					$class_labels[] = $label_map[ $raw_key ];
				}
			}
			$claimed = array_intersect( $unclaimed, $class_labels );
			if ( ! empty( $claimed ) ) {
				$computing[] = $class;
				$unclaimed   = array_diff( $unclaimed, $claimed );
			}
		}

		return $computing;
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
