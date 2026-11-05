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
		$this->assertEqualsWithDelta( 107.9, Newspack_UI_Palette::lc( '#ffffff', '#000000' ), 0.5 );
	}

	/**
	 * Unparseable input reads as no contrast.
	 */
	public function test_lc_unparseable_is_zero() {
		$this->assertSame( 0.0, Newspack_UI_Palette::lc( 'nope', '#ffffff' ) );
		$this->assertSame( 0.0, Newspack_UI_Palette::lc( '#000000', 'nope' ) );
	}

	/**
	 * Palettes: base, contrast.
	 *
	 * @return array
	 */
	public function palettes() {
		return [
			'neutral'  => [ '#ffffff', '#1e1e1e' ],
			'cream'    => [ '#f7f3ea', '#1a1a1a' ],
			'nocturne' => [ '#1e1e1e', '#ffffff' ],
			'ledger'   => [ '#f2f0ea', '#000000' ],
		];
	}

	/**
	 * Solved mixes reach their Lc targets.
	 *
	 * @dataProvider palettes
	 *
	 * @param string $base     Base.
	 * @param string $contrast Contrast.
	 */
	public function test_solved_mixes_meet_targets( $base, $contrast ) {
		$fill   = Newspack_UI_Palette::mix( $contrast, 7, $base );
		$muted  = Newspack_UI_Palette::mix( $contrast, Newspack_UI_Palette::solve_mix( $contrast, $base, 60, Newspack_UI_Palette::TARGETS['text-muted'], $fill ), $base );
		$subtle = Newspack_UI_Palette::mix( $contrast, Newspack_UI_Palette::solve_mix( $contrast, $base, 50, Newspack_UI_Palette::TARGETS['text-subtle'] ), $base );
		$border = Newspack_UI_Palette::mix( $contrast, Newspack_UI_Palette::solve_mix( $contrast, $base, 20, Newspack_UI_Palette::TARGETS['border'] ), $base );

		$this->assertGreaterThanOrEqual( 60, Newspack_UI_Palette::lc( $muted, $base ) );
		$this->assertGreaterThanOrEqual( 45, Newspack_UI_Palette::lc( $subtle, $base ) );
		$this->assertGreaterThanOrEqual( 30, Newspack_UI_Palette::lc( $border, $base ) );
		$this->assertGreaterThanOrEqual( 60, Newspack_UI_Palette::lc( $muted, $fill ) );
	}

	/**
	 * Neutral stays at or under Phase 1's 70.
	 */
	public function test_neutral_stays_close_to_phase_1() {
		$this->assertLessThanOrEqual( 70, Newspack_UI_Palette::solve_mix( '#1e1e1e', '#ffffff', 60, 60, Newspack_UI_Palette::mix( '#1e1e1e', 7, '#ffffff' ) ) );
	}

	/**
	 * A palette with no contrast stops at 100.
	 */
	public function test_solver_terminates_on_monochrome() {
		$this->assertSame( 100, Newspack_UI_Palette::solve_mix( '#808080', '#808080', 60, 60 ) );
	}

	/**
	 * An already-met target returns the start.
	 */
	public function test_solver_returns_start_when_already_met() {
		$this->assertSame( 60, Newspack_UI_Palette::solve_mix( '#000000', '#ffffff', 60, 60 ) );
	}

	/**
	 * Subtle surfaces match the block theme's notice tint.
	 */
	public function test_surface_subtle_matches_theme_notice_tint() {
		$this->assertSame( '#f7f7f7', Newspack_UI_Palette::surface_subtle( '#1e1e1e', '#ffffff' ) );
		$this->assertSame( '#efebe3', Newspack_UI_Palette::surface_subtle( '#1a1a1a', '#f7f3ea' ) );
		$this->assertSame( '#eae8e3', Newspack_UI_Palette::surface_subtle( '#000000', '#f2f0ea' ) );
		$this->assertSame( Newspack_UI_Palette::mix( '#ffffff', 4, '#1e1e1e' ), Newspack_UI_Palette::surface_subtle( '#ffffff', '#1e1e1e' ) );
	}
}
