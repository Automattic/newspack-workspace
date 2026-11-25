<?php
/**
 * Tests for Newspack_UI_Icons.
 *
 * @package Newspack\Tests
 */

/**
 * Test Newspack_UI_Icons.
 */
class Newspack_Test_UI_Icons extends WP_UnitTestCase {
	/**
	 * The comments icon is registered and renders an SVG.
	 */
	public function test_comments_icon_exists(): void {
		$svg = \Newspack\Newspack_UI_Icons::get_svg( 'comments' );
		$this->assertNotEmpty( $svg, 'comments icon should be registered' );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( 'newspack-ui__svg-icon--comments', $svg );
	}

	/**
	 * Stroked icons keep their stroke and fill attributes through the sanitizer.
	 */
	public function test_stroked_icons_survive_kses(): void {
		$stroked = 0;
		foreach ( array_keys( \Newspack\Newspack_UI_Icons::$ui_icons ) as $name ) {
			$raw = \Newspack\Newspack_UI_Icons::get_svg( $name );
			if ( ! str_contains( $raw, 'stroke="currentColor"' ) ) {
				continue;
			}
			$stroked++;
			$clean = wp_kses( $raw, \Newspack\Newspack_UI_Icons::sanitize_svgs() );

			$this->assertStringContainsString( 'style="fill:none"', $clean, "$name keeps its unfilled root" );
			$this->assertStringContainsString( 'stroke="currentColor"', $clean, "$name keeps its stroke color" );
			$this->assertStringContainsString( 'stroke-width="1.5"', $clean, "$name keeps its stroke width" );

			foreach ( [ 'd', 'fill', 'stroke', 'stroke-linejoin', 'stroke-linecap', 'cx', 'cy', 'r' ] as $attribute ) {
				$this->assertSame(
					preg_match_all( '/\s' . $attribute . '="[^"]*"/', $raw ),
					preg_match_all( '/\s' . $attribute . '="[^"]*"/', $clean ),
					"$name keeps every $attribute attribute"
				);
			}
			$this->assertSame( substr_count( $raw, '<circle' ), substr_count( $clean, '<circle' ), "$name keeps its circles" );
		}
		$this->assertGreaterThan( 0, $stroked );
	}

	/**
	 * Admin menu icons stay filled, because WordPress repaints menu SVGs by rewriting their fill and style attributes.
	 */
	public function test_admin_menu_icons_stay_filled(): void {
		foreach ( [ 'ad', 'chartReport', 'collections', 'curatedList', 'email', 'globe', 'newspack', 'people' ] as $name ) {
			$svg = \Newspack\Newspack_UI_Icons::get_svg( $name );
			$this->assertNotEmpty( $svg, "$name is registered" );
			$this->assertStringNotContainsString( 'stroke=', $svg, "$name has no stroke" );
			$this->assertStringNotContainsString( 'style=', $svg, "$name has no inline style" );
			$this->assertMatchesRegularExpression( '/\sfill="/', $svg, "$name has a fill the menu painter can recolour" );
		}
	}
}
