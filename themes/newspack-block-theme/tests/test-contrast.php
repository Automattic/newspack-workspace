<?php
/**
 * Contrast tests.
 *
 * @package Newspack_Block_Theme
 */

use Newspack_Block_Theme\Contrast;

/**
 * Tests the derived contrast styles.
 */
class Test_Contrast extends WP_UnitTestCase {
	/**
	 * The palette colors set by the current test.
	 *
	 * @var string[]
	 */
	private $colors = [];

	/**
	 * The styles queue before the test ran.
	 *
	 * @var WP_Styles|null
	 */
	private $original_styles;

	/**
	 * Reset the styles queue.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_styles = $GLOBALS['wp_styles'] ?? null;
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Drop the palette override and restore the styles queue.
	 */
	public function tear_down() {
		remove_filter( 'wp_theme_json_data_user', [ $this, 'set_palette' ] );
		wp_clean_theme_json_cache();
		$GLOBALS['wp_styles'] = $this->original_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Override palette colors in the user origin.
	 *
	 * @param WP_Theme_JSON_Data $theme_json The user theme.json data.
	 * @return WP_Theme_JSON_Data
	 */
	public function set_palette( $theme_json ) {
		$palette = [];
		foreach ( $this->colors as $slug => $color ) {
			$palette[] = [
				'slug'  => $slug,
				'color' => $color,
				'name'  => $slug,
			];
		}

		return $theme_json->update_with(
			[
				'version'  => 3,
				'settings' => [ 'color' => [ 'palette' => $palette ] ],
			]
		);
	}

	/**
	 * Enqueue the contrast styles for a palette and return the inline CSS.
	 *
	 * @param string[] $colors Palette colors keyed by slug.
	 * @return string
	 */
	private function get_css( $colors ) {
		$this->colors = $colors;
		add_filter( 'wp_theme_json_data_user', [ $this, 'set_palette' ] );
		wp_clean_theme_json_cache();

		Contrast::enqueue_block_assets();

		$css = wp_styles()->get_data( 'newspack-block-theme-accent-contrast', 'after' );
		return is_array( $css ) ? implode( '', $css ) : '';
	}

	/**
	 * An accent gets its derived contrast color.
	 */
	public function test_accent_gets_contrast_color() {
		$css = $this->get_css(
			[
				'accent' => '#003da5',
			]
		);

		$this->assertStringContainsString( '--wp--preset--color--accent-contrast: #ffffff;', $css );
	}

	/**
	 * The theme default hover and press backgrounds get a derived hover contrast color.
	 *
	 * @dataProvider data_hover_contrast
	 *
	 * @param string $accent   The accent color.
	 * @param string $expected The expected hover contrast color.
	 */
	public function test_theme_default_hover_gets_contrast_color( $accent, $expected ) {
		$css = $this->get_css(
			[
				'accent' => $accent,
			]
		);

		$this->assertStringContainsString( '--wp--preset--color--accent-contrast-hover: ' . $expected . ';', $css );
	}

	/**
	 * Accents whose hover contrast color depends on the WCAG pick, the press
	 * background and the 85% hover mix respectively.
	 *
	 * @return array[]
	 */
	public function data_hover_contrast() {
		return [
			'wcag pick'     => [ '#00aaff', '#000000' ],
			'press counted' => [ '#0099cc', '#ffffff' ],
			'85% hover mix' => [ '#0099ff', '#000000' ],
		];
	}
}
