<?php
/**
 * Tests the Curated List dark background classification.
 *
 * @package Newspack_Listings
 */

use function Newspack_Listings\Curated_List_Block\has_dark_background;

require_once dirname( __DIR__ ) . '/src/blocks/curated-list/view.php';

/**
 * Tests the Curated List dark background classification.
 */
class Test_Newspack_Listings_Curated_List_Contrast extends WP_UnitTestCase {
	/**
	 * Backgrounds, as [ background, expected dark ].
	 *
	 * @return array[]
	 */
	public function background_provider() {
		return [
			'teal'        => [ '#00897b', true ],
			'orange'      => [ '#e65100', true ],
			'blue'        => [ '#3366cc', true ],
			'yellow'      => [ '#ffcc00', false ],
			'light grey'  => [ '#f0f0f0', false ],
			'black'       => [ '#000000', true ],
			'white'       => [ '#ffffff', false ],
			'unparseable' => [ 'nonsense', false ],
		];
	}

	/**
	 * Test the classification follows the shared text color rule.
	 *
	 * @dataProvider background_provider
	 *
	 * @param string $background Background color.
	 * @param bool   $expected   Whether the background is dark.
	 */
	public function test_has_dark_background( $background, $expected ) {
		$this->assertSame( $expected, has_dark_background( $background ) );
	}
}
