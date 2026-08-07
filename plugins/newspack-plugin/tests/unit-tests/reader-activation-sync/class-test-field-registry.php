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
	 * Definitions are keyed by a `{version}:{raw_key}` id. The id is the raw
	 * key's version-qualified spelling and is independent of the ESP name, so
	 * a v2 field that was renamed to stop colliding with its legacy
	 * counterpart keeps its id and changes only its `name`.
	 */
	public function test_ids_are_version_qualified() {
		$defs = Field_Registry::get_definitions();
		$this->assertArrayHasKey( 'v1:last_payment_amount', $defs );
		$this->assertArrayHasKey( 'v2:Last_Payment_Amount', $defs );
		$this->assertSame( 'v1', $defs['v1:last_payment_amount']['version'] );
		$this->assertSame( 'Last Payment Amount', $defs['v1:last_payment_amount']['name'] );
		$this->assertSame( 'Last Subscription Payment Amount', $defs['v2:Last_Payment_Amount']['name'] );
	}

	/**
	 * The structural guarantee schema coexistence rests on: no ESP field name
	 * is claimed by both schemas, so a publisher can enable both versions of
	 * any field at once and nothing has to arbitrate between them.
	 *
	 * Every collision is dissolved one of two ways — same-meaning pairs
	 * declare the v2 field `equivalent` and collapse into one field, and
	 * changed-meaning v2 fields carry their own ESP name. A failure here means
	 * a new or renamed field has re-created a conflict: fix the field, not
	 * this test. Restoring the pick-one save rule this replaced would break
	 * dual-schema sync.
	 */
	public function test_no_esp_name_is_claimed_by_both_schemas() {
		$this->assertSame( [], Field_Registry::get_conflict_groups() );

		// Not vacuous: shared names still exist, they are collapsed rather than
		// contested. A derivation that returned [] because it stopped seeing
		// shared names at all would pass the assertion above on its own.
		$this->assertSame( 'v1:account', Field_Registry::get_by_name( 'Account', 'v1' )['id'] );
		$this->assertSame( 'v2:Account', Field_Registry::get_by_name( 'Account', 'v2' )['id'] );
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
	 * Content Gate fields are registered as version-neutral and are always
	 * excluded from conflict groups.
	 *
	 * This must reach past get_conflict_groups(), which is permanently empty
	 * (see test_no_esp_name_is_claimed_by_both_schemas) — a loop over its
	 * return value would never iterate, so it could never actually catch a
	 * neutral field sneaking into a group. Reflection reaches the private raw
	 * derivation, get_name_collision_groups(), instead — its groups are not
	 * empty, so this exercises real data and actually verifies neutral fields
	 * are excluded, rather than passing vacuously.
	 */
	public function test_content_gate_is_neutral() {
		$defs    = Field_Registry::get_definitions();
		$neutral = array_filter( $defs, fn( $d ) => 'neutral' === $d['version'] && null !== $d['class'] );
		$this->assertNotEmpty( $neutral );

		$method = new \ReflectionMethod( Field_Registry::class, 'get_name_collision_groups' );
		$method->setAccessible( true );
		$collision_groups = $method->invoke( null );
		$this->assertNotEmpty( $collision_groups, 'This must exercise real collision groups, not an empty derivation.' );

		// Neutral fields never form (raw) collision groups.
		foreach ( $collision_groups as $ids ) {
			foreach ( $ids as $id ) {
				$this->assertNotSame( 'neutral', Field_Registry::get_definition( $id )['version'] );
			}
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
	 * `get_by_name()` prefers the requested version when both schemas declare
	 * the name, and still resolves an unqualified lookup to some definition.
	 *
	 * Since the conflicting names were dissolved, the only remaining shape
	 * where one name maps to both versions is a collapsed equivalent pair —
	 * equivalence does not rewrite either definition's name, it only decides
	 * which id survives a save.
	 */
	public function test_get_by_name_prefers_requested_version() {
		$v1 = Field_Registry::get_by_name( 'Total Paid', 'v1' );
		$v2 = Field_Registry::get_by_name( 'Total Paid', 'v2' );
		$this->assertSame( 'v1:total_paid', $v1['id'] );
		$this->assertSame( 'v2:Total_Paid', $v2['id'] );
		// Unqualified lookup returns some definition for the name.
		$this->assertNotNull( Field_Registry::get_by_name( 'Newsletter Selection' ) );
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
	 * A v2 field that renames/replaces a v1 field declares `supersedes`
	 * with the v1 id; the registry derives the reverse `superseded_by`
	 * link onto the v1 definition.
	 *
	 * The Content Gate "Content Access" field is registered as
	 * version-neutral (see Field_Registry::get_class_map()), so its id is
	 * `neutral:Content_Access`, not `v2:Content_Access`.
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

		$definition = Field_Registry::get_by_name( 'Renamed Account' );

		\remove_filter( 'newspack_ras_metadata_keys', $rename );
		Field_Registry::reset();

		$this->assertNotNull( $definition, 'A renamed field must resolve under its filtered label.' );
		$this->assertSame( 'v1:account', $definition['id'] );
	}

	/**
	 * Name resolution encodes the shared invariant: every same-version
	 * definition sharing the name, falling back to a single any-version
	 * match (which is what covers version-neutral fields).
	 */
	public function test_resolve_name_prefers_version_then_falls_back() {
		// "Registration Page" is declared by two v1 raw keys.
		$v1 = Field_Registry::resolve_name( 'Registration Page', 'v1' );
		$this->assertGreaterThan( 1, count( $v1 ) );
		foreach ( $v1 as $definition ) {
			$this->assertSame( 'v1', $definition['version'] );
		}

		// A v2-only name resolves through the any-version fallback.
		$fallback = Field_Registry::resolve_name( 'User Role', 'v1' );
		$this->assertCount( 1, $fallback );
		$this->assertSame( 'v2', $fallback[0]['version'] );

		$this->assertSame( [], Field_Registry::resolve_name( 'No Such Field', 'v1' ) );
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
		$this->assertFalse( $row['in_conflict_group'] );
		$this->assertIsBool( $row['available'] );
		$this->assertIsBool( $row['dynamic_suffix'] );
		$this->assertIsArray( $row['superseded_by'] );
		$this->assertArrayNotHasKey( 'class', $row );

		// Conflict membership: Last Payment Amount exists in both schemas.
		$this->assertTrue( $by_id['v1:last_payment_amount']['in_conflict_group'] );
		$this->assertTrue( $by_id['v2:Last_Payment_Amount']['in_conflict_group'] );

		// Equivalence is serialized for the UI's row collapse.
		$this->assertTrue( $by_id['v2:Account']['equivalent'] );
		$this->assertFalse( $by_id['v2:Last_Payment_Amount']['equivalent'] );
		$this->assertFalse( $by_id['v1:account']['equivalent'] );

		// Superseded side of a rename carries the reverse link.
		$this->assertContains( 'v2:Registration_Strategy', $by_id['v1:registration_method']['superseded_by'] );

		// Fields with no declared status serialize with safe defaults.
		foreach ( $rows as $r ) {
			$this->assertContains( $r['status'], [ 'new', 'updated', 'existing' ] );
			$this->assertIsString( $r['section'] );
		}
	}

	/**
	 * Value-equivalent pairs (declared on the v2 config) upgrade their v1 ids
	 * to the v2 twin at storage time. A v1 field whose v2 counterpart carries
	 * its own ESP name is a separate field, not a pair, so it passes through
	 * untouched — as do v2 ids and unknown ids.
	 */
	public function test_equivalent_pairs_upgrade_and_alias() {
		$this->assertSame(
			[ 'v2:Account', 'v1:last_payment_amount' ],
			Field_Registry::upgrade_equivalent_ids( [ 'v1:account', 'v1:last_payment_amount' ] )
		);
		$this->assertSame(
			[ 'v2:Connected_Account' ],
			Field_Registry::upgrade_equivalent_ids( [ 'v1:connected_account', 'v2:Connected_Account' ] )
		);
		// The v2 twin accepts the v1 raw key as an input alias; separately
		// named v2 fields and v1 ids alias nothing.
		$this->assertSame( [ 'account' ], Field_Registry::get_equivalent_input_raw_keys( 'v2:Account' ) );
		$this->assertSame( [], Field_Registry::get_equivalent_input_raw_keys( 'v2:Last_Payment_Amount' ) );
		$this->assertSame( [], Field_Registry::get_equivalent_input_raw_keys( 'v1:account' ) );
	}

	/**
	 * Equivalence spans every legacy raw key sharing the name. The legacy
	 * schema maps two raw keys to "Registration Page" — `registration_page`
	 * and the event-time `current_page_url` — and the v2 field is equivalent
	 * to both: they are the same value at two moments, since every
	 * registration producer of `current_page_url` writes it to the same user
	 * meta the v2 field reads. Both have to upgrade to the v2 twin, and both
	 * have to alias onto it as inputs, or a hand-built contact carrying the
	 * legacy key would silently stop syncing.
	 */
	public function test_equivalence_spans_multiple_legacy_raw_keys() {
		$this->assertSame(
			[ 'v2:Registration_Page' ],
			Field_Registry::upgrade_equivalent_ids( [ 'v1:registration_page', 'v1:current_page_url' ] )
		);
		$this->assertEqualsCanonicalizing(
			[ 'registration_page', 'current_page_url' ],
			Field_Registry::get_equivalent_input_raw_keys( 'v2:Registration_Page' )
		);
	}
}
