<?php
/**
 * Tests the Newspack UI Theme style adapter.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack_UI;
use Newspack\Newspack_UI_Palette;
use Newspack\Newspack_UI_Theme_Style;

/**
 * Test the Newspack_UI_Theme_Style class.
 *
 * @group Newspack_UI_Theme_Style
 */
class Newspack_Test_UI_Theme_Style extends WP_UnitTestCase {

	/**
	 * The styles registry before the test swapped it.
	 *
	 * @var WP_Styles|null
	 */
	private $original_styles = null;

	/**
	 * Remember the styles registry.
	 */
	public function set_up(): void {
		global $wp_styles;
		parent::set_up();
		$this->original_styles = $wp_styles;
	}

	/**
	 * Reset the style registry.
	 */
	public function tear_down(): void {
		global $wp_styles;
		$wp_styles = $this->original_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		remove_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		remove_all_filters( 'newspack_ui_theme_style_active' );
		remove_all_filters( 'wp_theme_json_data_user' );
		WP_Theme_JSON_Resolver::clean_cached_data();
		Newspack_UI_Theme_Style::reset_cache();
		wp_dequeue_style( 'newspack-ui' );
		wp_deregister_style( 'newspack-ui' );
		parent::tear_down();
	}

	/**
	 * Without a theme opt-in, the inline CSS is the pre-adapter output, which pins the block theme's legacy body rule.
	 */
	public function test_inline_css_without_theme_support_matches_legacy_rule(): void {
		Newspack_UI::enqueue_assets();
		Newspack_UI::theme_colors_css();
		Newspack_UI::colors_css_wrap();

		$inline = implode( "\n", (array) wp_styles()->get_data( 'newspack-ui', 'after' ) );

		$this->assertSame(
			file_get_contents( dirname( __DIR__ ) . '/fixtures/newspack-ui-classic-inline.css' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$inline
		);
	}

	/**
	 * Settings with presets keyed by origin, as wp_get_global_settings() returns them.
	 *
	 * @return array
	 */
	private function settings(): array {
		return [
			'color'      => [
				'palette' => [
					'default' => [
						[
							'slug'  => 'base',
							'color' => '#ffffff',
						],
					],
					'theme'   => [
						[
							'slug'  => 'base',
							'color' => '#1e1e1e',
						],
						[
							'slug'  => 'contrast',
							'color' => '#ffffff',
						],
						[
							'slug'  => 'accent',
							'color' => '#ffc857',
						],
					],
					'custom'  => [
						[
							'slug'  => 'brand',
							'color' => '#003da5',
						],
					],
				],
			],
			'typography' => [
				'fontFamilies' => [
					'theme' => [
						[
							'slug'       => 'body',
							'fontFamily' => 'Georgia, serif',
						],
					],
				],
				'fontSizes'    => [
					'theme' => [
						[
							'slug' => 'medium',
							'size' => '1.125rem',
						],
					],
				],
			],
			'custom'     => [ 'state' => [ 'hover' => '#abcdef' ] ],
		];
	}

	/**
	 * Read a property, falling back to the Neutral status value it was left out for.
	 *
	 * @param array  $properties The properties.
	 * @param string $name       The name without the prefix.
	 *
	 * @return string
	 */
	private function resolved( array $properties, string $name ): string {
		return $properties[ '--newspack-ui-color-' . $name ] ?? Newspack_UI_Palette::STATUS_DEFAULTS[ $name ];
	}

	/**
	 * Without the flag the style is off.
	 */
	public function test_inactive_without_flag(): void {
		$this->assertFalse( Newspack_UI_Theme_Style::is_active() );
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->assertTrue( Newspack_UI_Theme_Style::is_active() );
	}

	/**
	 * The developer filter forces Neutral.
	 */
	public function test_filter_forces_neutral(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		add_filter( 'newspack_ui_theme_style_active', '__return_false' );
		$this->assertFalse( Newspack_UI_Theme_Style::is_active() );
	}

	/**
	 * The palette resolves user-origin preset references.
	 *
	 * @dataProvider data_preset_references
	 *
	 * @param array       $styles Global styles.
	 * @param string|null $brand  Expected brand, or null for Neutral.
	 */
	public function test_user_origin_preset_references_are_used( array $styles, ?string $brand ): void {
		$resolved   = Newspack_UI_Theme_Style::resolve_references( $styles, $this->settings() );
		$properties = Newspack_UI_Theme_Style::get_properties( $resolved, $this->settings() );

		if ( null === $brand ) {
			$this->assertSame( [], preg_grep( '/^--newspack-ui-color-/', array_keys( $properties ) ) );
			return;
		}
		$this->assertSame( $brand, $properties['--newspack-ui-color-primary'] );
		$this->assertSame( '#1e1e1e', $properties['--newspack-ui-color-base'] );
		$this->assertSame( '#ffffff', $properties['--newspack-ui-color-contrast'] );
	}

	/**
	 * Preset-referenced fonts and the button size print.
	 */
	public function test_preset_referenced_fonts_and_sizes_print(): void {
		$resolved = Newspack_UI_Theme_Style::resolve_references(
			[
				'typography' => [ 'fontFamily' => 'var:preset|font-family|body' ],
				'elements'   => [
					'button' => [
						'typography' => [
							'fontFamily' => 'var:preset|font-family|body',
							'fontSize'   => 'var:preset|font-size|medium',
							'lineHeight' => '1.3',
						],
					],
				],
			],
			$this->settings()
		);
		$css      = Newspack_UI_Theme_Style::get_css( $resolved, $this->settings(), [] );

		$this->assertStringContainsString( '--newspack-ui-font-family-body:Georgia, serif;', $css );
		$this->assertStringContainsString( '--newspack-ui-button-font-family:Georgia, serif;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-family-control:Georgia, serif;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-size-button:1.125rem;', $css );
	}

	/**
	 * Font settings with the size presets the scale reads.
	 *
	 * @return array
	 */
	private function scale_settings(): array {
		$sizes = [];
		foreach ( [
			'xx-small' => '0.75rem',
			'x-small'  => '0.875rem',
			'small'    => '1rem',
			'medium'   => '1.25rem',
			'x-large'  => '1.5rem',
			'xx-large' => '2rem',
		] as $slug => $size ) {
			$sizes[] = [
				'slug' => $slug,
				'size' => $size,
			];
		}

		return [
			'typography' => [ 'fontSizes' => [ 'theme' => $sizes ] ],
			'custom'     => [
				'line-height' => [
					'xx-small' => 1.3333333333,
					'x-small'  => 1.4285714286,
					'small'    => 1.5,
					'medium'   => 1.6,
					'x-large'  => 1.375,
					'xx-large' => 1.3333333333,
				],
			],
		];
	}

	/**
	 * Preset reference cases.
	 *
	 * @return array
	 */
	public function data_preset_references(): array {
		return [
			'known slugs'  => [
				[
					'color'    => [
						'background' => 'var:preset|color|base',
						'text'       => 'var:preset|color|contrast',
					],
					'elements' => [ 'button' => [ 'color' => [ 'background' => 'var:preset|color|brand' ] ] ],
				],
				'#003da5',
			],
			'unknown slug' => [
				[
					'color' => [
						'background' => 'var:preset|color|nope',
						'text'       => 'var:preset|color|contrast',
					],
				],
				null,
			],
		];
	}

	/**
	 * Font references resolve to the preset values.
	 */
	public function test_font_references_resolve(): void {
		$resolved = Newspack_UI_Theme_Style::resolve_references(
			[
				'typography' => [
					'fontFamily' => 'var:preset|font-family|body',
					'fontSize'   => 'var:preset|font-size|medium',
					'lineHeight' => 'var:preset|font-size|nope',
				],
			],
			$this->settings()
		);

		$this->assertSame( 'Georgia, serif', $resolved['typography']['fontFamily'] );
		$this->assertSame( '1.125rem', $resolved['typography']['fontSize'] );
		$this->assertSame( 'var:preset|font-size|nope', $resolved['typography']['lineHeight'] );
	}

	/**
	 * The custom origin wins over the theme origin.
	 */
	public function test_custom_origin_wins_over_theme_origin_for_presets(): void {
		$settings = $this->settings();

		$settings['color']['palette']['custom'][] = [
			'slug'  => 'accent',
			'color' => '#112233',
		];
		$resolved = Newspack_UI_Theme_Style::resolve_references( [ 'a' => 'var:preset|color|accent' ], $settings );

		$this->assertSame( '#112233', $resolved['a'] );
	}

	/**
	 * Custom references resolve from settings.custom.
	 */
	public function test_var_custom_references_resolve(): void {
		$resolved = Newspack_UI_Theme_Style::resolve_references( [ 'a' => [ 'b' => 'var:custom|state|hover' ] ], $this->settings() );

		$this->assertSame( '#abcdef', $resolved['a']['b'] );
	}

	/**
	 * The rule is on :root.
	 */
	public function test_get_css_writes_root_not_body(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'color' => [
					'background' => '#1e1e1e',
					'text'       => '#ffffff',
				],
			],
			[]
		);

		$this->assertStringStartsWith( ':root{', $css );
		$this->assertStringNotContainsString( 'body{', $css );
		$this->assertStringContainsString( '--newspack-ui-color-base:#1e1e1e;', $css );
	}

	/**
	 * Nocturne's real resolved input gives the palette function's output and the block theme's pinned values.
	 */
	public function test_nocturne_global_styles_equal_palette_function(): void {
		$styles = [
			'color'    => [
				'background' => '#1E1E1E',
				'text'       => '#FFFFFF',
			],
			'elements' => [
				'button' => [
					'color' => [
						'background' => '#FFC857',
						'text'       => 'var(--wp--preset--color--accent-contrast, #000000)',
					],
				],
			],
		];

		$properties = Newspack_UI_Theme_Style::get_properties( $styles, [] );

		$this->assertSame(
			Newspack_UI_Palette::get_properties(
				[
					'base'       => '#1E1E1E',
					'contrast'   => '#FFFFFF',
					'brand'      => '#FFC857',
					'brand_text' => '',
				]
			) + [ '--newspack-ui-field-caret' => 'var(--newspack-ui-field-caret-light)' ],
			$properties
		);

		$this->assertSame( '#000000', $properties['--newspack-ui-color-button-text'] );
		foreach ( [
			'error-text'   => '#facfd2',
			'error'        => '#d63638',
			'error-tint'   => '#332422',
			'success-text' => '#b8e6bf',
			'success'      => '#008a20',
			'success-tint' => '#212a21',
			'warning-text' => '#fcf9e8',
			'warning'      => '#dba617',
			'warning-tint' => '#312d23',
		] as $name => $value ) {
			$this->assertSame( $value, $this->resolved( $properties, $name ), $name );
		}

		$this->assertEqualsWithDelta( 79.1, Newspack_UI_Palette::lc( '#000000', '#ffc857' ), 0.1 );
		$this->assertEqualsWithDelta( 62.8, Newspack_UI_Palette::lc( '#000000', $properties['--newspack-ui-color-button-bg-hover'] ), 0.1 );
		$this->assertEqualsWithDelta( 52.4, Newspack_UI_Palette::lc( '#000000', $properties['--newspack-ui-color-btn-pressed'] ), 0.1 );
	}

	/**
	 * Checkbox and radio marks follow the brand, falling back to the contrast color when the brand is too faint.
	 */
	public function test_adapter_prints_mark_and_on_mark(): void {
		$styles = [
			'color'    => [
				'background' => '#1E1E1E',
				'text'       => '#FFFFFF',
			],
			'elements' => [
				'button' => [
					'color' => [
						'background' => '#FFC857',
						'text'       => 'var(--wp--preset--color--accent-contrast, #000000)',
					],
				],
			],
		];

		$css = Newspack_UI_Theme_Style::get_css( $styles, [] );
		$this->assertStringContainsString( '--newspack-ui-color-mark:#ffc857;', $css );
		$this->assertStringContainsString( '--newspack-ui-color-on-mark:#000000;', $css );

		$styles['elements']['button']['color'] = [
			'background' => '#333333',
			'text'       => '#FFFFFF',
		];
		$this->assertLessThan( Newspack_UI_Palette::MARK_VISIBLE_LC, Newspack_UI_Palette::lc( '#333333', '#1e1e1e' ) );

		$css = Newspack_UI_Theme_Style::get_css( $styles, [] );
		$this->assertStringContainsString( '--newspack-ui-color-mark:#ffffff;', $css );
		$this->assertStringContainsString( '--newspack-ui-color-on-mark:#000000;', $css );
	}

	/**
	 * The theme's accent-contrast var is not a set button text.
	 */
	public function test_theme_accent_contrast_var_is_ignored(): void {
		$palette = Newspack_UI_Theme_Style::get_palette(
			[
				'color'    => [
					'background' => '#1e1e1e',
					'text'       => '#ffffff',
				],
				'elements' => [
					'button' => [
						'color' => [
							'background' => '#FFC857',
							'text'       => 'var(--wp--preset--color--accent-contrast, #000)',
						],
					],
				],
			],
			[]
		);

		$this->assertSame( '', $palette['brand_text'] );
		$this->assertSame( '#000000', Newspack_UI_Palette::get_properties( $palette )['--newspack-ui-color-button-text'] );
	}

	/**
	 * A base that is not a plain hex leaves Neutral colors.
	 */
	public function test_non_hex_base_keeps_neutral_colours(): void {
		foreach ( [ 'linear-gradient(red,blue)', 'var(--wp--preset--color--base)' ] as $base ) {
			$properties = Newspack_UI_Theme_Style::get_properties(
				[
					'color'    => [
						'background' => $base,
						'text'       => '#ffffff',
					],
					'elements' => [ 'button' => [ 'color' => [ 'background' => '#ffc857' ] ] ],
				],
				[]
			);
			$this->assertSame( [], $properties, $base );
		}
	}

	/**
	 * A gradient button falls back to the accent preset, then to no brand.
	 */
	public function test_gradient_button_falls_back_to_accent_then_neutral(): void {
		$styles = [
			'color'    => [
				'background' => '#ffffff',
				'text'       => '#1e1e1e',
			],
			'elements' => [ 'button' => [ 'color' => [ 'background' => 'linear-gradient(red,blue)' ] ] ],
		];

		$with = Newspack_UI_Theme_Style::get_properties( $styles, $this->settings() );
		$this->assertSame( '#ffc857', $with['--newspack-ui-color-primary'] );

		$without = Newspack_UI_Theme_Style::get_properties( $styles, [] );
		$this->assertArrayNotHasKey( '--newspack-ui-color-primary', $without );
		$this->assertArrayNotHasKey( '--newspack-ui-color-button-bg', $without );
	}

	/**
	 * A button the color of the page keeps the focus ink readable on it.
	 */
	public function test_button_fill_equal_to_base_keeps_focus_visible(): void {
		$properties = Newspack_UI_Theme_Style::get_properties(
			[
				'color'    => [
					'background' => '#003da5',
					'text'       => '#ffffff',
				],
				'elements' => [ 'button' => [ 'color' => [ 'background' => '#003da5' ] ] ],
			],
			[]
		);

		$this->assertSame( '#003da5', $properties['--newspack-ui-color-primary'] );
		$ink = $properties['--newspack-ui-color-focus-on-brand'] ?? $properties['--newspack-ui-color-button-text'];
		$this->assertGreaterThanOrEqual( 30, Newspack_UI_Palette::lc( $ink, '#003da5' ) );
	}

	/**
	 * Make the global styles carry these user styles.
	 *
	 * @param array $styles The styles.
	 */
	private function set_user_styles( array $styles ): void {
		add_filter(
			'wp_theme_json_data_user',
			function ( $data ) use ( $styles ) {
				return $data->update_with(
					[
						'version' => WP_Theme_JSON::LATEST_SCHEMA,
						'styles'  => $styles,
					]
				);
			}
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
		Newspack_UI_Theme_Style::reset_cache();
	}

	/**
	 * The inline CSS the wrap prints.
	 *
	 * @return string
	 */
	private function wrap_output(): string {
		Newspack_UI::enqueue_assets();
		Newspack_UI::colors_css_wrap();

		return implode( "\n", (array) wp_styles()->get_data( 'newspack-ui', 'after' ) );
	}

	/**
	 * With the Theme style on and a usable base, the old body rule is not printed.
	 */
	public function test_colors_css_wrap_returns_when_active(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->set_user_styles(
			[
				'color' => [
					'background' => '#1e1e1e',
					'text'       => '#ffffff',
				],
			]
		);

		$this->assertStringNotContainsString( 'body {', $this->wrap_output() );
	}

	/**
	 * With the Theme style on but a base it cannot read, the legacy rule prints as before.
	 *
	 * @dataProvider data_unreadable_bases
	 *
	 * @param string $base The page background.
	 */
	public function test_colors_css_wrap_falls_back_when_base_is_unreadable( string $base ): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->set_user_styles(
			[
				'color' => [
					'background' => $base,
					'text'       => '#ffffff',
				],
			]
		);

		$this->assertSame( 'body {--newspack-ui-color-primary: #32373c;--newspack-ui-color-against-primary: #fff;}', substr( $this->wrap_output(), -strlen( 'body {--newspack-ui-color-primary: #32373c;--newspack-ui-color-against-primary: #fff;}' ) ) );
	}

	/**
	 * Backgrounds the palette cannot read.
	 *
	 * @return array
	 */
	public function data_unreadable_bases(): array {
		return [
			'alpha hex' => [ '#1e1e1ecc' ],
			'rgba'      => [ 'rgba(30,30,30,0.8)' ],
			'gradient'  => [ 'linear-gradient(red,blue)' ],
		];
	}

	/**
	 * The global styles are resolved once until the cache is reset.
	 */
	public function test_global_styles_are_memoized_until_reset(): void {
		$this->set_user_styles( [ 'color' => [ 'background' => '#112233' ] ] );
		$this->assertSame( '#112233', Newspack_UI_Theme_Style::get_global_styles()['color']['background'] );

		remove_all_filters( 'wp_theme_json_data_user' );
		WP_Theme_JSON_Resolver::clean_cached_data();
		$this->assertSame( '#112233', Newspack_UI_Theme_Style::get_global_styles()['color']['background'] );

		do_action( 'switch_theme' );
		$this->assertNotSame( '#112233', Newspack_UI_Theme_Style::get_global_styles()['color']['background'] ?? null );

		$this->set_user_styles( [ 'color' => [ 'background' => '#445566' ] ] );
		$this->assertSame( '#445566', Newspack_UI_Theme_Style::get_global_styles()['color']['background'] );
		remove_all_filters( 'wp_theme_json_data_user' );
		WP_Theme_JSON_Resolver::clean_cached_data();
		do_action( 'save_post_wp_global_styles' );
		$this->assertNotSame( '#445566', Newspack_UI_Theme_Style::get_global_styles()['color']['background'] ?? null );
	}

	/**
	 * Only the subtrees the adapter reads are kept.
	 */
	public function test_only_read_subtrees_are_kept(): void {
		$this->set_user_styles(
			[
				'color'    => [ 'background' => '#112233' ],
				'spacing'  => [ 'padding' => '1px' ],
				'css'      => 'a{b:c}',
				'elements' => [
					'heading' => [ 'typography' => [ 'fontWeight' => '700' ] ],
					'cite'    => [ 'color' => [ 'text' => '#000000' ] ],
				],
			]
		);
		$styles = Newspack_UI_Theme_Style::get_global_styles();

		$this->assertArrayHasKey( 'color', $styles );
		$this->assertArrayNotHasKey( 'spacing', $styles );
		$this->assertArrayNotHasKey( 'css', $styles );
		$this->assertSame( '700', $styles['elements']['heading']['typography']['fontWeight'] );
		$this->assertArrayNotHasKey( 'cite', $styles['elements'] );
	}

	/**
	 * The rule is solved once until the cache is reset.
	 */
	public function test_site_css_is_memoized_until_reset(): void {
		$this->set_user_styles(
			[
				'color' => [
					'background' => '#1e1e1e',
					'text'       => '#ffffff',
				],
			]
		);
		$first = Newspack_UI_Theme_Style::get_site_css();
		$this->assertStringContainsString( '--newspack-ui-color-base:#1e1e1e;', $first );

		remove_all_filters( 'wp_theme_json_data_user' );
		WP_Theme_JSON_Resolver::clean_cached_data();
		$this->assertSame( $first, Newspack_UI_Theme_Style::get_site_css() );

		do_action( 'switch_theme' );
		$this->assertNotSame( $first, Newspack_UI_Theme_Style::get_site_css() );
	}

	/**
	 * A font size preset resolves through core's fluid typography.
	 */
	public function test_font_size_preset_uses_core_fluid_value(): void {
		$settings                        = $this->settings();
		$settings['typography']['fluid'] = true;
		$settings['typography']['fontSizes']['theme'][0]['size'] = '2rem';

		$resolved = Newspack_UI_Theme_Style::resolve_references( [ 'a' => 'var:preset|font-size|medium' ], $settings );

		$this->assertSame( wp_get_typography_font_size_value( $settings['typography']['fontSizes']['theme'][0], $settings ), $resolved['a'] );
		$this->assertStringStartsWith( 'clamp(', $resolved['a'] );
	}

	/**
	 * Custom references resolve at any depth and map kebab-case to camelCase.
	 */
	public function test_var_custom_resolves_deep_and_camel_case(): void {
		$settings           = $this->settings();
		$settings['custom'] = [
			'lineHeight' => [ 'body' => '1.6' ],
			'a'          => [ 'bC' => [ 'd' => 'deep' ] ],
		];

		$resolved = Newspack_UI_Theme_Style::resolve_references(
			[
				'x' => 'var:custom|line-height|body',
				'y' => 'var:custom|a|b-c|d',
				'z' => 'var:custom|a|missing|d',
			],
			$settings
		);

		$this->assertSame( '1.6', $resolved['x'] );
		$this->assertSame( 'deep', $resolved['y'] );
		$this->assertSame( 'var:custom|a|missing|d', $resolved['z'] );
	}

	/**
	 * Spacing presets resolve.
	 */
	public function test_spacing_preset_resolves(): void {
		$settings                                   = $this->settings();
		$settings['spacing']['spacingSizes']['theme'] = [
			[
				'slug' => '40',
				'size' => '1.5rem',
			],
		];

		$resolved = Newspack_UI_Theme_Style::resolve_references( [ 'a' => 'var:preset|spacing|40' ], $settings );

		$this->assertSame( '1.5rem', $resolved['a'] );
	}

	/**
	 * A preset that is itself a reference resolves, within the pass cap.
	 */
	public function test_nested_references_resolve_with_a_cap(): void {
		$settings                                        = $this->settings();
		$settings['color']['palette']['custom'][]        = [
			'slug'  => 'alias',
			'color' => 'var:preset|color|accent',
		];
		$settings['color']['palette']['custom'][]        = [
			'slug'  => 'loop',
			'color' => 'var:preset|color|loop',
		];

		$resolved = Newspack_UI_Theme_Style::resolve_references(
			[
				'a' => 'var:preset|color|alias',
				'b' => 'var:preset|color|loop',
			],
			$settings
		);

		$this->assertSame( '#ffc857', $resolved['a'] );
		$this->assertSame( 'var:preset|color|loop', $resolved['b'] );
	}

	/**
	 * Only properties of a mapped group are printed, with their values checked.
	 */
	public function test_unmapped_property_names_have_no_type(): void {
		$this->assertSame( 'color', Newspack_UI_Theme_Style::property_type( '--newspack-ui-color-base' ) );
		$this->assertSame( '', Newspack_UI_Theme_Style::property_type( '--newspack-ui-other-thing' ) );
		$this->assertSame( '', Newspack_UI_Theme_Style::property_type( '--other-color-base' ) );
	}

	/**
	 * The solid destructive button is filled with the status error.
	 */
	public function test_destructive_is_solid_error_in_theme(): void {
		$properties = Newspack_UI_Theme_Style::get_properties(
			[
				'color' => [
					'background' => '#1e1e1e',
					'text'       => '#ffffff',
				],
			],
			[]
		);

		$this->assertSame( $this->resolved( $properties, 'error' ), $properties['--newspack-ui-color-destructive-fill'] );
		$this->assertArrayNotHasKey( '--newspack-ui-color-destructive-edge', $properties );
		$this->assertSame( $this->resolved( $properties, 'error-text' ), $properties['--newspack-ui-color-destructive-text'] );
	}

	/**
	 * Without a palette there is no destructive token and the baseline holds.
	 */
	public function test_destructive_untouched_in_neutral(): void {
		$this->assertSame( '', Newspack_UI_Theme_Style::get_css( [], [] ) );
		foreach ( array_keys( Newspack_UI_Theme_Style::get_properties( [], [] ) ) as $name ) {
			$this->assertStringNotContainsString( 'destructive', $name );
		}
		$this->test_inline_css_without_theme_support_matches_legacy_rule();
	}

	/**
	 * The theme's button radius sets every radius role.
	 *
	 * @dataProvider data_radius_roles
	 *
	 * @param string $radius   The button radius.
	 * @param string $expected The radius the roles are built from.
	 */
	public function test_radius_roles( string $radius, string $expected ): void {
		$styles = [ 'elements' => [ 'button' => [ 'border' => [ 'radius' => $radius ] ] ] ];
		$css    = Newspack_UI_Theme_Style::get_css( $styles, [] );

		$this->assertStringContainsString( '--newspack-ui-radius-button:' . $expected . ';', $css );
		$this->assertStringContainsString( '--newspack-ui-radius-control:min(' . $expected . ', 12px);', $css );
		$this->assertStringContainsString( '--newspack-ui-radius-badge:calc(' . $expected . ' * 0.5);', $css );
		$this->assertStringContainsString( '--newspack-ui-radius-surface:min(calc(' . $expected . ' * 1.5), 24px);', $css );
		$this->assertStringContainsString( '--newspack-ui-radius-sheet:min(calc(' . $expected . ' * 2), 24px);', $css );
	}

	/**
	 * Button radii and the value the roles are built from.
	 *
	 * @return array
	 */
	public function data_radius_roles(): array {
		return [
			'zero'   => [ '0', '0px' ],
			'8px'    => [ '8px', '8px' ],
			'0.5rem' => [ '0.5rem', '0.5rem' ],
			'pill'   => [ '999px', '999px' ],
			'2px'    => [ '2px', '2px' ],
		];
	}

	/**
	 * The button radius accessor returns the printed length, or nothing without one.
	 */
	public function test_button_radius_accessor(): void {
		$this->set_user_styles( [ 'elements' => [ 'button' => [ 'border' => [ 'radius' => '24px' ] ] ] ] );
		$this->assertSame( '24px', Newspack_UI_Theme_Style::get_button_radius() );

		remove_all_filters( 'wp_theme_json_data_user' );
		$this->set_user_styles( [ 'elements' => [ 'button' => [ 'border' => [ 'radius' => 'var(--x)' ] ] ] ] );
		$this->assertSame( '', Newspack_UI_Theme_Style::get_button_radius() );
	}

	/**
	 * A radius that is not a plain length prints no radius role.
	 *
	 * @dataProvider data_invalid_radius
	 *
	 * @param mixed $radius The button radius.
	 */
	public function test_invalid_radius_prints_nothing( $radius ): void {
		$styles = [ 'elements' => [ 'button' => [ 'border' => [ 'radius' => $radius ] ] ] ];
		$this->assertStringNotContainsString( 'radius', Newspack_UI_Theme_Style::get_css( $styles, [] ) );
		$this->assertSame( [], array_filter( array_keys( Newspack_UI_Theme_Style::get_properties( $styles, [] ) ), fn( $name ) => false !== strpos( $name, 'radius' ) ) );
	}

	/**
	 * Button radii the roles are not built from.
	 *
	 * @return array
	 */
	public function data_invalid_radius(): array {
		return [
			'var'       => [ 'var(--x)' ],
			'inherit'   => [ 'inherit' ],
			'negative'  => [ '-4px' ],
			'injection' => [ '8px;}' ],
			'inner gap' => [ '8 px' ],
			'corners'   => [ [ 'topLeft' => '8px' ] ],
			'empty'     => [ '' ],
		];
	}

	/**
	 * Radius forms that collapse to one plain length.
	 *
	 * @dataProvider data_radius_forms
	 *
	 * @param mixed  $radius   The button radius.
	 * @param string $expected The button role it prints.
	 */
	public function test_radius_forms( $radius, string $expected ): void {
		$styles = [ 'elements' => [ 'button' => [ 'border' => [ 'radius' => $radius ] ] ] ];
		$this->assertStringContainsString( '--newspack-ui-radius-button:' . $expected . ';', Newspack_UI_Theme_Style::get_css( $styles, [] ) );
	}

	/**
	 * Radius forms and what they print.
	 *
	 * @return array
	 */
	public function data_radius_forms(): array {
		return [
			'padded'        => [ ' 8px ', '8px' ],
			'leading dot'   => [ '.5rem', '0.5rem' ],
			'int zero'      => [ 0, '0px' ],
			'equal corners' => [
				[
					'topLeft'     => '6px',
					'topRight'    => ' 6px',
					'bottomRight' => '6px',
					'bottomLeft'  => '6px',
				],
				'6px',
			],
			'equal zero'    => [
				[
					'topLeft'     => '0',
					'topRight'    => '0px',
					'bottomRight' => 0,
					'bottomLeft'  => '0',
				],
				'0px',
			],
		];
	}

	/**
	 * The Button block's radius wins over the element's.
	 */
	public function test_block_button_radius_wins_over_element(): void {
		$styles = [
			'elements' => [ 'button' => [ 'border' => [ 'radius' => '4px' ] ] ],
			'blocks'   => [ 'core/button' => [ 'border' => [ 'radius' => '20px' ] ] ],
		];
		$css    = Newspack_UI_Theme_Style::get_css( $styles, [] );
		$this->assertStringContainsString( '--newspack-ui-radius-button:20px;', $css );
		$this->assertStringNotContainsString( 'min(4px', $css );
	}

	/**
	 * An unusable block radius falls back to the element's.
	 */
	public function test_invalid_block_radius_falls_back_to_element(): void {
		$styles = [
			'elements' => [ 'button' => [ 'border' => [ 'radius' => '4px' ] ] ],
			'blocks'   => [ 'core/button' => [ 'border' => [ 'radius' => 'var(--x)' ] ] ],
		];
		$this->assertStringContainsString( '--newspack-ui-radius-button:4px;', Newspack_UI_Theme_Style::get_css( $styles, [] ) );
	}

	/**
	 * The block radius is kept from the global styles.
	 */
	public function test_block_button_border_is_read(): void {
		$this->set_user_styles( [ 'blocks' => [ 'core/button' => [ 'border' => [ 'radius' => '20px' ] ] ] ] );
		$this->assertSame( '20px', Newspack_UI_Theme_Style::get_global_styles()['blocks']['core/button']['border']['radius'] );
	}

	/**
	 * Radius presets and custom values resolve to a length that prints.
	 */
	public function test_radius_references_resolve_and_print(): void {
		$settings           = $this->settings();
		$settings['border'] = [
			'radiusSizes' => [
				'theme' => [
					[
						'slug' => 'large',
						'size' => '14px',
					],
				],
			],
		];
		$settings['custom'] = [ 'border' => [ 'radius-large' => '10px' ] ];

		$resolved = Newspack_UI_Theme_Style::resolve_references(
			[
				'elements' => [
					'button' => [ 'border' => [ 'radius' => 'var:preset|border-radius|large' ] ],
				],
				'blocks'   => [
					'core/button' => [ 'border' => [ 'radius' => 'var:custom|border|radius-large' ] ],
				],
			],
			$settings
		);

		$this->assertSame( '14px', $resolved['elements']['button']['border']['radius'] );
		$this->assertSame( '10px', $resolved['blocks']['core/button']['border']['radius'] );
		$this->assertStringContainsString( '--newspack-ui-radius-button:10px;', Newspack_UI_Theme_Style::get_css( $resolved, [] ) );
		unset( $resolved['blocks'] );
		$this->assertStringContainsString( '--newspack-ui-radius-button:14px;', Newspack_UI_Theme_Style::get_css( $resolved, [] ) );
	}

	/**
	 * A radius alone does not drop the legacy color rule.
	 */
	public function test_radius_alone_keeps_legacy_color_rule(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->set_user_styles(
			[
				'color'    => [
					'background' => 'rgba(30,30,30,0.8)',
					'text'       => '#ffffff',
				],
				'elements' => [ 'button' => [ 'border' => [ 'radius' => '12px' ] ] ],
			]
		);

		$this->assertStringContainsString( '--newspack-ui-radius-button:12px;', Newspack_UI_Theme_Style::get_site_css() );
		$this->assertStringContainsString( 'body {--newspack-ui-color-primary', $this->wrap_output() );
	}

	/**
	 * The body, heading and control families come from the three places the theme sets them.
	 */
	public function test_fonts_map_to_heading_body_control(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'typography' => [ 'fontFamily' => 'Georgia, serif' ],
				'elements'   => [
					'heading' => [ 'typography' => [ 'fontFamily' => '"Playfair Display", serif' ] ],
					'button'  => [ 'typography' => [ 'fontFamily' => 'Arial, sans-serif' ] ],
				],
			],
			[],
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-font-family-body:Georgia, serif;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-family-heading:"Playfair Display", serif;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-family-control:Arial, sans-serif;', $css );
		$this->assertStringContainsString( '--newspack-ui-button-font-family:Arial, sans-serif;', $css );
	}

	/**
	 * Without a heading family nothing is printed, so the body family stands.
	 */
	public function test_heading_family_falls_back_to_body(): void {
		$css = Newspack_UI_Theme_Style::get_css( [ 'typography' => [ 'fontFamily' => 'Georgia, serif' ] ], [], [] );

		$this->assertStringContainsString( '--newspack-ui-font-family-body:', $css );
		$this->assertStringNotContainsString( '--newspack-ui-font-family-heading', $css );
	}

	/**
	 * The heading weight prints; the heading line height does not.
	 */
	public function test_heading_weight_prints_without_line_height(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'elements' => [
					'heading' => [
						'typography' => [
							'fontWeight' => '800',
							'lineHeight' => 1.15,
						],
					],
				],
			]
		);

		$this->assertStringContainsString( '--newspack-ui-heading-font-weight:800;', $css );
		$this->assertStringNotContainsString( 'heading-line-height', $css );
	}

	/**
	 * The control family and the field message tokens carry no transform or spacing.
	 */
	public function test_control_takes_family_only(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'elements' => [
					'button' => [
						'typography' => [
							'fontFamily'    => 'Arial, sans-serif',
							'textTransform' => 'uppercase',
							'letterSpacing' => '0.1em',
						],
					],
				],
			],
			[],
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-button-text-transform:uppercase;', $css );
		$this->assertStringContainsString( '--newspack-ui-button-letter-spacing:0.1em;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-family-control:Arial, sans-serif;', $css );
		$this->assertStringNotContainsString( '--newspack-ui-font-family-fine', $css );
	}

	/**
	 * The button typography prints in full and the label wraps.
	 */
	public function test_button_typography_full_and_wraps(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'elements' => [
					'button' => [
						'typography' => [
							'fontSize'      => '1.125rem',
							'fontWeight'    => '700',
							'letterSpacing' => '0.05em',
							'textTransform' => 'uppercase',
							'lineHeight'    => '1.2',
						],
					],
				],
			],
			[],
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-font-size-button:1.125rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-button-font-weight:700;', $css );
		$this->assertStringContainsString( '--newspack-ui-button-letter-spacing:0.05em;', $css );
		$this->assertStringContainsString( '--newspack-ui-button-text-transform:uppercase;', $css );
		$this->assertStringContainsString( '--newspack-ui-line-height-button:1.2;', $css );
		$this->assertStringContainsString( '--newspack-ui-button-white-space:normal;', $css );
		$this->assertSame( '', Newspack_UI_Theme_Style::get_css( [], [], [] ) );
	}

	/**
	 * The Button block's colors and typography win over the element's.
	 */
	public function test_block_button_typography_and_text_win(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'color'    => [
					'background' => '#ffffff',
					'text'       => '#111111',
				],
				'elements' => [
					'button' => [
						'color'      => [
							'background' => '#003da5',
							'text'       => '#ffffff',
						],
						'typography' => [ 'fontWeight' => '400' ],
					],
				],
				'blocks'   => [
					'core/button' => [
						'color'      => [ 'background' => '#c93a00' ],
						'typography' => [ 'fontWeight' => '700' ],
						':hover'     => [ 'color' => [ 'text' => '#fff4e0' ] ],
					],
				],
			],
			[],
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-color-button-bg:#c93a00;', $css );
		$this->assertStringContainsString( '--newspack-ui-color-button-text:#ffffff;', $css );
		$this->assertStringContainsString( '--newspack-ui-button-font-weight:700;', $css );
		$this->assertStringContainsString( '--newspack-ui-color-button-text-hover:#fff4e0;', $css );
	}

	/**
	 * The block button subtrees survive the read-path filter.
	 */
	public function test_block_button_colors_and_typography_are_read(): void {
		$this->set_user_styles(
			[
				'blocks' => [
					'core/button' => [
						'color'      => [ 'background' => '#c93a00' ],
						'typography' => [ 'fontWeight' => '700' ],
						':hover'     => [ 'color' => [ 'background' => '#112233' ] ],
						':active'    => [ 'color' => [ 'background' => '#223344' ] ],
					],
				],
			]
		);
		$button = Newspack_UI_Theme_Style::get_global_styles()['blocks']['core/button'];

		$this->assertSame( '#c93a00', $button['color']['background'] );
		$this->assertSame( '700', $button['typography']['fontWeight'] );
		$this->assertSame( '#112233', $button[':hover']['color']['background'] );
		$this->assertSame( '#223344', $button[':active']['color']['background'] );
	}

	/**
	 * The scale maps by slug and a missing slug prints nothing.
	 */
	public function test_scale_maps_by_slug_to_resolved_values(): void {
		$settings = $this->scale_settings();
		$css      = Newspack_UI_Theme_Style::get_css( [], $settings, [] );

		$this->assertStringContainsString( '--newspack-ui-font-size-caption:0.75rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-size-small:0.875rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-size-body:1rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-size-title-m:1.25rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-size-title-l:1.5rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-size-display:2rem;', $css );

		array_shift( $settings['typography']['fontSizes']['theme'] );
		$css = Newspack_UI_Theme_Style::get_css( [], $settings, [] );
		$this->assertStringNotContainsString( '--newspack-ui-font-size-caption:', $css );
		$this->assertStringContainsString( '--newspack-ui-font-size-small:', $css );
	}

	/**
	 * The six roles with their slug and resolved pair.
	 *
	 * @return array
	 */
	public function data_scale_roles(): array {
		return [
			'caption' => [ 'caption', 'xx-small', '0.75rem', '1.3333333333' ],
			'small'   => [ 'small', 'x-small', '0.875rem', '1.4285714286' ],
			'body'    => [ 'body', 'small', '1rem', '1.5' ],
			'title-m' => [ 'title-m', 'medium', '1.25rem', '1.6' ],
			'title-l' => [ 'title-l', 'x-large', '1.5rem', '1.375' ],
			'display' => [ 'display', 'xx-large', '2rem', '1.3333333333' ],
		];
	}

	/**
	 * Each role takes its size and its line height from one slug.
	 *
	 * @dataProvider data_scale_roles
	 *
	 * @param string $role  The role.
	 * @param string $slug  The slug.
	 * @param string $size  The resolved size.
	 * @param string $lh    The unitless line height.
	 */
	public function test_scale_pairs_size_and_line_height_by_slug( string $role, string $slug, string $size, string $lh ): void {
		$css = Newspack_UI_Theme_Style::get_css( [], $this->scale_settings(), [] );
		$this->assertStringContainsString( '--newspack-ui-font-size-' . $role . ':' . $size . ';', $css );
		$this->assertStringContainsString( '--newspack-ui-line-height-' . $role . ':' . $lh . ';', $css );
		$this->assertStringNotContainsString( 'var(--wp--preset--font-size--', $css );
		$this->assertStringNotContainsString( 'var(--wp--custom--line-height--', $css );
	}

	/**
	 * A role without a line height prints neither half.
	 *
	 * @dataProvider data_scale_roles
	 *
	 * @param string $role The role.
	 * @param string $slug The slug.
	 */
	public function test_scale_role_without_line_height_prints_neither( string $role, string $slug ): void {
		$settings = $this->scale_settings();
		unset( $settings['custom']['line-height'][ $slug ] );
		$css = Newspack_UI_Theme_Style::get_css( [], $settings, [] );
		$this->assertStringNotContainsString( '--newspack-ui-font-size-' . $role . ':', $css );
		$this->assertStringNotContainsString( '--newspack-ui-line-height-' . $role . ':', $css );
	}

	/**
	 * A role without a font size prints neither half.
	 *
	 * @dataProvider data_scale_roles
	 *
	 * @param string $role The role.
	 * @param string $slug The slug.
	 */
	public function test_scale_role_without_size_prints_neither( string $role, string $slug ): void {
		$settings = $this->scale_settings();
		$settings['typography']['fontSizes']['theme'] = array_values(
			array_filter(
				$settings['typography']['fontSizes']['theme'],
				static function ( $preset ) use ( $slug ) {
					return $preset['slug'] !== $slug;
				}
			)
		);
		$css = Newspack_UI_Theme_Style::get_css( [], $settings, [] );
		$this->assertStringNotContainsString( '--newspack-ui-font-size-' . $role . ':', $css );
		$this->assertStringNotContainsString( '--newspack-ui-line-height-' . $role . ':', $css );
	}

	/**
	 * The block theme's medium pair feeds the modal title role.
	 */
	public function test_title_m_uses_medium_line_height(): void {
		$settings = $this->scale_settings();
		$settings['typography']['fluid'] = true;
		$settings['typography']['fontSizes']['theme'][3]['fluid'] = [
			'min' => '1.125rem',
			'max' => '1.5rem',
		];
		$css = Newspack_UI_Theme_Style::get_css( [], $settings, [] );

		$this->assertMatchesRegularExpression( '/--newspack-ui-font-size-title-m:clamp\([^;]+\);/', $css );
		$this->assertStringContainsString( '--newspack-ui-line-height-title-m:1.6;', $css );
	}

	/**
	 * Fine print takes its line height from the source of its size.
	 */
	public function test_fine_print_line_height_follows_size_source(): void {
		$settings = $this->scale_settings();
		$css      = Newspack_UI_Theme_Style::get_css(
			[
				'elements' => [
					'caption' => [
						'typography' => [
							'fontSize'   => '0.8125rem',
							'lineHeight' => '1.4',
						],
					],
				],
			],
			$settings,
			[]
		);
		$this->assertStringContainsString( '--newspack-ui-font-size-fine:0.8125rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-line-height-fine:1.4;', $css );

		$css = Newspack_UI_Theme_Style::get_css( [ 'elements' => [ 'caption' => [ 'typography' => [ 'fontSize' => '0.8125rem' ] ] ] ], $settings, [] );
		$this->assertStringNotContainsString( '--newspack-ui-font-size-fine', $css );
		$this->assertStringNotContainsString( '--newspack-ui-line-height-fine', $css );

		$css = Newspack_UI_Theme_Style::get_css( [], $settings, [] );
		$this->assertStringContainsString( '--newspack-ui-font-size-fine:0.875rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-line-height-fine:1.4285714286;', $css );

		unset( $settings['custom']['line-height']['x-small'] );
		$css = Newspack_UI_Theme_Style::get_css( [], $settings, [] );
		$this->assertStringNotContainsString( '--newspack-ui-font-size-fine', $css );
		$this->assertStringNotContainsString( '--newspack-ui-line-height-fine', $css );
	}

	/**
	 * A line height with units is not a pair half.
	 */
	public function test_line_height_with_units_rejected(): void {
		$settings = $this->scale_settings();
		$settings['custom']['line-height']['medium'] = '24px';
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'elements' => [
					'caption' => [
						'typography' => [
							'fontSize'   => '0.8125rem',
							'lineHeight' => '24px',
						],
					],
				],
			],
			$settings,
			[]
		);
		$this->assertStringNotContainsString( '--newspack-ui-font-size-title-m:', $css );
		$this->assertStringNotContainsString( '--newspack-ui-line-height-title-m:', $css );
		$this->assertStringNotContainsString( '--newspack-ui-font-size-fine:0.8125rem', $css );
		$this->assertStringNotContainsString( '--newspack-ui-line-height-fine:24px', $css );
	}

	/**
	 * The caption element drives the fine print.
	 */
	public function test_caption_drives_fine_print(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'elements' => [
					'caption' => [
						'typography' => [
							'fontSize'   => '0.8125rem',
							'lineHeight' => '1.4',
						],
					],
				],
			],
			$this->scale_settings(),
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-font-size-fine:0.8125rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-line-height-fine:1.4;', $css );
	}

	/**
	 * Without a caption the fine print takes the x-small size.
	 */
	public function test_missing_caption_falls_back_to_x_small(): void {
		$css = Newspack_UI_Theme_Style::get_css( [], $this->scale_settings(), [] );
		$this->assertStringContainsString( '--newspack-ui-font-size-fine:0.875rem;', $css );

		$settings = $this->scale_settings();
		unset( $settings['typography']['fontSizes']['theme'][1] );
		$settings['typography']['fontSizes']['theme'] = array_values( $settings['typography']['fontSizes']['theme'] );
		$this->assertStringNotContainsString( '--newspack-ui-font-size-fine', Newspack_UI_Theme_Style::get_css( [], $settings, [] ) );
	}

	/**
	 * The caption family and a plain-hex color print for the fine print; field messages keep the control family.
	 */
	public function test_caption_font_family_and_colour_drive_fine_print(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'color'    => [
					'background' => '#ffffff',
					'text'       => '#111111',
				],
				'elements' => [
					'caption' => [
						'typography' => [ 'fontFamily' => 'Georgia, serif' ],
						'color'      => [ 'text' => '#555555' ],
					],
					'button'  => [ 'typography' => [ 'fontFamily' => 'Arial, sans-serif' ] ],
				],
			],
			[],
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-font-family-fine:Georgia, serif;', $css );
		$this->assertStringContainsString( '--newspack-ui-color-text-fine:#555555;', $css );
		$this->assertStringContainsString( '--newspack-ui-font-family-control:Arial, sans-serif;', $css );
	}

	/**
	 * Without a plain-hex caption color the solved muted color stands.
	 */
	public function test_caption_colour_absent_keeps_solved_muted(): void {
		foreach ( [ null, 'var(--wp--preset--color--contrast)', 'rgba(0,0,0,0.5)' ] as $color ) {
			$caption = null === $color ? [] : [ 'color' => [ 'text' => $color ] ];
			$css     = Newspack_UI_Theme_Style::get_css(
				[
					'color'    => [
						'background' => '#ffffff',
						'text'       => '#111111',
					],
					'elements' => [ 'caption' => $caption ],
				],
				[],
				[]
			);
			$this->assertStringContainsString( '--newspack-ui-color-text-muted:', $css );
			$this->assertStringNotContainsString( '--newspack-ui-color-text-fine', $css );
		}
	}

	/**
	 * A hostile family never prints.
	 */
	public function test_hostile_font_family_rejected(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'typography' => [ 'fontFamily' => 'Georgia; } body { display: none' ],
				'elements'   => [
					'heading' => [ 'typography' => [ 'fontFamily' => 'x") ; y' ] ],
					'button'  => [ 'typography' => [ 'fontFamily' => 'url(https://e.com/f)' ] ],
				],
			],
			[],
			[]
		);

		$this->assertSame( '', $css );
	}

	/**
	 * The states are computed from the fill, whatever the theme's own hover strings say.
	 */
	public function test_hover_computed_from_the_fill(): void {
		$styles   = [
			'color'    => [
				'background' => '#1e1e1e',
				'text'       => '#ffffff',
			],
			'elements' => [
				'button' => [
					'color'  => [
						'background' => '#c93a00',
						'text'       => '#ffffff',
					],
					':hover' => [ 'color' => [ 'background' => 'color-mix(in oklab, #ffc857 85%, #1e1e1e)' ] ],
				],
			],
		];
		$expected = Newspack_UI_Palette::get_properties(
			[
				'base'       => '#1e1e1e',
				'contrast'   => '#ffffff',
				'brand'      => '#c93a00',
				'brand_text' => '#ffffff',
			]
		);
		$css      = Newspack_UI_Theme_Style::get_css( $styles, [], [] );

		$this->assertStringContainsString( '--newspack-ui-color-btn-hover:' . $expected['--newspack-ui-color-btn-hover'] . ';', $css );
		$this->assertStringContainsString( '--newspack-ui-color-button-bg-hover:' . $expected['--newspack-ui-color-button-bg-hover'] . ';', $css );
		$this->assertStringContainsString( '--newspack-ui-color-btn-pressed:' . $expected['--newspack-ui-color-btn-pressed'] . ';', $css );
		$this->assertStringNotContainsString( 'color-mix', $css );
	}

	/**
	 * The theme's hover and pressed shares drive the computed states.
	 */
	public function test_state_shares_come_from_custom_settings(): void {
		$styles   = [
			'color'    => [
				'background' => '#ffffff',
				'text'       => '#111111',
			],
			'elements' => [
				'button' => [
					'color' => [
						'background' => '#003da5',
						'text'       => '#ffffff',
					],
				],
			],
		];
		$expected = Newspack_UI_Palette::get_properties(
			[
				'base'          => '#ffffff',
				'contrast'      => '#111111',
				'brand'         => '#003da5',
				'brand_text'    => '#ffffff',
				'hover_share'   => 0.6,
				'pressed_share' => 0.4,
			]
		);
		$css      = Newspack_UI_Theme_Style::get_css(
			$styles,
			[
				'custom' => [
					'state' => [
						'hover'   => '60%',
						'pressed' => '40%',
					],
				],
			],
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-color-btn-hover:' . $expected['--newspack-ui-color-btn-hover'] . ';', $css );
		$this->assertStringContainsString( '--newspack-ui-color-btn-pressed:' . $expected['--newspack-ui-color-btn-pressed'] . ';', $css );

		$default = Newspack_UI_Theme_Style::get_css(
			$styles,
			[
				'custom' => [
					'state' => [
						'hover'   => '#abcdef',
						'pressed' => 'lots',
					],
				],
			],
			[]
		);
		$this->assertStringNotContainsString( $expected['--newspack-ui-color-btn-hover'], $default );
	}

	/**
	 * A state the publisher set wins, and the guard leaves it out of its checks.
	 */
	public function test_user_state_wins_and_is_skipped_by_guard(): void {
		$styles = [
			'color'    => [
				'background' => '#111111',
				'text'       => '#ffffff',
			],
			'elements' => [
				'button' => [
					'color' => [
						'background' => '#ff9900',
						'text'       => '#000000',
					],
				],
			],
		];
		$base   = [
			'base'       => '#111111',
			'contrast'   => '#ffffff',
			'brand'      => '#ff9900',
			'brand_text' => '#000000',
		];
		$plain  = Newspack_UI_Palette::get_properties( $base );
		$user   = [ 'elements' => [ 'button' => [ ':active' => [ 'color' => [ 'background' => 'var:preset|color|brand' ] ] ] ] ];
		$skip   = Newspack_UI_Palette::get_properties( $base + [ 'skip_states' => [ 'pressed' ] ] );
		$css    = Newspack_UI_Theme_Style::get_css( $styles, $this->settings(), $user );

		$this->assertStringContainsString( '--newspack-ui-color-btn-pressed:#003da5;', $css );
		$this->assertStringContainsString( '--newspack-ui-color-btn-hover:' . $skip['--newspack-ui-color-btn-hover'] . ';', $css );
		$this->assertNotSame( $plain['--newspack-ui-color-btn-hover'], $skip['--newspack-ui-color-btn-hover'] );

		$user = [ 'elements' => [ 'button' => [ ':hover' => [ 'color' => [ 'background' => '#112233' ] ] ] ] ];
		$css  = Newspack_UI_Theme_Style::get_css( $styles, $this->settings(), $user );
		$this->assertStringContainsString( '--newspack-ui-color-btn-hover:#112233;', $css );
		$this->assertStringContainsString( '--newspack-ui-color-button-bg-hover:#112233;', $css );

		$user    = [ 'elements' => [ 'button' => [ ':hover' => [ 'color' => [ 'background' => 'color-mix(in oklab, red, blue)' ] ] ] ] ];
		$ignored = Newspack_UI_Theme_Style::get_css( $styles, $this->settings(), $user );
		$this->assertStringContainsString( '--newspack-ui-color-btn-hover:' . $plain['--newspack-ui-color-btn-hover'] . ';', $ignored );
	}

	/**
	 * A plain hex hover text prints; anything else leaves the default.
	 */
	public function test_hover_text_plain_hex_only(): void {
		$styles = [
			'color'    => [
				'background' => '#ffffff',
				'text'       => '#111111',
			],
			'elements' => [
				'button' => [
					'color'  => [
						'background' => '#003da5',
						'text'       => '#ffffff',
					],
					':hover' => [ 'color' => [ 'text' => '#fff4e0' ] ],
				],
			],
		];
		$this->assertStringContainsString( '--newspack-ui-color-button-text-hover:#fff4e0;', Newspack_UI_Theme_Style::get_css( $styles, [], [] ) );

		$styles['elements']['button'][':hover']['color']['text'] = 'var(--wp--preset--color--x)';
		$this->assertStringNotContainsString( '--newspack-ui-color-button-text-hover', Newspack_UI_Theme_Style::get_css( $styles, [], [] ) );
	}

	/**
	 * Link color, hover color and hover decoration print.
	 */
	public function test_links_print(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'elements' => [
					'link' => [
						'color'  => [ 'text' => '#003da5' ],
						':hover' => [
							'color'      => [ 'text' => '#001f55' ],
							'typography' => [ 'textDecoration' => 'none' ],
						],
					],
				],
			],
			[],
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-color-link:#003da5;', $css );
		$this->assertStringContainsString( '--newspack-ui-color-link-hover:#001f55;', $css );
		$this->assertStringContainsString( '--newspack-ui-link-hover-decoration:none;', $css );

		$bad = Newspack_UI_Theme_Style::get_css( [ 'elements' => [ 'link' => [ ':hover' => [ 'typography' => [ 'textDecoration' => 'underline; color: red' ] ] ] ] ], [], [] );
		$this->assertStringNotContainsString( 'decoration', $bad );
	}

	/**
	 * Link colors alone do not stand in for the palette.
	 */
	public function test_link_color_alone_is_not_a_palette(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->set_user_styles( [ 'elements' => [ 'link' => [ 'color' => [ 'text' => '#003da5' ] ] ] ] );

		$this->assertStringContainsString( '--newspack-ui-color-link:#003da5;', Newspack_UI_Theme_Style::get_site_css() );
		$this->assertFalse( Newspack_UI_Theme_Style::has_color_properties() );
	}

	/**
	 * The new value types.
	 */
	public function test_new_value_types(): void {
		$this->assertSame( 'var(--wp--preset--font-size--x, 12px)', Newspack_UI_Theme_Style::sanitize_value( 'var(--wp--preset--font-size--x, 12px)', 'length' ) );
		$this->assertSame( '', Newspack_UI_Theme_Style::sanitize_value( 'var(--wp--preset--font-size--x, red)', 'length' ) );
		$this->assertSame( 'uppercase', Newspack_UI_Theme_Style::sanitize_value( 'uppercase', 'text-transform' ) );
		$this->assertSame( '', Newspack_UI_Theme_Style::sanitize_value( 'upper', 'text-transform' ) );
		$this->assertSame( 'normal', Newspack_UI_Theme_Style::sanitize_value( 'normal', 'white-space' ) );
		$this->assertSame( '', Newspack_UI_Theme_Style::sanitize_value( 'nowrap; x', 'white-space' ) );
		$this->assertSame( 'underline', Newspack_UI_Theme_Style::sanitize_value( 'underline', 'text-decoration' ) );
		$this->assertSame( 'underline overline', Newspack_UI_Theme_Style::sanitize_value( 'underline overline', 'text-decoration' ) );
		$this->assertSame( '', Newspack_UI_Theme_Style::sanitize_value( 'underline dotted', 'text-decoration' ) );
	}

	/**
	 * CSS-wide keywords never print, whatever the type.
	 */
	public function test_css_wide_keywords_are_rejected(): void {
		foreach ( [ 'inherit', 'INITIAL', 'unset', 'revert', 'revert-layer' ] as $keyword ) {
			foreach ( [ 'font-family', 'length', 'weight', 'letter-spacing', 'text-transform', 'line-height', 'color' ] as $type ) {
				$this->assertSame( '', Newspack_UI_Theme_Style::sanitize_value( $keyword, $type ), $keyword . ' ' . $type );
			}
		}

		$inherit = [];
		foreach ( [ 'fontFamily', 'fontSize', 'fontWeight', 'letterSpacing', 'textTransform', 'lineHeight' ] as $key ) {
			$inherit[ $key ] = 'inherit';
		}
		$this->assertSame( '', Newspack_UI_Theme_Style::get_css( [ 'elements' => [ 'button' => [ 'typography' => $inherit ] ] ] ) );
	}

	/**
	 * Values only core's default origin sets are not the theme's.
	 */
	public function test_core_defaults_do_not_count_as_set(): void {
		$this->set_user_styles( [ 'color' => [ 'background' => '#ffffff' ] ] );
		$styles = Newspack_UI_Theme_Style::get_global_styles();

		$this->assertArrayNotHasKey( 'typography', $styles['elements']['button'] ?? [] );
		$this->assertArrayNotHasKey( 'background', $styles['elements']['button']['color'] ?? [] );

		$css = Newspack_UI_Theme_Style::get_site_css();
		$this->assertStringNotContainsString( '32373c', strtolower( $css ) );
		$this->assertStringNotContainsString( '--newspack-ui-font-family-control', $css );
		$this->assertStringNotContainsString( '--newspack-ui-button-white-space', $css );
		$this->assertNotSame( '#32373c', strtolower( Newspack_UI_Theme_Style::get_palette( $styles, [] )['brand'] ) );
	}

	/**
	 * What the user set stays, with its resolved value.
	 */
	public function test_user_set_values_are_kept(): void {
		$this->set_user_styles( [ 'elements' => [ 'button' => [ 'typography' => [ 'fontFamily' => 'Georgia, serif' ] ] ] ] );

		$this->assertSame( 'Georgia, serif', Newspack_UI_Theme_Style::get_global_styles()['elements']['button']['typography']['fontFamily'] );
		$this->assertStringContainsString( '--newspack-ui-font-family-control:Georgia, serif;', Newspack_UI_Theme_Style::get_site_css() );
	}

	/**
	 * The scale and the x-small fallback count the theme's presets only.
	 */
	public function test_scale_counts_theme_and_custom_presets_only(): void {
		$default = [];
		foreach ( [ 'small', 'medium', 'large', 'x-large', 'xx-large' ] as $slug ) {
			$default[] = [
				'slug' => $slug,
				'size' => '1rem',
			];
		}
		$settings = [
			'typography' => [
				'fontSizes' => [
					'default' => $default,
					'theme'   => [
						[
							'slug' => 'base',
							'size' => '1rem',
						],
						[
							'slug' => 'lg',
							'size' => '2rem',
						],
					],
				],
			],
		];
		$this->assertSame( '', Newspack_UI_Theme_Style::get_css( [], $settings ) );

		$settings['typography']['fontSizes']['theme'][] = [
			'slug' => 'small',
			'size' => '1rem',
		];
		$settings['custom']['line-height']['small'] = 1.5;
		$this->assertStringContainsString( '--newspack-ui-font-size-body:1rem;', Newspack_UI_Theme_Style::get_css( [], $settings ) );
	}

	/**
	 * An invalid caption size falls back to x-small.
	 */
	public function test_invalid_caption_size_falls_back_to_x_small(): void {
		$css = Newspack_UI_Theme_Style::get_css( [ 'elements' => [ 'caption' => [ 'typography' => [ 'fontSize' => 'bogus' ] ] ] ], $this->scale_settings() );

		$this->assertStringContainsString( '--newspack-ui-font-size-fine:0.875rem;', $css );
	}

	/**
	 * The hover text prints only when readable on the final hover fill, and only with a brand fill.
	 */
	public function test_hover_text_needs_a_brand_and_readable_contrast(): void {
		$styles = [
			'color'    => [
				'background' => '#ffffff',
				'text'       => '#111111',
			],
			'elements' => [
				'button' => [
					'color' => [
						'background' => '#003da5',
						'text'       => '#ffffff',
					],
				],
			],
		];
		$hover  = Newspack_UI_Palette::get_properties(
			[
				'base'       => '#ffffff',
				'contrast'   => '#111111',
				'brand'      => '#003da5',
				'brand_text' => '#ffffff',
			]
		)['--newspack-ui-color-btn-hover'];

		$styles['elements']['button'][':hover'] = [ 'color' => [ 'text' => '#ffffff' ] ];
		$this->assertStringContainsString( '--newspack-ui-color-button-text-hover:#ffffff;', Newspack_UI_Theme_Style::get_css( $styles ) );

		$styles['elements']['button'][':hover']['color']['text'] = $hover;
		$this->assertStringNotContainsString( '--newspack-ui-color-button-text-hover', Newspack_UI_Theme_Style::get_css( $styles ) );

		unset( $styles['elements']['button']['color'] );
		$styles['elements']['button'][':hover']['color']['text'] = '#ffffff';
		$this->assertStringNotContainsString( '--newspack-ui-color-button-text-hover', Newspack_UI_Theme_Style::get_css( $styles ) );
	}

	/**
	 * A user state equal to a style variation's was copied from it, so it is the theme's.
	 */
	public function test_variation_states_are_not_the_users(): void {
		$styles    = [
			'color'    => [
				'background' => '#ffffff',
				'text'       => '#111111',
			],
			'elements' => [
				'button' => [
					'color' => [
						'background' => '#c93a00',
						'text'       => '#ffffff',
					],
				],
			],
		];
		$computed  = Newspack_UI_Palette::get_properties(
			[
				'base'       => '#ffffff',
				'contrast'   => '#111111',
				'brand'      => '#c93a00',
				'brand_text' => '#ffffff',
			]
		)['--newspack-ui-color-btn-hover'];
		$user      = [ 'elements' => [ 'button' => [ ':hover' => [ 'color' => [ 'background' => 'var(--wp--preset--color--brand)' ] ] ] ] ];
		$variation = [ 'elements' => [ 'button' => [ ':hover' => [ 'color' => [ 'background' => 'var:preset|color|brand' ] ] ] ] ];

		$css = Newspack_UI_Theme_Style::get_css( $styles, $this->settings(), $user );
		$this->assertStringContainsString( '--newspack-ui-color-btn-hover:#003da5;', $css );

		$css = Newspack_UI_Theme_Style::get_css( $styles, $this->settings(), $user, [ $variation ] );
		$this->assertStringContainsString( '--newspack-ui-color-btn-hover:' . $computed . ';', $css );

		$css = Newspack_UI_Theme_Style::get_css( $styles, $this->settings(), $user, static fn() => [ [ 'elements' => [ 'button' => [ ':hover' => [ 'color' => [ 'background' => '#445566' ] ] ] ] ] ] );
		$this->assertStringContainsString( '--newspack-ui-color-btn-hover:#003da5;', $css );
	}

	/**
	 * A preset custom property resolves to the preset value.
	 */
	public function test_preset_custom_properties_resolve(): void {
		$resolved = Newspack_UI_Theme_Style::resolve_references(
			[
				'a' => 'var(--wp--preset--color--brand)',
				'b' => 'var(--wp--preset--color--brand, #ffffff)',
				'c' => 'var(--wp--preset--color--nope)',
				'd' => 'var(--wp--preset--font-family--body)',
			],
			$this->settings()
		);

		$this->assertSame( '#003da5', $resolved['a'] );
		$this->assertSame( '#003da5', $resolved['b'] );
		$this->assertSame( 'var(--wp--preset--color--nope)', $resolved['c'] );
		$this->assertSame( 'Georgia, serif', $resolved['d'] );
	}

	/**
	 * The field styles of a global styles tree.
	 *
	 * @param array $text_input The textInput element.
	 * @param array $select     The select element.
	 *
	 * @return array
	 */
	private function field_styles( array $text_input, array $select = [] ): array {
		return [
			'elements' => [
				'textInput' => $text_input,
				'select'    => $select,
			],
		];
	}

	/**
	 * Every field value the text input sets is printed.
	 */
	public function test_text_input_values_win(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[
					'border'     => [
						'radius' => '.5rem',
						'width'  => '2px',
						'color'  => '#336699',
					],
					'color'      => [
						'background' => '#f5f5f5',
						'text'       => '#111111',
					],
					'typography' => [ 'fontFamily' => 'Georgia, serif' ],
				],
				[
					'border'     => [
						'radius' => '1rem',
						'width'  => '4px',
						'color'  => '#990000',
					],
					'color'      => [
						'background' => '#000000',
						'text'       => '#ffffff',
					],
					'typography' => [ 'fontFamily' => 'Arial, sans-serif' ],
				]
			),
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-field-radius:0.5rem;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-border-width:2px;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-border-color:#336699;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-bg:#f5f5f5;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-ink:#111111;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-font-family:Georgia, serif;', $css );
	}

	/**
	 * The select fills in what the text input leaves out.
	 */
	public function test_select_fills_in_missing_field_values(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[ 'border' => [ 'width' => '2px' ] ],
				[
					'border' => [
						'radius' => [
							'topLeft'     => '6px',
							'topRight'    => '6px',
							'bottomRight' => '6px',
							'bottomLeft'  => '6px',
						],
						'width'  => '4px',
					],
				]
			),
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-field-radius:6px;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-border-width:2px;', $css );
	}

	/**
	 * Without field styles nothing is printed, and the solved input border stays.
	 */
	public function test_missing_text_input_uses_fallbacks(): void {
		$styles = [
			'color'    => [
				'background' => '#f4efe6',
				'text'       => '#222222',
			],
			'elements' => [ 'button' => [ 'border' => [ 'radius' => '4px' ] ] ],
		];
		$css    = Newspack_UI_Theme_Style::get_css( $styles, [] );

		$this->assertStringNotContainsString( '--newspack-ui-field-', $css );
		$this->assertStringContainsString( '--newspack-ui-color-border-control:', $css );
		$this->assertStringContainsString( '--newspack-ui-radius-control:min(4px, 12px);', $css );
	}

	/**
	 * The solved input border prints when the text input sets no border color.
	 */
	public function test_input_border_mix_prints_without_a_field_border_color(): void {
		$styles = [
			'color'    => [
				'background' => '#f4efe6',
				'text'       => '#222222',
			],
			'elements' => [ 'textInput' => [ 'border' => [ 'radius' => '4px' ] ] ],
		];
		$css    = Newspack_UI_Theme_Style::get_css( $styles, [] );

		$this->assertStringContainsString( '--newspack-ui-field-radius:4px;', $css );
		$this->assertStringNotContainsString( '--newspack-ui-field-border-color', $css );
		$this->assertStringContainsString( '--newspack-ui-color-border-control:', $css );
	}

	/**
	 * Background and text color print together or not at all.
	 */
	public function test_field_colors_print_as_a_pair(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles( [ 'color' => [ 'background' => '#f5f5f5' ] ] ),
			[]
		);
		$this->assertStringNotContainsString( '--newspack-ui-field-bg', $css );
		$this->assertStringNotContainsString( '--newspack-ui-field-ink', $css );

		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles( [ 'color' => [ 'text' => '#111111' ] ] ),
			[]
		);
		$this->assertStringNotContainsString( '--newspack-ui-field-bg', $css );
		$this->assertStringNotContainsString( '--newspack-ui-field-ink', $css );

		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[ 'color' => [ 'background' => '#f5f5f5' ] ],
				[
					'color' => [
						'background' => '#222222',
						'text'       => '#eeeeee',
					],
				]
			),
			[]
		);
		$this->assertStringContainsString( '--newspack-ui-field-bg:#222222;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-ink:#eeeeee;', $css );

		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[
					'color' => [
						'background' => '#f5f5f5',
						'text'       => 'notacolor',
					],
				]
			),
			[]
		);
		$this->assertStringNotContainsString( '--newspack-ui-field-bg', $css );
	}

	/**
	 * The publisher's field border color is printed as set, whatever the contrast.
	 */
	public function test_publisher_field_border_not_rechecked(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[
					'border' => [ 'color' => '#eeeeee' ],
					'color'  => [
						'background' => '#ffffff',
						'text'       => '#111111',
					],
				]
			),
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-field-border-color:#eeeeee;', $css );
	}

	/**
	 * A user-origin font family or color preset on the text input is used.
	 */
	public function test_user_origin_field_font_family_preset_is_used(): void {
		$settings = $this->settings();
		$styles   = Newspack_UI_Theme_Style::resolve_references(
			$this->field_styles(
				[
					'border'     => [ 'color' => 'var:preset|color|brand' ],
					'typography' => [ 'fontFamily' => 'var:preset|font-family|body' ],
				]
			),
			$settings
		);
		$css      = Newspack_UI_Theme_Style::get_css( $styles, $settings );

		$this->assertStringContainsString( '--newspack-ui-field-font-family:Georgia, serif;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-border-color:#003da5;', $css );
	}

	/**
	 * The field family never reaches the control family that labels and messages read.
	 */
	public function test_labels_keep_control_font(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles( [ 'typography' => [ 'fontFamily' => 'Georgia, serif' ] ] ),
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-field-font-family:Georgia, serif;', $css );
		$this->assertStringNotContainsString( '--newspack-ui-font-family-control', $css );
	}

	/**
	 * Hostile field values are not printed.
	 */
	public function test_hostile_field_values_are_not_printed(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[
					'border'     => [
						'radius' => 'red; } body { display:none',
						'width'  => 'calc(1px',
						'color'  => 'url(x)',
					],
					'typography' => [ 'fontFamily' => 'a; b' ],
				]
			),
			[]
		);

		$this->assertStringNotContainsString( '--newspack-ui-field-', $css );
	}

	/**
	 * A zero border width prints as 0px, however it is written.
	 */
	public function test_zero_border_width_prints(): void {
		foreach ( [ 0, '0', '0px' ] as $zero ) {
			$css = Newspack_UI_Theme_Style::get_css( $this->field_styles( [ 'border' => [ 'width' => $zero ] ] ), [] );
			$this->assertStringContainsString( '--newspack-ui-field-border-width:0px;', $css );
		}
	}

	/**
	 * Only non-negative px, rem and em widths, zero and var() references are printed.
	 */
	public function test_border_width_is_validated(): void {
		foreach ( [ '5%', '-1px', '1vw', 'calc(1px + 2px)', 'thick', '2px 3px' ] as $width ) {
			$css = Newspack_UI_Theme_Style::get_css( $this->field_styles( [ 'border' => [ 'width' => $width ] ] ), [] );
			$this->assertStringNotContainsString( '--newspack-ui-field-border-width', $css, $width );
		}
		foreach ( [ '1px', '.5rem', '0.1em' ] as $width ) {
			$css = Newspack_UI_Theme_Style::get_css( $this->field_styles( [ 'border' => [ 'width' => $width ] ] ), [] );
			$this->assertStringContainsString( '--newspack-ui-field-border-width:', $css, $width );
		}
	}

	/**
	 * A border style of none or hidden removes the border.
	 */
	public function test_border_style_none_prints_zero_width(): void {
		foreach ( [ 'none', 'hidden' ] as $style ) {
			$css = Newspack_UI_Theme_Style::get_css(
				$this->field_styles(
					[
						'border' => [
							'style' => $style,
							'width' => '3px',
						],
					]
				),
				[]
			);
			$this->assertStringContainsString( '--newspack-ui-field-border-width:0px;', $css );
		}
		$css = Newspack_UI_Theme_Style::get_css( $this->field_styles( [ 'border' => [ 'style' => 'solid' ] ] ), [] );
		$this->assertStringNotContainsString( '--newspack-ui-field-border-width', $css );
	}

	/**
	 * Four identical per-side borders count as one; differing sides print nothing.
	 */
	public function test_per_side_borders_collapse_like_corners(): void {
		$side = static function ( string $width, string $color ): array {
			return [
				'width' => $width,
				'color' => $color,
				'style' => 'solid',
			];
		};
		$css  = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[
					'border' => [
						'top'    => $side( '2px', '#336699' ),
						'right'  => $side( '2px', '#336699' ),
						'bottom' => $side( '2px', '#336699' ),
						'left'   => $side( '2px', '#336699' ),
					],
				]
			),
			[]
		);
		$this->assertStringContainsString( '--newspack-ui-field-border-width:2px;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-border-color:#336699;', $css );

		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[
					'border' => [
						'bottom' => $side( '2px', '#336699' ),
					],
				]
			),
			[]
		);
		$this->assertStringNotContainsString( '--newspack-ui-field-border-width', $css );
		$this->assertStringNotContainsString( '--newspack-ui-field-border-color', $css );
	}

	/**
	 * The select's colors are used when the text input sets none.
	 */
	public function test_select_only_color_pair_prints(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[ 'border' => [ 'radius' => '4px' ] ],
				[
					'color' => [
						'background' => '#222222',
						'text'       => '#eeeeee',
					],
				]
			),
			[]
		);

		$this->assertStringContainsString( '--newspack-ui-field-bg:#222222;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-ink:#eeeeee;', $css );
	}

	/**
	 * A dark field gets the light caret; a light one the dark caret; tones follow the pair.
	 */
	public function test_caret_and_tones_follow_the_pair(): void {
		$dark = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[
					'color' => [
						'background' => '#222222',
						'text'       => '#eeeeee',
					],
				]
			),
			[]
		);
		$this->assertStringContainsString( '--newspack-ui-field-caret:var(--newspack-ui-field-caret-light);', $dark );
		$this->assertMatchesRegularExpression( '/--newspack-ui-field-ink-subtle:#[0-9a-f]{6};/', $dark );
		$this->assertMatchesRegularExpression( '/--newspack-ui-field-ink-muted:#[0-9a-f]{6};/', $dark );

		$light = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[
					'color' => [
						'background' => '#fff',
						'text'       => '#111',
					],
				]
			),
			[]
		);
		$this->assertStringContainsString( '--newspack-ui-field-caret:var(--newspack-ui-field-caret-dark);', $light );

		$none = Newspack_UI_Theme_Style::get_css( $this->field_styles( [ 'color' => [ 'background' => '#222222' ] ] ), [] );
		$this->assertStringNotContainsString( '--newspack-ui-field-caret', $none );
		$this->assertStringNotContainsString( '--newspack-ui-field-ink-', $none );

		$var = Newspack_UI_Theme_Style::get_css(
			$this->field_styles(
				[
					'color' => [
						'background' => 'var(--wp--preset--color--base)',
						'text'       => '#111111',
					],
				]
			),
			[]
		);
		$this->assertStringNotContainsString( '--newspack-ui-field-bg', $var );
		$this->assertStringNotContainsString( '--newspack-ui-field-caret', $var );
	}

	/**
	 * Literal sizes go fluid as in core; sizes from presets keep the theme's own value.
	 */
	public function test_fluid_applies_to_literal_sizes_only(): void {
		$settings = [
			'typography' => [
				'fluid'     => true,
				'fontSizes' => [
					'theme' => [
						[
							'slug'  => 'small',
							'size'  => '1rem',
							'fluid' => false,
						],
					],
				],
			],
		];
		foreach ( [ 'var:preset|font-size|small', 'var(--wp--preset--font-size--small)' ] as $reference ) {
			$authored = [
				'elements' => [
					'button' => [
						'typography' => [
							'fontSize'   => $reference,
							'lineHeight' => '1.3',
						],
					],
				],
			];
			$styles   = Newspack_UI_Theme_Style::resolve_references( $authored, $settings );
			$styles   = Newspack_UI_Theme_Style::apply_fluid_sizes( $styles, $authored, $settings );

			$this->assertStringContainsString( '--newspack-ui-font-size-button:1rem;', Newspack_UI_Theme_Style::get_css( $styles, $settings ) );
		}

		$authored = [
			'elements' => [
				'button' => [
					'typography' => [
						'fontSize'   => '1.5rem',
						'lineHeight' => '1.3',
					],
				],
			],
		];
		$styles   = Newspack_UI_Theme_Style::apply_fluid_sizes( $authored, $authored, $settings );
		$this->assertStringContainsString( '--newspack-ui-font-size-button:clamp(', Newspack_UI_Theme_Style::get_css( $styles, $settings ) );
	}

	/**
	 * A hostile value never reaches the output.
	 */
	public function test_hostile_values_are_not_printed(): void {
		$hostile = 'red;}body{display:none';
		$css     = Newspack_UI_Theme_Style::get_css(
			[
				'color'    => [
					'background' => '#ffffff',
					'text'       => $hostile,
				],
				'elements' => [
					'button' => [
						'color' => [
							'background' => $hostile,
							'text'       => $hostile,
						],
					],
				],
			],
			[]
		);
		$this->assertStringNotContainsString( '}body', $css );
		$this->assertStringNotContainsString( 'display:none', $css );

		$css = Newspack_UI_Theme_Style::get_css( [ 'color' => [ 'background' => $hostile ] ], [] );
		$this->assertSame( '', $css );
	}

	/**
	 * The allow-list per property type.
	 *
	 * @dataProvider data_sanitize
	 *
	 * @param mixed  $value    Value.
	 * @param string $type     Type.
	 * @param string $expected Expected.
	 */
	public function test_sanitize_value( $value, string $type, string $expected ): void {
		$this->assertSame( $expected, Newspack_UI_Theme_Style::sanitize_value( $value, $type ) );
	}

	/**
	 * Sanitizer cases.
	 *
	 * @return array
	 */
	public function data_sanitize(): array {
		return [
			'hex'               => [ '#FFC857', 'color', '#FFC857' ],
			'var'               => [ 'var(--wp--preset--color--base, #fff)', 'color', 'var(--wp--preset--color--base, #fff)' ],
			'color-mix dropped' => [ 'color-mix( in oklab, red, blue )', 'color', '' ],
			'injection'         => [ 'red;}body{display:none', 'color', '' ],
			'var injection'     => [ 'var(--a;}body{x:y)', 'color', '' ],
			'unbalanced'        => [ 'var(--a, (1)', 'color', '' ],
			'family'            => [ '"Source Serif 4", Georgia, serif', 'font-family', '"Source Serif 4", Georgia, serif' ],
			'family bad'        => [ 'Georgia; color:red', 'font-family', '' ],
			'rem'               => [ '1.125rem', 'length', '1.125rem' ],
			'percent'           => [ '50%', 'length', '50%' ],
			'clamp'             => [ 'clamp(1rem, 2.5vw + 0.5rem, 2rem)', 'length', 'clamp(1rem, 2.5vw + 0.5rem, 2rem)' ],
			'calc bad'          => [ 'calc(1rem + url(x))', 'length', '' ],
			'unitless lh'       => [ '1.6', 'line-height', '1.6' ],
			'length lh'         => [ '24px', 'line-height', '24px' ],
			'weight'            => [ '700', 'weight', '700' ],
			'weight word'       => [ 'bold', 'weight', 'bold' ],
			'weight bad'        => [ '750', 'weight', '' ],
			'spacing zero'      => [ '0', 'letter-spacing', '0' ],
			'spacing em'        => [ '-0.01em', 'letter-spacing', '-0.01em' ],
			'spacing bad'       => [ 'wide', 'letter-spacing', '' ],
			'var then url'      => [ 'var(--a, x) url(https://e.com/p.png)', 'color', '' ],
			'var shorthand'     => [ 'var(--a, 1px solid red)', 'color', '' ],
			'var open quote'    => [ 'var(--a, ")', 'color', '' ],
			'var url fallback'  => [ 'var(--x, url(https://e.com/p.png))', 'color', '' ],
			'var plain'         => [ 'var(--a)', 'color', 'var(--a)' ],
			'var hex fallback'  => [ 'var(--a, #fff)', 'color', 'var(--a, #fff)' ],
			'var nested'        => [ 'var(--a, var(--b, #fff))', 'color', 'var(--a, var(--b, #fff))' ],
			'var empty'         => [ 'var(--a,)', 'color', 'var(--a,)' ],
			'family quote'      => [ 'Georgia"', 'font-family', '' ],
			'family open quote' => [ 'Georgia, "Serif', 'font-family', '' ],
			'family var'        => [ 'var(--wp--preset--font-family--body, Georgia, serif)', 'font-family', 'var(--wp--preset--font-family--body, Georgia, serif)' ],
			'mixed quotes 1'    => [ '"it\'s" \'', 'font-family', '' ],
			'mixed quotes 2'    => [ '\'a"b\' "', 'font-family', '' ],
			'var mixed quotes'  => [ 'var(--a, "it\'s" \')', 'font-family', '' ],
			'apostrophe name'   => [ '"Kid\'s Font", serif', 'font-family', '"Kid\'s Font", serif' ],
			'quote in color'    => [ 'var(--a, "x")', 'color', '' ],
			'family newline'    => [ "\"a\nb\", serif", 'font-family', '' ],
			'family return'     => [ "\"a\rb\"", 'font-family', '' ],
			'family formfeed'   => [ "'a\fb'", 'font-family', '' ],
			'var newline'       => [ "var(--a, \"x\ny\")", 'font-family', '' ],
			'family tab'        => [ "Georgia,\tserif", 'font-family', '' ],
			'family nul'        => [ "Geor\0gia", 'font-family', '' ],
			'color trailing nl' => [ "#fff\n", 'color', '' ],
			'not a string'      => [ [ '#fff' ], 'color', '' ],
		];
	}

	/**
	 * The rule is attached to the newspack-ui handle once.
	 */
	public function test_inline_css_attached_to_newspack_ui_handle_once(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->set_user_styles( [ 'elements' => [ 'button' => [ 'border' => [ 'radius' => '12px' ] ] ] ] );
		Newspack_UI::enqueue_assets();

		Newspack_UI_Theme_Style::print_css();
		Newspack_UI_Theme_Style::print_css();

		$inline = implode( "\n", (array) wp_styles()->get_data( 'newspack-ui', 'after' ) );
		$this->assertSame( 1, substr_count( $inline, ':root{' ) );
		$this->assertStringContainsString( Newspack_UI_Theme_Style::get_site_css(), $inline );
	}

	/**
	 * A fresh styles instance gets the rule even after another instance printed it, and a copy that shares the
	 * registered handles does not get it twice.
	 */
	public function test_guard_keys_on_handle_data_not_a_static_flag(): void {
		global $wp_styles;

		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->set_user_styles( [ 'elements' => [ 'button' => [ 'border' => [ 'radius' => '12px' ] ] ] ] );
		Newspack_UI::enqueue_assets();
		Newspack_UI_Theme_Style::print_css();

		$wp_styles = new WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Newspack_UI::enqueue_assets();
		Newspack_UI_Theme_Style::print_css();
		$fresh = implode( "\n", (array) wp_styles()->get_data( 'newspack-ui', 'after' ) );
		$this->assertSame( 1, substr_count( $fresh, ':root{' ) );

		$shared             = new WP_Styles();
		$shared->registered = $wp_styles->registered;
		$wp_styles          = $shared; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_enqueue_style( 'newspack-ui' );
		Newspack_UI_Theme_Style::print_css();
		Newspack_UI_Theme_Style::print_css();
		$copy = implode( "\n", (array) wp_styles()->get_data( 'newspack-ui', 'after' ) );
		$this->assertSame( 1, substr_count( $copy, ':root{' ) );
	}

	/**
	 * Nothing prints when the Theme style is off, even with CSS to print.
	 */
	public function test_noop_when_inactive(): void {
		$this->set_user_styles( [ 'elements' => [ 'button' => [ 'border' => [ 'radius' => '12px' ] ] ] ] );
		Newspack_UI::enqueue_assets();
		$this->assertNotSame( '', Newspack_UI_Theme_Style::get_site_css() );

		Newspack_UI_Theme_Style::print_css();

		$this->assertEmpty( wp_styles()->get_data( 'newspack-ui', 'after' ) );
	}

	/**
	 * Nothing prints, and nothing is raised, when the handle is registered but not enqueued.
	 */
	public function test_noop_when_handle_not_enqueued(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->set_user_styles( [ 'elements' => [ 'button' => [ 'border' => [ 'radius' => '12px' ] ] ] ] );
		wp_register_style( 'newspack-ui', false, [], '1' );
		$this->assertNotSame( '', Newspack_UI_Theme_Style::get_site_css() );

		Newspack_UI_Theme_Style::print_css();

		$this->assertEmpty( wp_styles()->get_data( 'newspack-ui', 'after' ) );
	}

	/**
	 * The button size and line height print as a pair from one source, or not at all.
	 */
	public function test_button_size_and_line_height_print_as_a_pair(): void {
		$both  = [
			'fontSize'   => '1.125rem',
			'lineHeight' => '1.2',
		];
		$pair  = Newspack_UI_Theme_Style::get_css( [ 'elements' => [ 'button' => [ 'typography' => $both ] ] ], [] );
		$this->assertStringContainsString( '--newspack-ui-font-size-button:1.125rem;', $pair );
		$this->assertStringContainsString( '--newspack-ui-line-height-button:1.2;', $pair );

		$block = Newspack_UI_Theme_Style::get_css(
			[
				'blocks'   => [ 'core/button' => [ 'typography' => [ 'fontSize' => '1rem' ] ] ],
				'elements' => [ 'button' => [ 'typography' => $both ] ],
			],
			[]
		);
		$this->assertStringNotContainsString( '--newspack-ui-font-size-button', $block );
		$this->assertStringNotContainsString( '--newspack-ui-line-height-button', $block );

		$block_pair = Newspack_UI_Theme_Style::get_css(
			[
				'blocks'   => [
					'core/button' => [
						'typography' => [
							'fontSize'   => '1rem',
							'lineHeight' => '1.5',
						],
					],
				],
				'elements' => [ 'button' => [ 'typography' => $both ] ],
			],
			[]
		);
		$this->assertStringContainsString( '--newspack-ui-font-size-button:1rem;', $block_pair );
		$this->assertStringContainsString( '--newspack-ui-line-height-button:1.5;', $block_pair );

		foreach ( [ [ 'fontSize' => '1rem' ], [ 'lineHeight' => '1.4' ] ] as $one ) {
			$css = Newspack_UI_Theme_Style::get_css( [ 'elements' => [ 'button' => [ 'typography' => $one ] ] ], [] );
			$this->assertStringNotContainsString( '--newspack-ui-font-size-button', $css );
			$this->assertStringNotContainsString( '--newspack-ui-line-height-button', $css );
		}
	}

	/**
	 * Without a field pair, a dark base still gets the light caret; a light base keeps Neutral's.
	 */
	public function test_caret_falls_back_to_the_base(): void {
		$dark = Newspack_UI_Theme_Style::get_css(
			[
				'color' => [
					'background' => '#1e1e1e',
					'text'       => '#ffffff',
				],
			],
			[]
		);
		$this->assertStringContainsString( '--newspack-ui-field-caret:var(--newspack-ui-field-caret-light);', $dark );

		$light = Newspack_UI_Theme_Style::get_css(
			[
				'color' => [
					'background' => '#f2f0ea',
					'text'       => '#000000',
				],
			],
			[]
		);
		$this->assertStringNotContainsString( '--newspack-ui-field-caret', $light );
	}

	/**
	 * Every property the adapter can produce has a value type, so render() never drops one silently.
	 */
	public function test_every_produced_property_has_a_type_and_prints(): void {
		$settings = $this->settings();
		$settings['typography']['fontSizes']['theme'] = [
			[
				'slug' => 'xx-small',
				'size' => '0.75rem',
			],
			[
				'slug' => 'x-small',
				'size' => '0.875rem',
			],
			[
				'slug' => 'small',
				'size' => '1rem',
			],
			[
				'slug' => 'medium',
				'size' => '1.25rem',
			],
			[
				'slug' => 'x-large',
				'size' => '1.5rem',
			],
			[
				'slug' => 'xx-large',
				'size' => '2rem',
			],
		];
		$settings['custom']['line-height'] = [
			'xx-small' => '1.3',
			'x-small'  => '1.4',
			'small'    => '1.5',
			'medium'   => '1.4',
			'x-large'  => '1.3',
			'xx-large' => '1.2',
		];
		$styles = [
			'color'      => [
				'background' => '#f2f0ea',
				'text'       => '#111111',
			],
			'typography' => [ 'fontFamily' => 'Georgia, serif' ],
			'blocks'     => [
				'core/button' => [
					'border'     => [ 'radius' => '4px' ],
					'typography' => [
						'fontFamily'    => 'Arial, sans-serif',
						'fontSize'      => '1rem',
						'lineHeight'    => '1.5',
						'fontWeight'    => '700',
						'letterSpacing' => '0.05em',
						'textTransform' => 'uppercase',
					],
				],
			],
			'elements'   => [
				'button'    => [
					'color'  => [
						'background' => '#003da5',
						'text'       => '#ffffff',
					],
					':hover' => [ 'color' => [ 'text' => '#ffffff' ] ],
				],
				'link'      => [
					'color'  => [ 'text' => '#003da5' ],
					':hover' => [
						'color'      => [ 'text' => '#002a73' ],
						'typography' => [ 'textDecoration' => 'underline' ],
					],
				],
				'caption'   => [ 'color' => [ 'text' => '#555555' ] ],
				'heading'   => [
					'typography' => [
						'fontFamily' => 'Georgia, serif',
						'fontWeight' => '800',
					],
				],
				'textInput' => [
					'border'     => [
						'radius' => '2px',
						'width'  => '1px',
						'color'  => '#999999',
					],
					'color'      => [
						'background' => '#222222',
						'text'       => '#eeeeee',
					],
					'typography' => [ 'fontFamily' => 'Arial, sans-serif' ],
				],
			],
		];
		$user = [ 'elements' => [ 'button' => [ ':hover' => [ 'color' => [ 'background' => '#112233' ] ] ] ] ];

		$properties = Newspack_UI_Theme_Style::get_properties( $styles, $settings, $user );
		$css        = Newspack_UI_Theme_Style::get_css( $styles, $settings, $user );

		$this->assertGreaterThan( 60, count( $properties ) );
		foreach ( array_keys( $properties ) as $name ) {
			$this->assertNotSame( '', Newspack_UI_Theme_Style::property_type( $name ), $name );
			$this->assertStringContainsString( $name . ':', $css, $name );
		}
	}

	/**
	 * A dark base prints stronger field borders for `prefers-contrast: more`; a white base prints none.
	 */
	public function test_high_contrast_field_borders_follow_the_theme_base(): void {
		$dark = Newspack_UI_Theme_Style::get_css(
			[
				'color' => [
					'background' => '#1e1e1e',
					'text'       => '#ffffff',
				],
			],
			[]
		);
		$this->assertSame( 1, preg_match( '/@media \(prefers-contrast: more\)\{:root\{([^{}]+)\}\}$/', $dark, $match ) );
		preg_match_all( '/(--newspack-ui-color-input-border[a-z-]*):(#[0-9a-f]{6});/', $match[1], $found );
		$more = array_combine( $found[1], $found[2] );
		$this->assertCount( 3, $more );

		preg_match( '/--newspack-ui-color-border-control:(#[0-9a-f]{6});/', $dark, $control );
		$lc = static function ( $hex ) {
			return Newspack_UI_Palette::lc( $hex, '#1e1e1e' );
		};
		$this->assertGreaterThan( $lc( $control[1] ), $lc( $more['--newspack-ui-color-input-border'] ) );
		$this->assertGreaterThan( $lc( $more['--newspack-ui-color-input-border'] ), $lc( $more['--newspack-ui-color-input-border-hover'] ) );
		$this->assertGreaterThanOrEqual( $lc( $control[1] ), $lc( $more['--newspack-ui-color-input-border-disabled'] ) );
		$this->assertGreaterThan( $lc( $more['--newspack-ui-color-input-border-disabled'] ), $lc( $more['--newspack-ui-color-input-border'] ) );

		$white = Newspack_UI_Theme_Style::get_css(
			[
				'color'    => [
					'background' => '#ffffff',
					'text'       => '#1e1e1e',
				],
				'elements' => [ 'button' => [ 'color' => [ 'background' => '#003da5' ] ] ],
			],
			[]
		);
		$this->assertStringNotContainsString( 'prefers-contrast', $white );
	}

	/**
	 * Printing the high contrast block goes through the same allow-list as the rest of the rule.
	 */
	public function test_high_contrast_block_is_valid_css_without_injection(): void {
		$css = Newspack_UI_Theme_Style::get_css(
			[
				'color' => [
					'background' => '#1e1e1e',
					'text'       => 'red;} body{display:none',
				],
			],
			[]
		);

		$this->assertStringNotContainsString( 'display:none', $css );
		$this->assertSame( substr_count( $css, '{' ), substr_count( $css, '}' ) );
		$this->assertSame( 1, preg_match( '/^:root\{[^{}]*\}@media \(prefers-contrast: more\)\{:root\{[^{}]*\}\}$/', $css ) );
	}

	/**
	 * A button size with a keyword line height prints neither half.
	 */
	public function test_button_size_with_keyword_line_height_prints_neither(): void {
		foreach ( [ 'normal', 'inherit' ] as $line_height ) {
			$css = Newspack_UI_Theme_Style::get_css(
				[
					'elements' => [
						'button' => [
							'typography' => [
								'fontSize'   => '1.125rem',
								'lineHeight' => $line_height,
							],
						],
					],
				],
				[]
			);
			$this->assertStringNotContainsString( '--newspack-ui-font-size-button', $css, $line_height );
			$this->assertStringNotContainsString( '--newspack-ui-line-height-button', $css, $line_height );
		}
	}

	/**
	 * A field background or text color that is not plain hex prints no pair, so the tones and the caret never come without it.
	 */
	public function test_field_pair_needs_plain_hex_on_both_sides(): void {
		foreach ( [ 'var(--wp--custom--field--bg)', '#222222cc' ] as $background ) {
			$css = Newspack_UI_Theme_Style::get_css(
				[
					'elements' => [
						'textInput' => [
							'color' => [
								'background' => $background,
								'text'       => '#eeeeee',
							],
						],
					],
				],
				[]
			);
			$this->assertStringNotContainsString( '--newspack-ui-field-bg', $css, $background );
			$this->assertStringNotContainsString( '--newspack-ui-field-ink', $css, $background );
		}

		$css = Newspack_UI_Theme_Style::get_css(
			[
				'elements' => [
					'textInput' => [
						'color' => [
							'background' => '#222',
							'text'       => '#eeeeee',
						],
					],
				],
			],
			[]
		);
		$this->assertStringContainsString( '--newspack-ui-field-bg:#222;', $css );
		$this->assertStringContainsString( '--newspack-ui-field-ink-muted:', $css );
	}

	/**
	 * Switching blogs drops the memoized rule.
	 */
	public function test_switch_blog_resets_the_memoized_rule(): void {
		$this->assertSame( 10, has_action( 'switch_blog', [ Newspack_UI_Theme_Style::class, 'reset_cache' ] ) );
	}
}
