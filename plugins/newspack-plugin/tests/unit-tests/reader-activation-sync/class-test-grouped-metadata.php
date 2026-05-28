<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests Metadata::get_grouped_default_fields().
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Sync\Metadata;

/**
 * Test grouped default fields.
 *
 * @group Grouped_Metadata
 */
class Test_Grouped_Metadata extends WP_UnitTestCase {

	/**
	 * Schema version restored in tear_down().
	 *
	 * @var string
	 */
	private static $original_version;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$original_version = Metadata::$version;
	}

	public function set_up() {
		parent::set_up();
		// Force the v1.0 schema so Identity / Registration / Engagement etc.
		// participate in get_metadata_classes() (legacy classes return empty
		// section names and would all fall into the "Additional" bucket).
		Metadata::$version = '1.0';
	}

	public function tear_down() {
		Metadata::$version = self::$original_version;
		remove_all_filters( 'newspack_ras_metadata_keys' );
		remove_all_filters( 'newspack_ras_grouped_metadata_fields' );
		parent::tear_down();
	}

	/**
	 * Helper: pluck section names from the grouped result.
	 *
	 * @param array $groups Result of get_grouped_default_fields().
	 * @return string[]
	 */
	private function get_section_names( $groups ) {
		return array_column( $groups, 'section' );
	}

	/**
	 * Helper: locate a group by section name.
	 *
	 * @param array  $groups Result of get_grouped_default_fields().
	 * @param string $section Section name to find.
	 * @return array|null
	 */
	private function find_group( $groups, $section ) {
		foreach ( $groups as $group ) {
			if ( $group['section'] === $section ) {
				return $group;
			}
		}
		return null;
	}

	public function test_returns_groups_with_expected_section_names() {
		$groups   = Metadata::get_grouped_default_fields();
		$sections = $this->get_section_names( $groups );

		// Identity, Registration and Engagement classes are unconditionally
		// available (no WC dependency) and define non-empty section names,
		// so they must appear in the result.
		$this->assertContains( 'Identity', $sections );
		$this->assertContains( 'Registration', $sections );
		$this->assertContains( 'Engagement', $sections );

		// Each group has the expected shape.
		foreach ( $groups as $group ) {
			$this->assertArrayHasKey( 'section', $group );
			$this->assertArrayHasKey( 'fields', $group );
			$this->assertNotEmpty( $group['section'] );
			$this->assertIsArray( $group['fields'] );
			$this->assertNotEmpty( $group['fields'] );
		}
	}

	public function test_filter_removed_fields_drop_out_of_groups() {
		// Drop a known Identity label via the metadata-keys filter.
		add_filter(
			'newspack_ras_metadata_keys',
			function ( $keys ) {
				unset( $keys['email'] );
				return $keys;
			}
		);

		$groups   = Metadata::get_grouped_default_fields();
		$identity = $this->find_group( $groups, 'Identity' );

		$this->assertNotNull( $identity, 'Identity group should still be present.' );
		$this->assertNotContains( 'Email', $identity['fields'] );
		// Sanity check: another Identity field is still there.
		$this->assertContains( 'First name', $identity['fields'] );
	}

	public function test_class_drops_when_all_its_fields_are_filtered_out() {
		// Remove every Identity field. The Identity group should disappear
		// because the intersection becomes empty.
		add_filter(
			'newspack_ras_metadata_keys',
			function ( $keys ) {
				$identity_keys = array_keys( \Newspack\Reader_Activation\Sync\Contact_Metadata\Identity::get_fields() );
				foreach ( $identity_keys as $key ) {
					unset( $keys[ $key ] );
				}
				return $keys;
			}
		);

		$groups   = Metadata::get_grouped_default_fields();
		$sections = $this->get_section_names( $groups );

		$this->assertNotContains( 'Identity', $sections );
	}

	public function test_filter_added_orphan_fields_land_in_additional_bucket() {
		// Append a field that doesn't belong to any class.
		add_filter(
			'newspack_ras_metadata_keys',
			function ( $keys ) {
				$keys['custom_orphan'] = 'Custom Orphan Label';
				return $keys;
			}
		);

		$groups     = Metadata::get_grouped_default_fields();
		$additional = $this->find_group( $groups, 'Additional' );

		$this->assertNotNull( $additional, 'Orphan field should produce an Additional bucket.' );
		$this->assertContains( 'Custom Orphan Label', $additional['fields'] );
	}

	public function test_grouped_filter_is_applied() {
		$replacement = [
			[
				'section' => 'Replaced',
				'fields'  => [ 'Only Field' ],
			],
		];
		add_filter(
			'newspack_ras_grouped_metadata_fields',
			function () use ( $replacement ) {
				return $replacement;
			}
		);

		$this->assertSame( $replacement, Metadata::get_grouped_default_fields() );
	}
}
