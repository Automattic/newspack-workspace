<?php
/**
 * Tests that the Newspack UI output for classic themes stays on its baseline.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack_UI;

/**
 * Pin the inline CSS the Newspack UI adds when no theme opts into a style.
 *
 * @group Newspack_UI_Theme_Style
 */
class Newspack_Test_UI_Theme_Style extends WP_UnitTestCase {

	/**
	 * Reset the style registry.
	 */
	public function tear_down(): void {
		wp_dequeue_style( 'newspack-ui' );
		wp_deregister_style( 'newspack-ui' );
		parent::tear_down();
	}

	/**
	 * Without a theme opt-in, the inline CSS is the pre-adapter output.
	 */
	public function test_classic_theme_inline_css_matches_baseline(): void {
		Newspack_UI::enqueue_assets();
		Newspack_UI::theme_colors_css();
		Newspack_UI::colors_css_wrap();

		$inline = implode( "\n", (array) wp_styles()->get_data( 'newspack-ui', 'after' ) );

		$this->assertSame(
			file_get_contents( dirname( __DIR__ ) . '/fixtures/newspack-ui-classic-inline.css' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$inline
		);
	}
}
