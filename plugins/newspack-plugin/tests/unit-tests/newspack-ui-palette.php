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

	/**
	 * Light bases and the brand the vectors use.
	 *
	 * @return array[]
	 */
	public function data_light_bases() {
		return [
			'neutral'    => [ '#ffffff', '#003da5' ],
			'cream'      => [ '#f7f3ea', '#003da5' ],
			'acid'       => [ '#ececec', '#003da5' ],
			'tabloid'    => [ '#ffffff', '#e10600' ],
			'gazette'    => [ '#f7f3ea', '#7a1f1f' ],
			'ledger'     => [ '#f2f0ea', '#003da5' ],
			'near white' => [ '#fdfdfd', '#003da5' ],
		];
	}

	/**
	 * Every base the vectors use.
	 *
	 * @return array[]
	 */
	public function data_bases() {
		return $this->data_light_bases() + [
			'nocturne'   => [ '#1e1e1e', '#003da5' ],
			'night red'  => [ '#1e1e1e', '#e10600' ],
			'charcoal'   => [ '#303030', '#003da5' ],
			'charcoal 2' => [ '#2b2b2b', '#003da5' ],
		];
	}

	/**
	 * Text steps reach Lc 60 on every base.
	 *
	 * @dataProvider data_bases
	 *
	 * @param string $base  Base.
	 * @param string $brand Brand.
	 */
	public function test_error_meets_lc_60_on_base( $base, $brand ) {
		$steps = Newspack_UI_Palette::status_steps( $base, $brand );

		foreach ( [ 'error', 'success', 'warning' ] as $role ) {
			$this->assertGreaterThanOrEqual( 60, Newspack_UI_Palette::lc( $steps[ $role ]['text'], $base ), "$role text on $base" );
		}
	}

	/**
	 * Error is never lighter than Phase 1 on a light base.
	 *
	 * @dataProvider data_light_bases
	 *
	 * @param string $base  Base.
	 * @param string $brand Brand.
	 */
	public function test_error_not_lighter_than_phase_1_on_light_base( $base, $brand ) {
		$steps = Newspack_UI_Palette::status_steps( $base, $brand );

		$this->assertContains( $steps['error']['text'], Newspack_UI_Palette::ERROR_TEXT_STEPS );
	}

	/**
	 * A bright red brand pushes error darker on a light base.
	 */
	public function test_bright_red_brand_pushes_error_darker() {
		$steps = Newspack_UI_Palette::status_steps( '#ffffff', '#e10600' );
		$index = array_search( $steps['error']['text'], Newspack_UI_Palette::ERROR_TEXT_STEPS, true );

		$this->assertGreaterThan( 0, $index );
		$this->assertSame( $steps['error']['edge'], Newspack_UI_Palette::ERROR_EDGE_STEPS[ $index ] );
	}

	/**
	 * A brand already darker than the error step leaves error alone.
	 */
	public function test_dark_red_brand_leaves_error() {
		$this->assertSame( '#b32d2e', Newspack_UI_Palette::status_steps( '#f7f3ea', '#7a1f1f' )['error']['text'] );
	}

	/**
	 * A non-red brand leaves error alone.
	 */
	public function test_non_red_brand_leaves_error() {
		$this->assertSame( '#b32d2e', Newspack_UI_Palette::status_steps( '#ffffff', '#003da5' )['error']['text'] );
	}

	/**
	 * A grey brand is not read as a hue near red.
	 */
	public function test_grey_brand_leaves_error() {
		$this->assertSame( '#b32d2e', Newspack_UI_Palette::status_steps( '#ffffff', '#cccccc' )['error']['text'] );
		$this->assertSame( '#b32d2e', Newspack_UI_Palette::status_steps( '#ffffff', '#ffffff' )['error']['text'] );
	}

	/**
	 * On a dark base a red brand moves error lighter and away from its lightness.
	 */
	public function test_dark_base_red_brand_moves_error_lighter() {
		$red  = Newspack_UI_Palette::status_steps( '#1e1e1e', '#e10600' );
		$blue = Newspack_UI_Palette::status_steps( '#1e1e1e', '#003da5' );

		$this->assertGreaterThan( Newspack_UI_Palette::oklab_lightness( $blue['error']['text'] ), Newspack_UI_Palette::oklab_lightness( $red['error']['text'] ) );
		$this->assertGreaterThanOrEqual( 0.08, abs( Newspack_UI_Palette::oklab_lightness( $red['error']['text'] ) - Newspack_UI_Palette::oklab_lightness( '#e10600' ) ) );
		$this->assertGreaterThanOrEqual( 60, Newspack_UI_Palette::lc( $red['error']['text'], '#1e1e1e' ) );
	}

	/**
	 * A dark base picks light steps.
	 */
	public function test_dark_base_picks_light_steps() {
		$steps = Newspack_UI_Palette::status_steps( '#1e1e1e', '#003da5' );

		$this->assertGreaterThanOrEqual( 60, Newspack_UI_Palette::lc( $steps['error']['text'], '#1e1e1e' ) );
		$this->assertGreaterThan( Newspack_UI_Palette::oklab_lightness( '#b32d2e' ), Newspack_UI_Palette::oklab_lightness( $steps['error']['text'] ) );
	}

	/**
	 * The error edge reaches Lc 25 on every base.
	 *
	 * @dataProvider data_bases
	 *
	 * @param string $base  Base.
	 * @param string $brand Brand.
	 */
	public function test_error_edge_meets_lc_25( $base, $brand ) {
		$this->assertGreaterThanOrEqual( 25, Newspack_UI_Palette::lc( Newspack_UI_Palette::status_steps( $base, $brand )['error']['edge'], $base ) );
	}

	/**
	 * Badge text reads on its tint at Lc 60 or more.
	 *
	 * @dataProvider data_bases
	 *
	 * @param string $base  Base.
	 * @param string $brand Brand.
	 */
	public function test_badge_text_meets_lc_60_on_tint( $base, $brand ) {
		$steps = Newspack_UI_Palette::status_steps( $base, $brand );

		foreach ( [ 'error', 'success', 'warning' ] as $role ) {
			$this->assertGreaterThanOrEqual( 60, Newspack_UI_Palette::lc( $steps[ $role ]['text'], $steps[ $role ]['tint'] ), "$role text on its tint on $base" );
		}
	}

	/**
	 * A 3-digit hex input is normalized.
	 */
	public function test_short_hex_is_normalized() {
		$this->assertSame( Newspack_UI_Palette::status_steps( '#ffffff', '#003da5' ), Newspack_UI_Palette::status_steps( '#FFF', '#003DA5' ) );
	}

	/**
	 * An unusable contrast color falls back to white on a dark base and black on a light one.
	 */
	public function test_unusable_contrast_falls_back() {
		$this->assertSame( Newspack_UI_Palette::status_steps( '#1e1e1e', '#003da5', '#ffffff' ), Newspack_UI_Palette::status_steps( '#1e1e1e', '#003da5', 'nope' ) );
		$this->assertSame( Newspack_UI_Palette::status_steps( '#f7f3ea', '#003da5', '#000000' ), Newspack_UI_Palette::status_steps( '#f7f3ea', '#003da5', '' ) );
	}

	/**
	 * The shared vectors: the exact values both implementations must give.
	 *
	 * @dataProvider data_vectors
	 *
	 * @param string $base     Base.
	 * @param string $brand    Brand.
	 * @param string $contrast Text color.
	 * @param array  $expected Expected steps.
	 */
	public function test_vectors( $base, $brand, $contrast, $expected ) {
		$this->assertSame( $expected, Newspack_UI_Palette::status_steps( $base, $brand, $contrast ) );
	}

	/**
	 * The vectors copied from the block theme's tests/test-status-colors.php; keep both in step.
	 *
	 * @return array[]
	 */
	public function data_vectors() {
		return [
			'neutral'    => [
				'#ffffff',
				'#003da5',
				'#1e1e1e',
				[
					'error'   => [
						'text' => '#b32d2e',
						'edge' => '#d63638',
						'tint' => '#fcf0f1',
					],
					'success' => [
						'text' => '#007017',
						'mark' => '#008a20',
						'tint' => '#edfaef',
					],
					'warning' => [
						'text' => '#755100',
						'mark' => '#bd8600',
						'tint' => '#fcf9e8',
					],
					'notice'  => [ 'tint' => '#f7f7f7' ],
				],
			],
			'cream'      => [
				'#f7f3ea',
				'#003da5',
				'#1e1e1e',
				[
					'error'   => [
						'text' => '#b32d2e',
						'edge' => '#d63638',
						'tint' => '#f4e5dd',
					],
					'success' => [
						'text' => '#007017',
						'mark' => '#008a20',
						'tint' => '#e6eedb',
					],
					'warning' => [
						'text' => '#755100',
						'mark' => '#bd8600',
						'tint' => '#f4edd5',
					],
					'notice'  => [ 'tint' => '#efebe3' ],
				],
			],
			'nocturne'   => [
				'#1e1e1e',
				'#003da5',
				'#ffffff',
				[
					'error'   => [
						'text' => '#facfd2',
						'edge' => '#d63638',
						'tint' => '#332422',
					],
					'success' => [
						'text' => '#b8e6bf',
						'mark' => '#008a20',
						'tint' => '#212a21',
					],
					'warning' => [
						'text' => '#fcf9e8',
						'mark' => '#dba617',
						'tint' => '#312d23',
					],
					'notice'  => [ 'tint' => '#252525' ],
				],
			],
			'night-red'  => [
				'#1e1e1e',
				'#e10600',
				'#ffffff',
				[
					'error'   => [
						'text' => '#fcf0f1',
						'edge' => '#facfd2',
						'tint' => '#343030',
					],
					'success' => [
						'text' => '#b8e6bf',
						'mark' => '#008a20',
						'tint' => '#212a21',
					],
					'warning' => [
						'text' => '#fcf9e8',
						'mark' => '#dba617',
						'tint' => '#312d23',
					],
					'notice'  => [ 'tint' => '#252525' ],
				],
			],
			'tabloid'    => [
				'#ffffff',
				'#e10600',
				'#1e1e1e',
				[
					'error'   => [
						'text' => '#691c1c',
						'edge' => '#8a2424',
						'tint' => '#fcf0f1',
					],
					'success' => [
						'text' => '#007017',
						'mark' => '#008a20',
						'tint' => '#edfaef',
					],
					'warning' => [
						'text' => '#755100',
						'mark' => '#bd8600',
						'tint' => '#fcf9e8',
					],
					'notice'  => [ 'tint' => '#f7f7f7' ],
				],
			],
			'gazette'    => [
				'#f7f3ea',
				'#7a1f1f',
				'#1e1e1e',
				[
					'error'   => [
						'text' => '#b32d2e',
						'edge' => '#d63638',
						'tint' => '#f4e5dd',
					],
					'success' => [
						'text' => '#007017',
						'mark' => '#008a20',
						'tint' => '#e6eedb',
					],
					'warning' => [
						'text' => '#755100',
						'mark' => '#bd8600',
						'tint' => '#f4edd5',
					],
					'notice'  => [ 'tint' => '#efebe3' ],
				],
			],
			'acid'       => [
				'#ececec',
				'#003da5',
				'#1e1e1e',
				[
					'error'   => [
						'text' => '#b32d2e',
						'edge' => '#d63638',
						'tint' => '#e9dedf',
					],
					'success' => [
						'text' => '#007017',
						'mark' => '#008a20',
						'tint' => '#dbe7dd',
					],
					'warning' => [
						'text' => '#755100',
						'mark' => '#bd8600',
						'tint' => '#e9e6d7',
					],
					'notice'  => [ 'tint' => '#e5e5e5' ],
				],
			],
			'ledger'     => [
				'#f2f0ea',
				'#003da5',
				'#1e1e1e',
				[
					'error'   => [
						'text' => '#b32d2e',
						'edge' => '#d63638',
						'tint' => '#efe2dd',
					],
					'success' => [
						'text' => '#007017',
						'mark' => '#008a20',
						'tint' => '#e1ebdb',
					],
					'warning' => [
						'text' => '#755100',
						'mark' => '#bd8600',
						'tint' => '#efead5',
					],
					'notice'  => [ 'tint' => '#eae8e3' ],
				],
			],
			'near-white' => [
				'#fdfdfd',
				'#003da5',
				'#1e1e1e',
				[
					'error'   => [
						'text' => '#b32d2e',
						'edge' => '#d63638',
						'tint' => '#faeeef',
					],
					'success' => [
						'text' => '#007017',
						'mark' => '#008a20',
						'tint' => '#ebf8ed',
					],
					'warning' => [
						'text' => '#755100',
						'mark' => '#bd8600',
						'tint' => '#faf7e6',
					],
					'notice'  => [ 'tint' => '#f5f5f5' ],
				],
			],
			'charcoal'   => [
				'#303030',
				'#003da5',
				'#ffffff',
				[
					'error'   => [
						'text' => '#facfd2',
						'edge' => '#d63638',
						'tint' => '#443432',
					],
					'success' => [
						'text' => '#b8e6bf',
						'mark' => '#008a20',
						'tint' => '#313a31',
					],
					'warning' => [
						'text' => '#fcf9e8',
						'mark' => '#dba617',
						'tint' => '#423d33',
					],
					'notice'  => [ 'tint' => '#373737' ],
				],
			],
			'charcoal2'  => [
				'#2b2b2b',
				'#003da5',
				'#ffffff',
				[
					'error'   => [
						'text' => '#facfd2',
						'edge' => '#d63638',
						'tint' => '#3f2f2e',
					],
					'success' => [
						'text' => '#b8e6bf',
						'mark' => '#008a20',
						'tint' => '#2d362c',
					],
					'warning' => [
						'text' => '#fcf9e8',
						'mark' => '#dba617',
						'tint' => '#3e392f',
					],
					'notice'  => [ 'tint' => '#323232' ],
				],
			],
		];
	}

	/**
	 * Neutral emits no status tokens.
	 */
	public function test_status_defaults_not_emitted() {
		$this->assertSame( [], Newspack_UI_Palette::status_properties( '#ffffff', '#003da5', '#1e1e1e' ) );
	}

	/**
	 * The theme's roles map onto newspack-ui's token names.
	 */
	public function test_status_properties_map_theme_roles() {
		$steps      = Newspack_UI_Palette::status_steps( '#1e1e1e', '#003da5', '#ffffff' );
		$properties = Newspack_UI_Palette::status_properties( '#1e1e1e', '#003da5', '#ffffff' );

		$this->assertSame( $steps['error']['text'], $properties['error-text'] );
		$this->assertSame( $steps['error']['tint'], $properties['error-tint'] );
		$this->assertSame( $steps['success']['tint'], $properties['success-tint'] );
		$this->assertSame( $steps['warning']['mark'], $properties['warning'] );
		$this->assertSame( $steps['warning']['text'], $properties['warning-text'] );
		$this->assertSame( $steps['warning']['tint'], $properties['warning-tint'] );
	}

	/**
	 * Roles without a theme rule: derived values on white, Nocturne and mid-grey.
	 *
	 * @dataProvider data_derived_roles
	 *
	 * @param string $base     Base.
	 * @param string $contrast Text color.
	 * @param array  $expected Expected derived tokens.
	 */
	public function test_derived_roles( $base, $contrast, $expected ) {
		$properties = Newspack_UI_Palette::status_properties( $base, '#003da5', $contrast );

		foreach ( $expected as $token => $value ) {
			$this->assertSame( $value, $properties[ $token ] ?? null, "$token on $base" );
		}
	}

	/**
	 * Derived role vectors.
	 *
	 * @return array[]
	 */
	public function data_derived_roles() {
		return [
			'cream'    => [
				'#f7f3ea',
				'#1e1e1e',
				[
					'error-border'       => '#f1bfba',
					'error-tint-hover'   => '#f4e5dd',
					'success-border'     => '#abd7a8',
					'warning-border'     => '#ebd797',
					'disabled-ink'       => '#8d8b86',
				],
			],
			'nocturne' => [
				'#1e1e1e',
				'#ffffff',
				[
					'error-border'       => '#b13634',
					'error-tint-hover'   => '#332422',
					'error-tint-pressed' => '#412725',
					'success-border'     => '#1c7025',
					'warning-border'     => '#61502a',
					'warning-dot'        => '#dba617',
					'disabled-ink'       => '#7a7a7a',
				],
			],
			'mid grey' => [
				'#808080',
				'#ffffff',
				[
					'error-border'       => '#b8a4a5',
					'error-tint-hover'   => '#8e8989',
					'success-border'     => '#9baf9d',
					'warning-border'     => '#9c9c98',
					'warning-dot'        => '#fcf9e8',
					'disabled-ink'       => '#b7b7b7',
				],
			],
		];
	}

	/**
	 * Derived roles hold their APCA guards on every base.
	 *
	 * @dataProvider data_bases
	 *
	 * @param string $base  Base.
	 * @param string $brand Brand.
	 */
	public function test_derived_roles_meet_guards( $base, $brand ) {
		$dark       = 'white' === \Newspack\newspack_get_color_contrast( $base );
		$contrast   = $dark ? '#ffffff' : '#1e1e1e';
		$properties = Newspack_UI_Palette::status_properties( $base, $brand, $contrast ) + Newspack_UI_Palette::STATUS_DEFAULTS;
		$steps      = Newspack_UI_Palette::status_steps( $base, $brand, $contrast );

		foreach ( [ 'error', 'success', 'warning' ] as $role ) {
			$this->assertGreaterThanOrEqual( min( 25, Newspack_UI_Palette::lc( Newspack_UI_Palette::STATUS_DEFAULTS[ "$role-border" ], '#ffffff' ) ), Newspack_UI_Palette::lc( $properties[ "$role-border" ], $base ), "$role border on $base" );
		}
	}

	/**
	 * Bases for the pressed tint tests, including ones where the ink guard has to move the value.
	 *
	 * @return array[]
	 */
	public function data_pressed_bases() {
		return $this->data_bases() + [
			'mid grey'  => [ '#808080', '#003da5' ],
			'light grey' => [ '#d0d0d0', '#003da5' ],
		];
	}

	/**
	 * Dark bases.
	 *
	 * @return array[]
	 */
	public function data_dark_bases() {
		return [
			'nocturne'   => [ '#1e1e1e', '#003da5' ],
			'night red'  => [ '#1e1e1e', '#e10600' ],
			'charcoal'   => [ '#303030', '#003da5' ],
			'charcoal 2' => [ '#2b2b2b', '#003da5' ],
			'mid grey'   => [ '#808080', '#003da5' ],
		];
	}

	/**
	 * The pressed destructive tint keeps the error text readable at Lc 60, or at Lc 45 where 60 is unreachable.
	 *
	 * @dataProvider data_pressed_bases
	 *
	 * @param string $base  Base.
	 * @param string $brand Brand.
	 */
	public function test_pressed_tint_keeps_ink_readable( $base, $brand ) {
		$contrast = 'white' === \Newspack\newspack_get_color_contrast( $base ) ? '#ffffff' : '#1e1e1e';
		$pressed  = ( Newspack_UI_Palette::status_properties( $base, $brand, $contrast ) + Newspack_UI_Palette::STATUS_DEFAULTS )['error-tint-pressed'];
		$ink      = Newspack_UI_Palette::status_steps( $base, $brand, $contrast )['error']['text'];
		$neutral  = Newspack_UI_Palette::lc( Newspack_UI_Palette::STATUS_DEFAULTS['error-text'], Newspack_UI_Palette::STATUS_DEFAULTS['error-tint-pressed'] );

		$this->assertGreaterThanOrEqual( 45, Newspack_UI_Palette::lc( $ink, $pressed ), "ink on pressed on $base" );
		if ( ! in_array( $base, [ '#d0d0d0' ], true ) ) {
			$this->assertGreaterThanOrEqual( min( 60, $neutral ), Newspack_UI_Palette::lc( $ink, $pressed ), "ink on pressed on $base" );
		}
	}

	/**
	 * On a dark base the pressed tint stays a tint: at most 30% of the way from the base to the error edge, and above the hover tint.
	 *
	 * @dataProvider data_dark_bases
	 *
	 * @param string $base  Base.
	 * @param string $brand Brand.
	 */
	public function test_pressed_tint_on_dark_base_stays_a_tint( $base, $brand ) {
		$properties = Newspack_UI_Palette::status_properties( $base, $brand, '#ffffff' );
		$edge       = Newspack_UI_Palette::status_steps( $base, $brand, '#ffffff' )['error']['edge'];
		$reach      = Newspack_UI_Palette::oklab_lightness( $edge ) - Newspack_UI_Palette::oklab_lightness( $base );
		$pressed    = Newspack_UI_Palette::oklab_lightness( $properties['error-tint-pressed'] ) - Newspack_UI_Palette::oklab_lightness( $base );

		$this->assertLessThanOrEqual( 0.3 * $reach + 0.005, $pressed );
		$this->assertGreaterThan( Newspack_UI_Palette::oklab_lightness( $properties['error-tint-hover'] ), Newspack_UI_Palette::oklab_lightness( $properties['error-tint-pressed'] ) );
	}
}
