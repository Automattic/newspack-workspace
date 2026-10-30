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

	public function tear_down() {
		remove_all_filters( 'newspack_ras_metadata_keys' );
		remove_all_filters( 'newspack_ras_grouped_metadata_fields' );
		Metadata::flush_fields_cache();
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

	public function test_legacy_fields_are_grouped_under_legacy_section() {
		$groups = Metadata::get_grouped_default_fields();
		$legacy = $this->find_group( $groups, 'Legacy' );

		$this->assertNotNull( $legacy, 'Legacy_Basic fields should form a Legacy group.' );
		$this->assertContains( 'Newsletter Selection', $legacy['fields'], 'Legacy-only fields belong in the Legacy section.' );

		$additional = $this->find_group( $groups, 'Additional' );
		if ( null !== $additional ) {
			$this->assertNotContains( 'Newsletter Selection', $additional['fields'], 'Legacy fields must not fall into Additional any more.' );
		}
	}

	/**
	 * Value-equivalent pair names render exactly once, in their modern group,
	 * so one stored selection entry never shows as two checkboxes.
	 */
	public function test_pair_names_render_once_in_their_modern_groups() {
		$groups = Metadata::get_grouped_default_fields();
		$pairs  = [
			'Account'           => 'Identity',
			'Connected Account' => 'Identity',
			'Registration Date' => 'Registration',
			'Registration Page' => 'Registration',
		];
		foreach ( $pairs as $name => $home_section ) {
			$appearances = 0;
			foreach ( $groups as $group ) {
				if ( in_array( $name, $group['fields'], true ) ) {
					$appearances++;
					$this->assertSame( $home_section, $group['section'], sprintf( '%s must render in its modern group.', $name ) );
				}
			}
			$this->assertSame( 1, $appearances, sprintf( '%s must render exactly once.', $name ) );
		}
	}

	/**
	 * Both legacy classes declare the "Legacy" section, so on a WooCommerce
	 * site the picker would otherwise render two adjacent panels with the same
	 * title and no way to tell them apart.
	 */
	public function test_groups_sharing_a_section_label_are_merged() {
		$sections = $this->get_section_names( Metadata::get_grouped_default_fields() );

		$this->assertSame(
			array_values( array_unique( $sections ) ),
			$sections,
			'No section label may appear on two groups.'
		);
	}

	public function test_all_legacy_groups_render_last() {
		$groups   = Metadata::get_grouped_default_fields();
		$sections = $this->get_section_names( $groups );

		$first_legacy_index = array_search( 'Legacy', $sections, true );
		$this->assertNotFalse( $first_legacy_index, 'Expected a Legacy section to be present.' );

		// Every group from the first Legacy group onward must also be Legacy:
		// nothing (including Additional) is allowed to sort after it.
		$section_count = count( $sections );
		for ( $i = $first_legacy_index; $i < $section_count; $i++ ) {
			$this->assertSame( 'Legacy', $sections[ $i ], 'Legacy groups must be the last groups in the list.' );
		}
	}

	public function test_field_details_carries_status_and_description_for_a_new_field() {
		$groups   = Metadata::get_grouped_default_fields();
		$identity = $this->find_group( $groups, 'Identity' );

		$this->assertNotNull( $identity, 'Identity group should be present.' );
		$this->assertArrayHasKey( 'field_details', $identity, 'Identity group should carry field_details.' );
		$this->assertArrayHasKey( 'User Role', $identity['field_details'] );
		$this->assertSame( 'new', $identity['field_details']['User Role']['status'] );
		$this->assertIsString( $identity['field_details']['User Role']['description'] );
		$this->assertNotEmpty( $identity['field_details']['User Role']['description'] );
	}

	public function test_field_details_carries_status_and_description_for_a_legacy_field() {
		$groups = Metadata::get_grouped_default_fields();
		$legacy = $this->find_group( $groups, 'Legacy' );

		$this->assertNotNull( $legacy, 'Legacy group should be present.' );
		$this->assertArrayHasKey( 'field_details', $legacy, 'Legacy group should carry field_details.' );
		$this->assertArrayHasKey( 'Newsletter Selection', $legacy['field_details'] );
		$this->assertSame( 'legacy', $legacy['field_details']['Newsletter Selection']['status'] );
		$this->assertIsString( $legacy['field_details']['Newsletter Selection']['description'] );
		$this->assertNotEmpty( $legacy['field_details']['Newsletter Selection']['description'] );
	}

	/**
	 * The field_details key is an additive sibling of 'fields', which must
	 * keep its original shape: a flat, sequentially-indexed list of
	 * display-name strings, not a map or a list of objects.
	 */
	public function test_fields_array_shape_is_unchanged_by_field_details() {
		$groups   = Metadata::get_grouped_default_fields();
		$identity = $this->find_group( $groups, 'Identity' );

		$this->assertIsArray( $identity['fields'] );
		$this->assertContains( 'User Role', $identity['fields'] );
		foreach ( $identity['fields'] as $field ) {
			$this->assertIsString( $field, 'fields must remain a flat list of display-name strings.' );
		}
		// A list, not a map: sequential integer keys starting at 0.
		$this->assertSame( array_values( $identity['fields'] ), $identity['fields'] );
	}

	/**
	 * "Registration Page" is declared by classes in both schemas (legacy
	 * `registration_page`/`current_page_url` and the new schema's
	 * `Registration_Page`). Cross-group dedupe keeps only the first
	 * appearance — the Registration group's — so the label renders once,
	 * carrying the modern class's details.
	 */
	public function test_shared_label_renders_once_with_the_modern_details() {
		$groups       = Metadata::get_grouped_default_fields();
		$registration = $this->find_group( $groups, 'Registration' );
		$legacy       = $this->find_group( $groups, 'Legacy' );

		$this->assertNotNull( $registration, 'Registration group should be present.' );
		$this->assertContains( 'Registration Page', $registration['fields'] );
		$this->assertSame( 'updated', $registration['field_details']['Registration Page']['status'] );
		$this->assertNotNull( $legacy, 'Legacy group should be present.' );
		$this->assertNotContains( 'Registration Page', $legacy['fields'], 'A label rendered in a modern group must not repeat in Legacy.' );
		$this->assertArrayNotHasKey( 'Registration Page', $legacy['field_details'] ?? [], 'Nor may its details linger there.' );
	}

	/**
	 * The field_details map must be resolved by each class's raw key, not by
	 * the (possibly renamed) label, so a site-renamed label still finds its
	 * config entry.
	 */
	public function test_field_details_resolves_by_raw_key_through_a_renamed_label() {
		add_filter(
			'newspack_ras_metadata_keys',
			function ( $keys ) {
				$keys['User_Role'] = 'Reader Role';
				return $keys;
			}
		);

		$groups   = Metadata::get_grouped_default_fields();
		$identity = $this->find_group( $groups, 'Identity' );

		$this->assertContains( 'Reader Role', $identity['fields'] );
		$this->assertArrayNotHasKey( 'User Role', $identity['field_details'], 'The pre-rename label must not linger in field_details.' );
		$this->assertArrayHasKey( 'Reader Role', $identity['field_details'] );
		$this->assertSame( 'new', $identity['field_details']['Reader Role']['status'] );
	}

	/**
	 * A filter-added field belongs to no metadata class, so there is no
	 * config to draw status/description from — field_details must be
	 * omitted entirely for that group rather than present-but-empty.
	 */
	public function test_additional_group_has_no_field_details_for_orphan_fields() {
		add_filter(
			'newspack_ras_metadata_keys',
			function ( $keys ) {
				$keys['custom_orphan'] = 'Custom Orphan Label';
				return $keys;
			}
		);

		$groups     = Metadata::get_grouped_default_fields();
		$additional = $this->find_group( $groups, 'Additional' );

		$this->assertNotNull( $additional );
		$this->assertContains( 'Custom Orphan Label', $additional['fields'] );
		$this->assertArrayNotHasKey( 'field_details', $additional );
	}
}
