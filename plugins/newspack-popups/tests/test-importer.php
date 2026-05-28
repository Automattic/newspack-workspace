<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Importer Test
 *
 * @package Newspack_Popups
 */

/**
 * Importer test case.
 */
class ImporterTest extends WP_UnitTestCase_PageWithPopups {

	/**
	 * Call protected/private method of a class.
	 *
	 * @param object $object    Instantiated object that we will run method on.
	 * @param string $method_name Method name to call.
	 * @param array  $parameters Array of parameters to pass into method.
	 *
	 * @return mixed Method return.
	 */
	public function invoke_method( &$object, $method_name, array $parameters = array() ) {
		$reflection = new \ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $parameters );
	}

	/**
	 * Data fortest_process_terms
	 *
	 * @return array
	 */
	public function process_terms_data() {
		return [
			'all mapped'     => [
				[
					[
						'id'   => 10,
						'name' => 'Test Term 1',
					],
					[
						'id'   => 20,
						'name' => 'Test Term 2',
					],
				],
				[
					[
						'id'   => 11,
						'name' => 'Test Term 1',
					],
					[
						'id'   => 21,
						'name' => 'Test Term 2',
					],
				],
			],
			'one not mapped' => [
				[
					[
						'id'   => 10,
						'name' => 'Test Term 1',
					],
					[
						'id'   => 15,
						'name' => 'Test Term 3',
					],
					[
						'id'   => 20,
						'name' => 'Test Term 2',
					],
				],
				[
					[
						'id'   => 11,
						'name' => 'Test Term 1',
					],
					[
						'id'   => 21,
						'name' => 'Test Term 2',
					],
				],
			],
			'none mapped'    => [
				[
					[
						'id'   => 1,
						'name' => 'Test Term 1',
					],
					[
						'id'   => 2,
						'name' => 'Test Term 3',
					],
					[
						'id'   => 3,
						'name' => 'Test Term 2',
					],
				],
				[],
			],
			'test_existing'  => [
				[
					[
						'id'   => 33,
						'name' => 'Existing',
					],
				],
				[],
				true,
			],
		];
	}

	/**
	 * Test the pre_process_terms method.
	 *
	 * @param array $input_data The array of terms to process.
	 * @param array $expected The expected result.
	 * @param bool  $test_existing Flag for a special test case for an existing term.
	 * @return void
	 * @dataProvider process_terms_data
	 */
	public function test_process_terms( $input_data, $expected, $test_existing = false ) {
		$importer      = new Newspack_Popups_Importer( [] );
		$r_importer    = new \ReflectionClass( $importer );
		$terms_mapping = [
			10 => 11,
			20 => 21,
		];

		$mapping = $r_importer->getProperty( 'terms_mapping' );
		$mapping->setAccessible( true );
		$mapping->setValue( $importer, $terms_mapping );

		$method = $r_importer->getMethod( 'pre_process_terms' );
		$method->setAccessible( true );

		if ( $test_existing ) {
			$existing_term = wp_insert_term( 'Existing', 'category' );
		}

		$result = $method->invokeArgs( $importer, [ $input_data, 'category' ] );

		if ( $test_existing ) {
			$this->assertSame( $existing_term['term_id'], $result[0]['id'] );
		} else {
			$this->assertEquals( $expected, $result );
		}
	}

	/**
	 * Test the full functionality of the Importer class
	 *
	 * @return void
	 */
	public function test_integration() {

		// Clear data created by other tests.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE post_type = %s", Newspack_Popups::NEWSPACK_POPUPS_CPT ) ); // phpcs:ignore
		Newspack_Segments_Model::delete_all_segments();

		$existing_category = wp_insert_term( 'Category 10', 'category' );
		$existing_tag      = wp_insert_term( 'Tag 10', 'post_tag' );

		$campaigns = [
			[
				'id'   => 10,
				'name' => 'Campaign 1',
			],
			[
				'id'   => 20,
				'name' => 'Campaign 2',
			],
		];
		$prompts   = [
			[
				'title'           => 'Test Prompt 1',
				'content'         => 'Test content',
				'status'          => 'publish',
				'campaign_groups' => [
					[
						'id'   => 10,
						'name' => 'Campaign 1',
					],
				],
				'categories'      => [
					[
						'id'   => 40,
						'name' => 'Category 10',
					],
				],
				'tags'            => [
					[
						'id'   => 50,
						'name' => 'Tag 10',
					],
				],
				'segments'        => [
					[
						'id'   => 1001,
						'name' => 'Test Segment',
					],
					[
						'id'   => 1002,
						'name' => 'Test Segment 2',
					],
				],
				'options'         => [
					'background_color' => '#FFFFFF',
					'hide_border'      => false,
					'large_border'     => false,
					'no_padding'       => false,
					'frequency'        => 'once',
					'placement'        => 'inline',
				],
			],
			[
				'title'           => 'Test Prompt 2',
				'slug'            => 'Test Prompt Slug',
				'content'         => 'Test content',
				'status'          => 'draft',
				'campaign_groups' => [
					[
						'id'   => 20,
						'name' => 'Campaign 2',
					],
				],
				'segments'        => [
					[
						'id'   => 1001,
						'name' => 'Test Segment',
					],
				],
				'options'         => [
					'background_color' => '#FFFF00',
					'hide_border'      => false,
					'large_border'     => false,
					'no_padding'       => false,
					'frequency'        => 'once',
					'placement'        => 'inline',
				],
			],
		];
		$segments  = [
			[
				'name'          => 'Test Segment',
				'id'            => 1001,
				'priority'      => 10,
				'configuration' => [
					'max_posts' => 1,
				],
			],
			[
				'name'          => 'Test Segment 2',
				'id'            => 1002,
				'priority'      => 10,
				'configuration' => [
					'max_posts'           => 1,
					'favorite_categories' => [
						[
							'id'   => 40,
							'name' => 'Category 10',
						],
					],
				],
			],
		];

		$package = [
			'campaigns' => $campaigns,
			'prompts'   => $prompts,
			'segments'  => $segments,
		];

		$importer = new Newspack_Popups_Importer( $package );
		$result   = $importer->import();

		$this->assertSame( 2, $result['totals']['campaigns'] );
		$this->assertSame( 2, $result['totals']['segments'] );
		$this->assertSame( 2, $result['totals']['prompts'] );

		// Test 2 groups were created.
		$created_groups = Newspack_Popups::get_groups();
		$this->assertSame( 2, count( $created_groups ) );

		// Test if segments were properly created.
		$created_segments = Newspack_Popups_Segmentation::get_segments();
		$this->assertSame( 2, count( $created_segments ) );
		$this->assertSame( 'Test Segment', $created_segments[0]['name'] );
		$this->assertSame( 0, $created_segments[0]['priority'] );
		$this->assertSame( 1, $created_segments[0]['configuration']['max_posts'] );
		$this->assertSame( 'Test Segment 2', $created_segments[1]['name'] );
		$this->assertSame( 1, $created_segments[1]['priority'] );

		// Get the IDs of the created segments to see if they were properly mapped in the prompts.
		$segment_1_id = $created_segments[0]['id'];
		$segment_2_id = $created_segments[1]['id'];

		// Check if 2 prompts were created.
		$created_prompts = Newspack_Popups_Model::retrieve_popups( true );
		$this->assertSame( 2, count( $created_prompts ) );

		// Check if the slug is set correctly if passed.
		$this->assertSame( sanitize_title( $package['prompts'][1]['slug'] ), get_post( $created_prompts[0]['id'] )->post_name );

		// Check if the prompts were properly mapped to the segments.
		$this->assertSame( 1, count( $created_prompts[0]['segments'] ) );
		$this->assertSame( (int) $segment_1_id, $created_prompts[0]['segments'][0]->term_id );
		$this->assertSame( 2, count( $created_prompts[1]['segments'] ) );
		$this->assertSame( (int) $segment_1_id, $created_prompts[1]['segments'][0]->term_id );
		$this->assertSame( (int) $segment_2_id, $created_prompts[1]['segments'][1]->term_id );

		// Check if the campaign groups terms were properly applied to the prompts.
		$this->assertSame( 1, count( $created_prompts[0]['campaign_groups'] ) );
		$this->assertSame( 'Campaign 2', $created_prompts[0]['campaign_groups'][0]->name );
		$this->assertSame( 1, count( $created_prompts[1]['campaign_groups'] ) );
		$this->assertSame( 'Campaign 1', $created_prompts[1]['campaign_groups'][0]->name );

		$this->assertSame( '#FFFF00', $created_prompts[0]['options']['background_color'] );

		// Check if the category was properly assigned.
		$this->assertSame( $existing_category['term_id'], $created_prompts[1]['categories'][0]->term_id );
		// Check if the tag was properly assigned.
		$this->assertSame( $existing_tag['term_id'], $created_prompts[1]['tags'][0]->term_id );
		// Check if the category was assigned to the segment.
		$this->assertSame( $existing_category['term_id'], $created_segments[1]['configuration']['favorite_categories'][0] );
	}

	/**
	 * Test the import method with invalid data.
	 *
	 * @return void
	 */
	public function test_integration_invalid_data() {

		$campaigns = [
			[
				'id'   => 10,
				'name' => 'Campaign 1',
			],
		];
		// missing required filed in prompts.
		$prompts = [
			[
				'title'    => 'Test Prompt 1',
				'content'  => 'Test content',
				'status'   => 'publish',
				'segments' => [
					[
						'id'   => 1001,
						'name' => 'Test Segment',
					],
					[
						'id'   => 1002,
						'name' => 'Test Segment 2',
					],
				],
				'options'  => [
					'frequency' => 'once',
				],
			],
		];
		// missing required filed in segments.
		$segments = [
			[
				'name'     => 'Test Segment',
				'id'       => 1001,
				'priority' => 10,
			],
		];

		$package = [
			'campaigns' => $campaigns,
			'prompts'   => $prompts,
			'segments'  => $segments,
		];

		$importer = new Newspack_Popups_Importer( $package );
		$result   = $importer->import();

		$this->assertNotEmpty( $result['errors'] );
		$this->assertNotEmpty( $result['errors']['validation'] );
	}

	/**
	 * Data for test_get_missing_terms
	 *
	 * @return array
	 */
	public function missing_terms_data() {
		return [
			'nothing'                        => [
				[],
				[],
				[ 1, 2, 3 ],
				[ 1, 2 ],
			],
			'create 1 category'              => [
				[ 1 ],
				[],
				[ 2, 3 ],
				[ 1, 2 ],
			],
			'create 2 categories'            => [
				[ 1, 2 ],
				[],
				[ 3 ],
				[ 1, 2 ],
			],
			'create 3 categories'            => [
				[ 1, 2, 3 ],
				[],
				[],
				[ 1, 2 ],
			],
			'create 3 categories and 1 tag'  => [
				[ 1, 2, 3 ],
				[ 1 ],
				[],
				[ 2 ],
			],
			'create 3 categories and 2 tags' => [
				[ 1, 2, 3 ],
				[ 1, 2 ],
				[],
				[],
			],
		];
	}

	/**
	 * Tests the get_missing_terms_from_input method
	 *
	 * @param array $cats_to_create The categories to create.
	 * @param array $tags_to_create The tags to create.
	 * @param array $cats_expected The expected categories.
	 * @param array $tags_expected The expected tags.
	 * @return void
	 * @dataProvider missing_terms_data
	 */
	public function test_get_missing_terms( $cats_to_create, $tags_to_create, $cats_expected, $tags_expected ) {

		foreach ( $tags_to_create as $tag ) {
			wp_insert_term( 'Tag ' . $tag, 'post_tag' );
		}
		foreach ( $cats_to_create as $cat ) {
			wp_insert_term( 'Category ' . $cat, 'category' );
		}

		$prompts  = [
			[
				'title'           => 'Test Prompt 1',
				'content'         => 'Test content',
				'status'          => 'publish',
				'categories'      => [
					[
						'id'   => 1,
						'name' => 'Category 1',
					],
				],
				'tags'            => [
					[
						'id'   => 1,
						'name' => 'Tag 1',
					],
				],
				'campaign_groups' => [
					[
						'id'   => 10,
						'name' => 'Campaign 1',
					],
				],
				'segments'        => [
					[
						'id'   => 1001,
						'name' => 'Test Segment',
					],
					[
						'id'   => 1002,
						'name' => 'Test Segment 2',
					],
				],
				'options'         => [
					'background_color'    => '#FFFFFF',
					'hide_border'         => false,
					'large_border'        => false,
					'no_padding'          => false,
					'frequency'           => 'once',
					'placement'           => 'inline',
					'excluded_categories' => [
						[
							'id'   => 2,
							'name' => 'Category 2',
						],
					],
					'excluded_tags'       => [
						[
							'id'   => 1,
							'name' => 'Tag 1', // repeated tag.
						],
						[
							'id'   => 2,
							'name' => 'Tag 2',
						],
					],
				],
			],
		];
		$segments = [
			[
				'name'          => 'Test Segment',
				'id'            => 1001,
				'priority'      => 10,
				'configuration' => [
					'max_posts'           => 1,
					'favorite_categories' => [
						[
							'id'   => 1,
							'name' => 'Category 1', // reapeated category.
						],
						[
							'id'   => 3,
							'name' => 'Category 3',
						],
					],
				],
			],
		];

		$package = [
			'campaigns' => [],
			'prompts'   => $prompts,
			'segments'  => $segments,
		];

		$importer = new Newspack_Popups_Importer( $package );

		$result = $importer->get_missing_terms_from_input();

		$this->assertSame( count( $cats_expected ), count( $result['category'] ) );
		$this->assertSame( count( $tags_expected ), count( $result['post_tag'] ) );

		foreach ( $cats_expected as $cat ) {
			$cat_obj = [
				'id'   => $cat,
				'name' => 'Category ' . $cat,
			];
			$this->assertContains( $cat_obj, $result['category'] );
		}
		foreach ( $tags_expected as $tag ) {
			$tag_obj = [
				'id'   => $tag,
				'name' => 'Tag ' . $tag,
			];
			$this->assertContains( $tag_obj, $result['post_tag'] );
		}

		// clean up.
		$this->delete_all_terms();
	}

	/**
	 * Clear all tags and categories
	 *
	 * @return void
	 */
	public function delete_all_terms() {
		foreach ( [ 'category', 'post_tag' ] as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
				)
			);
			foreach ( $terms as $term ) {
				wp_delete_term( $term->term_id, $tax );
			}
		}
	}
}
