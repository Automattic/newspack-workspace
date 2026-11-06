<?php
/**
 * Status color tests.
 *
 * @package Newspack_Block_Theme
 */

use Newspack_Block_Theme\Contrast;
use Newspack_Block_Theme\Status_Colors;

/**
 * Tests the error, success and warning colors derived from the base.
 */
class Test_Status_Colors extends WP_UnitTestCase {
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
		Contrast::reset_button_styles();
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
	 * Enqueue the status styles for a palette and return the inline CSS.
	 *
	 * @param string[] $colors Palette colors keyed by slug.
	 * @return string
	 */
	private function get_css( $colors ) {
		$this->colors = $colors;
		add_filter( 'wp_theme_json_data_user', [ $this, 'set_palette' ] );
		wp_clean_theme_json_cache();
		Contrast::reset_button_styles();

		Status_Colors::enqueue_block_assets();

		$css = wp_styles()->get_data( 'newspack-block-theme-status-colors', 'after' );
		return is_array( $css ) ? implode( '', $css ) : '';
	}

	/**
	 * Absolute APCA Lc of text on a background.
	 *
	 * @param string $text       The text color.
	 * @param string $background The background color.
	 * @return float
	 */
	private function lc( $text, $background ) {
		return abs( Contrast::get_apca_contrast( Contrast::get_apca_luminance( $background ), Contrast::get_apca_luminance( $text ) ) );
	}

	/**
	 * The color of a custom property in inline CSS.
	 *
	 * @param string $css  The CSS.
	 * @param string $name The property name without the prefix.
	 * @return string|null
	 */
	private function get_property( $css, $name ) {
		return preg_match( '/--wp--custom--color--' . preg_quote( $name, '/' ) . ': (#[0-9a-f]{6});/', $css, $matches ) ? $matches[1] : null;
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
	 * @param string $base  The base.
	 * @param string $brand The brand.
	 */
	public function test_text_steps_meet_lc_60_on_base( $base, $brand ) {
		$steps = Status_Colors::steps( $base, $brand );

		foreach ( [ 'error', 'success', 'warning' ] as $role ) {
			$this->assertGreaterThanOrEqual( 60, $this->lc( $steps[ $role ]['text'], $base ), "$role text on $base" );
		}
	}

	/**
	 * A bright red brand pushes error darker on a light base.
	 */
	public function test_bright_red_brand_pushes_error_darker() {
		$steps = Status_Colors::steps( '#ffffff', '#e10600' );

		$this->assertGreaterThan( 0, array_search( $steps['error']['text'], Status_Colors::ERROR_TEXT_STEPS, true ) );
		$this->assertSame( $steps['error']['edge'], Status_Colors::ERROR_EDGE_STEPS[ array_search( $steps['error']['text'], Status_Colors::ERROR_TEXT_STEPS, true ) ] );
	}

	/**
	 * On a dark base a red brand moves error lighter and away from its lightness.
	 */
	public function test_dark_base_red_brand_moves_error_lighter() {
		$red  = Status_Colors::steps( '#1e1e1e', '#e10600' );
		$blue = Status_Colors::steps( '#1e1e1e', '#003da5' );

		$this->assertGreaterThan( Status_Colors::oklab_lightness( $blue['error']['text'] ), Status_Colors::oklab_lightness( $red['error']['text'] ) );
		$this->assertGreaterThanOrEqual( 0.08, abs( Status_Colors::oklab_lightness( $red['error']['text'] ) - Status_Colors::oklab_lightness( '#e10600' ) ) );
		$this->assertGreaterThanOrEqual( 60, $this->lc( $red['error']['text'], '#1e1e1e' ) );
	}

	/**
	 * The error edge reaches APCA Lc 25 on every base.
	 *
	 * @dataProvider data_bases
	 *
	 * @param string $base  The base.
	 * @param string $brand The brand.
	 */
	public function test_error_edge_meets_lc_25( $base, $brand ) {
		$steps = Status_Colors::steps( $base, $brand );
		$this->assertGreaterThanOrEqual( 25, $this->lc( $steps['error']['edge'], $base ) );
	}

	/**
	 * A 3-digit hex input is normalized.
	 */
	public function test_short_hex_is_normalized() {
		$this->assertSame( Status_Colors::steps( '#ffffff', '#003da5' ), Status_Colors::steps( '#FFF', '#003DA5' ) );
	}

	/**
	 * A dark base picks light steps.
	 */
	public function test_dark_base_picks_light_steps() {
		$steps = Status_Colors::steps( '#1e1e1e', '#003da5' );

		$this->assertGreaterThanOrEqual( 60, $this->lc( $steps['error']['text'], '#1e1e1e' ) );
		$this->assertGreaterThan( Status_Colors::oklab_lightness( '#b32d2e' ), Status_Colors::oklab_lightness( $steps['error']['text'] ) );
	}

	/**
	 * The shared vectors: the exact values both implementations must give.
	 *
	 * @dataProvider data_vectors
	 *
	 * @param string $base     The base.
	 * @param string $brand    The brand.
	 * @param string $contrast The text color.
	 * @param array  $expected The expected steps.
	 */
	public function test_vectors( $base, $brand, $contrast, $expected ) {
		$this->assertSame( $expected, Status_Colors::steps( $base, $brand, $contrast ) );
	}

	/**
	 * The shared vectors.
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
	 * The default style emits nothing.
	 */
	public function test_default_style_emits_nothing() {
		$css = $this->get_css(
			[
				'base'     => '#ffffff',
				'contrast' => '#1e1e1e',
				'accent'   => '#003da5',
			]
		);

		$this->assertSame( '', $css );
	}

	/**
	 * Nocturne gets light steps and dark tints.
	 */
	public function test_nocturne_emits_light_steps() {
		$css = $this->get_css(
			[
				'base'     => '#1E1E1E',
				'contrast' => '#ffffff',
				'accent'   => '#0099cc',
			]
		);

		$text = $this->get_property( $css, 'error-text' );
		$this->assertNotNull( $text );
		$this->assertGreaterThanOrEqual( 60, $this->lc( $text, '#1E1E1E' ) );
		$tint = $this->get_property( $css, 'error-tint' );
		$this->assertNotNull( $tint );
		$this->assertLessThan( 0.4, Status_Colors::oklab_lightness( $tint ) );
	}

	/**
	 * The declarations outrank core's global styles declared on the root.
	 */
	public function test_selector_outranks_global_styles() {
		$css = $this->get_css(
			[
				'base'     => '#1E1E1E',
				'contrast' => '#ffffff',
				'accent'   => '#0099cc',
			]
		);

		$this->assertStringStartsWith( ':root:root, :root .editor-styles-wrapper {', $css );
	}

	/**
	 * Every emitted value is a plain hex color.
	 */
	public function test_values_are_plain_hex() {
		$css = $this->get_css(
			[
				'base'     => '#f7f3ea',
				'contrast' => '#1e1e1e',
				'accent'   => '#e10600',
			]
		);

		$this->assertNotSame( '', $css );
		preg_match_all( '/--wp--custom--color--[a-z-]+: ([^;]+);/', $css, $matches );
		$this->assertNotEmpty( $matches[1] );
		foreach ( $matches[1] as $value ) {
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $value );
		}
	}

	/**
	 * A base that is not a plain hex emits nothing.
	 */
	public function test_non_hex_base_emits_nothing() {
		$css = $this->get_css(
			[
				'base'     => 'oklch(0.2 0 0)',
				'contrast' => '#ffffff',
				'accent'   => '#e10600',
			]
		);

		$this->assertSame( '', $css );
	}

	/**
	 * The stylesheets read roles through newspack-ui's tokens, never numbered steps.
	 */
	public function test_scss_reads_roles_not_steps() {
		$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src/scss' ) );
		$map      = [ 'notice-tint' => 'surface-subtle' ];
		$checked  = 0;
		$leftover = [];

		foreach ( $files as $file ) {
			if ( 'scss' !== $file->getExtension() ) {
				continue;
			}
			$source = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$source = preg_replace_callback(
				'/var\(--newspack-ui-color-([a-z-]+), var\(--wp--custom--color--([a-z-]+)\)\)/',
				function ( $matches ) use ( $map, &$checked ) {
					$role = $matches[2];
					if ( ( $map[ $role ] ?? $role ) === $matches[1] ) {
						++$checked;
						return '';
					}
					return $matches[0];
				},
				$source
			);
			if ( preg_match_all( '/--wp--custom--color--(?:error|success|warning|notice)[a-z-]*(?:-\d+)?|--newspack-ui-color-(?:error|success|warning)[a-z0-9-]*/', $source, $matches ) ) {
				$leftover = array_merge( $leftover, $matches[0] );
			}
		}

		$this->assertSame( [], $leftover );
		$this->assertGreaterThan( 0, $checked );
	}
}
