<?php
/**
 * Class Segmentation Test
 *
 * @package Newspack_Popups
 */

use DMS\PHPUnitExtensions\ArraySubset\ArraySubsetAsserts;

/**
 * Segmentation test case.
 */
class SegmentationTest extends WP_UnitTestCase {

	use ArraySubsetAsserts;

	/**
	 * Parse a "view as" spec.
	 */
	public function test_parse_view_as() {
		self::assertEquals(
			Newspack_Popups_View_As::parse_view_as( 'groups:one,two;segment:123' ),
			[
				'groups'  => 'one,two',
				'segment' => '123',
			],
			'Spec is parsed.'
		);

		self::assertEquals(
			Newspack_Popups_View_As::parse_view_as( 'all' ),
			[
				'all' => true,
			],
			'Spec is parsed with the "all" value'
		);

		self::assertEquals(
			Newspack_Popups_View_As::parse_view_as( '' ),
			[],
			'Empty array is returned if there is no spec.'
		);
	}
}
