<?php
/**
 * Theme support tests.
 *
 * @package Newspack_Block_Theme
 */

/**
 * Tests the features the theme declares support for.
 */
class Test_Theme_Support extends WP_UnitTestCase {
	/**
	 * The theme opts in to the newspack-ui theme style.
	 */
	public function test_declares_newspack_ui_theme_style() {
		$this->assertTrue( current_theme_supports( 'newspack-ui-theme-style' ) );
	}
}
