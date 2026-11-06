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
	 * Reset the style registry.
	 */
	public function tear_down(): void {
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
	 * Without a theme opt-in, the inline CSS is the pre-adapter output.
	 */
	public function test_classic_theme_inline_css_matches_baseline(): void {
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
			$this->assertSame( [], $properties );
			return;
		}
		$this->assertSame( $brand, $properties['--newspack-ui-color-primary'] );
		$this->assertSame( '#1e1e1e', $properties['--newspack-ui-color-base'] );
		$this->assertSame( '#ffffff', $properties['--newspack-ui-color-contrast'] );
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
			),
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
	 * Without the Theme style, nothing is added and the inline CSS matches the baseline.
	 */
	public function test_colors_css_wrap_unchanged_when_inactive(): void {
		$this->test_classic_theme_inline_css_matches_baseline();
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
				'color'   => [ 'background' => '#112233' ],
				'spacing' => [ 'padding' => '1px' ],
				'css'     => 'a{b:c}',
			]
		);
		$styles = Newspack_UI_Theme_Style::get_global_styles();

		$this->assertArrayHasKey( 'color', $styles );
		$this->assertArrayNotHasKey( 'spacing', $styles );
		$this->assertArrayNotHasKey( 'css', $styles );
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
	 * Destructive buttons read as an outline in the base color and error steps.
	 */
	public function test_destructive_reads_as_outline_in_theme(): void {
		$properties = Newspack_UI_Theme_Style::get_properties(
			[
				'color' => [
					'background' => '#1e1e1e',
					'text'       => '#ffffff',
				],
			],
			[] 
		);

		$this->assertSame( '#1e1e1e', $properties['--newspack-ui-color-destructive-fill'] );
		$this->assertSame( $this->resolved( $properties, 'error-text' ), $properties['--newspack-ui-color-destructive-ink'] );
		$this->assertSame( $this->resolved( $properties, 'error-text' ), $properties['--newspack-ui-color-destructive-text'] );
		$this->assertSame( $this->resolved( $properties, 'error' ), $properties['--newspack-ui-color-destructive-edge'] );
	}

	/**
	 * Without a palette there is no destructive token and the baseline holds.
	 */
	public function test_destructive_untouched_in_neutral(): void {
		$this->assertSame( '', Newspack_UI_Theme_Style::get_css( [], [] ) );
		foreach ( array_keys( Newspack_UI_Theme_Style::get_properties( [], [] ) ) as $name ) {
			$this->assertStringNotContainsString( 'destructive', $name );
		}
		$this->test_classic_theme_inline_css_matches_baseline();
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
			'not a string'      => [ [ '#fff' ], 'color', '' ],
		];
	}
}
