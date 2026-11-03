<?php
/**
 * Button state color tests.
 *
 * @package Newspack_Block_Theme
 */

use Newspack_Block_Theme\Button_States;

/**
 * Tests the hover and press colors given to buttons with a custom background.
 */
class Test_Button_States extends WP_UnitTestCase {
	/**
	 * Run the filter on a button.
	 *
	 * @param array  $attrs The block attributes.
	 * @param string $style The inline style on the link.
	 * @return string
	 */
	private function render( $attrs, $style = '' ) {
		$html = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . ( '' === $style ? '' : ' style="' . $style . '"' ) . ' href="#">Go</a></div>';
		return Button_States::render_block(
			$html,
			[
				'blockName' => 'core/button',
				'attrs'     => $attrs,
			]
		);
	}

	/**
	 * A custom fill darkens toward black in oklab by the theme's hover and press shares.
	 */
	public function test_custom_fill_darkens() {
		$html = $this->render( [ 'style' => [ 'color' => [ 'background' => '#003da5' ] ] ], 'background-color:#003da5' );

		$this->assertStringContainsString( 'style="background-color:#003da5;--wp--custom--button--bg-hover: #002f84;--wp--custom--button--bg-pressed: #00266f"', $html );
	}

	/**
	 * A near-black fill lightens toward its text instead, by the lighten shares.
	 */
	public function test_near_black_fill_lightens() {
		$html = $this->render(
			[
				'style' => [
					'color' => [
						'background' => '#000000',
						'text'       => '#ffffff',
					],
				],
			],
			'color:#ffffff;background-color:#000000' 
		);

		$this->assertStringContainsString( '--wp--custom--button--bg-hover: #222222;--wp--custom--button--bg-pressed: #484848', $html );
	}

	/**
	 * The theme's own contrast color counts as near-black and lightens, toward white when no text color is set.
	 */
	public function test_contrast_fill_lightens_toward_white() {
		$html = $this->render( [ 'style' => [ 'color' => [ 'background' => '#1e1e1e' ] ] ], 'background-color:#1e1e1e' );

		$this->assertStringContainsString( '--wp--custom--button--bg-hover: #4f4f4f;--wp--custom--button--bg-pressed: #6f6f6f', $html );
	}

	/**
	 * A palette background is left to the stylesheet.
	 */
	public function test_palette_fill_is_untouched() {
		$html = $this->render( [ 'backgroundColor' => 'accent-2' ] );

		$this->assertStringNotContainsString( '--wp--custom--button', $html );
	}

	/**
	 * A button without a background is left alone.
	 */
	public function test_no_fill_is_untouched() {
		$html = $this->render( [] );

		$this->assertStringNotContainsString( '--wp--custom--button', $html );
	}
}
