<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests the merged v1/v2 field catalog invariants.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Sync\Contact_Metadata;

/**
 * The names-canonical design only holds while no ESP name is contested:
 * a name shared across schemas must belong to a sanctioned value-equivalent
 * pair, and raw keys must never collide at all.
 */
class Test_Merged_Catalog extends WP_UnitTestCase {

	const CLASSES = [
		Contact_Metadata\Legacy_Basic::class,
		Contact_Metadata\Legacy_Payment::class,
		Contact_Metadata\Identity::class,
		Contact_Metadata\Registration::class,
		Contact_Metadata\Engagement::class,
		Contact_Metadata\Subscription::class,
		Contact_Metadata\Donation::class,
		Contact_Metadata\Content_Gate::class,
	];

	const LEGACY_CLASSES = [
		Contact_Metadata\Legacy_Basic::class,
		Contact_Metadata\Legacy_Payment::class,
	];

	/**
	 * The 4 value-equivalent pairs: ESP name => sanctioned raw keys.
	 */
	const SANCTIONED_SHARED_NAMES = [
		'Account'           => [ 'account', 'Account' ],
		'Connected Account' => [ 'connected_account', 'Connected_Account' ],
		'Registration Date' => [ 'registration_date', 'Registration_Date' ],
		'Registration Page' => [ 'registration_page', 'current_page_url', 'Registration_Page' ],
	];

	public function test_raw_keys_never_collide_across_classes() {
		$seen = [];
		foreach ( self::CLASSES as $class ) {
			foreach ( array_keys( $class::get_fields() ) as $raw_key ) {
				// Null-coalesce: $seen[$raw_key] is unset on the (expected) non-colliding
				// path, and PHP 8 raises "Undefined array key" on reading it there even
				// though it's only for the failure message — this arg is built eagerly
				// regardless of whether the assertion passes.
				$this->assertArrayNotHasKey( $raw_key, $seen, "Raw key '$raw_key' declared by both " . ( $seen[ $raw_key ] ?? '' ) . " and $class." );
				$seen[ $raw_key ] = $class;
			}
		}
	}

	public function test_names_shared_across_schemas_only_within_sanctioned_pairs() {
		$by_name = [];
		foreach ( self::CLASSES as $class ) {
			$is_legacy = in_array( $class, self::LEGACY_CLASSES, true );
			foreach ( $class::get_fields() as $raw_key => $name ) {
				$by_name[ $name ][] = [
					'raw_key' => $raw_key,
					'legacy'  => $is_legacy,
				];
			}
		}
		foreach ( $by_name as $name => $members ) {
			$eras = array_unique( array_column( $members, 'legacy' ) );
			if ( count( $eras ) < 2 ) {
				continue; // Single-schema name (incl. legacy same-name siblings): no contest.
			}
			$this->assertArrayHasKey( $name, self::SANCTIONED_SHARED_NAMES, "Name '$name' is contested across schemas without a sanctioned pair." );
			$this->assertEqualsCanonicalizing( self::SANCTIONED_SHARED_NAMES[ $name ], array_column( $members, 'raw_key' ), "Pair '$name' has unexpected members." );
		}
	}

	public function test_every_field_has_a_status_in_config() {
		foreach ( self::CLASSES as $class ) {
			$config = $class::get_fields_config();
			foreach ( $class::get_fields() as $raw_key => $name ) {
				$this->assertArrayHasKey( $raw_key, $config, "$class config missing $raw_key." );
				$this->assertSame( $name, $config[ $raw_key ]['name'], "$class config name mismatch for $raw_key." );
				$this->assertNotEmpty( $config[ $raw_key ]['status'] ?? '', "$class config missing status for $raw_key." );
			}
			$expected_status = in_array( $class, self::LEGACY_CLASSES, true ) ? [ 'legacy' ] : [ 'existing', 'new', 'updated' ];
			foreach ( $config as $raw_key => $field_config ) {
				$this->assertContains( $field_config['status'], $expected_status, "$class $raw_key has out-of-place status '{$field_config['status']}'." );
			}
		}
	}
}
