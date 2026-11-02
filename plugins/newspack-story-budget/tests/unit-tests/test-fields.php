<?php
/**
 * Class TestFields
 *
 * @package Newspack_Story_Budget
 */

use Newspack_Story_Budget\Fields;
use Newspack_Story_Budget\Fields\Editable_Field;
use Newspack_Story_Budget\Fields\Read_Only_Field;
use Newspack_Story_Budget\Fields\Taxonomy_Field;

/**
 * Class for testing fields and their functionality.
 */
class TestFields extends WP_UnitTestCase {
	/**
	 * Create a test post
	 *
	 * @param array $data Data used to create the post.
	 * @return int
	 */
	public static function create_post( $data = [] ) {
		$default = [
			'post_title'   => 'Test post',
			'post_content' => 'Test post content',
			'post_status'  => 'publish',
		];
		return wp_insert_post( wp_parse_args( $data, $default ) );
	}

	/**
	 * Test registering default fields.
	 */
	public function test_register_default_fields() {
		$all_fields = Fields::get_all_fields();
		$this->assertNotEmpty( $all_fields, 'Default fields have been registered.' );
	}

	/**
	 * Test an editable field.
	 */
	public function test_editable_field() {
		$post_id      = self::create_post();
		$field_config = [
			'name' => 'Editable',
			'type' => 'text',
		];

		$field = new Editable_Field( $field_config );
		$this->assertEquals( 'Editable', $field->get_name(), 'Field name is correct.' );
		$this->assertEquals( 'text', $field->get_type(), 'Field type is correct.' );
		$this->assertEquals( 'editable', $field->get_slug(), 'Field slug is generated if not provided.' );
		$this->assertTrue( $field->is_editable(), 'Field is editable.' );
		$this->assertEquals(
			$field->get_value( $post_id, 'default value' ),
			'default value',
			'Field returns default value before value is set.'
		);

		$field->update_value( $post_id, 'new value' );
		$this->assertEquals(
			$field->get_value( $post_id, 'default value' ),
			'new value',
			'Field returns set value.'
		);
	}

	/**
	 * Test a taxonomy field.
	 */
	public function test_taxonomy_field() {
		$post_id = self::create_post();

		$field = new Taxonomy_Field(
			[
				'name'     => 'Taxonomy',
				'type'     => 'taxonomy',
				'taxonomy' => 'category',
			]
		);

		$this->assertEquals( 'Taxonomy', $field->get_name(), 'Field name is correct.' );
		$this->assertTrue( $field->is_editable(), 'Field is editable.' );

		// Create some terms.
		$term_1 = wp_insert_term( 'Term 1', 'category' );
		$term_2 = wp_insert_term( 'Term 2', 'category' );

		// Create a child term.
		$child_term = wp_insert_term(
			'Child Term',
			'category',
			[
				'parent' => $term_1['term_id'],
			]
		);

		// Create a grandchild term.
		$grandchild_term = wp_insert_term(
			'Grandchild Term',
			'category',
			[
				'parent' => $child_term['term_id'],
			]
		);

		$options = $field->get_options();
		$this->assertCount( 5, $options, 'All terms are options.' );
		$this->assertEquals( 'Uncategorized', $options[0]['label'], 'First option is correct.' );

		$this->assertEquals( 'Term 1', $options[1]['label'], 'Term option label is correct.' );
		$this->assertEquals( $term_1['term_id'], $options[1]['value'], 'Term option value is correct.' );
		$this->assertEquals( $child_term['term_id'], $options[2]['value'], 'Child term is sorted after parent.' );

		$this->assertEquals( $grandchild_term['term_id'], $options[3]['value'], 'Grandchild term is sorted after child.' );
		$this->assertEquals( 'Term 1 — Child Term — Grandchild Term', $options[3]['label'], 'Grandchild term label is correct.' );

		$this->assertEquals( $term_2['term_id'], $options[4]['value'], 'Term 2 is sorted after all Term 1 children.' );
	}

	/**
	 * Test a taxonomy field value.
	 */
	public function test_taxonomy_field_value() {
		$post_id = self::create_post();

		$term_1 = wp_insert_term( 'Term 1', 'category' );
		$term_2 = wp_insert_term( 'Term 2', 'category' );

		$field = new Taxonomy_Field(
			[
				'name'     => 'Taxonomy',
				'type'     => 'taxonomy',
				'taxonomy' => 'category',
			]
		);
		$this->assertEquals( [ 1 ], $field->get_value( $post_id ), 'Field returns default category ID.' );

		$field->update_value( $post_id, [ $term_1['term_id'] ] );
		$this->assertEquals( [ $term_1['term_id'] ], $field->get_value( $post_id ), 'Field returns updated value.' );

		$field->add_value( $post_id, $term_2['term_id'] );
		$this->assertEquals( [ $term_1['term_id'], $term_2['term_id'] ], $field->get_value( $post_id ), 'Field returns updated value.' );

		$field->delete_value( $post_id, $term_1['term_id'] );
		$this->assertEquals( [ $term_2['term_id'] ], $field->get_value( $post_id ), 'Field returns updated value.' );
	}

	/**
	 * Test a read-only field.
	 */
	public function test_read_only_field() {
		$post_id = self::create_post();
		$field   = Fields::get_field( 'word_count' );

		$this->assertEquals( 'Length', $field->get_name(), 'Field name is correct.' );
		$this->assertFalse( $field->is_editable(), 'Field is not editable.' );
		$this->assertEquals(
			$field->get_value( $post_id ),
			3,
			'Field uses callback to get value.'
		);
		\wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'One more little word',
			]
		);

		$this->assertEquals(
			\get_post_meta( $post_id, $field::FIELD_PREFIX . $field->get_slug(), true ),
			4,
			'Read-only field values are stored as post meta on post update.'
		);
		$this->assertTrue(
			\is_wp_error( $field->update_value( $post_id, 'new value' ) ),
			'Cannot directly update a read-only field value.'
		);
	}

	/**
	 * Test a read-only field with an empty value.
	 */
	public function test_read_only_field_with_empty_value() {
		$post_id = self::create_post();
		$field   = Fields::get_field( 'image_count' );

		$this->assertEquals( 0, $field->get_value( $post_id ), 'Field returns 0 if no images are found.' );
	}

	/**
	 * Each <img> tag saved in the content counts once, from block markup or classic HTML.
	 */
	public function test_image_count_counts_images_in_content_markup() {
		$post_id = self::create_post(
			[
				'post_content' => '<!-- wp:image {"id":1} --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="" class="wp-image-1"/></figure><!-- /wp:image --><p>Classic <img src="https://example.com/b.jpg" alt=""></p>',
			]
		);

		$this->assertSame( 2, Fields::get_image_count( $post_id ), 'Counts the image tags saved in the content.' );
	}

	/**
	 * Gallery shortcodes and the image count each adds to a story that has two
	 * attached images, on a site with three unattached ones. `{attached}` stands
	 * for the first attached image's ID, `{unattached}` for the unattached IDs.
	 *
	 * @return array[]
	 */
	public function gallery_shortcodes() {
		return [
			'a bare gallery'                     => [ '[gallery]', 2 ],
			'a draft story'                      => [ '[gallery]', 2, [ 'post_status' => 'draft' ] ],
			'a password-protected story'         => [ '[gallery]', 2, [ 'post_password' => 'secret' ] ],
			'an id of 0 falls back to the story' => [ '[gallery id="0"]', 2 ],
			'listed ids'                         => [ '[gallery ids="{unattached}"]', 3 ],
			'a list with no valid ids'           => [ '[gallery ids=","]', 0 ],
			'excluded ids'                       => [ '[gallery exclude="{attached}"]', 1 ],
			'an escaped shortcode'               => [ '[[gallery]]', 0 ],
		];
	}

	/**
	 * A classic [gallery] adds the images it lists, whoever is saving. Rendering
	 * it would not: core hides a gallery from a visitor who cannot read its
	 * post, and lists every unattached image on the site when it has no post.
	 *
	 * @dataProvider gallery_shortcodes
	 *
	 * @param string $shortcode  Gallery shortcode, with ID placeholders.
	 * @param int    $expected   Expected image count.
	 * @param array  $story_data Post data for the story.
	 */
	public function test_image_count_counts_the_images_a_gallery_shortcode_lists( $shortcode, $expected, $story_data = [] ) {
		$post_id        = self::create_post( $story_data );
		$attachment_ids = [];
		foreach ( [ $post_id, $post_id, 0, 0, 0 ] as $parent ) {
			$attachment_ids[] = self::factory()->attachment->create(
				[
					'file'           => 'image.jpg',
					'post_parent'    => $parent,
					'post_mime_type' => 'image/jpeg',
					'post_status'    => 'inherit',
				]
			);
		}
		$placeholders = [
			'{attached}'   => $attachment_ids[0],
			'{unattached}' => implode( ',', array_slice( $attachment_ids, 2 ) ),
		];
		\wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<p>Photos below.</p>' . strtr( $shortcode, $placeholders ),
			]
		);

		$this->assertSame( $expected, Fields::get_image_count( $post_id ) );
	}

	/**
	 * Refreshing read-only fields on save must not run the front-end content pipeline.
	 */
	public function test_read_only_fields_do_not_render_the_content_on_save() {
		$post_id  = self::create_post( [ 'post_content' => '<p>Hello</p>[gallery]' ] );
		$rendered = [];
		$spy      = function ( $value ) use ( &$rendered ) {
			$rendered[] = \current_filter();
			return $value;
		};

		\add_filter( 'the_content', $spy );
		\add_filter( 'pre_do_shortcode_tag', $spy );
		Fields::update_read_only_fields( $post_id );
		\remove_filter( 'the_content', $spy );
		\remove_filter( 'pre_do_shortcode_tag', $spy );

		$this->assertSame( [], $rendered, 'Saving a story must not render the content or its shortcodes.' );
	}

	/**
	 * Test a select dropdown field.
	 */
	public function test_field_with_options() {
		$post_id = self::create_post();
		$select  = new Editable_Field(
			[
				'name'    => 'Select Dropdown',
				'type'    => 'text',
				'options' => [
					[
						'label' => 'Option 1',
						'value' => 'option1',
					],
					[
						'label'    => 'Option 2',
						'value'    => 'option2',
						'selected' => true,
					],
				],
			]
		);
		$this->assertFalse( $select->has_errors(), 'Select fields are registered with a list of available options.' );
		$this->assertEquals(
			$select->get_value( $post_id ),
			'option2',
			'Returns default selected value before being set.'
		);
	}

	/**
	 * Test field with multiple values.
	 */
	public function test_is_multiple_field() {
		$post_id = self::create_post();
		$field    = new Editable_Field(
			[
				'name'        => 'Multiple values',
				'type'        => 'text',
				'is_multiple' => true,
			]
		);
		$field->update_value( $post_id, 'value1' );
		$this->assertTrue(
			is_array( $field->get_value( $post_id ) ),
			'is_multiple fields return values as an array.'
		);
		$field->add_value( $post_id, 'value2' );
		$this->assertEquals(
			$field->get_value( $post_id ),
			[ 'value1', 'value2' ],
			'is_multiple fields can have multiple values.'
		);
		$field->delete_value( $post_id, 'value1' );
		$this->assertEquals(
			$field->get_value( $post_id ),
			[ 'value2' ],
			'is_multiple fields can remove values.'
		);
	}

	/**
	 * Test invalid field configuration.
	 */
	public function test_invalid_field_configuration() {
		$select = new Editable_Field(
			[
				'name' => 'Invalid Type',
				'type' => 'foobar',
			]
		);
		$this->assertTrue( $select->has_errors(), 'Provided field type must be valid.' );
		$select = new Read_Only_Field(
			[
				'name' => 'Slug is too long',
				'slug' => 'a_very_long_string_that_is_too_long_' . \wp_generate_password( 191, false ),
			]
		);
		$this->assertTrue( $select->has_errors(), 'Provided field slug must not be too long.' );
		$read_only = new Read_Only_Field(
			[ 'name' => 'Read-Only' ]
		);
		$this->assertTrue( $read_only->has_errors(), 'Read-only fields must have a callback to get their value.' );

		$read_only = new Read_Only_Field(
			[
				'get_value_callback' => 'get_the_ID',
				'name'               => 'Read-Only',
			]
		);
		$this->assertFalse( $read_only->has_errors(), 'Read-only fields are registered with a callback to get their value.' );
	}

	/**
	 * Test that get_all_fields returns fields sorted by default_order.
	 */
	public function test_get_all_fields_sort_order() {
		// Get all fields.
		$all_fields = Fields::get_all_fields();

		// Verify we have fields.
		$this->assertNotEmpty( $all_fields, 'Fields should not be empty.' );

		// Check if fields are sorted by default_order.
		$previous_order = -1;
		foreach ( $all_fields as $slug => $field ) {
			$current_order = $field->get_default_order();

			// Verify that each field's default_order is greater than or equal to the previous one.
			$this->assertGreaterThanOrEqual(
				$previous_order,
				$current_order,
				sprintf( 'Field "%s" with order %d should come after field with order %d.', $slug, $current_order, $previous_order )
			);

			$previous_order = $current_order;
		}

		// Test with $as_array = true.
		$all_fields_array = Fields::get_all_fields( true );

		// Verify we have the same number of fields.
		$this->assertEquals(
			count( $all_fields ),
			count( $all_fields_array ),
			'The number of fields should be the same whether returned as objects or arrays.'
		);

		// Verify that the array version maintains the same keys.
		foreach ( $all_fields as $slug => $field ) {
			$this->assertArrayHasKey(
				$slug,
				$all_fields_array,
				sprintf( 'Array version should have the same key "%s" as the object version.', $slug )
			);
		}
	}
}
