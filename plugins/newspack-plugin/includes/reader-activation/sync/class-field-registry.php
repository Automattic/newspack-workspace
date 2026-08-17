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
	 * Per-request cache of a derived, never-persisted schema version. Also
	 * tracks ESP-integration registration, the one input that can change
	 * the answer mid-request.
	 *
	 * @var array|null { version: string, esp_registered: bool }
	 */
	private static $derivation_cache = null;

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
		self::$derivation_cache    = null;
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

		// Drop filter-removed raw keys, and adopt filter renames — a relabeled
		// raw key must relabel the definition too, or name-based resolution
		// would look for the old label and find nothing.
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
	 * The legacy schema maps multiple raw keys to one ESP name (e.g.
	 * registration_page and current_page_url both map to "Registration
	 * Page"), so a name can resolve to more than one definition.
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
	 * Can return more than one definition: the legacy schema maps two raw
	 * keys to "Registration Page", and value-equivalent pairs are one field
	 * spelled twice. A given $version restricts the match, falling back to
	 * any version when there is no match — which covers version-neutral
	 * (filter-added) fields.
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
	 * Distinguishes an unregistered custom key (passes through) from a
	 * registered-but-disabled field (dropped).
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
	 * Deliberately private: get_equivalent_upgrades() must read this raw
	 * view rather than the filtered one, or the collapse it defines would
	 * empty the very upgrade map that collapse depends on.
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
	 * Empty by construction: every v1/v2 name collision is dissolved by
	 * declaring the v2 field `equivalent` (collapsing into one field) or by
	 * giving a changed-meaning v2 field its own ESP name.
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
	 * Serialize every definition for the integrations settings payload.
	 *
	 * Flat list, all schema versions: the per-field UI derives rows and
	 * visibility client-side, so it needs both sides of every rename. No
	 * conflict/equivalence flags — get_conflict_groups() is always empty, so
	 * the UI reads equivalence off the pair itself.
	 *
	 * `status` drives the New badge ('new'/'updated' only), the sunset rule
	 * (a legacy field lists only while enabled), and whether a `section`
	 * sorts last (once every field in it is legacy).
	 *
	 * @return array[] List of definition arrays (see the settings REST contract).
	 */
	public static function get_definitions_for_settings() {
		$rows = [];
		foreach ( self::get_definitions() as $id => $definition ) {
			$rows[] = [
				'id'             => $id,
				'version'        => $definition['version'],
				'raw_key'        => $definition['raw_key'],
				'name'           => $definition['name'],
				'section'        => (string) ( $definition['section'] ?? '' ),
				'available'      => (bool) $definition['available'],
				'dynamic_suffix' => (bool) $definition['dynamic_suffix'],
				'description'    => (string) ( $definition['description'] ?? '' ),
				'example'        => (string) ( $definition['example'] ?? '' ),
				'status'         => in_array( $definition['status'] ?? '', [ 'new', 'updated', 'legacy' ], true ) ? $definition['status'] : 'existing',
				'supersedes'     => $definition['supersedes'] ?? null,
				'superseded_by'  => array_values( $definition['superseded_by'] ?? [] ),
			];
		}
		return $rows;
	}

	/**
	 * Build the equivalent-upgrade map: v1 id => v2 ids, restricted to
	 * name-collision groups whose every v2 member declares `equivalent`.
	 *
	 * Equivalence is an authored, audited claim — never inferred from
	 * matching copy.
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
	 * Safe because an equivalent pair produces a byte-identical ESP payload
	 * on either version. Divergent pairs are never touched; their migration
	 * stays an explicit publisher decision in the UI.
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
	 * Needed because callers still hand-build contacts with legacy raw keys
	 * (e.g. the deletion connector passes `account`), so an enabled v2 id
	 * must also match its v1 counterparts' raw keys.
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
	 * The retired schema-origin marker. Read once by the seeder, then
	 * deleted; nothing else in the codebase knows this option exists.
	 *
	 * @var string
	 */
	private const RETIRED_ORIGIN_OPTION = 'newspack_sync_schema_origin';

	/**
	 * Re-entrancy guard for seed_default_field_selections(): a future
	 * is_set_up() that consults the selection would loop through the lazy
	 * seed trigger on the ESP's own read path.
	 *
	 * @var bool
	 */
	private static $seeding = false;

	/**
	 * Seed the ESP integration's stored outgoing-field selection, once.
	 *
	 * Materialises the site's current effective defaults as stored ids so
	 * every runtime path can stop asking which schema the site came from.
	 * Idempotent and trigger-independent: runs from both the activation
	 * hook and lazily from ESP::ensure_outgoing_fields_seeded(); whichever
	 * fires first wins. An existing selection, including a deliberately
	 * empty one, is never overwritten.
	 *
	 * @param bool $only_when_confident Confidence-gated: an unconfident
	 *   guess is never persisted from the lazy path, since a transiently
	 *   unset-up ESP would otherwise freeze a legacy site onto the new
	 *   schema forever. Activation passes false and may act on the guess.
	 *
	 * @return void
	 */
	public static function seed_default_field_selections( $only_when_confident = false ) {
		if ( self::$seeding ) {
			return;
		}

		$option = \Newspack\Reader_Activation\Integration::OUTGOING_FIELDS_OPTION_PREFIX . \Newspack\Reader_Activation\Integration::ESP_INTEGRATION_ID;
		if ( null !== \get_option( $option, null ) ) {
			// Already configured: nothing to seed, and the marker is dead.
			self::retire_origin_marker();
			return;
		}

		// The pre-integrations global option is the publisher's own selection;
		// defer to it, since ESP::ensure_outgoing_fields_seeded() copies it
		// verbatim, and seeding here too would shadow it, re-enabling fields
		// the publisher turned off.
		if ( is_array( \get_option( Metadata::FIELDS_OPTION, null ) ) ) {
			self::retire_origin_marker();
			return;
		}

		self::$seeding = true;
		try {
			$detection = self::detect_retired_schema_version();
			if ( ! $detection['confident'] ) {
				if ( $only_when_confident ) {
					return;
				}
				// Activation may still be acting on a guess; only do so before
				// setup completes, when "no prior usage" is actually checkable.
				$setup_option = defined( 'NEWSPACK_SETUP_COMPLETE' ) ? NEWSPACK_SETUP_COMPLETE : 'newspack_setup_complete';
				if ( '1' === \get_option( $setup_option, '0' ) ) {
					return;
				}
			}
			$ids = self::get_version_default_field_ids( $detection['version'] );
		} finally {
			self::$seeding = false;
		}

		if ( empty( $ids ) ) {
			// No definitions loaded yet. Storing an empty selection here would
			// read as "push nothing", so leave it untouched and retry later.
			return;
		}

		\update_option( $option, $ids, false );
		self::retire_origin_marker();
	}

	/**
	 * Every definition belonging to a schema version, plus the version-neutral
	 * ones.
	 *
	 * Availability is deliberately not filtered: a fresh install seeds
	 * before dependent features (e.g. WooCommerce Subscriptions) exist.
	 * Stored ids for unavailable fields are inert until their class becomes
	 * available.
	 *
	 * @param string $version Schema version.
	 *
	 * @return string[] List of field ids.
	 */
	private static function get_version_default_field_ids( $version ) {
		$ids = [];
		foreach ( self::get_definitions() as $id => $definition ) {
			if ( $definition['version'] === $version || self::VERSION_NEUTRAL === $definition['version'] ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Drop the retired schema-origin marker.
	 *
	 * Idempotent; safe to call from any path that has settled the question
	 * the marker existed to answer, including ones that bypass the seeder.
	 *
	 * @return void
	 */
	public static function retire_origin_marker() {
		\delete_option( self::RETIRED_ORIGIN_OPTION );
	}

	/**
	 * The schema version a derived, never-persisted default selection
	 * resolves against.
	 *
	 * Needed because an unseeded site resolving names against the merged
	 * registry would leak both schemas' field names to any push integration
	 * inheriting from an unconfigured ESP.
	 *
	 * Memoized per request; re-detected once the ESP integration registers,
	 * the one input that can change the answer mid-request. reset() clears
	 * the cache.
	 *
	 * @return string 'v1' or 'v2'.
	 */
	public static function get_derivation_schema_version() {
		$esp_integration = \Newspack\Reader_Activation\Integrations::get_integration( \Newspack\Reader_Activation\Integration::ESP_INTEGRATION_ID );

		if ( null !== self::$derivation_cache && ( self::$derivation_cache['esp_registered'] || null === $esp_integration ) ) {
			return self::$derivation_cache['version'];
		}

		$version = self::detect_retired_schema_version()['version'];

		self::$derivation_cache = [
			'version'        => $version,
			'esp_registered' => null !== $esp_integration,
		];

		return $version;
	}

	/**
	 * Detection order: stored marker, constants, existing selections, legacy
	 * fields option, set-up ESP (v1), else v2.
	 *
	 * @return array{version: string, confident: bool}
	 */
	private static function detect_retired_schema_version() {
		$recorded = \get_option( self::RETIRED_ORIGIN_OPTION, null );
		if ( in_array( $recorded, [ self::VERSION_V1, self::VERSION_V2 ], true ) ) {
			return self::certain( $recorded );
		}
		if ( null !== $recorded ) {
			// Meaningless value; retire it now rather than re-reading it forever.
			\delete_option( self::RETIRED_ORIGIN_OPTION );
		}

		$flag_version = null;
		if ( defined( 'NEWSPACK_SYNC_METADATA_VERSION' ) ) {
			$flag_version = NEWSPACK_SYNC_METADATA_VERSION;
		} elseif ( defined( 'NEWSPACK_SYNC_METADATA_VERSION_1' ) && NEWSPACK_SYNC_METADATA_VERSION_1 ) {
			$flag_version = '1.0';
		}
		if ( null !== $flag_version ) {
			return self::certain( 'legacy' === $flag_version ? self::VERSION_V1 : self::VERSION_V2 );
		}

		$selection_values = self::get_stored_selection_values();
		if ( null !== $selection_values ) {
			return self::certain( self::version_from_selection_values( $selection_values ) );
		}

		if ( false !== \get_option( Metadata::FIELDS_OPTION, false ) ) {
			return self::certain( self::VERSION_V1 );
		}

		// A configured ESP with no stored selections is a legacy site on
		// dynamic defaults, not a fresh install. Construct it directly, since
		// seeding runs at activation before integrations register; is_set_up()
		// only reads stored config, never the live provider API.
		$esp = \Newspack\Reader_Activation\Integrations::get_integration( \Newspack\Reader_Activation\Integration::ESP_INTEGRATION_ID );
		if ( ! $esp && class_exists( \Newspack\Reader_Activation\Integrations\ESP::class ) ) {
			$esp = new \Newspack\Reader_Activation\Integrations\ESP();
		}
		if ( $esp && $esp->is_set_up() ) {
			return self::certain( self::VERSION_V1 );
		}

		return [
			'version'   => self::VERSION_V2,
			'confident' => false,
		];
	}

	/**
	 * Wrap an evidence-backed detection result.
	 *
	 * @param string $version Detected schema version.
	 *
	 * @return array{version: string, confident: bool}
	 */
	private static function certain( $version ) {
		return [
			'version'   => $version,
			'confident' => true,
		];
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
	 * Bare display names mean v1 (the pre-coexistence format). All-id
	 * selections carry their version explicitly; the first non-neutral
	 * version wins.
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
