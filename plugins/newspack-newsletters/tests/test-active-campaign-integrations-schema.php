<?php
/**
 * Tests for the ActiveCampaign → Newspack integrations schema mapper.
 *
 * @package Newspack_Newsletters
 */

/**
 * Lock in which AC field types get auto-promoted, what `matching_function` the
 * mapper emits per type (single-select → 'default', multi-select → 'list__in'),
 * and the full schema shape.
 *
 * AC's per-field options API is not exercised here — the eligibility matrix and
 * matching-function selection are pure logic.
 */
class ActiveCampaignIntegrationsSchemaTest extends WP_UnitTestCase {

	/**
	 * Invoke the private AC mapper via reflection.
	 *
	 * The class is `final`, so we can't subclass to bypass HTTP. The mapper itself
	 * doesn't read instance state for the branches under test (no `id` ⇒ no
	 * `fetch_field_options()` call), so we instantiate without running the
	 * constructor — that avoids the parent class's hook registrations from firing
	 * once per test invocation in the test process.
	 *
	 * @param array $field Raw AC field.
	 * @return array|null
	 */
	private function map_field( $field ) {
		$reflection                = new ReflectionClass( 'Newspack_Newsletters_Active_Campaign' );
		$provider                  = $reflection->newInstanceWithoutConstructor();
		$map_contact_field_method  = $reflection->getMethod( 'map_contact_field_to_integration_schema' );
		$map_contact_field_method->setAccessible( true );
		return $map_contact_field_method->invoke( $provider, $field );
	}

	/**
	 * Without a `perstag`, AC fields lack a stable machine identifier — must be skipped.
	 */
	public function test_skip_when_perstag_missing() {
		$this->assertNull( $this->map_field( [] ) );
		$this->assertNull( $this->map_field( [ 'perstag' => '' ] ) );
	}

	/**
	 * Single-selection enumerated types use 'default' matching (strict equality
	 * against the chosen option). Per AC's Contact Custom Fields API Guide:
	 * dropdown / radio / listbox are single selection.
	 */
	public function test_single_select_types_use_default_matching() {
		foreach ( [ 'dropdown', 'radio', 'listbox' ] as $type ) {
			$mapped = $this->map_field(
				[
					'perstag' => 'X',
					'type'    => $type,
				]
			);
			$this->assertSame( 'default', $mapped['matching_function'], "$type should use 'default' matching" );
		}
	}

	/**
	 * Multi-selection enumerated types use 'list__in'. AC stores their value
	 * with `||` delimiters; the consumer's parse_list_value() recognizes that
	 * format. Strict equality cannot match `||A||B||` against `'A'`.
	 */
	public function test_multi_select_types_use_list_in_matching() {
		foreach ( [ 'checkbox', 'multiselect' ] as $type ) {
			$mapped = $this->map_field(
				[
					'perstag' => 'X',
					'type'    => $type,
				]
			);
			$this->assertSame( 'list__in', $mapped['matching_function'], "$type should use 'list__in' matching" );
		}
	}

	/**
	 * Promotion eligibility — the eligible set drives the `is_access_rule` /
	 * `is_segment_criteria` defaults the consumer applies to fresh fields. Both
	 * flags are derived from the same internal predicate today, but assert each
	 * one independently so a future split (e.g. adding a segment-only type)
	 * can't pass silently.
	 */
	public function test_promotion_eligibility_matrix() {
		$promoted     = [ 'text', 'textarea', 'date', 'datetime', 'dropdown', 'radio', 'listbox', 'checkbox', 'multiselect' ];
		$not_promoted = [ 'hidden', 'NULL', 'made-up-future-type' ];

		foreach ( $promoted as $type ) {
			$mapped = $this->map_field(
				[
					'perstag' => 'X',
					'type'    => $type,
				]
			);
			$this->assertTrue( $mapped['is_access_rule'], "$type should be promoted as access rule" );
			$this->assertTrue( $mapped['is_segment_criteria'], "$type should be promoted as segment criteria" );
		}
		foreach ( $not_promoted as $type ) {
			$mapped = $this->map_field(
				[
					'perstag' => 'X',
					'type'    => $type,
				]
			);
			$this->assertFalse( $mapped['is_access_rule'], "$type should NOT be promoted as access rule" );
			$this->assertFalse( $mapped['is_segment_criteria'], "$type should NOT be promoted as segment criteria" );
		}
	}

	/**
	 * Schema shape — every key the consumer reads must be present.
	 */
	public function test_returned_schema_shape() {
		$mapped = $this->map_field(
			[
				'perstag'  => 'FAVCOLOR',
				'type'     => 'dropdown',
				'title'    => 'Favorite Color',
				'descript' => 'Picked at signup',
			]
		);
		foreach ( [ 'key', 'name', 'value_type', 'matching_function', 'options', 'description', 'is_access_rule', 'is_segment_criteria' ] as $key ) {
			$this->assertArrayHasKey( $key, $mapped );
		}
		$this->assertSame( 'FAVCOLOR', $mapped['key'] );
		$this->assertSame( 'Favorite Color', $mapped['name'] );
		$this->assertSame( 'Picked at signup', $mapped['description'] );
	}

	/**
	 * `name` falls back to `perstag` when AC's `title` is missing.
	 */
	public function test_name_falls_back_to_perstag() {
		$mapped = $this->map_field(
			[
				'perstag' => 'PHONE_NUM',
				'type'    => 'text',
			]
		);
		$this->assertSame( 'PHONE_NUM', $mapped['name'] );
	}
}
