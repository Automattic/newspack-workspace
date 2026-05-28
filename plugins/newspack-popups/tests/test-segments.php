<?php
/**
 * Class Segments Test
 *
 * @package Newspack_Popups
 */

/**
 * Segments test case.
 */
class SegmentsTest extends WP_UnitTestCase {

	/**
	 * A complete and valid segment.
	 *
	 * @var array
	 */
	public $complete_and_valid = [
		'name'          => 'Complete and valid',
		'priority'      => 10,
		'criteria'      => [
			[
				'criteria_id' => 'articles_read',
				'value'       => [
					'min' => 5,
					'max' => 20,
				],
			],
			[
				'criteria_id' => 'newsletter',
				'value'       => 'is_subscriber',
			],
		],
		'configuration' => [
			'max_posts'           => 1,
			'min_posts'           => 1,
			'min_session_posts'   => 1,
			'max_session_posts'   => 1,
			'is_subscribed'       => true,
			'is_not_subscribed'   => true,
			'is_donor'            => true,
			'is_not_donor'        => true,
			'is_former_donor'     => true,
			'is_logged_in'        => true,
			'is_not_logged_in'    => true,
			'favorite_categories' => [],
			'referrers'           => '',
			'referrers_not'       => '',
			'is_disabled'         => false,
		],
	];

	/**
	 * A valid segment, without all the properties.
	 *
	 * @var array
	 */
	public $valid = [
		'name'          => 'Valid',
		'priority'      => 20,
		'criteria'      => [
			[
				'criteria_id' => 'newsletter',
				'value'       => 'is_subscriber',
			],
		],
		'configuration' => [
			'max_posts' => 1,
		],
	];

	/**
	 * A segment missing required properties.
	 *
	 * @var array
	 */
	public $missing_required = [
		'priority'      => 30,
		'configuration' => [
			'max_posts' => 1,
		],
	];

	/**
	 * A segment with additional unknown properties.
	 *
	 * @var array
	 */
	public $additional_properties = [
		'name'          => 'Additional properties',
		'priority'      => 40,
		'criteria'      => [
			[
				'criteria_id' => 'newsletter',
				'value'       => 'is_subscriber',
			],
		],
		'configuration' => [
			'max_posts' => 1,
			'unknown'   => 'invalid',
		],
	];

	/**
	 * A segment with a string in a property that expects an integer.
	 *
	 * @var array
	 */
	public $invalid_int = [
		'name'          => 'Invalid Int',
		'priority'      => 10,
		'criteria'      => [
			[
				'criteria_id' => 'newsletter',
				'value'       => 'is_subscriber',
			],
		],
		'configuration' => [
			'max_posts' => 'string',
		],
	];

	/**
	 * An inactive segment.
	 *
	 * @var array
	 */
	public $inactive = [
		'name'          => 'Inactive Segment',
		'priority'      => 50,
		'criteria'      => [
			[
				'criteria_id' => 'newsletter',
				'value'       => 'is_subscriber',
			],
		],
		'configuration' => [
			'max_posts'   => 'string',
			'is_disabled' => true,
		],
	];

	/**
	 * Make sure we have a clear environment
	 */
	public static function set_up_before_class() {
		$segments = Newspack_Popups_Segmentation::get_segments();
		foreach ( $segments as $segment ) {
			Newspack_Popups_Segmentation::delete_segment( $segment['id'] );
		}
	}

	/**
	 * Data provider for test_create_segment
	 *
	 * @return array
	 */
	public function create_segment_data() {
		return [
			'complete_and_valid'    => [ $this->complete_and_valid ],
			'valid'                 => [ $this->valid ],
			'missing_required'      => [ $this->missing_required ],
			'additional_properties' => [ $this->additional_properties ],
			'invalid_int'           => [ $this->invalid_int ],
			'inactive'              => [ $this->inactive ],
		];
	}

	/**
	 * Test create_segment
	 *
	 * @param array $segment The segment.
	 * @dataProvider create_segment_data
	 */
	public function test_create_segment( $segment ) {
		$result = Newspack_Popups_Segmentation::create_segment( $segment );
		$this->assertSame( 1, count( $result ) );

		// Assert everything passed in was stored.
		// As of today, any arbitrary properties are allowed.
		foreach ( $segment as $key => $value ) {
			if ( 'priority' === $key ) {
				$this->assertSame( 0, $result[0][ $key ] );
				continue;
			}
			$this->assertSame( $value, $result[0][ $key ] );
		}

		$created_properties = [ 'id', 'created_at', 'updated_at' ];
		foreach ( $created_properties as $property ) {
			$this->assertArrayHasKey( $property, $result[0] );
		}
	}

	/**
	 * Test create_segment throws an error when passed a string.
	 */
	public function test_create_segment_throws() {
		$this->expectException( TypeError::class );
		Newspack_Popups_Segmentation::create_segment( 'string' );
	}

	/**
	 * Test get_segments
	 */
	public function test_get_segments() {
		$this->assertSame( [], Newspack_Popups_Segmentation::get_segments() );
		Newspack_Popups_Segmentation::create_segment( $this->complete_and_valid );
		Newspack_Popups_Segmentation::create_segment( $this->valid );
		Newspack_Popups_Segmentation::create_segment( $this->inactive );

		$segments = Newspack_Popups_Segmentation::get_segments();
		$this->assertSame( 3, count( $segments ) );
		$this->assertSame( $this->complete_and_valid['name'], $segments[0]['name'] );
		$this->assertSame( $this->valid['name'], $segments[1]['name'] );
		$this->assertSame( $this->inactive['name'], $segments[2]['name'] );

		$segments = Newspack_Popups_Segmentation::get_segments( false );

		$this->assertSame( 2, count( $segments ) );
		$this->assertSame( $this->complete_and_valid['name'], $segments[0]['name'] );
		$this->assertSame( $this->valid['name'], $segments[1]['name'] );
	}

	/**
	 * Test get_segments fill in empty priorities.
	 */
	public function test_get_segments_reindex_priorities() {
		$modified = $this->complete_and_valid;
		unset( $modified['priority'] );
		Newspack_Popups_Segmentation::create_segment( $this->valid );
		Newspack_Popups_Segmentation::create_segment( $modified );

		$segments = Newspack_Popups_Segmentation::get_segments();

		$this->assertSame( 2, count( $segments ) );
		$this->assertSame( $this->valid['name'], $segments[0]['name'] );
		$this->assertSame( $this->complete_and_valid['name'], $segments[1]['name'] );
		$this->assertSame( 0, $segments[0]['priority'] );
		$this->assertSame( 1, $segments[1]['priority'] );
	}

	/**
	 * Test get_segment_ids
	 */
	public function test_get_segment_ids() {
		$this->assertSame( [], Newspack_Popups_Segmentation::get_segment_ids() );
		Newspack_Popups_Segmentation::create_segment( $this->complete_and_valid );
		Newspack_Popups_Segmentation::create_segment( $this->valid );

		$segments    = Newspack_Popups_Segmentation::get_segments();
		$segment_ids = Newspack_Popups_Segmentation::get_segment_ids();

		$this->assertSame( 2, count( $segment_ids ) );
		$this->assertSame( $segments[0]['id'], $segment_ids[0] );
		$this->assertSame( $segments[1]['id'], $segment_ids[1] );
	}

	/**
	 * Test get_segment
	 */
	public function test_get_segment() {
		$segments_to_create = [
			$this->complete_and_valid,
			$this->valid,
			$this->missing_required,
			$this->additional_properties,
			$this->invalid_int,
		];

		foreach ( $segments_to_create as $segment ) {
			Newspack_Popups_Segmentation::create_segment( $segment );
		}

		$segments = Newspack_Popups_Segmentation::get_segments();

		foreach ( $segments as $segment ) {
			$segment_id      = $segment['id'];
			$segment_from_db = Newspack_Popups_Segmentation::get_segment( $segment_id );

			// Criteria hash and is_criteria_duplicated are calculated on the fly, so wont match.
			unset( $segment['criteria_hash'] );
			unset( $segment['is_criteria_duplicated'] );

			// but they should exist.
			$this->assertArrayHasKey( 'criteria_hash', $segment_from_db );
			$this->assertArrayHasKey( 'is_criteria_duplicated', $segment_from_db );
			unset( $segment_from_db['criteria_hash'] );
			unset( $segment_from_db['is_criteria_duplicated'] );

			$this->assertSame( $segment, $segment_from_db );
		}
	}

	/**
	 * Test delete_segment
	 */
	public function test_delete_segment() {
		$segments_to_create = [
			$this->complete_and_valid,
			$this->valid,
			$this->missing_required,
			$this->additional_properties,
			$this->invalid_int,
		];

		foreach ( $segments_to_create as $segment ) {
			Newspack_Popups_Segmentation::create_segment( $segment );
		}

		$segments = Newspack_Popups_Segmentation::get_segments();

		$delete_result = Newspack_Popups_Segmentation::delete_segment( $segments[0]['id'] );
		$this->assertSame( 4, count( $delete_result ) );
		$this->assertSame( $segments[1]['id'], $delete_result[0]['id'] );

		$delete_result = Newspack_Popups_Segmentation::delete_segment( $segments[3]['id'] );
		$this->assertSame( 3, count( $delete_result ) );
		$this->assertSame( $segments[4]['id'], $delete_result[2]['id'] );

		$delete_result2 = Newspack_Popups_Segmentation::delete_segment( 'non-existent' );
		$this->assertSame( $delete_result, $delete_result2 );
	}

	/**
	 * Test update_segment
	 */
	public function test_update_segment() {
		$segments_to_create = [
			$this->complete_and_valid,
			$this->valid,
		];

		foreach ( $segments_to_create as $segment ) {
			Newspack_Popups_Segmentation::create_segment( $segment );
		}

		$segments = Newspack_Popups_Segmentation::get_segments();

		$complete = $segments[0];

		$complete['name']                       = 'Edited';
		$complete['priority']                   = 99;
		$complete['configuration']['min_posts'] = 30;
		$complete['other_properties']           = true;

		$result = Newspack_Popups_Segmentation::update_segment( $complete );

		$this->assertSame( 'Edited', $result[0]['name'] );
		$this->assertSame( 30, $result[0]['configuration']['min_posts'] );
		$this->assertSame( 0, $result[0]['priority'], 'Priority should not be updated' );
		$this->assertNotContains( 'other_properties', $result[0], 'additional properties should not be included' );

		$this->assertSame( $this->valid['name'], $result[1]['name'] );
	}

	/**
	 * Test update_segment throws an error when passed a string.
	 */
	public function test_update_segment_throws() {
		$this->expectException( TypeError::class );
		Newspack_Popups_Segmentation::update_segment( 'string' );
	}

	/**
	 * Test reindex_segments
	 */
	public function test_reindex_segments() {
		$segments_to_create_in_different_order = [
			$this->complete_and_valid,
			$this->additional_properties,
			$this->invalid_int,
			$this->valid,
		];

		// Remove priority from the first segment. This will be populated with priority 0.
		unset( $segments_to_create_in_different_order[0]['priority'] );

		foreach ( $segments_to_create_in_different_order as $segment ) {
			Newspack_Popups_Segmentation::create_segment( $segment );
		}

		$segments = Newspack_Popups_Segmentation::get_segments();

		$index = 0;
		foreach ( $segments as $segment ) {
			$this->assertSame( $index, $segment['priority'] );
			$this->assertSame( $segments_to_create_in_different_order[ $index ]['name'], $segment['name'] );
			$index++;
		}
	}

	/**
	 * Test reindex_segments throws an error when passed a string.
	 */
	public function test_reindex_segments_throws() {
		$this->expectException( TypeError::class );
		Newspack_Popups_Segmentation::reindex_segments( 'string' );
	}

	/**
	 * Test sort_segments
	 */
	public function test_sort_segments() {
		$segments_to_create_in_different_order = [
			$this->complete_and_valid,
			$this->additional_properties,
			$this->invalid_int,
			$this->valid,
		];

		foreach ( $segments_to_create_in_different_order as $segment ) {
			Newspack_Popups_Segmentation::create_segment( $segment );
		}

		$segments = Newspack_Popups_Segmentation::get_segments();

		$new_order = [
			$segments[3]['id'],
			$segments[0]['id'],
			$segments[1]['id'],
			$segments[2]['id'],
		];

		$sorted = Newspack_Popups_Segmentation::sort_segments( $new_order );

		$this->assertSame( $segments[3]['name'], $sorted[0]['name'] );
		$this->assertSame( $segments[0]['name'], $sorted[1]['name'] );
		$this->assertSame( $segments[1]['name'], $sorted[2]['name'] );
		$this->assertSame( $segments[2]['name'], $sorted[3]['name'] );

		// Assert that the sorted segments were persisted.
		$this->assertSame( $sorted, Newspack_Popups_Segmentation::get_segments() );

		$this->assertTrue(
			is_wp_error(
				Newspack_Popups_Segmentation::sort_segments( array_merge( $new_order, [ 'asdasd' ] ) )
			),
			'Should return wp error if an invalid id is part of the array'
		);
	}

	/**
	 * Test sort_segments throws an error when passed a string.
	 */
	public function test_sort_segments_throws() {
		$this->expectException( TypeError::class );
		Newspack_Popups_Segmentation::sort_segments( 'string' );
	}

	/**
	 * Data provider for test_validate_segment_ids
	 *
	 * @return array
	 */
	public function data_validate_segment_ids() {
		return [
			[
				[ 1, 2, 3 ],
				[
					[
						'id' => 1,
					],
					[
						'id' => 2,
					],
					[
						'id' => 3,
					],
				],
				true,
			],
			[
				[ 1, 2, 3 ],
				[
					[
						'id' => 1,
					],
					[
						'id' => 2,
					],
				],
				false,
			],
			[
				[ 1, 2, 3 ],
				[
					[
						'id' => 1,
					],
					[
						'id' => 2,
					],
					[
						'id' => 4,
					],
				],
				false,
			],
			[
				[ 1, 2, 3 ],
				[
					[
						'id' => 1,
					],
					[
						'id' => 2,
					],
					[
						'id' => 3,
					],
					[
						'id' => 4,
					],
				],
				false,
			],
			[
				[ 1, 2, 3, 4 ],
				[
					[
						'id' => 1,
					],
					[
						'id' => 2,
					],
					[
						'id' => 4,
					],
				],
				false,
			],
			[
				[ 1, 2 ],
				'string',
				false,
				true,
			],
			[
				'string',
				[
					[
						'id' => 1,
					],
				],
				false,
				true,
			],
		];
	}

	/**
	 * Test validate_segment_ids
	 *
	 * @param array   $segment_ids Array of segment IDs to validate.
	 * @param array   $segments    Array of existing segments to validate against.
	 * @param boolean $expected    Whether $segment_ids is valid.
	 * @param boolean $throw       Whether it will throw Type_Error.
	 * @dataProvider data_validate_segment_ids
	 */
	public function test_validate_segment_ids( $segment_ids, $segments, $expected, $throw = false ) {
		if ( $throw ) {
			$this->expectException( TypeError::class );
		}
		$this->assertSame( $expected, Newspack_Popups_Segmentation::validate_segment_ids( $segment_ids, $segments ) );
	}

	/**
	 * Test that the priority order is preserved when creating segments.
	 *
	 * New segments should be added to the end of the list, regardless of the informed priority.
	 */
	public function test_create_preserves_order() {
		$test_segments = [
			'defaultSegment'                      => [],
			'disabledSegment'                     => [
				'is_not_subscribed' => true,
				'is_disabled'       => true,
			],
			'segmentBetween3And5'                 => [
				'min_posts' => 2,
				'max_posts' => 3,
				'priority'  => 0,
			],
			'segmentSessionReadCountBetween3And5' => [
				'min_session_posts' => 2,
				'max_session_posts' => 3,
				'priority'          => 1,
			],
			'segmentSubscribers'                  => [
				'is_subscribed' => true,
				'priority'      => 2,
			],
			'segmentLoggedIn'                     => [
				'has_user_account' => true,
				'priority'         => 3,
			],
			'segmentNonSubscribers'               => [
				'is_not_subscribed' => true,
				'priority'          => 4,
			],
			'segmentWithReferrers'                => [
				'referrers' => 'foobar.com, newspack.pub',
				'priority'  => 5,
			],
			'anotherSegmentWithReferrers'         => [
				'referrers' => 'bar.com',
				'priority'  => 6,
			],
			'segmentWithNegativeReferrer'         => [
				'referrers_not' => 'baz.com',
				'priority'      => 7,
			],
		];

		foreach ( $test_segments as $key => $value ) {
			$segments = Newspack_Popups_Segmentation::create_segment(
				[
					'name'          => $key,
					'configuration' => $value,
				]
			);

			$last_segment = end( $segments );
			$this->assertSame( $key, $last_segment['name'] );
		}
	}
}
