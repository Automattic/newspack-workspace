<?php
/**
 * Tests for Field_Registry.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Unit\Reader_Activation_Sync;

use Newspack\Reader_Activation\Sync\Contact_Metadata;
use Newspack\Reader_Activation\Sync\Field_Registry;

/**
 * Field_Registry tests.
 *
 * @group field-registry
 */
class Test_Field_Registry extends \WP_UnitTestCase {

	/**
	 * Reset the registry's static cache after each test.
	 */
	public function tear_down() {
		Field_Registry::reset();
		parent::tear_down();
	}

	/**
	 * Group every definition by its ESP name.
	 *
	 * @return array<string, array[]> Map of ESP name => definitions.
	 */
	private function definitions_by_name() {
		$by_name = [];
		foreach ( Field_Registry::get_definitions() as $definition ) {
			$by_name[ $definition['name'] ][] = $definition;
		}
		return $by_name;
	}

	/**
	 * Definitions are keyed by a `{version}:{raw_key}` id — the raw key's
	 * version-qualified spelling, independent of the ESP name. A v2 field
	 * that was renamed to stop colliding with its legacy counterpart carries
	 * its own distinct raw key, so its id and its `name` both differ from
	 * the legacy definition's.
	 */
	public function test_ids_are_version_qualified() {
		$defs = Field_Registry::get_definitions();
		$this->assertArrayHasKey( 'v1:last_payment_amount', $defs );
		$this->assertArrayHasKey( 'v2:Last_Subscription_Payment_Amount', $defs );
		$this->assertSame( 'v1', $defs['v1:last_payment_amount']['version'] );
		$this->assertSame( 'Last Payment Amount', $defs['v1:last_payment_amount']['name'] );
		$this->assertSame( 'Last Subscription Payment Amount', $defs['v2:Last_Subscription_Payment_Amount']['name'] );
	}

	/**
	 * The permanent invariant behind coexistence: no ESP field name may be
	 * claimed by both schemas, UNLESS the v2 member declares itself
	 * `equivalent` and points `supersedes` at the v1 member it collapses.
	 *
	 * Without that rule two producers write one ESP key and the payload
	 * depends on merge order; with it, name resolution has one answer.
	 * Asserted directly over the definitions rather than through a derivation
	 * that could go vacuous.
	 */
	public function test_no_esp_name_is_claimed_by_both_schemas() {
		$shared = 0;
		foreach ( $this->definitions_by_name() as $name => $definitions ) {
			$versions = array_column( $definitions, 'version' );
			if ( ! in_array( 'v1', $versions, true ) || ! in_array( 'v2', $versions, true ) ) {
				continue;
			}
			++$shared;

			$v1_ids = [];
			foreach ( $definitions as $definition ) {
				if ( 'v1' === $definition['version'] ) {
					$v1_ids[] = $definition['id'];
				}
			}
			foreach ( $definitions as $definition ) {
				if ( 'v2' !== $definition['version'] ) {
					continue;
				}
				$this->assertNotEmpty(
					$definition['equivalent'] ?? null,
					"{$definition['id']} shares the ESP name \"{$name}\" with the legacy schema without declaring equivalence."
				);
				$this->assertContains(
					$definition['supersedes'] ?? null,
					$v1_ids,
					"{$definition['id']} must supersede the legacy definition it shares \"{$name}\" with."
				);
			}
		}

		// Not vacuous: shared names really do exist, they are collapsed rather
		// than contested.
		$this->assertSame( 4, $shared, 'The four value-equivalent pairs are the only shared ESP names.' );
	}

	/**
	 * A shared name resolves to the surviving v2 member alone, so a migrating
	 * site can never end up with both producers writing one ESP key. A name
	 * only one schema claims resolves to that schema's definitions.
	 */
	public function test_resolve_name_collapses_equivalent_pairs() {
		$this->assertSame(
			[ 'v2:Account' ],
			array_column( Field_Registry::resolve_name( 'Account' ), 'id' )
		);

		// Both legacy raw keys for "Registration Page" collapse onto the one
		// surviving definition.
		$this->assertSame(
			[ 'v2:Registration_Page' ],
			array_column( Field_Registry::resolve_name( 'Registration Page' ), 'id' )
		);

		// A renamed v2 field is a different field, not a pair: each name keeps
		// its own definition.
		$this->assertSame(
			[ 'v1:registration_method' ],
			array_column( Field_Registry::resolve_name( 'Registration Method' ), 'id' )
		);
		$this->assertSame(
			[ 'v2:Registration_Strategy' ],
			array_column( Field_Registry::resolve_name( 'Registration Strategy' ), 'id' )
		);

		$this->assertSame( [], Field_Registry::resolve_name( 'No Such Field' ) );
	}

	/**
	 * The legacy UTM fields that carry a dynamic suffix (source/medium/...)
	 * are flagged as such; a static v2 field is not.
	 */
	public function test_dynamic_suffix_fields_flagged() {
		$defs = Field_Registry::get_definitions();
		$this->assertTrue( $defs['v1:signup_page_utm']['dynamic_suffix'] );
		$this->assertTrue( $defs['v1:payment_page_utm']['dynamic_suffix'] );
		$this->assertFalse( $defs['v2:Registration_UTM_Source']['dynamic_suffix'] );
	}

	/**
	 * Content Gate fields are version-neutral: they belong to every version's
	 * default set, and never share an ESP name with a versioned field (which
	 * would put them on the wrong side of the coexistence invariant).
	 */
	public function test_content_gate_is_neutral() {
		$defs    = Field_Registry::get_definitions();
		$neutral = array_filter( $defs, fn( $d ) => 'neutral' === $d['version'] && null !== $d['class'] );
		$this->assertNotEmpty( $neutral );

		foreach ( $neutral as $definition ) {
			$this->assertSame(
				[ $definition['id'] ],
				array_column( $this->definitions_by_name()[ $definition['name'] ], 'id' ),
				"Version-neutral field {$definition['id']} must not share its ESP name."
			);
		}
	}

	/**
	 * Raw keys added by the `newspack_ras_metadata_keys` filter that don't
	 * belong to any known class are ingested as version-neutral definitions.
	 */
	public function test_filter_extras_are_ingested_as_neutral() {
		$callback = function ( $keys ) {
			$keys['custom_extra'] = 'Custom Extra';
			return $keys;
		};
		add_filter( 'newspack_ras_metadata_keys', $callback );
		Field_Registry::reset();

		$def = Field_Registry::get_definition( 'neutral:custom_extra' );
		$this->assertNotNull( $def );
		$this->assertSame( 'Custom Extra', $def['name'] );
		$this->assertNull( $def['class'] );

		remove_filter( 'newspack_ras_metadata_keys', $callback );
		Field_Registry::reset();
	}

	/**
	 * A name only the legacy schema claims resolves to the legacy definition —
	 * canonicalization applies to pairs, not to every name.
	 */
	public function test_legacy_only_name_resolves_to_its_own_definition() {
		$this->assertSame(
			[ 'v1:newsletter_selection' ],
			array_column( Field_Registry::resolve_name( 'Newsletter Selection' ), 'id' )
		);
	}

	/**
	 * Every class-owned definition (i.e. not a filter-added extra) must
	 * carry a description, since these back the Phase-2 field-picker UI.
	 */
	public function test_all_class_owned_definitions_have_descriptions() {
		foreach ( Field_Registry::get_definitions() as $id => $definition ) {
			if ( null === $definition['class'] ) {
				continue; // Filter extras carry no authored metadata.
			}
			$this->assertNotEmpty( $definition['description'] ?? '', "Missing description for {$id}" );
		}
	}

	/**
	 * A v2 field that renames/replaces a v1 field declares `supersedes` with
	 * the v1 id, and the registry derives the reverse `superseded_by` link
	 * onto the v1 definition — including the version-neutral Content Access
	 * field, whose id is `neutral:Content_Access`, not `v2:Content_Access`.
	 */
	public function test_supersedes_links_are_bidirectional() {
		$defs = Field_Registry::get_definitions();
		$this->assertSame( 'v1:signup_page_utm', $defs['v2:Registration_UTM_Source']['supersedes'] );
		$this->assertContains( 'v2:Registration_UTM_Source', $defs['v1:signup_page_utm']['superseded_by'] );
		$this->assertSame( 'v1:membership_status', $defs['neutral:Content_Access']['supersedes'] ?? null );
		$this->assertContains( 'neutral:Content_Access', $defs['v1:membership_status']['superseded_by'] );
	}

	/**
	 * Each metadata class's get_fields_config() must annotate exactly the
	 * keys its get_fields() declares -- no more, no less -- so the rich
	 * config can never silently drift from the field list the class
	 * actually exposes.
	 */
	public function test_fields_config_key_sets_match_get_fields() {
		$classes = [
			Contact_Metadata\Legacy_Basic::class,
			Contact_Metadata\Legacy_Payment::class,
			Contact_Metadata\Identity::class,
			Contact_Metadata\Registration::class,
			Contact_Metadata\Engagement::class,
			Contact_Metadata\Subscription::class,
			Contact_Metadata\Donation::class,
			Contact_Metadata\Content_Gate::class,
		];
		foreach ( $classes as $class ) {
			$this->assertSame(
				array_keys( $class::get_fields() ),
				array_keys( $class::get_fields_config() ),
				"get_fields()/get_fields_config() key-set drift in {$class}"
			);
		}
	}

	/**
	 * A `newspack_ras_metadata_keys` callback that renames an existing field
	 * must rename its registry definition too. Otherwise name-based
	 * resolution (defaults, migration, settings saves) looks up the filtered
	 * label, finds no definition, and the field silently drops out of
	 * outgoing sync.
	 */
	public function test_filter_rename_is_adopted_by_definitions() {
		$rename = function ( $keys ) {
			$keys['account'] = 'Renamed Account';
			return $keys;
		};
		\add_filter( 'newspack_ras_metadata_keys', $rename );
		Field_Registry::reset();

		$definitions = Field_Registry::resolve_name( 'Renamed Account' );

		\remove_filter( 'newspack_ras_metadata_keys', $rename );
		Field_Registry::reset();

		$this->assertNotEmpty( $definitions, 'A renamed field must resolve under its filtered label.' );
		$this->assertSame( 'v1:account', $definitions[0]['id'] );
	}

	/**
	 * Registration lookup tells an unknown (explicitly-injected) prefixed
	 * field from a registered one, including dynamic-suffix matches — the
	 * distinction prepare_contact() uses to decide pass-through vs drop.
	 */
	public function test_name_is_registered_covers_dynamic_suffixes() {
		$this->assertTrue( Field_Registry::name_is_registered( 'Account' ) );
		$this->assertTrue( Field_Registry::name_is_registered( 'Signup UTM: source' ) );
		$this->assertFalse( Field_Registry::name_is_registered( 'Totally Custom Field' ) );
	}

	/**
	 * The settings serialization carries everything the per-field UI renders,
	 * for every version, and nothing internal (no class references).
	 */
	public function test_definitions_for_settings_shape() {
		$rows  = Field_Registry::get_definitions_for_settings();
		$by_id = array_column( $rows, null, 'id' );

		$this->assertArrayHasKey( 'v1:account', $by_id );
		$this->assertArrayHasKey( 'v2:Registration_Strategy', $by_id );

		$row = $by_id['v2:Registration_Strategy'];
		$this->assertSame( 'Registration Strategy', $row['name'] );
		$this->assertSame( 'v2', $row['version'] );
		$this->assertSame( 'Registration_Strategy', $row['raw_key'] );
		$this->assertSame( 'new', $row['status'] );
		$this->assertSame( 'v1:registration_method', $row['supersedes'] );
		$this->assertNotEmpty( $row['description'] );
		$this->assertNotEmpty( $row['example'] );
		$this->assertIsBool( $row['available'] );
		$this->assertIsBool( $row['dynamic_suffix'] );
		$this->assertIsArray( $row['superseded_by'] );
		$this->assertArrayNotHasKey( 'class', $row );

		// No conflict or equivalence flags: the UI has no version choice to
		// offer, and reads a collapsed pair off the pair itself.
		$this->assertArrayNotHasKey( 'in_conflict_group', $row );
		$this->assertArrayNotHasKey( 'equivalent', $row );

		// Superseded side of a rename carries the reverse link.
		$this->assertContains( 'v2:Registration_Strategy', $by_id['v1:registration_method']['superseded_by'] );

		// `status` is what the badges and sunset rule key on: new/updated badge
		// New (legacy is unbadged — it sorts into its own Legacy-last section
		// instead), and a field with no declared status is a safe 'existing'.
		$this->assertSame( 'legacy', $by_id['v1:account']['status'] );
		foreach ( $rows as $r ) {
			$this->assertContains( $r['status'], [ 'new', 'updated', 'legacy', 'existing' ] );
			$this->assertIsString( $r['section'] );
		}
	}

	/**
	 * Every renamed v2 field (supersedes a v1 field under a DIFFERENT ESP
	 * name) must carry a badge-worthy status, so the UI shows it as New.
	 */
	public function test_renamed_fields_carry_badge_worthy_status() {
		$definitions = Field_Registry::get_definitions();
		$checked     = 0;
		foreach ( $definitions as $definition ) {
			if ( 'v2' !== $definition['version'] || empty( $definition['supersedes'] ) ) {
				continue;
			}
			$superseded = $definitions[ $definition['supersedes'] ] ?? null;
			if ( ! $superseded || $superseded['name'] === $definition['name'] ) {
				continue;
			}
			++$checked;
			$this->assertContains(
				$definition['status'],
				[ 'new', 'updated' ],
				"Renamed field {$definition['id']} must carry a badge-worthy status."
			);
		}
		$this->assertGreaterThanOrEqual( 6, $checked );
	}

	/**
	 * Stored ids are never rewritten, so raw-key aliasing runs in both
	 * directions: whichever member of a pair a site has stored must accept
	 * the other member's raw key as input. A v2 field with its own ESP name
	 * is a separate field, not a pair, and aliases nothing.
	 */
	public function test_equivalent_pairs_alias_raw_keys_both_ways() {
		$this->assertSame( [ 'account' ], Field_Registry::get_equivalent_input_raw_keys( 'v2:Account' ) );
		$this->assertSame( [ 'Account' ], Field_Registry::get_equivalent_input_raw_keys( 'v1:account' ) );

		$this->assertSame( [], Field_Registry::get_equivalent_input_raw_keys( 'v2:Last_Subscription_Payment_Amount' ) );
		$this->assertSame( [], Field_Registry::get_equivalent_input_raw_keys( 'v1:last_payment_amount' ) );
	}

	/**
	 * A pair spans every legacy raw key sharing its ESP name: both
	 * `registration_page` and `current_page_url` mean "Registration Page", so
	 * both alias onto the surviving definition and it aliases back onto them.
	 */
	public function test_equivalence_spans_multiple_legacy_raw_keys() {
		$this->assertEqualsCanonicalizing(
			[ 'registration_page', 'current_page_url' ],
			Field_Registry::get_equivalent_input_raw_keys( 'v2:Registration_Page' )
		);
		$this->assertSame( [ 'Registration_Page' ], Field_Registry::get_equivalent_input_raw_keys( 'v1:current_page_url' ) );
	}

	/**
	 * Ids are recognised by shape, not by resolving them — an id for a
	 * definition a deactivated plugin no longer declares is still an id, and
	 * must not be mistaken for a bare display name needing migration.
	 */
	public function test_is_field_id_matches_shape_only() {
		$this->assertTrue( Field_Registry::is_field_id( 'v1:account' ) );
		$this->assertTrue( Field_Registry::is_field_id( 'v2:Account' ) );
		$this->assertTrue( Field_Registry::is_field_id( 'neutral:Content_Access' ) );
		$this->assertTrue( Field_Registry::is_field_id( 'v2:no_such_definition' ) );
		$this->assertFalse( Field_Registry::is_field_id( 'Account' ) );
		$this->assertFalse( Field_Registry::is_field_id( 'v3:account' ) );
		$this->assertFalse( Field_Registry::is_field_id( '' ) );
	}

	/**
	 * The derived default selection is the same list seeding would persist:
	 * one schema version's definitions plus the version-neutral ones, never
	 * the merged set.
	 */
	public function test_default_field_ids_are_scoped_to_one_version() {
		$ids = Field_Registry::get_default_field_ids();

		$this->assertNotEmpty( $ids );
		$versions = [];
		foreach ( $ids as $id ) {
			$versions[ Field_Registry::get_definition( $id )['version'] ] = true;
		}
		unset( $versions[ Field_Registry::VERSION_NEUTRAL ] );
		$this->assertCount( 1, $versions, 'A derived default must never mix both schemas.' );
	}
}
