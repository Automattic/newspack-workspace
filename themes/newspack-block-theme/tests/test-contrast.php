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
	 * The accent and accent-2 colors set by the current test.
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
	 * A readable accent keeps its fill as the focus ring.
	 */
	public function test_readable_accent_has_no_focus_fallback() {
		$css = $this->get_css(
			[
				'base'     => '#ffffff',
				'accent'   => '#003da5',
				'accent-2' => '#2055b0',
			]
		);

		$this->assertStringContainsString( '--wp--preset--color--accent-contrast', $css );
		$this->assertStringNotContainsString( '--wp--custom--focus--fill', $css );
	}

	/**
	 * A pale accent falls back to the focus color for the fill ring.
	 */
	public function test_pale_accent_falls_back_to_focus_color() {
		$css = $this->get_css(
			[
				'base'     => '#ffffff',
				'accent'   => '#ffe680',
				'accent-2' => '#2055b0',
			]
		);

		$this->assertStringContainsString( '.has-accent-background-color', $css );
		$this->assertStringContainsString( '.has-primary-background-color', $css );
		$this->assertStringContainsString( ':not([class*="-background-color"])', $css );
		$this->assertStringNotContainsString( '.has-accent-2-background-color', $css );
		$this->assertStringContainsString( '--wp--custom--focus--fill: var(--wp--custom--focus--color, var(--wp--preset--color--contrast))', $css );
	}

	/**
	 * A pale second accent only changes buttons filled with it.
	 */
	public function test_pale_second_accent() {
		$css = $this->get_css(
			[
				'base'     => '#ffffff',
				'accent'   => '#003da5',
				'accent-2' => '#ffe680',
			]
		);

		$this->assertStringContainsString( '.has-accent-2-background-color', $css );
		$this->assertStringContainsString( '.has-secondary-background-color', $css );
		$this->assertStringNotContainsString( '.has-accent-background-color', $css );
	}

	/**
	 * An accent at 3:1 against the base keeps its fill ring.
	 */
	public function test_accent_at_three_to_one_has_no_focus_fallback() {
		$css = $this->get_css(
			[
				'base'     => '#ffffff',
				'accent'   => '#949494',
				'accent-2' => '#2055b0',
			]
		);

		$this->assertStringNotContainsString( '--wp--custom--focus--fill', $css );
	}

	/**
	 * An accent just under 3:1 against the base falls back to the focus color.
	 */
	public function test_accent_under_three_to_one_falls_back_to_focus_color() {
		$css = $this->get_css(
			[
				'base'     => '#ffffff',
				'accent'   => '#959595',
				'accent-2' => '#2055b0',
			]
		);

		$this->assertStringContainsString( '--wp--custom--focus--fill', $css );
	}

	/**
	 * A light accent on a dark base reads.
	 */
	public function test_light_accent_on_dark_base() {
		$css = $this->get_css(
			[
				'base'     => '#1e1e1e',
				'accent'   => '#ffc857',
				'accent-2' => '#b8d2f0',
			]
		);

		$this->assertStringNotContainsString( '--wp--custom--focus--fill', $css );
	}
}
