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
		self::$definitions = null;
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

		// Drop definitions whose raw key was removed by the filter.
		foreach ( $definitions as $id => $definition ) {
			if ( ! isset( $filtered_map[ $definition['raw_key'] ] ) ) {
				unset( $definitions[ $id ] );
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
		$fallback = null;
		foreach ( self::get_definitions() as $definition ) {
			if ( $definition['name'] !== $name ) {
				continue;
			}
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
		$matches = [];
		foreach ( self::get_definitions() as $definition ) {
			if ( $definition['name'] === $name && $definition['version'] === $version ) {
				$matches[] = $definition;
			}
		}
		return $matches;
	}

	/**
	 * Get conflict groups: ESP names claimed by both schema versions.
	 *
	 * @return array Map of ESP name => list of definition ids.
	 */
	public static function get_conflict_groups() {
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
}
