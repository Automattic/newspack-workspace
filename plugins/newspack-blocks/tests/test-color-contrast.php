<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ColorContrastTest
 *
 * @package Newspack_Blocks
 */

/**
 * Tests for Newspack_Blocks::get_color_for_contrast().
 *
 * The expectations must stay in parity with getColorForContrast() in
 * src/blocks/donate/utils.ts, which runs the same APCA math.
 */
class ColorContrastTest extends WP_UnitTestCase {
	/**
	 * Background color to expected text color ('black' or 'white').
	 *
	 * @return array[]
	 */
	public function contrast_provider() {
		return [
			'pure black background'    => [ '#000000', 'white' ],
			'pure white background'    => [ '#ffffff', 'black' ],
			'near-white background'    => [ '#f0f0f0', 'black' ],
			'amber background'         => [ '#ffcc00', 'black' ],
			// APCA flip zone: the old WCAG2 heuristic picked black here, but APCA
			// scores white decisively higher, so white must win.
			'mid-tone green flip zone' => [ '#178f15', 'white' ],
			'blue background'          => [ '#3366cc', 'white' ],
			'red background'           => [ '#dd3333', 'white' ],
			'shorthand white'          => [ '#fff', 'black' ],
			'shorthand blue'           => [ '#36c', 'white' ],
			'unparseable input'        => [ 'not-a-color', 'black' ],
			// Parity cases shared with the jest fixture table.
			'unprefixed blue'          => [ '3366cc', 'white' ],
			'unprefixed green'         => [ '178f15', 'white' ],
			'empty string'             => [ '', 'black' ],
			'dark 8-digit hex'         => [ '#33333380', 'white' ],
			'uppercase amber'          => [ '#FFCC00', 'black' ],
			'padded green'             => [ ' #178f15 ', 'white' ],
		];
	}

	/**
	 * The picker returns the readable text color for a background.
	 *
	 * @dataProvider contrast_provider
	 *
	 * @param string $background Background color to test.
	 * @param string $expected   Expected text color ('black' or 'white').
	 */
	public function test_get_color_for_contrast( $background, $expected ) {
		$this->assertSame( $expected, Newspack_Blocks::get_color_for_contrast( $background ) );
	}
}
