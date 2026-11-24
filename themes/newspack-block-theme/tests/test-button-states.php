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

		$this->assertStringContainsString( 'style="background-color:#003da5;--newspack-button-bg-hover: #002f84;--newspack-button-bg-pressed: #00266f"', $html );
	}

	/**
	 * Pure black lightens toward its text by the lighten shares, the floor the standard step can't reach from black.
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

		$this->assertStringContainsString( '--newspack-button-bg-hover: #222222;--newspack-button-bg-pressed: #484848', $html );
	}

	/**
	 * The theme's own contrast color counts as near-black and lightens by the standard step, toward white when no text color is set.
	 *
	 * This is the same color newspack-ui's dark button reaches from the same fill.
	 */
	public function test_contrast_fill_lightens_like_newspack_ui() {
		$html = $this->render( [ 'style' => [ 'color' => [ 'background' => '#1e1e1e' ] ] ], 'background-color:#1e1e1e' );

		$this->assertStringContainsString( '--newspack-button-bg-hover: #3a3a3a;--newspack-button-bg-pressed: #4f4f4f', $html );
	}

	/**
	 * A translucent fill is left to the stylesheet's overlay, since a mixed hover would turn opaque.
	 */
	public function test_translucent_fill_is_untouched() {
		$html = $this->render( [ 'style' => [ 'color' => [ 'background' => '#ffffff33' ] ] ], 'background-color:#ffffff33' );

		$this->assertStringNotContainsString( '--newspack-button', $html );
	}

	/**
	 * A palette background is left to the stylesheet.
	 */
	public function test_palette_fill_is_untouched() {
		$html = $this->render( [ 'backgroundColor' => 'accent-2' ] );

		$this->assertStringNotContainsString( '--newspack-button', $html );
	}

	/**
	 * A button without a background is left alone.
	 */
	public function test_no_fill_is_untouched() {
		$html = $this->render( [] );

		$this->assertStringNotContainsString( '--newspack-button', $html );
	}

	/**
	 * A fill darkens from #272727 up and lightens below it, by APCA luminance.
	 */
	public function test_near_black_cut_off() {
		$above = Button_States::get_state_colors( '#272727', '#ffffff' );
		$below = Button_States::get_state_colors( '#262626', '#ffffff' );

		$this->assertLessThan( Button_States::to_oklab( '#272727' )[0], Button_States::to_oklab( $above['hover'] )[0] );
		$this->assertGreaterThan( Button_States::to_oklab( '#262626' )[0], Button_States::to_oklab( $below['hover'] )[0] );
	}
}
