<?php
/**
 * Tests the ads model functionality.
 *
 * @package Newspack\Tests
 */

use Newspack_Ads\Providers\GAM_Model;
use Newspack\Reader_Data;

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
	 * A caller-supplied unique id must be escaped where it is built into the
	 * container div id, so it cannot break out of the id attribute.
	 */
	public function test_ad_unit_code_escapes_container_id() {
		$payload = "a' onmouseover='alert(document.domain)";
		$code    = GAM_Model::get_ad_unit_code(
			[
				'code'  => 'test-ad-code',
				'sizes' => [],
			],
			$payload
		);
		// The reflected value must survive (not be silently dropped) but must not
		// break out of the single-quoted id attribute.
		self::assertStringContainsString(
			'alert(document.domain)',
			$code,
			'The id value should be preserved, only escaped.'
		);
		self::assertStringNotContainsString(
			"' onmouseover='",
			$code,
			'The id must not break out of the attribute: ' . $code
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
	 * A newspack-plugin without get_active_subscriptions() still gets the
	 * newsletter and donor statuses. A separate process, because the stand-in
	 * Reader_Data class can be declared only once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_older_newspack_plugin_keeps_newsletter_and_donor_statuses() {
		require __DIR__ . '/mocks/reader-data-without-active-subscriptions.php';

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		\Newspack\Reader_Data::$bool_values[ $user_id ] = [
			'is_newsletter_subscriber' => true,
			'is_donor'                 => true,
		];

		self::assertSame( [ 'logged_in', 'newsletter_subscriber', 'donor' ], GAM_Model::get_ad_targeting( [] )['reader_status'] );
	}

	/**
	 * Reader status targeting respects JSON-encoded boolean values.
	 */
	public function test_ad_targeting_reader_status_booleans() {
		require_once __DIR__ . '/mocks/reader-data.php';

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		try {
			Reader_Data::$data = [
				'is_newsletter_subscriber' => 'true',
				'is_donor'                 => 'false',
			];
			$targeting = GAM_Model::get_ad_targeting( self::$mock_gam_ad_units[0] );
			self::assertContains( 'newsletter_subscriber', $targeting['reader_status'] );
			self::assertNotContains( 'donor', $targeting['reader_status'] );

			Reader_Data::$data = [
				'is_newsletter_subscriber' => 'false',
				'is_donor'                 => 'true',
			];
			$targeting = GAM_Model::get_ad_targeting( self::$mock_gam_ad_units[0] );
			self::assertNotContains( 'newsletter_subscriber', $targeting['reader_status'] );
			self::assertContains( 'donor', $targeting['reader_status'] );
		} finally {
			Reader_Data::$data = [];
			wp_set_current_user( 0 );
		}
	}

	/**
	 * A reader whose only subscription is cancelled has the stored list "[]",
	 * and is no longer targeted as a subscriber.
	 */
	public function test_former_subscriber_is_not_targeted_as_subscriber() {
		require_once __DIR__ . '/mocks/reader-data.php';

		wp_set_current_user( self::factory()->user->create() );

		try {
			Reader_Data::$data = [ 'active_subscriptions' => '[123]' ];
			$targeting         = GAM_Model::get_ad_targeting( self::$mock_gam_ad_units[0] );
			self::assertContains( 'subscriber', $targeting['reader_status'] );

			Reader_Data::$data = [ 'active_subscriptions' => '[]' ];
			$targeting         = GAM_Model::get_ad_targeting( self::$mock_gam_ad_units[0] );
			self::assertNotContains( 'subscriber', $targeting['reader_status'] );
		} finally {
			Reader_Data::$data = [];
			wp_set_current_user( 0 );
		}
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
