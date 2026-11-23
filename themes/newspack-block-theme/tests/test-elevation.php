<?php
/**
 * Elevation token tests.
 *
 * @package Newspack_Block_Theme
 */

use Newspack_Block_Theme\Contrast;
use Newspack_Block_Theme\Elevation;

/**
 * Tests the elevation tokens derived from the resolved base color.
 */
class Test_Elevation extends WP_UnitTestCase {
	/**
	 * The user-origin data set by the current test.
	 *
	 * @var array
	 */
	private $user_data = [];

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
	 * Drop the user-origin override and restore the styles queue.
	 */
	public function tear_down() {
		remove_filter( 'wp_theme_json_data_user', [ $this, 'set_user_data' ] );
		wp_clean_theme_json_cache();
		Contrast::reset_button_styles();
		$GLOBALS['wp_styles'] = $this->original_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Override the user origin.
	 *
	 * @param WP_Theme_JSON_Data $theme_json The user theme.json data.
	 * @return WP_Theme_JSON_Data
	 */
	public function set_user_data( $theme_json ) {
		return $theme_json->update_with( [ 'version' => 3 ] + $this->user_data );
	}

	/**
	 * A palette as theme.json settings.
	 *
	 * @param string[] $colors Palette colors keyed by slug.
	 * @return array
	 */
	private function palette( $colors ) {
		$palette = [];
		foreach ( $colors as $slug => $color ) {
			$palette[] = [
				'slug'  => $slug,
				'color' => $color,
				'name'  => $slug,
			];
		}

		return [ 'color' => [ 'palette' => $palette ] ];
	}

	/**
	 * Enqueue the elevation styles for a user origin and return the inline CSS.
	 *
	 * @param array $user_data The user-origin theme.json data, without the version.
	 * @return string
	 */
	private function get_css( $user_data ) {
		$this->user_data = $user_data;
		add_filter( 'wp_theme_json_data_user', [ $this, 'set_user_data' ] );
		wp_clean_theme_json_cache();
		Contrast::reset_button_styles();

		Elevation::enqueue_block_assets();

		$css = wp_styles()->get_data( 'newspack-block-theme-elevation', 'after' );
		return is_array( $css ) ? implode( '', $css ) : '';
	}

	/**
	 * The declarations a dark base prints.
	 *
	 * @return string
	 */
	private function dark_css() {
		return ':root:root, :root .editor-styles-wrapper { --wp--custom--elevation--ink: #000; --wp--custom--elevation--edge: color-mix(in oklab, var(--wp--preset--color--contrast) 14%, transparent); --wp--custom--elevation--strength: 2.5; }';
	}

	/**
	 * A dark base prints the dark elevation tokens, ahead of core's root declarations.
	 */
	public function test_dark_base_prints_dark_tokens() {
		$css = $this->get_css(
			[
				'settings' => $this->palette(
					[
						'base'     => '#1E1E1E',
						'contrast' => '#ffffff',
					]
				),
			]
		);

		$this->assertSame( $this->dark_css(), $css );
	}

	/**
	 * A light base prints nothing, so the theme.json defaults apply.
	 *
	 * @dataProvider data_light_bases
	 *
	 * @param string $base The base.
	 */
	public function test_light_base_prints_nothing( $base ) {
		$css = $this->get_css(
			[
				'settings' => $this->palette(
					[
						'base'     => $base,
						'contrast' => '#1e1e1e',
					]
				),
			]
		);

		$this->assertSame( '', $css );
	}

	/**
	 * Light bases.
	 *
	 * @return array[]
	 */
	public function data_light_bases() {
		return [
			'white' => [ '#ffffff' ],
			'cream' => [ '#f7f3ea' ],
			'acid'  => [ '#e6f05a' ],
		];
	}

	/**
	 * The theme's default palette prints nothing.
	 */
	public function test_default_palette_prints_nothing() {
		$this->assertSame( '', $this->get_css( [] ) );
	}

	/**
	 * A base that is not a plain hex prints nothing.
	 */
	public function test_non_hex_base_prints_nothing() {
		$css = $this->get_css(
			[
				'settings' => $this->palette(
					[
						'base'     => 'oklch(0.2 0 0)',
						'contrast' => '#ffffff',
					]
				),
			]
		);

		$this->assertSame( '', $css );
	}

	/**
	 * A Nocturne choice copied into the user origin without elevation tokens
	 * keeps the light theme.json defaults in its settings and still gets the dark tokens.
	 */
	public function test_user_origin_nocturne_copy_gets_dark_tokens() {
		$nocturne = wp_json_file_decode( get_theme_file_path( 'styles/10-nocturne.json' ), [ 'associative' => true ] );
		$settings = $nocturne['settings'];
		unset( $settings['custom']['elevation'] );

		$css = $this->get_css(
			[
				'settings' => $settings,
				'styles'   => $nocturne['styles'] ?? [],
			]
		);

		$this->assertSame( 'nocturne', wp_get_global_settings( [ 'custom', 'className' ] ) );
		$this->assertSame( '1', wp_get_global_settings( [ 'custom', 'elevation', 'strength' ] ) );
		$this->assertSame( $this->dark_css(), $css );
	}

	/**
	 * No style variation carries its own elevation tokens, so the dark values have one source.
	 */
	public function test_variations_leave_elevation_to_the_runtime() {
		foreach ( glob( get_theme_file_path( 'styles/*.json' ) ) as $file ) {
			$variation = wp_json_file_decode( $file, [ 'associative' => true ] );
			$this->assertArrayNotHasKey( 'elevation', $variation['settings']['custom'] ?? [], basename( $file ) );
		}
	}
}
