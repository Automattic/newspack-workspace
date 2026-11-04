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
	 * The user global styles set by the current test.
	 *
	 * @var array
	 */
	private $styles = [];

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
				'styles'   => $this->styles,
			]
		);
	}

	/**
	 * Enqueue the contrast styles for a palette and return the inline CSS.
	 *
	 * @param string[] $colors Palette colors keyed by slug.
	 * @param array    $styles User global styles to apply, such as a button hover background.
	 * @return string
	 */
	private function get_css( $colors, $styles = [] ) {
		$this->colors = $colors;
		$this->styles = $styles;
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
	 * The WCAG floor overrides APCA's pick at rest.
	 *
	 * APCA prefers white on this accent, but white only reaches 3.3:1 where black
	 * reaches 6.4:1.
	 */
	public function test_rest_contrast_holds_to_the_wcag_floor() {
		$css = $this->get_css(
			[
				'accent' => '#0099cc',
			]
		);

		$this->assertStringContainsString( '--wp--preset--color--accent-contrast: #000000;', $css );
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
	 * Accents whose hover text keeps the rest color because it still reads, keeps
	 * it because neither color reaches the floor on the darkened fills, and flips
	 * because only the other color reaches it, respectively. The fills are the
	 * theme's oklab mixes read through the state tokens.
	 *
	 * @return array[]
	 */
	public function data_hover_contrast() {
		return [
			'rest color reads'      => [ '#003da5', '#ffffff' ],
			'neither reaches floor' => [ '#00aaff', '#000000' ],
			'floor forces a flip'   => [ '#0099cc', '#ffffff' ],
		];
	}

	/**
	 * A publisher's srgb mix with a literal share is scored as the mix it produces.
	 *
	 * The oklab theme default darkens this accent far enough for black to fail on
	 * press and white to take over; the lighter srgb fills keep black.
	 */
	public function test_srgb_hover_mix_is_scored_in_srgb() {
		$css = $this->get_css(
			[
				'accent' => '#1e90ff',
			],
			[
				'elements' => [
					'button' => [
						':hover'  => [ 'color' => [ 'background' => 'color-mix( in srgb, var( --wp--preset--color--accent ) 85%, black )' ] ],
						':active' => [ 'color' => [ 'background' => 'color-mix( in srgb, var( --wp--preset--color--accent ) 75%, black )' ] ],
					],
				],
			]
		);

		$this->assertStringContainsString( '--wp--preset--color--accent-contrast-hover: #000000;', $css );
	}

	/**
	 * A hover background that can't be resolved leaves the hover property unset.
	 */
	public function test_unresolvable_hover_background_is_skipped() {
		$css = $this->get_css(
			[
				'accent' => '#1e90ff',
			],
			[
				'elements' => [
					'button' => [
						':hover' => [ 'color' => [ 'background' => 'linear-gradient( red, blue )' ] ],
					],
				],
			]
		);

		$this->assertStringContainsString( '--wp--preset--color--accent-contrast: #000000;', $css );
		$this->assertStringNotContainsString( 'accent-contrast-hover', $css );
	}
}
