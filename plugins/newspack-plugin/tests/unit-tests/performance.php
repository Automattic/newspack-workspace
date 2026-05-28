<?php
/**
 * Tests the Performance optmisations.
 *
 * @package Newspack\Tests
 */

use Newspack\Performance;

/**
 * Tests the performance optmisations.
 */
class Newspack_Test_Newspack_Performance extends WP_UnitTestCase {
	/**
	 * Test CSS minification.
	 */
	public function test_performance_css_minification() {
		$html_with_style_tags = '<html>
			<style>
				/* Here is a comment*/
				/**
				 * And this is a multi-line comment, with a link: https://example.com/hello-world
				 */
				body {
					color: red;
				}
			</style>
		</html>';
		self::assertEquals(
			'<html>
			<style>body{color: red;}</style>
		</html>',
			Newspack\Performance::minify_inline_style_tags( $html_with_style_tags ),
			'Minifies inline CSS.'
		);
	}
}
