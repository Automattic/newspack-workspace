<?php
/**
 * Focus color tests.
 *
 * @package Newspack_Block_Theme
 */

use Newspack_Block_Theme\Focus_Color;

/**
 * Tests the focus color render_block filter.
 */
class Test_Focus_Color extends WP_UnitTestCase {
	/**
	 * Drop the user palette override.
	 */
	public function tear_down() {
		remove_filter( 'wp_theme_json_data_user', [ $this, 'add_user_palette' ] );
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * Run the filter on a block wrapper.
	 *
	 * @param string $block_name The block name.
	 * @param array  $attrs      The block attributes.
	 * @param string $html       The rendered markup.
	 * @return string
	 */
	private function render( $block_name, $attrs, $html = '<div class="wp-block"><p>Text</p></div>' ) {
		return Focus_Color::render_block(
			$html,
			[
				'blockName' => $block_name,
				'attrs'     => $attrs,
			]
		);
	}

	/**
	 * Add a palette entry to the user origin.
	 *
	 * @param WP_Theme_JSON_Data $theme_json The user theme.json data.
	 * @return WP_Theme_JSON_Data
	 */
	public function add_user_palette( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => 3,
				'settings' => [
					'color' => [
						'palette' => [
							[
								'slug'  => 'brand',
								'color' => '#123456',
								'name'  => 'Brand',
							],
							[
								'slug'  => 'color1',
								'color' => '#654321',
								'name'  => 'Color 1',
							],
						],
					],
				],
			]
		);
	}

	/**
	 * A custom hex text color is written to the wrapper.
	 */
	public function test_custom_text_color() {
		$output = $this->render( 'core/group', [ 'style' => [ 'color' => [ 'text' => '#123456' ] ] ] );

		$this->assertMatchesRegularExpression( '/style="--wp--custom--focus--color:\s*#123456"/', $output );
	}

	/**
	 * An existing inline style is kept.
	 */
	public function test_keeps_existing_style() {
		$output = $this->render(
			'core/group',
			[ 'style' => [ 'color' => [ 'text' => '#123456' ] ] ],
			'<div class="wp-block" style="color:#123456;"><p>Text</p></div>'
		);

		$this->assertMatchesRegularExpression( '/style="color:#123456;--wp--custom--focus--color:\s*#123456"/', $output );
	}

	/**
	 * Theme and classic palette slugs are left to the stylesheet.
	 */
	public function test_stylesheet_slugs_are_skipped() {
		foreach ( [ 'contrast', 'base-5', 'primary', 'dark-gray', 'white' ] as $slug ) {
			$this->assertStringNotContainsString( 'focus--color', $this->render( 'core/group', [ 'textColor' => $slug ] ), $slug );
			$this->assertStringNotContainsString( 'focus--color', $this->render( 'core/group', [ 'style' => [ 'color' => [ 'text' => 'var:preset|color|' . $slug ] ] ] ), $slug );
		}
	}

	/**
	 * A slug that is in no palette never yields an undefined preset variable.
	 */
	public function test_unknown_slug_is_skipped() {
		$this->assertStringNotContainsString( 'focus--color', $this->render( 'core/group', [ 'textColor' => 'deleted-color' ] ) );
		$this->assertStringNotContainsString( 'focus--color', $this->render( 'core/group', [ 'style' => [ 'color' => [ 'text' => 'var:preset|color|deleted-color' ] ] ] ) );
	}

	/**
	 * A slug from the user palette maps to its preset variable.
	 */
	public function test_user_palette_slug() {
		add_filter( 'wp_theme_json_data_user', [ $this, 'add_user_palette' ] );
		wp_clean_theme_json_cache();

		$output = $this->render( 'core/group', [ 'textColor' => 'brand' ] );

		$this->assertMatchesRegularExpression( '/--wp--custom--focus--color:\s*var\(--wp--preset--color--brand\)/', $output );
	}

	/**
	 * A slug is converted to kebab case, as core names its preset variable.
	 */
	public function test_preset_variable_uses_kebab_case_slug() {
		add_filter( 'wp_theme_json_data_user', [ $this, 'add_user_palette' ] );
		wp_clean_theme_json_cache();

		$output = $this->render( 'core/group', [ 'textColor' => 'color1' ] );

		$this->assertMatchesRegularExpression( '/--wp--custom--focus--color:\s*var\(--wp--preset--color--color-1\)/', $output );
	}

	/**
	 * A palette slug in textColor wins over style.color.text, as in core.
	 */
	public function test_text_color_slug_takes_precedence() {
		$output = $this->render(
			'core/group',
			[
				'textColor' => 'contrast',
				'style'     => [ 'color' => [ 'text' => '#123456' ] ],
			]
		);

		$this->assertStringNotContainsString( 'focus--color', $output );
	}

	/**
	 * Navigation's custom text color is read from customTextColor.
	 */
	public function test_navigation_custom_text_color() {
		$output = $this->render( 'core/navigation', [ 'customTextColor' => '#123456' ], '<nav class="wp-block-navigation"></nav>' );

		$this->assertMatchesRegularExpression( '/--wp--custom--focus--color:\s*#123456/', $output );
	}

	/**
	 * Blocks that put their text color on an inner control are skipped.
	 */
	public function test_skips_blocks_without_text_color_serialization() {
		foreach ( [ 'core/button', 'core/search' ] as $block_name ) {
			$this->assertSame(
				'<div class="wp-block"><p>Text</p></div>',
				$this->render( $block_name, [ 'style' => [ 'color' => [ 'text' => '#ffffff' ] ] ] ),
				$block_name
			);
		}
	}
}
