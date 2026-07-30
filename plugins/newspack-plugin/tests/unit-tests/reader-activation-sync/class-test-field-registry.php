<?php
/**
 * Tests for Field_Registry.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Unit\Reader_Activation_Sync;

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
	 * Definitions are keyed by a `{version}:{raw_key}` id, and the same ESP
	 * name is preserved across both the legacy and new raw keys.
	 */
	public function test_ids_are_version_qualified() {
		$defs = Field_Registry::get_definitions();
		$this->assertArrayHasKey( 'v1:last_payment_amount', $defs );
		$this->assertArrayHasKey( 'v2:Last_Payment_Amount', $defs );
		$this->assertSame( 'v1', $defs['v1:last_payment_amount']['version'] );
		$this->assertSame( 'Last Payment Amount', $defs['v1:last_payment_amount']['name'] );
		$this->assertSame( 'Last Payment Amount', $defs['v2:Last_Payment_Amount']['name'] );
	}

	/**
	 * Conflict groups are derived from ESP names claimed by both the v1 and
	 * v2 schemas; names unique to a single version never form a group.
	 */
	public function test_conflict_groups_derived_from_shared_names() {
		$groups = Field_Registry::get_conflict_groups();
		$expected_names = [
			'Account',
			'Connected Account',
			'Registration Date',
			'Registration Page',
			'Payment Page',
			'Current Subscription Start Date',
			'Current Subscription End Date',
			'Subscription Cancellation Reason',
			'Last Payment Date',
			'Last Payment Amount',
			'Total Paid',
		];
		$this->assertEqualsCanonicalizing( $expected_names, array_keys( $groups ) );
		$this->assertEqualsCanonicalizing(
			[ 'v1:last_payment_amount', 'v2:Last_Payment_Amount' ],
			$groups['Last Payment Amount']
		);
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
	 */
	public function test_content_gate_is_neutral() {
		$defs    = Field_Registry::get_definitions();
		$neutral = array_filter( $defs, fn( $d ) => 'neutral' === $d['version'] && null !== $d['class'] );
		$this->assertNotEmpty( $neutral );
		// Neutral fields never form conflict groups.
		foreach ( Field_Registry::get_conflict_groups() as $ids ) {
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
	 * `get_by_name()` prefers the requested version on a name collision, and
	 * still resolves an unqualified lookup to some definition.
	 */
	public function test_get_by_name_prefers_requested_version() {
		$v1 = Field_Registry::get_by_name( 'Last Payment Amount', 'v1' );
		$v2 = Field_Registry::get_by_name( 'Last Payment Amount', 'v2' );
		$this->assertSame( 'v1:last_payment_amount', $v1['id'] );
		$this->assertSame( 'v2:Last_Payment_Amount', $v2['id'] );
		// Unqualified lookup returns some definition for the name.
		$this->assertNotNull( Field_Registry::get_by_name( 'Newsletter Selection' ) );
	}
}
