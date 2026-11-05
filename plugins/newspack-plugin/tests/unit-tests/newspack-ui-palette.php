<?php
/**
 * Tests the Newspack_UI_Palette colour math.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack_UI_Palette;

/**
 * Test the Newspack_UI_Palette class.
 *
 * @group Newspack_UI_Palette
 */
class Newspack_Test_UI_Palette extends WP_UnitTestCase {

	/**
	 * Mixing matches CSS color-mix in oklab.
	 */
	public function test_mix_matches_css_color_mix() {
		$this->assertSame( '#636363', Newspack_UI_Palette::mix( '#ffffff', 50, '#000000' ) );
	}

	/**
	 * The endpoints return the inputs.
	 */
	public function test_mix_endpoints() {
		$this->assertSame( '#d63638', Newspack_UI_Palette::mix( '#d63638', 100, '#003da5' ) );
		$this->assertSame( '#003da5', Newspack_UI_Palette::mix( '#d63638', 0, '#003da5' ) );
	}

	/**
	 * Unparseable input falls back to the background.
	 */
	public function test_mix_unparseable_returns_background() {
		$this->assertSame( '#123456', Newspack_UI_Palette::mix( 'nope', 50, '#123456' ) );
	}

	/**
	 * Red and blue land on their expected hues.
	 */
	public function test_oklch_hue_red_vs_blue() {
		$this->assertEqualsWithDelta( 25.3, Newspack_UI_Palette::oklch_hue( '#d63638' ), 0.5 );
		$this->assertEqualsWithDelta( 261.2, Newspack_UI_Palette::oklch_hue( '#003da5' ), 0.5 );
	}

	/**
	 * Lightness spans 0..1.
	 */
	public function test_oklab_lightness_extremes() {
		$this->assertEqualsWithDelta( 1.0, Newspack_UI_Palette::oklab_lightness( '#ffffff' ), 0.001 );
		$this->assertEqualsWithDelta( 0.0, Newspack_UI_Palette::oklab_lightness( '#000000' ), 0.001 );
	}

	/**
	 * Black on white is about Lc 106.
	 */
	public function test_lc_black_on_white() {
		$this->assertEqualsWithDelta( 106.0, Newspack_UI_Palette::lc( '#000000', '#ffffff' ), 0.5 );
	}

	/**
	 * Light-on-dark is reported as a positive magnitude.
	 */
	public function test_lc_symmetric_sign_free() {
		$this->assertGreaterThan( 0, Newspack_UI_Palette::lc( '#ffffff', '#000000' ) );
	}
}
