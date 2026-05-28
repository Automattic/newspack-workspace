<?php
/**
 * Tests the GAM API.
 *
 * @package Newspack\Tests
 */

use Newspack_Ads\Providers\GAM\Api;

/**
 * Tests the GAM API.
 */
class GAMApiTest extends WP_UnitTestCase {
	/**
	 * Creatives sample config.
	 *
	 * @var array[]
	 */
	private static $creatives_config;

	/**
	 * Test setup.
	 */
	public function set_up() {
		self::$creatives_config = [
			[
				'advertiser_id'            => '1',
				'name'                     => 'Test Name',
				'xsi_type'                 => 'ThirdPartyCreative',
				'width'                    => 1,
				'height'                   => 1,
				'is_safe_frame_compatible' => true,
				'snippet'                  => '<p>Test</p>',
			],
		];
	}

	/**
	 * Test building creatives from config.
	 */
	public function test_build_creatives() {
		$creatives = Api\Creatives::build_creatives_from_config( self::$creatives_config );
		$this->assertEquals( 1, count( $creatives ) );
		$creative = $creatives[0];
		$this->assertEquals( 'Test Name', $creative->getName() );
		$this->assertEquals( '1', $creative->getAdvertiserId() );
		$this->assertEquals( 1, $creative->getSize()->getWidth() );
		$this->assertEquals( 1, $creative->getSize()->getHeight() );
	}

	/**
	 * Test serializing Creative objects.
	 */
	public function test_serialize_creatives() {
		$creatives            = Api\Creatives::build_creatives_from_config( self::$creatives_config );
		$serialized_creatives = ( new Api\Creatives() )->get_serialized_creatives( $creatives );
		$this->assertEqualsCanonicalizing(
			[
				[
					'id'           => null,
					'name'         => 'Test Name',
					'advertiserId' => '1',
				],
			],
			$serialized_creatives
		);
	}
}
