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
	 * Lazily-built equivalent-pair index. See get_equivalent_pairs().
	 *
	 * @var array|null
	 */
	private static $equivalent_pairs = null;

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
		self::$definitions      = null;
		self::$name_index       = null;
		self::$equivalent_pairs = null;
		\Newspack\Reader_Activation\Integration::flush_prepare_contact_lookups();
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
					$id = $version . ':' . $raw_key;
					// Structural fields last: they are derived from where the
					// definition lives, not authored, so a config key must never
					// be able to overwrite an id, a version or the availability
					// of the class that owns it.
					$definitions[ $id ]     = array_merge(
						$config,
						[
							'id'             => $id,
							'version'        => $version,
							'raw_key'        => $raw_key,
							'name'           => $config['name'],
							'section'        => $section,
							'available'      => $available,
							'class'          => $class,
							'dynamic_suffix' => ! empty( $config['dynamic_suffix'] ),
						]
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
	 * Whether a stored value is spelled as a version-qualified field id rather
	 * than a bare display name.
	 *
	 * Shape only — an id for a definition that no longer exists still is one,
	 * which is what distinguishes "already migrated" from "needs migrating".
	 *
	 * @param mixed $value Stored selection entry.
	 *
	 * @return bool
	 */
	public static function is_field_id( $value ) {
		$prefixes = [ self::VERSION_V1 . ':', self::VERSION_V2 . ':', self::VERSION_NEUTRAL . ':' ];
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( (string) $value, $prefix ) ) {
				return true;
			}
		}
		return false;
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
	 * Resolve an ESP field name to the definitions it enables.
	 *
	 * Deterministic and single-valued per field: an ESP name identifies one
	 * field, and where both schemas spell that field the same way (a
	 * value-equivalent pair) the name resolves to the surviving v2 member
	 * alone. Enabling both members would put two producers on one ESP key and
	 * make the payload depend on merge order.
	 *
	 * Can still return more than one definition when a schema deliberately
	 * spells one field with several raw keys — legacy `registration_page` and
	 * `current_page_url` both mean "Registration Page".
	 *
	 * @param string $name ESP field name (unprefixed).
	 *
	 * @return array[] List of definitions (possibly empty).
	 */
	public static function resolve_name( $name ) {
		$definitions = self::get_definitions();
		$matches     = [];
		foreach ( self::get_name_index()[ $name ] ?? [] as $id ) {
			$matches[ $id ] = $definitions[ $id ];
		}

		// An equivalent pair collapses onto its v2 member.
		foreach ( array_keys( $matches ) as $id ) {
			$superseded = self::get_equivalent_pairs()[ $id ] ?? [];
			if ( empty( $superseded ) ) {
				continue;
			}
			foreach ( $superseded as $superseded_id ) {
				unset( $matches[ $superseded_id ] );
			}
		}

		return array_values( $matches );
	}

	/**
	 * The value-equivalent pairs: surviving (v2) definition id => the v1
	 * definition ids it collapses.
	 *
	 * Authored, never inferred from matching copy: a v2 definition declares
	 * `equivalent` and points `supersedes` at its v1 twin. The legacy schema
	 * spells some fields with more than one raw key under a single ESP name
	 * (`registration_page` / `current_page_url`), so the twin's same-name v1
	 * siblings belong to the pair too — they are that same one field.
	 *
	 * @return array<string, string[]> Map of v2 id => list of v1 ids.
	 */
	private static function get_equivalent_pairs() {
		if ( null !== self::$equivalent_pairs ) {
			return self::$equivalent_pairs;
		}
		$definitions = self::get_definitions();
		$pairs       = [];
		foreach ( $definitions as $id => $definition ) {
			if ( empty( $definition['equivalent'] ) || empty( $definition['supersedes'] ) ) {
				continue;
			}
			$twin = $definitions[ $definition['supersedes'] ] ?? null;
			if ( ! $twin || self::VERSION_V1 !== $twin['version'] || $twin['name'] !== $definition['name'] ) {
				// Equivalence claims a shared ESP name. A `supersedes` target
				// that renames is a different field, not a pair.
				continue;
			}
			$members = [];
			foreach ( self::get_name_index()[ $definition['name'] ] ?? [] as $sibling_id ) {
				if ( $sibling_id !== $id && self::VERSION_V1 === $definitions[ $sibling_id ]['version'] ) {
					$members[] = $sibling_id;
				}
			}
			$pairs[ $id ] = $members;
		}
		self::$equivalent_pairs = $pairs;
		return self::$equivalent_pairs;
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
	 * Raw keys an id accepts as input aliases, from across its equivalent pair.
	 *
	 * Bidirectional, because stored ids are never rewritten: a site can be
	 * pushing either member of a pair, while callers hand-build contacts in
	 * whichever raw-key spelling their own code has always used (the deletion
	 * connector passes legacy `account`; the metadata classes emit `Account`).
	 * Both must land on whichever member is actually enabled.
	 *
	 * @param string $id Field id.
	 *
	 * @return string[] Raw keys aliased to this id (empty for unpaired ids).
	 */
	public static function get_equivalent_input_raw_keys( $id ) {
		$definitions = self::get_definitions();
		$pairs       = self::get_equivalent_pairs();
		$aliases     = [];

		// The surviving v2 member: its collapsed v1 members' raw keys.
		foreach ( $pairs[ $id ] ?? [] as $v1_id ) {
			$aliases[] = $definitions[ $v1_id ]['raw_key'];
		}

		// A v1 member: the surviving v2 member's raw key.
		foreach ( $pairs as $v2_id => $v1_ids ) {
			if ( in_array( $id, $v1_ids, true ) ) {
				$aliases[] = $definitions[ $v2_id ]['raw_key'];
			}
		}

		return array_values( array_unique( $aliases ) );
	}

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
			return;
		}

		// The pre-integrations global option is the publisher's own selection;
		// defer to it, since ESP::ensure_outgoing_fields_seeded() copies it
		// verbatim, and seeding here too would shadow it, re-enabling fields
		// the publisher turned off.
		if ( is_array( \get_option( Metadata::FIELDS_OPTION, null ) ) ) {
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
	}

	/**
	 * The site's default outgoing-field selection, derived rather than stored.
	 *
	 * The same candidate list seeding persists — computed inline for the reads
	 * that arrive before a selection could be seeded (an unconfident detection,
	 * or an integration built outside the registry). One list, one detection,
	 * whether it ends up persisted or not.
	 *
	 * Deliberately NOT memoized like get_definitions()/get_name_index(): those
	 * caches are safe to freeze for the process lifetime because their inputs
	 * (which classes exist, the newspack_ras_metadata_keys filter) only ever
	 * change under a reader-activation-sync test's own reset() discipline.
	 * detect_retired_schema_version()'s inputs — stored per-integration
	 * options reached via an uncached LIKE scan, and live ESP setup state —
	 * are touched far more broadly (any integration registration, any stored
	 * selection), so freezing this answer until an unrelated caller happens
	 * to call reset() silently serves stale derivations across a shared PHP
	 * process. Confirmed by running the full test suite: memoizing this
	 * method corrupted dozens of unrelated tests that never touch
	 * Field_Registry directly.
	 *
	 * @return string[] List of field ids.
	 */
	public static function get_default_field_ids() {
		return self::get_version_default_field_ids( self::detect_retired_schema_version()['version'] );
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
	 * Detection order: constants, existing selections, legacy fields option,
	 * set-up ESP (v1), else v2.
	 *
	 * @return array{version: string, confident: bool}
	 */
	private static function detect_retired_schema_version() {
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
				if ( ! self::is_field_id( $entry ) ) {
					return self::VERSION_V1;
				}
				$version = (string) strstr( (string) $entry, ':', true );
				if ( null === $id_version && self::VERSION_NEUTRAL !== $version ) {
					$id_version = $version;
				}
			}
		}
		return $id_version ?? self::VERSION_V1;
	}
}
