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
	 * Per-request cache of a detected-but-not-persisted schema origin.
	 *
	 * Keyed nowhere — there is at most one origin per request. Records
	 * whether the 'esp' integration was registered when the value was
	 * detected, because that is the one input that can change the answer
	 * within a request (see get_schema_origin()).
	 *
	 * @var array|null { origin: string, esp_registered: bool }
	 */
	private static $detected_origin = null;

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
		self::$detected_origin     = null;
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
	 * Get every definition of a version that shares an ESP field name.
	 *
	 * Needed because the legacy schema maps multiple raw keys to a single
	 * ESP name (registration_page and current_page_url both map to
	 * "Registration Page") — resolving a name must yield all of them.
	 *
	 * @param string $name    ESP field name (unprefixed).
	 * @param string $version Schema version.
	 *
	 * @return array[] List of definitions (possibly empty).
	 */
	public static function get_all_by_name( $name, $version ) {
		$definitions = self::get_definitions();
		$matches     = [];
		foreach ( self::get_name_index()[ $name ] ?? [] as $id ) {
			if ( $definitions[ $id ]['version'] === $version ) {
				$matches[] = $definitions[ $id ];
			}
		}
		return $matches;
	}

	/**
	 * Resolve an ESP field name to its definitions for a version, with the
	 * shared any-version fallback.
	 *
	 * Encodes the resolution invariant used by defaults, migration and
	 * settings saves: prefer every same-version definition sharing the name
	 * (legacy maps several raw keys to one name), and when the version has
	 * none, fall back to a single any-version match — which is what covers
	 * version-neutral (filter-added) fields.
	 *
	 * @param string $name    ESP field name (unprefixed).
	 * @param string $version Preferred schema version.
	 *
	 * @return array[] List of definitions (possibly empty).
	 */
	public static function resolve_name( $name, $version ) {
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

	const SCHEMA_ORIGIN_OPTION = 'newspack_sync_schema_origin';

	/**
	 * Get the site's schema origin: the schema version it was on before
	 * per-field coexistence. Decides presentation defaults, migration
	 * name-resolution and the origin-scoped compatibility maps (e.g.
	 * Metadata::get_keys()); it does not decide what actually syncs — that
	 * is decided per-integration by each integration's enabled field ids.
	 *
	 * @return string 'v1' or 'v2'.
	 */
	public static function get_schema_origin() {
		$origin = \get_option( self::SCHEMA_ORIGIN_OPTION );
		if ( in_array( $origin, [ self::VERSION_V1, self::VERSION_V2 ], true ) ) {
			return $origin;
		}
		$esp_integration = \Newspack\Reader_Activation\Integrations::get_integration( 'esp' );

		// Reuse the per-request detection rather than re-running it (and its
		// $wpdb LIKE query) on every call. The only input that can change the
		// answer mid-request is the 'esp' integration registering, so a value
		// detected before that happened is re-detected once an integration is
		// present; anything else is served from the cache.
		if ( null !== self::$detected_origin && ( self::$detected_origin['esp_registered'] || null === $esp_integration ) ) {
			return self::$detected_origin['origin'];
		}

		$detection             = self::detect_schema_origin();
		$origin                = $detection['origin'];
		self::$detected_origin = [
			'origin'         => $origin,
			'esp_registered' => null !== $esp_integration,
		];

		// Persist only confident answers. Two inputs make a detection a guess
		// rather than evidence: the 'esp' integration not being registered yet
		// (this ran before init priority 5), and the fresh-install fallback,
		// whose discriminator — ESP::is_set_up() — is transiently false
		// whenever Newspack Newsletters is deactivated or unconfigured. A
		// legacy site touched during that window must not be frozen as v2
		// forever (its ESP automations key on the v1 field names); an
		// unpersisted guess self-heals on a later, correctly-timed call.
		// Genuinely fresh installs don't rely on this path at all: their
		// origin is persisted at activation (see seed_fresh_install_origin()).
		if ( null === $esp_integration || ! $detection['confident'] ) {
			return $origin;
		}

		return self::persist_origin( $origin );
	}

	/**
	 * Persist a detected schema origin, settle-once.
	 *
	 * The get_option() miss that precedes detection registers the option in
	 * the persistent 'notoptions' cache, which makes add_option() skip its
	 * existence check and issue an unconditional upsert — overwriting a value
	 * a concurrent request may have just stored. Clear the notoptions entry
	 * so add_option() re-checks the database, then re-read: whatever value
	 * actually settled wins.
	 *
	 * @param string $origin Detected origin ('v1' or 'v2').
	 *
	 * @return string The persisted origin (the concurrent winner on a race).
	 */
	private static function persist_origin( $origin ) {
		\wp_cache_delete( 'notoptions', 'options' );
		\add_option( self::SCHEMA_ORIGIN_OPTION, $origin, '', false );
		$stored = \get_option( self::SCHEMA_ORIGIN_OPTION );
		if ( in_array( $stored, [ self::VERSION_V1, self::VERSION_V2 ], true ) ) {
			self::$detected_origin = null;
			return $stored;
		}
		return $origin;
	}

	/**
	 * Persist the schema origin for a genuinely fresh install, at activation.
	 *
	 * Activation is the one moment "no prior Newspack usage" is unambiguous:
	 * lazy detection cannot tell a fresh site whose ESP got configured before
	 * its first origin-scoped call apart from an existing legacy site, so
	 * fresh installs get their marker here instead. Reactivating a site with
	 * any prior-usage evidence (completed setup, stored field selections)
	 * skips seeding and leaves the decision to lazy detection.
	 *
	 * @return void
	 */
	public static function seed_fresh_install_origin() {
		$origin = \get_option( self::SCHEMA_ORIGIN_OPTION );
		if ( in_array( $origin, [ self::VERSION_V1, self::VERSION_V2 ], true ) ) {
			return;
		}
		$setup_option = defined( 'NEWSPACK_SETUP_COMPLETE' ) ? NEWSPACK_SETUP_COMPLETE : 'newspack_setup_complete';
		if ( '1' === \get_option( $setup_option, '0' ) ) {
			return;
		}
		if ( false !== \get_option( Metadata::FIELDS_OPTION, false ) ) {
			return;
		}
		if ( null !== self::get_stored_selection_values() ) {
			return;
		}
		self::persist_origin( self::detect_schema_origin()['origin'] );
		self::$detected_origin = null;
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
	 * Detect the schema origin for a site that has not recorded one yet.
	 *
	 * This is the single remaining consumer of the old global version
	 * switch: an explicit flag decides directly; sites with existing
	 * outgoing-field selections resolve to the version those selections
	 * carry (bare display names are the pre-coexistence format, so v1);
	 * the pre-integrations global fields option is v1; a site with a
	 * configured ESP but none of those (never opened the metadata-fields
	 * settings) is still an existing legacy site syncing dynamic defaults,
	 * so it is also v1; everything else looks like a fresh install and
	 * starts on v2 — but only as an unpersistable guess, because a legacy
	 * site whose ESP is temporarily unconfigured looks identical.
	 *
	 * @return array { origin: 'v1'|'v2', confident: bool }
	 */
	private static function detect_schema_origin() {
		$flag_version = null;
		if ( defined( 'NEWSPACK_SYNC_METADATA_VERSION' ) ) {
			$flag_version = NEWSPACK_SYNC_METADATA_VERSION;
		} elseif ( defined( 'NEWSPACK_SYNC_METADATA_VERSION_1' ) && NEWSPACK_SYNC_METADATA_VERSION_1 ) {
			$flag_version = '1.0';
		}
		if ( null !== $flag_version ) {
			return [
				'origin'    => 'legacy' === $flag_version ? self::VERSION_V1 : self::VERSION_V2,
				'confident' => true,
			];
		}

		$selection_values = self::get_stored_selection_values();
		if ( null !== $selection_values ) {
			return [
				'origin'    => self::origin_from_selection_values( $selection_values ),
				'confident' => true,
			];
		}
		if ( false !== \get_option( Metadata::FIELDS_OPTION, false ) ) {
			return [
				'origin'    => self::VERSION_V1,
				'confident' => true,
			];
		}

		// A configured ESP with no stored selections is an existing legacy
		// site syncing dynamic defaults — not a fresh install.
		$esp_integration = \Newspack\Reader_Activation\Integrations::get_integration( 'esp' );
		if ( $esp_integration && $esp_integration->is_set_up() ) {
			return [
				'origin'    => self::VERSION_V1,
				'confident' => true,
			];
		}

		return [
			'origin'    => self::VERSION_V2,
			'confident' => false,
		];
	}

	/**
	 * Derive the schema origin from stored selection values.
	 *
	 * Bare display names are the pre-coexistence storage format, so any of
	 * them means v1. All-id selections carry their version explicitly: the
	 * first non-neutral version wins (a site that saved v2 ids while its
	 * origin was still undecided must not be branded v1 by the mere
	 * existence of the option).
	 *
	 * @param array $selection_values Stored option values (unserialized).
	 *
	 * @return string 'v1' or 'v2'.
	 */
	private static function origin_from_selection_values( $selection_values ) {
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
