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
		// Spelled out because production code no longer exposes a constant
		// for it (see test_get_derivation_schema_version_memoizes_detection).
		\delete_option( 'newspack_sync_schema_origin' );
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
	 * No ESP field name is claimed by both schemas — every naming collision
	 * is dissolved by collapsing into an equivalent pair or renaming apart —
	 * so a publisher can enable both versions of a field at once.
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
	 * Content Gate fields are version-neutral, so they never appear in a
	 * conflict group. Checked via the private raw collision-group derivation
	 * (not the always-empty public API), so the test exercises real data.
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
	 * a name, and still resolves an unqualified lookup to some definition.
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
	 * Equivalence spans every legacy raw key sharing a name: both
	 * `registration_page` and `current_page_url` map to "Registration Page",
	 * and both must upgrade to, and alias onto, the same v2 twin.
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

	/**
	 * Schema-version derivation is memoized per request. Verified via
	 * detect_retired_schema_version()'s corrupt-marker cleanup, a side
	 * effect only a real detection run performs.
	 */
	public function test_get_derivation_schema_version_memoizes_detection() {
		Field_Registry::reset();
		$marker = 'newspack_sync_schema_origin';
		\update_option( $marker, 'not-a-real-version' );

		$first = Field_Registry::get_derivation_schema_version();

		$this->assertFalse( \get_option( $marker ), 'The first call must run detection and clear the corrupt marker.' );

		\update_option( $marker, 'not-a-real-version' );

		$second = Field_Registry::get_derivation_schema_version();

		$this->assertSame( $first, $second, 'The memoized call must answer identically to the first.' );
		$this->assertSame(
			'not-a-real-version',
			\get_option( $marker ),
			'A second call must be served from the per-request cache, not re-run detection.'
		);

		Field_Registry::reset();
		Field_Registry::get_derivation_schema_version();

		$this->assertFalse(
			\get_option( $marker ),
			'reset() must clear the cache, so the next call re-runs detection.'
		);
	}
}
