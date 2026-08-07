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
	 * Per-request cache of a derived — never persisted — schema version.
	 * Records whether the 'esp' integration was registered when the value
	 * was computed, because that is the one input that can change the
	 * answer within a request (see get_derivation_schema_version()).
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
	 * Serialize every definition for the integrations settings payload.
	 *
	 * Flat list, all schema versions — the per-field UI derives its rows and
	 * their visibility client-side, so it needs both sides of every rename.
	 *
	 * Deliberately carries no conflict or equivalence flags. The UI has no
	 * version choice to offer: get_conflict_groups() is empty by construction,
	 * so an ESP name appearing under both versions is always a collapsed
	 * equivalent pair, and the UI reads that off the pair itself. `status` is
	 * the whole of a field's fate: it drives the badges — 'legacy' and
	 * 'new'/'updated' badge, anything else (or nothing) is an unbadged
	 * 'existing' — and the sunset rule, under which a legacy field lists only
	 * while enabled and every other field lists everywhere.
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
	 * Re-entrancy guard for seed_default_field_selections().
	 *
	 * Detection asks the ESP integration whether it is set up, and the lazy
	 * trigger sits on the ESP's own read path, so a future is_set_up() that
	 * consulted the selection would loop. Structurally it cannot today —
	 * seeding resolves ids from the registry's definitions, never from an
	 * integration read — and this makes that a hard guarantee rather than a
	 * property of the current call graph.
	 *
	 * @var bool
	 */
	private static $seeding = false;

	/**
	 * Seed the ESP integration's stored outgoing-field selection, once.
	 *
	 * Coexistence made the registry a merged, all-versions view: a site with
	 * no stored selection would derive its defaults from that merged view and
	 * start pushing the other schema's field names to the publisher's ESP.
	 * Materialising the site's current effective default selection as stored
	 * ids freezes what it already syncs, and is what lets every runtime path
	 * stop asking which schema the site came from. That freeze holds only
	 * until the next Outbound settings save, since update_enabled_outgoing_fields()
	 * drops any currently-unavailable id.
	 *
	 * Idempotent and trigger-independent, because the trigger cannot be
	 * relied on: `newspack_activation` fires only on plugin activation, which
	 * an in-place update never does. So this runs from two places — the
	 * activation hook as an early bird, and lazily from
	 * ESP::ensure_outgoing_fields_seeded() the first time the ESP is read
	 * without a stored option. Whichever fires first wins; the other becomes
	 * a no-op.
	 *
	 * The only path that PERSISTS anything derived from the retired origin
	 * logic. Once a selection is stored, that logic is out of the picture for
	 * good: reads answer from the stored ids, and neither the marker nor the
	 * `NEWSPACK_SYNC_METADATA_VERSION*` constants are consulted again. (An
	 * unseeded site still consults the same detection to scope a derived
	 * fallback — see get_derivation_schema_version() — but stores nothing.) An
	 * existing selection, including a deliberately empty one, is never
	 * overwritten.
	 *
	 * @param bool $only_when_confident Skip seeding when detection had to fall
	 *   back to its fresh-install guess. The lazy caller passes true: that
	 *   guess is discriminated by ESP::is_set_up(), which is transiently false
	 *   whenever Newspack Newsletters is deactivated, unconfigured, or simply
	 *   read mid-request before its settings are in place — and a legacy site
	 *   read during that window would be frozen onto the new schema forever,
	 *   silently changing the field names its ESP automations key on. Declining
	 *   to seed costs nothing there: an ESP that is not set up cannot sync, so
	 *   the derived fallback never reaches a provider, and a later read seeds
	 *   correctly. (`NEWSPACK_FORCE_ALLOW_ESP_SYNC` bypasses the validation
	 *   that premise rests on, but it forces sync past an unconfigured ESP
	 *   too — a site running it is already outside the guarantee.) Activation
	 *   passes false and may act on the guess, since activation is the one
	 *   moment "no prior usage at all" is checkable; it still refuses on a
	 *   site that has completed setup.
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

		// The pre-integrations global option is the publisher's own selection,
		// and very likely a narrowed one. ESP::ensure_outgoing_fields_seeded()
		// copies it verbatim; seeding the full default set here would shadow it
		// permanently, silently re-enabling fields the publisher turned off.
		// Defer to that copy — the shapes are mutually exclusive by design.
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
				// Activation, on a guess. Only a site with no prior usage can be
				// the fresh install this branch assumes: a completed setup means
				// an existing site whose ESP merely happens to be unconfigured
				// right now, and freezing it onto the new schema would change
				// the field names its ESP automations key on.
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
			// No definitions at all (the metadata classes have not loaded).
			// Storing an empty selection here would read as "push nothing", so
			// leave both options untouched and retry on the next read.
			return;
		}

		\update_option( $option, $ids, false );
		self::retire_origin_marker();
	}

	/**
	 * Every definition belonging to a schema version, plus the version-neutral
	 * ones.
	 *
	 * Availability is deliberately NOT filtered. The stored snapshot is
	 * permanent, but availability is a runtime property of the moment seeding
	 * happens: a fresh install seeds before WooCommerce Subscriptions is
	 * installed or the content gates are switched on, so filtering here would
	 * bar those fields from ever syncing, with nothing to un-bar them.
	 * Storing an unavailable id is inert — Metadata::get_contact_with_metadata()
	 * skips any class whose is_available() is false, so the field simply
	 * produces no value until its class lights up, and then starts working.
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
	 * Idempotent, and safe to call from any path that has settled the question
	 * the marker existed to answer — including the ESP's own short-circuits,
	 * which reach a stored selection without going through the seeder at all.
	 * Leaving it behind would strand dead state on every upgraded site.
	 *
	 * @return void
	 */
	public static function retire_origin_marker() {
		\delete_option( self::RETIRED_ORIGIN_OPTION );
	}

	/**
	 * The schema version a derived — never persisted — default selection
	 * resolves against.
	 *
	 * Not a reinstatement of the origin marker: nothing is stored (though a
	 * meaningless, corrupt marker may be deleted on sight), no confidence is
	 * required, and a wrong answer costs one request rather than the site's
	 * future. It exists because the alternative for an unseeded site is
	 * resolving names against the merged registry, which yields both
	 * schemas' field names — and a configured non-ESP push integration
	 * inheriting from an unconfigured ESP would put them in front of a real
	 * provider. Scoping the derivation restores the pre-coexistence behavior
	 * for exactly that window.
	 *
	 * Memoized per request: an unseeded site reaches this once per contact
	 * per push-capable integration on the sync path — get_enabled_outgoing_field_ids()
	 * calling get_default_outgoing_field_ids(), fanned out across
	 * integrations by Metadata::get_sync_metadata_classes() — and re-running
	 * detection's $wpdb LIKE query and ESP::is_set_up() chain that often
	 * would be wasteful. The one input that can change the answer
	 * mid-request is the 'esp' integration registering, so a value computed
	 * before that happened is re-detected once an integration is present;
	 * anything else is served from the cache. reset() clears it.
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
	 * Every branch but the last rests on stored evidence and is reported as
	 * confident. The last is a guess — a legacy site whose ESP is momentarily
	 * unconfigured is indistinguishable from a fresh install — which is why
	 * the lazy caller refuses to act on it.
	 *
	 * @return array{version: string, confident: bool}
	 */
	private static function detect_retired_schema_version() {
		$recorded = \get_option( self::RETIRED_ORIGIN_OPTION, null );
		if ( in_array( $recorded, [ self::VERSION_V1, self::VERSION_V2 ], true ) ) {
			return self::certain( $recorded );
		}
		if ( null !== $recorded ) {
			// Stored but meaningless. It can never decide anything, so retire it
			// on sight rather than leaving it to be re-read by every later
			// attempt on a site seeding declines to seed.
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
