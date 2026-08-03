<?php
/**
 * Tests for the Mailchimp → Newspack integrations schema mapper.
 *
 * @package Newspack_Newsletters
 */

/**
 * Lock in which Mailchimp merge-field types get auto-promoted, and the shape
 * `Newspack_Newsletters_Mailchimp::map_merge_field_to_integration_schema()`
 * returns. The mapper is the cross-repo contract between this plugin and
 * newspack-plugin's ESP integration; treat it as a self-proving spec.
 */
class MailchimpIntegrationsSchemaTest extends WP_UnitTestCase {

	/**
	 * Invoke the private static mapper via reflection.
	 *
	 * @param array $field Raw merge field input.
	 * @return array|null
	 */
	private function map_field( $field ) {
		$reflection         = new ReflectionClass( 'Newspack_Newsletters_Mailchimp' );
		$map_field_method   = $reflection->getMethod( 'map_merge_field_to_integration_schema' );
		$map_field_method->setAccessible( true );
		return $map_field_method->invoke( null, $field );
	}

	/**
	 * A field without a `tag` has no usable machine key — must be skipped.
	 */
	public function test_skip_when_tag_missing() {
		$this->assertNull( $this->map_field( [] ) );
		$this->assertNull( $this->map_field( [ 'tag' => '' ] ) );
		$this->assertNull(
			$this->map_field(
				[
					'tag'  => null,
					'name' => 'No Tag',
				] 
			) 
		);
	}

	/**
	 * Promotion eligibility matrix. Keep this in sync with the implementation.
	 */
	public function test_promotion_eligibility_by_type() {
		$promoted_types  = [ 'text', 'number', 'date', 'radio', 'dropdown' ];
		$exposed_only    = [ 'phone', 'url', 'imageurl', 'birthday', 'zip', 'address' ];

		foreach ( $promoted_types as $type ) {
			$mapped = $this->map_field(
				[
					'tag'  => 'T',
					'type' => $type,
				] 
			);
			$this->assertTrue( $mapped['is_access_rule'], "$type should be promoted as access rule" );
			$this->assertTrue( $mapped['is_segment_criteria'], "$type should be promoted as segment criteria" );
		}
		foreach ( $exposed_only as $type ) {
			$mapped = $this->map_field(
				[
					'tag'  => 'T',
					'type' => $type,
				] 
			);
			$this->assertFalse( $mapped['is_access_rule'], "$type should NOT be promoted as access rule" );
			$this->assertFalse( $mapped['is_segment_criteria'], "$type should NOT be promoted as segment criteria" );
		}
	}

	/**
	 * Enumerated types (radio / dropdown) expose their inline choices as options.
	 */
	public function test_options_built_from_choices_for_enumerated_types() {
		$dropdown_mapped = $this->map_field(
			[
				'tag'     => 'COLOR',
				'type'    => 'dropdown',
				'options' => [ 'choices' => [ 'Red', 'Green', 'Blue' ] ],
			]
		);
		$this->assertSame(
			[
				[
					'value' => 'Red',
					'label' => 'Red',
				],
				[
					'value' => 'Green',
					'label' => 'Green',
				],
				[
					'value' => 'Blue',
					'label' => 'Blue',
				],
			],
			$dropdown_mapped['options']
		);

		$text_mapped = $this->map_field(
			[
				'tag'  => 'NAME',
				'type' => 'text',
			] 
		);
		$this->assertSame( [], $text_mapped['options'], 'text fields have no options array' );

		$dropdown_no_choices = $this->map_field(
			[
				'tag'  => 'EMPTY',
				'type' => 'dropdown',
			] 
		);
		$this->assertSame( [], $dropdown_no_choices['options'], 'dropdown without choices yields []' );
	}

	/**
	 * Schema shape — every key the consumer (newspack-plugin) reads must be present.
	 */
	public function test_returned_schema_shape() {
		$mapped = $this->map_field(
			[
				'tag'       => 'FAVCOLOR',
				'type'      => 'dropdown',
				'name'      => 'Favorite Color',
				'help_text' => 'Picked at signup',
				'options'   => [ 'choices' => [ 'Red' ] ],
			]
		);

		$expected_keys = [
			'key',
			'name',
			'value_type',
			'matching_function',
			'options',
			'description',
			'is_access_rule',
			'is_segment_criteria',
		];
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $mapped, "Mapped schema must include '$key'" );
		}

		$this->assertSame( 'FAVCOLOR', $mapped['key'] );
		$this->assertSame( 'Favorite Color', $mapped['name'] );
		$this->assertSame( 'string', $mapped['value_type'] );
		$this->assertSame( 'default', $mapped['matching_function'] );
		$this->assertSame( 'Picked at signup', $mapped['description'] );
	}

	/**
	 * `name` falls back to `tag` when missing or empty so the consumer never
	 * has to render a blank label.
	 */
	public function test_name_falls_back_to_tag() {
		$no_name = $this->map_field(
			[
				'tag'  => 'PHONE_NUM',
				'type' => 'phone',
			] 
		);
		$this->assertSame( 'PHONE_NUM', $no_name['name'] );

		$empty_name = $this->map_field(
			[
				'tag'  => 'PHONE_NUM',
				'type' => 'phone',
				'name' => '',
			] 
		);
		$this->assertSame( 'PHONE_NUM', $empty_name['name'] );
	}

	/**
	 * Missing `type` defaults to 'text', which is in the promoted set — keep this
	 * test so a future refactor of the default doesn't silently change promotion.
	 */
	public function test_missing_type_defaults_to_text_and_is_promoted() {
		$mapped = $this->map_field( [ 'tag' => 'FOO' ] );
		$this->assertTrue( $mapped['is_access_rule'] );
		$this->assertTrue( $mapped['is_segment_criteria'] );
	}
}
