<?php
/**
 * Tests the ads model functionality.
 *
 * @package Newspack\Tests
 */

use Newspack_Ads\Providers\GAM_Model;

/**
 * Test ads model functionality.
 */
class ModelTest extends WP_UnitTestCase {
	private static $network_code      = 42; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $legacy_ad_id      = null; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $ad_code_1         = 'code1'; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $sizes_1           = [ [ 123, 321 ] ]; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $mock_gam_ad_units = []; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	public static function set_up_before_class() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		// Set the active network code.
		update_option( GAM_Model::OPTION_NAME_LEGACY_NETWORK_CODE, self::$network_code );
	}

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( self::$legacy_ad_id ) {
			wp_delete_post( self::$legacy_ad_id );
		}

		// Create a legacy ad unit (a CPT).
		self::$legacy_ad_id = self::factory()->post->create(
			[
				'post_type'  => 'newspack_ad_codes',
				'post_title' => 'Legacy Ad Unit 1',
			]
		);
		update_post_meta( self::$legacy_ad_id, 'sizes', self::$sizes_1 );
		update_post_meta( self::$legacy_ad_id, 'code', self::$ad_code_1 );

		// Save mock GAM properties.
		self::$mock_gam_ad_units = [
			self::createMockGAMAdUnit(
				[
					'id'    => '12345',
					'code'  => 'code2',
					'name'  => 'GAM Ad Unit 1',
					'sizes' => self::$sizes_1,
				]
			),
			self::createMockGAMAdUnit(
				[
					'id'    => '54321',
					'code'  => 'code3',
					'name'  => 'GAM Ad Unit 2',
					'sizes' => [],
					'fluid' => true,
				]
			),
		];
		GAM_Model::sync_gam_settings(
			self::$mock_gam_ad_units,
			[ 'network_code' => self::$network_code ]
		);
	}

	/**
	 * Create mock GAM Ad Unit object.
	 *
	 * @param object $config Config.
	 */
	private static function createMockGAMAdUnit( $config ) {
		return array_merge(
			[
				'id'     => uniqid(),
				'status' => 'ACTIVE',
			],
			$config
		);
	}

	/**
	 * Format of the saved option storing the GAM properties.
	 */
	public function test_option_format() {
		$option_value = get_option( GAM_Model::OPTION_NAME_GAM_ITEMS, true );
		self::assertEquals(
			$option_value,
			[
				self::$network_code => [
					'ad_units' => self::$mock_gam_ad_units,
				],
			],
			'The option value has the expected shape - the ad units grouped under the network code.'
		);
	}

	/**
	 * The markup generated to be inserted on the page.
	 */
	public function test_ad_unit_generated_markup() {
		$legacy_ad_unit = GAM_Model::get_ad_unit_for_display( self::$legacy_ad_id );
		self::assertStringContainsString(
			'<!-- /' . self::$network_code . '/' . self::$ad_code_1 . ' -->',
			$legacy_ad_unit['ad_code'],
			'The ad code for the legacy ad unit contains a comment with network ID and ad unit code.'
		);

		$gam_ad_unit = GAM_Model::get_ad_unit_for_display( self::$mock_gam_ad_units[0]['id'] );
		self::assertStringContainsString(
			'<!-- /' . self::$network_code . '/' . $gam_ad_unit['code'] . ' -->',
			$gam_ad_unit['ad_code'],
			'The ad code contains a comment with network ID and ad unit code.'
		);
	}

	/**
	 * Ad units getter.
	 */
	public function test_ad_units_getter() {
		$default_ad_units = GAM_Model::get_default_ad_units();
		$synced_ad_units  = GAM_Model::get_synced_ad_units();
		$result           = GAM_Model::get_ad_units();
		self::assertEquals(
			count( $default_ad_units ) + count( $synced_ad_units ) + 1,
			count( $result ),
			'All units are returned, because we want the synced units even without GAM connection.'
		);
		$legacy = array_search( true, array_column( $result, 'is_legacy' ), true );
		self::assertEquals(
			self::$legacy_ad_id,
			$result[ $legacy ]['id'],
			'The legacy ad unit is returned.'
		);
	}

	/**
	 * Ad targeting application.
	 */
	public function test_ad_targeting() {
		$post_id       = self::factory()->post->create();
		$category_slug = 'events';
		$category_id   = self::factory()->term->create(
			[
				'name'     => 'Events',
				'taxonomy' => 'category',
				'slug'     => $category_slug,
			]
		);
		wp_set_post_terms( $post_id, [ $category_id ], 'category' );

		self::go_to( get_permalink( $post_id ) );
		$result = GAM_Model::get_ad_targeting( self::$mock_gam_ad_units[0] );
		self::assertEquals(
			$result['category'],
			[ $category_slug ],
			'The targeting property contains the category slug'
		);
	}

	/**
	 * Test sanitization functions.
	 */
	public function test_sanitization() {
		$sizes = [ [ 10, 10 ], [ 100, 100 ] ];
		$this->assertEquals( $sizes, GAM_Model::sanitize_sizes( $sizes ) );

		$sizes = [ [ 10, 10 ] ];
		$this->assertEquals( $sizes, GAM_Model::sanitize_sizes( $sizes ) );

		$sizes = [ [ 10, 10, 90 ] ];
		$this->assertNotEquals( $sizes, GAM_Model::sanitize_sizes( $sizes ) );

		$sizes = [ [ 'dog', 'cat' ] ];
		$this->assertNotEquals( $sizes, GAM_Model::sanitize_sizes( $sizes ) );

		$sizes = 'notanarray';
		$this->assertNotEquals( $sizes, GAM_Model::sanitize_sizes( $sizes ) );
	}

	/**
	 * Test size map rules.
	 */
	public function test_responsive_size_map() {

		self::assertEquals(
			[
				'10'  => [ [ 10, 10 ] ],
				'100' => [ [ 100, 100 ] ],
			],
			GAM_Model::get_responsive_size_map( [ [ 10, 10 ], [ 100, 100 ] ] )
		);

		self::assertEquals(
			[
				'10'  => [ [ 10, 10 ] ],
				'60'  => [ [ 60, 60 ] ],
				'90'  => [ [ 90, 90 ] ],
				'100' => [ [ 90, 90 ], [ 100, 100 ] ],
			],
			GAM_Model::get_responsive_size_map( [ [ 10, 10 ], [ 100, 100 ], [ 90, 90 ], [ 60, 60 ] ] )
		);

		self::assertEquals(
			[
				'10'  => [ [ 10, 10 ] ],
				'60'  => [ [ 60, 60 ] ],
				'90'  => [ [ 60, 60 ], [ 90, 90 ] ],
				'100' => [ [ 60, 60 ], [ 90, 90 ], [ 100, 100 ] ],
			],
			GAM_Model::get_responsive_size_map( [ [ 10, 10 ], [ 100, 100 ], [ 90, 90 ], [ 60, 60 ] ], 0.5 ),
			'Groups sizes with a custom difference ratio.'
		);

		self::assertEquals(
			[
				'300' => [ [ 300, 200 ], [ 300, 250 ] ],
				'350' => [ [ 350, 200 ] ],
				'640' => [ [ 640, 360 ] ],
				'960' => [ [ 640, 360 ], [ 960, 540 ] ],
			],
			GAM_Model::get_responsive_size_map( [ [ 300, 200 ], [ 300, 250 ], [ 350, 200 ], [ 640, 360 ], [ 960, 540 ] ], 0 ),
			'Groups sizes above the default width threshold of 600 regardless of their ratio difference.'
		);

		self::assertEquals(
			[
				'300' => [ [ 300, 200 ], [ 300, 250 ] ],
				'350' => [ [ 350, 200 ] ],
				'640' => [ [ 640, 360 ] ],
				'960' => [ [ 960, 540 ] ],
			],
			GAM_Model::get_responsive_size_map( [ [ 300, 200 ], [ 300, 250 ], [ 350, 200 ], [ 640, 360 ], [ 960, 540 ] ], 0, false ),
			'Groups sizes without ratio difference and threshold disabled.'
		);
	}
}
