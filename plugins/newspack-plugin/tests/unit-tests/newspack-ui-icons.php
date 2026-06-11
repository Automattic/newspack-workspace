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
	public function test_comments_icon_exists() {
		$svg = \Newspack\Newspack_UI_Icons::get_svg( 'comments' );
		$this->assertNotEmpty( $svg, 'comments icon should be registered' );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( 'newspack-ui__svg-icon--comments', $svg );
	}
}
