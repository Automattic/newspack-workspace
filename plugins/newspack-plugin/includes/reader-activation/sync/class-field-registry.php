<?php
/**
 * Reader Activation Sync Field Registry.
 *
 * Merged, version-qualified source of truth for all contact metadata
 * field definitions across the legacy (v1) and new (v2) schemas.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Field Registry Class.
 */
class Field_Registry {

	const VERSION_V1      = 'v1';
	const VERSION_V2      = 'v2';
	const VERSION_NEUTRAL = 'neutral';

	/**
	 * Cached definitions, keyed by id.
	 *
	 * @var array|null
	 */
	private static $definitions = null;

	/**
	 * Lazily-built index of ESP field name => list of definition ids, in
	 * definition order. Backs the name-resolution helpers so lookups don't
	 * scan the full definition set per call.
	 *
	 * @var array|null
	 */
	private static $name_index = null;

	/**
	 * Monotonic counter bumped on every reset(). Callers memoizing values
	 * derived from the registry include it in their cache keys so a reset
	 * (tests, filter changes) invalidates them.
	 *
	 * @var int
	 */
	private static $generation = 0;

	/**
	 * Lazily-built map of v1 definition id => v2 definition ids, for the
	 * conflict groups whose every v2 member declares itself value-equivalent
	 * to its v1 counterpart. Backs the storage-time id upgrade.
	 *
	 * @var array|null
	 */
	private static $equivalent_upgrades = null;

	/**
	 * Map of schema version to the metadata classes that own its fields.
	 *
	 * @return array
	 */
	private static function get_class_map() {
		return [
			self::VERSION_V1      => [
				Contact_Metadata\Legacy_Basic::class,
				Contact_Metadata\Legacy_Payment::class,
			],
			self::VERSION_V2      => [
				Contact_Metadata\Identity::class,
				Contact_Metadata\Registration::class,
				Contact_Metadata\Engagement::class,
				Contact_Metadata\Subscription::class,
				Contact_Metadata\Donation::class,
			],
			self::VERSION_NEUTRAL => [
				Contact_Metadata\Content_Gate::class,
			],
		];
	}

	/**
	 * Reset the static cache. Intended for tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$definitions         = null;
		self::$name_index          = null;
		self::$equivalent_upgrades = null;
		self::$generation++;
	}

	/**
	 * Get the registry generation, bumped on every reset().
	 *
	 * @return int
	 */
	public static function get_generation() {
		return self::$generation;
	}

	/**
	 * Get all field definitions, keyed by version-qualified id.
	 *
	 * @return array[]
	 */
	public static function get_definitions() {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}

		$definitions = [];
		$merged_map  = [];

		foreach ( self::get_class_map() as $version => $classes ) {
			foreach ( $classes as $class ) {
				if ( ! class_exists( $class ) ) {
					continue;
				}
				$section   = $class::get_section_name();
				$available = $class::is_available();
				foreach ( $class::get_fields_config() as $raw_key => $config ) {
					$id                     = $version . ':' . $raw_key;
					$definitions[ $id ]     = array_merge(
						[
							'id'             => $id,
							'version'        => $version,
							'raw_key'        => $raw_key,
							'name'           => $config['name'],
							'section'        => $section,
							'available'      => $available,
							'class'          => $class,
							'dynamic_suffix' => ! empty( $config['dynamic_suffix'] ),
						],
						$config
					);
					$merged_map[ $raw_key ] = $config['name'];
				}
			}
		}

		/** This filter is documented in includes/reader-activation/sync/class-metadata.php */
		$filtered_map = \apply_filters( 'newspack_ras_metadata_keys', $merged_map, false );

		// Drop definitions whose raw key was removed by the filter, and adopt
		// filter renames: a callback that relabels an existing raw key must
		// relabel the definition too, or name-based resolution (defaults,
		// migration, settings saves) would look for the filtered label and
		// find nothing — silently dropping the field from outgoing sync.
		foreach ( $definitions as $id => $definition ) {
			$raw_key = $definition['raw_key'];
			if ( ! isset( $filtered_map[ $raw_key ] ) ) {
				unset( $definitions[ $id ] );
				continue;
			}
			if (
				is_string( $filtered_map[ $raw_key ] )
				&& $definition['name'] === ( $merged_map[ $raw_key ] ?? null )
				&& $filtered_map[ $raw_key ] !== $definition['name']
			) {
				$definitions[ $id ]['name'] = $filtered_map[ $raw_key ];
			}
		}

		// Ingest filter-added extras as version-neutral definitions.
		foreach ( $filtered_map as $raw_key => $name ) {
			if ( isset( $merged_map[ $raw_key ] ) ) {
				continue;
			}
			$id                 = self::VERSION_NEUTRAL . ':' . $raw_key;
			$definitions[ $id ] = [
				'id'             => $id,
				'version'        => self::VERSION_NEUTRAL,
				'raw_key'        => $raw_key,
				'name'           => $name,
				'section'        => '',
				'available'      => true,
				'class'          => null,
				'dynamic_suffix' => false,
			];
		}

		// Derive reverse supersedes links.
		foreach ( $definitions as $id => $definition ) {
			if ( empty( $definition['supersedes'] ) ) {
				continue;
			}
			$target = $definition['supersedes'];
			if ( isset( $definitions[ $target ] ) ) {
				$definitions[ $target ]['superseded_by'][] = $id;
			}
		}

		self::$definitions = $definitions;
		return self::$definitions;
	}

	/**
	 * Get a single definition by id.
	 *
	 * @param string $id Version-qualified field id.
	 *
	 * @return array|null
	 */
	public static function get_definition( $id ) {
		$definitions = self::get_definitions();
		return $definitions[ $id ] ?? null;
	}

	/**
	 * Get a definition by ESP field name, optionally preferring a version.
	 *
	 * @param string      $name              ESP field name (unprefixed).
	 * @param string|null $preferred_version Version to prefer on name collisions.
	 *
	 * @return array|null
	 */
	public static function get_by_name( $name, $preferred_version = null ) {
		$definitions = self::get_definitions();
		$fallback    = null;
		foreach ( self::get_name_index()[ $name ] ?? [] as $id ) {
			$definition = $definitions[ $id ];
			if ( null === $preferred_version || $definition['version'] === $preferred_version ) {
				return $definition;
			}
			if ( null === $fallback ) {
				$fallback = $definition;
			}
		}
		return $fallback;
	}

	/**
	 * Get the lazily-built name index: ESP field name => definition ids.
	 *
	 * @return array
	 */
	private static function get_name_index() {
		if ( null !== self::$name_index ) {
			return self::$name_index;
		}
		$index = [];
		foreach ( self::get_definitions() as $id => $definition ) {
			$index[ $definition['name'] ][] = $id;
		}
		self::$name_index = $index;
		return self::$name_index;
	}

	/**
	 * Get every definition sharing an ESP field name, optionally restricted
	 * to one schema version.
	 *
	 * Needed because the legacy schema maps multiple raw keys to a single
	 * ESP name (registration_page and current_page_url both map to
	 * "Registration Page") — resolving a name must yield all of them.
	 *
	 * @param string      $name    ESP field name (unprefixed).
	 * @param string|null $version Schema version, or null for every version.
	 *
	 * @return array[] List of definitions (possibly empty).
	 */
	public static function get_all_by_name( $name, $version = null ) {
		$definitions = self::get_definitions();
		$matches     = [];
		foreach ( self::get_name_index()[ $name ] ?? [] as $id ) {
			if ( null === $version || $definitions[ $id ]['version'] === $version ) {
				$matches[] = $definitions[ $id ];
			}
		}
		return $matches;
	}

	/**
	 * Resolve an ESP field name to its definitions, with the single-match
	 * fallback.
	 *
	 * Encodes the resolution invariant used by defaults, migration and
	 * settings saves. Production callers resolve without a version: the two
	 * schemas no longer contest a name, so a name identifies a field
	 * outright. It can still map to several definitions — the legacy schema
	 * maps two raw keys to "Registration Page", and the five value-equivalent
	 * pairs are one field spelled twice, whose ids the write paths collapse
	 * onto the v2 twin (see upgrade_equivalent_ids()).
	 *
	 * Passing a version restricts the match to that version, falling back to
	 * a single any-version match when it has none — which is what covers
	 * version-neutral (filter-added) fields.
	 *
	 * @param string      $name    ESP field name (unprefixed).
	 * @param string|null $version Preferred schema version, or null for every version.
	 *
	 * @return array[] List of definitions (possibly empty).
	 */
	public static function resolve_name( $name, $version = null ) {
		$definitions = self::get_all_by_name( $name, $version );
		if ( ! empty( $definitions ) ) {
			return $definitions;
		}
		$definition = self::get_by_name( $name );
		return $definition ? [ $definition ] : [];
	}

	/**
	 * Whether an unprefixed ESP field name belongs to any registered
	 * definition, including dynamic-suffix matches (e.g. "Signup UTM: source"
	 * matches the "Signup UTM: " definition).
	 *
	 * Used by Integration::prepare_contact() to tell an explicitly-injected
	 * custom prefixed key (unknown to the registry: passes through) from a
	 * registered field that is simply not enabled for the integration
	 * (dropped, respecting the per-integration selection).
	 *
	 * @param string $name ESP field name (unprefixed).
	 *
	 * @return bool
	 */
	public static function name_is_registered( $name ) {
		if ( isset( self::get_name_index()[ $name ] ) ) {
			return true;
		}
		foreach ( self::get_definitions() as $definition ) {
			if (
				$definition['dynamic_suffix']
				&& 0 === strpos( $name, $definition['name'] )
				&& $name !== $definition['name']
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get name-collision groups: ESP names claimed by both schema versions,
	 * including the pairs equivalence has since collapsed.
	 *
	 * The raw derivation, and deliberately private. get_equivalent_upgrades()
	 * has to read this rather than the public view: the collapse is defined in
	 * terms of these groups, so reading the filtered view would empty the
	 * upgrade map — and with it the id upgrade and the input aliasing that make
	 * a collapsed pair work at all.
	 *
	 * @return array Map of ESP name => list of definition ids.
	 */
	private static function get_name_collision_groups() {
		$by_name = [];
		foreach ( self::get_definitions() as $definition ) {
			if ( self::VERSION_NEUTRAL === $definition['version'] ) {
				continue;
			}
			$by_name[ $definition['name'] ][ $definition['version'] ][] = $definition['id'];
		}

		$groups = [];
		foreach ( $by_name as $name => $versions ) {
			if ( isset( $versions[ self::VERSION_V1 ], $versions[ self::VERSION_V2 ] ) ) {
				$groups[ $name ] = array_merge( $versions[ self::VERSION_V1 ], $versions[ self::VERSION_V2 ] );
			}
		}
		return $groups;
	}

	/**
	 * Get conflict groups: ESP names a publisher cannot enable on both schema
	 * versions at once.
	 *
	 * Empty by construction, and meant to stay that way. Every v1/v2 name
	 * collision is dissolved one of two ways: same-meaning pairs declare the v2
	 * field `equivalent` and become one field (the v1 ids upgrade to the v2
	 * twin, the v1 raw keys alias onto it as inputs), and changed-meaning v2
	 * fields get their own ESP name. That is what lets both schemas run at once
	 * with no backfill. Test_Field_Registry asserts this returns [], so a new
	 * field that re-claims a legacy name fails the suite instead of silently
	 * needing back the pick-one save rule this replaced.
	 *
	 * @return array Map of ESP name => list of definition ids.
	 */
	public static function get_conflict_groups() {
		$upgrades = self::get_equivalent_upgrades();
		$groups   = [];
		foreach ( self::get_name_collision_groups() as $name => $ids ) {
			// Every v1 member of a collapsed group is a key of the upgrade map,
			// so one hit settles the group.
			$collapsed = false;
			foreach ( $ids as $id ) {
				if ( isset( $upgrades[ $id ] ) ) {
					$collapsed = true;
					break;
				}
			}
			if ( ! $collapsed ) {
				$groups[ $name ] = $ids;
			}
		}
		return $groups;
	}

	/**
	 * Build the equivalent-upgrade map: v1 id => v2 ids, restricted to
	 * name-collision groups whose every v2 member declares `equivalent`.
	 *
	 * Equivalence is an authored claim on the v2 config — "the v2 pipeline
	 * produces the identical value for the same ESP name" — audited against
	 * the field-inventory sheet, never inferred from matching copy.
	 *
	 * @return array Map of v1 definition id => list of v2 definition ids.
	 */
	private static function get_equivalent_upgrades() {
		if ( null !== self::$equivalent_upgrades ) {
			return self::$equivalent_upgrades;
		}
		$definitions = self::get_definitions();
		$map         = [];
		foreach ( self::get_name_collision_groups() as $ids ) {
			$v1_ids         = [];
			$v2_ids         = [];
			$all_equivalent = true;
			foreach ( $ids as $id ) {
				if ( self::VERSION_V1 === $definitions[ $id ]['version'] ) {
					$v1_ids[] = $id;
					continue;
				}
				$v2_ids[] = $id;
				if ( empty( $definitions[ $id ]['equivalent'] ) ) {
					$all_equivalent = false;
				}
			}
			if ( ! $all_equivalent || empty( $v2_ids ) || empty( $v1_ids ) ) {
				continue;
			}
			foreach ( $v1_ids as $v1_id ) {
				$map[ $v1_id ] = $v2_ids;
			}
		}
		self::$equivalent_upgrades = $map;
		return self::$equivalent_upgrades;
	}

	/**
	 * Upgrade v1 ids of value-equivalent conflict pairs to their v2 twins.
	 *
	 * The storage-time sunset lever: because an equivalent pair produces a
	 * byte-identical ESP payload on either version, rewriting the stored id
	 * is unobservable at the provider and retires legacy ids for free. This
	 * is only safe where the registry attests equivalence — divergent pairs
	 * (different values or formats on the same ESP name) are never touched;
	 * their migration stays an explicit publisher decision in the UI.
	 *
	 * @param string[] $ids Field ids.
	 *
	 * @return string[] Ids with equivalent v1 members upgraded, de-duplicated.
	 */
	public static function upgrade_equivalent_ids( $ids ) {
		$upgrades = self::get_equivalent_upgrades();
		$upgraded = [];
		foreach ( (array) $ids as $id ) {
			if ( isset( $upgrades[ $id ] ) ) {
				foreach ( $upgrades[ $id ] as $v2_id ) {
					$upgraded[] = $v2_id;
				}
				continue;
			}
			$upgraded[] = $id;
		}
		return array_values( array_unique( $upgraded ) );
	}

	/**
	 * Raw keys an equivalent-group v2 id accepts as input aliases.
	 *
	 * Callers still hand-build contacts with legacy raw keys (the deletion
	 * connector passes `account`, for one), so an enabled v2 id from an
	 * equivalent pair must match its v1 counterparts' raw keys in
	 * Integration::prepare_contact() — the values are identical by
	 * declaration, only the internal key spelling differs.
	 *
	 * @param string $id Field id.
	 *
	 * @return string[] v1 raw keys aliased to this id (empty for non-equivalent ids).
	 */
	public static function get_equivalent_input_raw_keys( $id ) {
		$definitions = self::get_definitions();
		if ( empty( $definitions[ $id ]['equivalent'] ) ) {
			return [];
		}
		$aliases = [];
		foreach ( self::get_equivalent_upgrades() as $v1_id => $v2_ids ) {
			if ( in_array( $id, $v2_ids, true ) && isset( $definitions[ $v1_id ]['raw_key'] ) ) {
				$aliases[] = $definitions[ $v1_id ]['raw_key'];
			}
		}
		return array_values( array_unique( $aliases ) );
	}

	/**
	 * The retired schema-origin marker. Read once, by the seeder below, and
	 * deleted the moment it has done its job. Nothing else in the codebase
	 * knows this option exists.
	 *
	 * @var string
	 */
	private const RETIRED_ORIGIN_OPTION = 'newspack_sync_schema_origin';

	/**
	 * Seed the ESP integration's stored outgoing-field selection, once.
	 *
	 * Coexistence made the registry a merged, all-versions view: a site with
	 * no stored selection would derive its defaults from that merged view and
	 * start pushing the other schema's field names to the publisher's ESP.
	 * Materialising the site's current effective default selection as stored
	 * ids freezes what it already syncs, and is what lets every runtime path
	 * stop asking which schema the site came from.
	 *
	 * This is the last consumer of the retired origin logic, and deliberately
	 * a one-shot: after it runs, no code path reads the origin marker, the
	 * `NEWSPACK_SYNC_METADATA_VERSION*` constants, or any notion of a
	 * site-wide schema version ever again. An existing selection — including
	 * a deliberately empty one — is never overwritten.
	 *
	 * @return void
	 */
	public static function seed_default_field_selections() {
		$option = \Newspack\Reader_Activation\Integration::OUTGOING_FIELDS_OPTION_PREFIX . \Newspack\Reader_Activation\Integration::ESP_INTEGRATION_ID;
		if ( null !== \get_option( $option, null ) ) {
			// Already configured: nothing to seed, and the marker is dead.
			\delete_option( self::RETIRED_ORIGIN_OPTION );
			return;
		}

		$ids = self::get_version_default_field_ids( self::detect_retired_schema_version() );
		if ( empty( $ids ) ) {
			// No definitions available (the metadata classes have not loaded).
			// Storing an empty selection here would read as "push nothing", so
			// leave both options untouched and retry on a later activation.
			return;
		}

		\update_option( $option, $ids, false );
		\delete_option( self::RETIRED_ORIGIN_OPTION );
	}

	/**
	 * Every available definition belonging to a schema version, plus the
	 * version-neutral ones.
	 *
	 * Reproduces what the pre-seeding default selection resolved to: the
	 * origin-scoped field list (available classes only), each display name
	 * resolved to its same-version definitions, with version-neutral fields
	 * picked up through the any-version fallback.
	 *
	 * @param string $version Schema version.
	 *
	 * @return string[] List of field ids.
	 */
	private static function get_version_default_field_ids( $version ) {
		$ids = [];
		foreach ( self::get_definitions() as $id => $definition ) {
			if ( empty( $definition['available'] ) ) {
				continue;
			}
			if ( $definition['version'] === $version || self::VERSION_NEUTRAL === $definition['version'] ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Which schema the site was on before coexistence — the seeding input,
	 * and the only place this question is still asked.
	 *
	 * A marker recorded by an earlier release wins outright. Otherwise the
	 * retired global version switch decides directly; sites with existing
	 * outgoing-field selections resolve to the version those selections carry
	 * (bare display names are the pre-coexistence format, so v1); the
	 * pre-integrations global fields option is v1; a site with a configured
	 * ESP but none of those (it never opened the metadata-fields settings) is
	 * still an existing legacy site syncing dynamic defaults, so it is also
	 * v1; everything else is a fresh install and starts on v2.
	 *
	 * @return string 'v1' or 'v2'.
	 */
	private static function detect_retired_schema_version() {
		$recorded = \get_option( self::RETIRED_ORIGIN_OPTION );
		if ( in_array( $recorded, [ self::VERSION_V1, self::VERSION_V2 ], true ) ) {
			return $recorded;
		}

		$flag_version = null;
		if ( defined( 'NEWSPACK_SYNC_METADATA_VERSION' ) ) {
			$flag_version = NEWSPACK_SYNC_METADATA_VERSION;
		} elseif ( defined( 'NEWSPACK_SYNC_METADATA_VERSION_1' ) && NEWSPACK_SYNC_METADATA_VERSION_1 ) {
			$flag_version = '1.0';
		}
		if ( null !== $flag_version ) {
			return 'legacy' === $flag_version ? self::VERSION_V1 : self::VERSION_V2;
		}

		$selection_values = self::get_stored_selection_values();
		if ( null !== $selection_values ) {
			return self::version_from_selection_values( $selection_values );
		}

		if ( false !== \get_option( Metadata::FIELDS_OPTION, false ) ) {
			return self::VERSION_V1;
		}

		// A configured ESP with no stored selections is an existing legacy
		// site syncing dynamic defaults — not a fresh install. Seeding runs at
		// activation, before integrations register on init priority 5, so fall
		// back to constructing the ESP integration directly; is_set_up() reads
		// stored configuration only, never the live provider API.
		$esp = \Newspack\Reader_Activation\Integrations::get_integration( \Newspack\Reader_Activation\Integration::ESP_INTEGRATION_ID );
		if ( ! $esp && class_exists( \Newspack\Reader_Activation\Integrations\ESP::class ) ) {
			$esp = new \Newspack\Reader_Activation\Integrations\ESP();
		}
		if ( $esp && $esp->is_set_up() ) {
			return self::VERSION_V1;
		}

		return self::VERSION_V2;
	}

	/**
	 * Fetch stored per-integration outgoing-field selections, if any.
	 *
	 * @return array|null Up to five stored option values (unserialized), or
	 *                    null when no per-integration option exists.
	 */
	private static function get_stored_selection_values() {
		global $wpdb;
		$values = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 5",
				$wpdb->esc_like( \Newspack\Reader_Activation\Integration::OUTGOING_FIELDS_OPTION_PREFIX ) . '%'
			)
		);
		return empty( $values ) ? null : array_map( 'maybe_unserialize', $values );
	}

	/**
	 * Derive the pre-coexistence schema version from stored selection values.
	 *
	 * Bare display names are the pre-coexistence storage format, so any of
	 * them means v1. All-id selections carry their version explicitly: the
	 * first non-neutral version wins (a site that saved v2 ids before the
	 * question was settled must not be read as v1 by the mere existence of
	 * the option).
	 *
	 * @param array $selection_values Stored option values (unserialized).
	 *
	 * @return string 'v1' or 'v2'.
	 */
	private static function version_from_selection_values( $selection_values ) {
		$id_version = null;
		foreach ( $selection_values as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			foreach ( $value as $entry ) {
				if ( ! preg_match( '/^(v1|v2|neutral):/', (string) $entry, $matches ) ) {
					return self::VERSION_V1;
				}
				if ( null === $id_version && self::VERSION_NEUTRAL !== $matches[1] ) {
					$id_version = $matches[1];
				}
			}
		}
		return $id_version ?? self::VERSION_V1;
	}
}
