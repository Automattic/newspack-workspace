<?php
/**
 * Class Theme Json Builder Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Theme_Json_Builder;

/**
 * Theme Json Builder Test.
 */
class Test_Theme_Json_Builder extends WP_UnitTestCase {
	/**
	 * Background and text colors are mapped from post meta into theme.json styles.
	 */
	public function test_maps_background_and_text_color_from_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'background_color', '#112233' );
		update_post_meta( $post_id, 'text_color', '#445566' );

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( '#112233', $theme['styles']['color']['background'] );
		$this->assertSame( '#445566', $theme['styles']['color']['text'] );
	}

	/**
	 * Missing color meta falls back to a white background and black text.
	 */
	public function test_defaults_when_meta_absent() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( '#ffffff', $theme['styles']['color']['background'] );
		$this->assertSame( '#000000', $theme['styles']['color']['text'] );
	}

	/**
	 * Invalid or unsafe color meta is rejected and falls back to defaults.
	 */
	public function test_invalid_color_meta_falls_back_to_defaults() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'background_color', 'red; body{display:none}' );
		update_post_meta( $post_id, 'text_color', 'not-a-hex' );

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( '#ffffff', $theme['styles']['color']['background'] );
		$this->assertSame( '#000000', $theme['styles']['color']['text'] );
	}

	/**
	 * Remove options mutated by tests so they never leak between cases.
	 */
	public function tear_down() {
		delete_option( 'newspack_newsletters_color_palette' );
		parent::tear_down();
	}

	/**
	 * With no palette configured, the palette key is omitted so the merge does
	 * not wipe the editor's default color presets.
	 */
	public function test_omits_palette_when_option_unconfigured() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertArrayNotHasKey( 'color', $theme['settings'] );
	}

	/**
	 * Palette entries with invalid hex values are skipped.
	 */
	public function test_palette_skips_invalid_hex_entries() {
		update_option(
			'newspack_newsletters_color_palette',
			wp_json_encode(
				[
					'good' => '#112233',
					'bad'  => 'not-a-hex',
				]
			)
		);
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertNotNull( $this->find_preset( $theme['settings']['color']['palette'], 'good' ) );
		$this->assertNull( $this->find_preset( $theme['settings']['color']['palette'], 'bad' ) );
	}

	/**
	 * Find a preset entry by its slug.
	 *
	 * @param array  $presets Theme.json preset array (palette/fontSizes/spacingSizes).
	 * @param string $slug    Slug to find.
	 * @return array|null
	 */
	private function find_preset( $presets, $slug ) {
		foreach ( (array) $presets as $preset ) {
			if ( isset( $preset['slug'] ) && $slug === $preset['slug'] ) {
				return $preset;
			}
		}
		return null;
	}

	/**
	 * The newsletter color palette option is injected as the theme color palette.
	 */
	public function test_injects_color_palette_from_option() {
		update_option(
			'newspack_newsletters_color_palette',
			wp_json_encode(
				[
					'primary'   => '#003da5',
					'secondary' => '#112233',
				]
			)
		);
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$primary = $this->find_preset( $theme['settings']['color']['palette'], 'primary' );
		$this->assertNotNull( $primary );
		$this->assertSame( '#003da5', $primary['color'] );
	}

	/**
	 * The Newspack font-size scale is injected (e.g. small resolves to 12px).
	 */
	public function test_injects_newspack_font_size_scale() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$small = $this->find_preset( $theme['settings']['typography']['fontSizes'], 'small' );
		$this->assertNotNull( $small );
		$this->assertSame( '12px', $small['size'] );
	}

	/**
	 * The Newspack spacing scale is injected (e.g. preset 50 resolves to 32px).
	 */
	public function test_injects_newspack_spacing_scale() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$fifty = $this->find_preset( $theme['settings']['spacing']['spacingSizes'], '50' );
		$this->assertNotNull( $fifty );
		$this->assertSame( '32px', $fifty['size'] );
	}

	/**
	 * Fluid typography is disabled so font sizes resolve to fixed pixel values.
	 */
	public function test_disables_fluid_typography() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertFalse( $theme['settings']['typography']['fluid'] );
	}

	/**
	 * Supported font_header/font_body meta map to heading and body font families.
	 */
	public function test_maps_fonts_from_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'font_header', 'Georgia, serif' );
		update_post_meta( $post_id, 'font_body', 'Verdana, sans-serif' );

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( 'Verdana, sans-serif', $theme['styles']['typography']['fontFamily'] );
		$this->assertSame( 'Georgia, serif', $theme['styles']['elements']['heading']['typography']['fontFamily'] );
	}

	/**
	 * Unsupported or empty font meta falls back to the default font stacks.
	 */
	public function test_font_meta_falls_back_to_defaults_when_unsupported() {
		if ( function_exists( 'newspack_font_stack' ) ) {
			$this->markTestSkipped( 'Theme font fn present; unsupported meta resolves to the theme stack, not the hardcoded fallback.' );
		}
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'font_header', 'Comic Sans' );
		update_post_meta( $post_id, 'font_body', '' );

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( 'Arial, Helvetica, sans-serif', $theme['styles']['elements']['heading']['typography']['fontFamily'] );
		$this->assertSame( 'Georgia, serif', $theme['styles']['typography']['fontFamily'] );
	}

	/**
	 * Flag on: builder emits styles.elements.button.border.radius as a px string.
	 *
	 * The test environment runs with the default (classic) theme which defines no
	 * button border-radius, so the fallback Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS
	 * ("4px") is expected.
	 */
	public function test_flag_on_emits_button_border_radius_as_px() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$theme = Theme_Json_Builder::build( get_post( self::factory()->post->create() ) );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		$radius = $theme['styles']['elements']['button']['border']['radius'] ?? null;
		$this->assertNotNull( $radius, 'button border-radius must be present when flag is on' );
		// Must be a px string (integer or decimal, e.g. "4px" or "4.5px").
		$this->assertMatchesRegularExpression( '/^\d+(?:\.\d+)?px$/', $radius, 'border-radius must be a px value' );
		// Classic/default theme has no button radius → fallback is 4px.
		$this->assertSame( '4px', $radius );
	}

	/**
	 * Flag on: builder emits no button color (only border.radius; padding only when theme defines it).
	 *
	 * The test environment runs with the default (classic) theme which defines no button padding
	 * in theme.json, so no spacing key should be emitted for that theme.
	 */
	public function test_flag_on_does_not_emit_button_color() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$theme = Theme_Json_Builder::build( get_post( self::factory()->post->create() ) );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		$button = $theme['styles']['elements']['button'] ?? [];
		$this->assertArrayNotHasKey( 'color', $button, 'button color must not be emitted by the builder' );
	}

	/**
	 * Flag on, classic/default theme (no button padding in theme.json): spacing key is absent.
	 *
	 * When the active theme defines no `styles.elements.button.spacing.padding`,
	 * the builder must not emit a spacing key — leaving classic-theme renders unchanged.
	 */
	public function test_flag_on_omits_spacing_when_theme_defines_no_button_padding() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$theme = Theme_Json_Builder::build( get_post( self::factory()->post->create() ) );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		$button = $theme['styles']['elements']['button'] ?? [];
		$this->assertArrayNotHasKey( 'spacing', $button, 'spacing/padding must not be emitted when the theme defines none' );
	}

	/**
	 * Flag off: builder emits no button element at all (unchanged behavior).
	 */
	public function test_flag_off_does_not_inject_button() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		$theme = Theme_Json_Builder::build( get_post( self::factory()->post->create() ) );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		$this->assertArrayNotHasKey( 'button', $theme['styles']['elements'] ?? [] );
	}

	/**
	 * Helper: call the protected resolve_button_border_radius_from_raw() method.
	 *
	 * @param array $raw Raw theme.json data to pass to the resolver.
	 * @return string Resolved radius string.
	 */
	private function resolve_radius( array $raw ): string {
		$method = new \ReflectionMethod( Theme_Json_Builder::class, 'resolve_button_border_radius_from_raw' );
		$method->setAccessible( true );
		return $method->invoke( null, $raw );
	}

	/**
	 * Helper: call the protected resolve_button_padding_from_raw() method.
	 *
	 * @param array $raw Raw theme.json data to pass to the resolver.
	 * @return array Resolved padding map (side => px).
	 */
	private function resolve_padding( array $raw ): array {
		$method = new \ReflectionMethod( Theme_Json_Builder::class, 'resolve_button_padding_from_raw' );
		$method->setAccessible( true );
		return $method->invoke( null, $raw );
	}

	/**
	 * Helper: call the protected resolve_length_to_px() method.
	 *
	 * @param string $value CSS length string.
	 * @param array  $raw   Raw theme.json data.
	 * @return string|null Resolved px string or null.
	 */
	private function resolve_length( string $value, array $raw = [] ): ?string {
		$method = new \ReflectionMethod( Theme_Json_Builder::class, 'resolve_length_to_px' );
		$method->setAccessible( true );
		return $method->invoke( null, $value, $raw );
	}

	/**
	 * Resolver: var( --wp--custom--border--radius-medium ) → 0.375rem → 6px.
	 *
	 * Exercises the full var-resolution + rem→px path without needing a live theme.
	 */
	public function test_resolver_resolves_var_rem_to_px() {
		$raw = [
			'styles'   => [
				'elements' => [
					'button' => [
						'border' => [
							'radius' => 'var( --wp--custom--border--radius-medium )',
						],
					],
				],
			],
			'settings' => [
				'custom' => [
					'border' => [
						'radius-medium' => '0.375rem',
					],
				],
			],
		];

		$this->assertSame( '6px', $this->resolve_radius( $raw ) );
	}

	/**
	 * A non-px value like "50%" is not email-safe and must fall back to the default.
	 *
	 * Covers the Important fix: the final guard that prevents pass-through of
	 * non-px values (percentages, vw, unresolvable vars, etc.).
	 */
	public function test_resolver_falls_back_for_non_px_value() {
		$raw = [
			'styles' => [
				'elements' => [
					'button' => [
						'border' => [
							'radius' => '50%',
						],
					],
				],
			],
		];

		$this->assertSame( '4px', $this->resolve_radius( $raw ) );
	}

	/**
	 * A genuine px value from a theme must pass through unchanged.
	 *
	 * Verifies the final guard allows real px values (e.g. "8px", "4.5px").
	 */
	public function test_resolver_passes_through_px_value() {
		$raw = [
			'styles' => [
				'elements' => [
					'button' => [
						'border' => [
							'radius' => '8px',
						],
					],
				],
			],
		];

		$this->assertSame( '8px', $this->resolve_radius( $raw ) );
	}

	// -----------------------------------------------------------------------
	// resolve_length_to_px shared helper tests
	// -----------------------------------------------------------------------

	/**
	 * Length resolver: plain rem converts to px (× 16).
	 */
	public function test_length_resolver_converts_rem_to_px() {
		$this->assertSame( '12px', $this->resolve_length( '0.75rem' ) );
		$this->assertSame( '24px', $this->resolve_length( '1.5rem' ) );
	}

	/**
	 * Length resolver: plain px passes through unchanged.
	 */
	public function test_length_resolver_passes_through_px() {
		$this->assertSame( '24px', $this->resolve_length( '24px' ) );
	}

	/**
	 * Length resolver: non-px/non-rem value (e.g. percent) returns null.
	 */
	public function test_length_resolver_returns_null_for_percent() {
		$this->assertNull( $this->resolve_length( '50%' ) );
	}

	/**
	 * Length resolver: preset spacing var resolves via SPACING_SIZES fallback.
	 *
	 * `var( --wp--preset--spacing--40 )` → slug "40" → "24px" via Theme_Json_Builder::SPACING_SIZES.
	 */
	public function test_length_resolver_resolves_preset_spacing_var() {
		$this->assertSame( '24px', $this->resolve_length( 'var( --wp--preset--spacing--40 )' ) );
	}

	/**
	 * Length resolver: preset spacing var resolves via raw theme.json spacingSizes.
	 *
	 * When the raw data contains the slug in `settings.spacing.spacingSizes`,
	 * that size value takes priority (can be a rem that further converts to px).
	 */
	public function test_length_resolver_resolves_preset_spacing_var_from_raw() {
		$raw = [
			'settings' => [
				'spacing' => [
					'spacingSizes' => [
						[
							'slug' => '40',
							'size' => '1.5rem',
							'name' => '40',
						],
					],
				],
			],
		];
		$this->assertSame( '24px', $this->resolve_length( 'var( --wp--preset--spacing--40 )', $raw ) );
	}

	/**
	 * Length resolver: fractional rem converts to fractional px.
	 *
	 * `0.28125rem` = 4.5 px — the fractional result must be preserved, not truncated.
	 */
	public function test_length_resolver_converts_fractional_rem_to_fractional_px() {
		$this->assertSame( '4.5px', $this->resolve_length( '0.28125rem' ) );
	}

	/**
	 * Length resolver: preset spacing var whose theme size is a clamp() (fluid)
	 * falls back to the email-safe SPACING_SIZES map, not null.
	 *
	 * Newspack-block-theme spacingSizes entries 60/70/80 use `clamp(...)` values.
	 * Feeding such a size to the resolver must return the email-safe fallback px
	 * value from SPACING_SIZES, not drop the side entirely.
	 */
	public function test_length_resolver_clamp_preset_falls_back_to_spacing_sizes() {
		// Simulate a block-theme spacingSizes entry with a fluid clamp value.
		$raw = [
			'settings' => [
				'spacing' => [
					'spacingSizes' => [
						[
							'slug' => '60',
							'size' => 'clamp( 2rem, 3vw + 1rem, 4rem )',
							'name' => '6',
						],
					],
				],
			],
		];

		// Slug '60' maps to '32px' in SPACING_SIZES.
		$this->assertSame(
			Theme_Json_Builder::SPACING_SIZES['60'],
			$this->resolve_length( 'var( --wp--preset--spacing--60 )', $raw ),
			'A fluid clamp() theme size must fall back to the email-safe SPACING_SIZES value.'
		);
	}

	/**
	 * Length resolver: preset spacing var whose theme size has a missing 'size' key
	 * falls back to the SPACING_SIZES map (no PHP notice).
	 */
	public function test_length_resolver_missing_size_key_falls_back_to_spacing_sizes() {
		// Malformed entry: 'size' key absent.
		$raw = [
			'settings' => [
				'spacing' => [
					'spacingSizes' => [
						[
							'slug' => '40',
							'name' => 'Forty',
							// 'size' intentionally omitted.
						],
					],
				],
			],
		];

		// Should fall back to SPACING_SIZES['40'] = '24px'.
		$this->assertSame(
			Theme_Json_Builder::SPACING_SIZES['40'],
			$this->resolve_length( 'var( --wp--preset--spacing--40 )', $raw ),
			'Missing "size" key must fall back to the email-safe SPACING_SIZES value.'
		);
	}

	/**
	 * Length resolver: custom spacing var resolves via settings.custom path.
	 *
	 * `var( --wp--custom--spacing--25 )` → `settings.custom.spacing.25` → "0.75rem" → 12px.
	 */
	public function test_length_resolver_resolves_custom_spacing_var() {
		$raw = [
			'settings' => [
				'custom' => [
					'spacing' => [
						'25' => '0.75rem',
					],
				],
			],
		];
		$this->assertSame( '12px', $this->resolve_length( 'var( --wp--custom--spacing--25 )', $raw ) );
	}

	// -----------------------------------------------------------------------
	// resolve_button_padding_from_raw tests
	// -----------------------------------------------------------------------

	/**
	 * Padding resolver: no button padding defined → returns empty array (no emit).
	 */
	public function test_padding_resolver_returns_empty_when_no_padding_defined() {
		$raw = [
			'styles' => [
				'elements' => [
					'button' => [
						'border' => [
							'radius' => '4px',
						],
					],
				],
			],
		];
		$this->assertSame( [], $this->resolve_padding( $raw ) );
	}

	/**
	 * Padding resolver (block-theme scenario): custom var top/bottom → 12px, preset var left/right → 24px.
	 *
	 * `var( --wp--custom--spacing--25 )` top/bottom and `var( --wp--preset--spacing--40 )` left/right.
	 * This is the core parity test: before the fix, all sides resolved to 24px.
	 * After the fix, top/bottom = 12px, left/right = 24px.
	 */
	public function test_padding_resolver_block_theme_scenario() {
		$raw = [
			'styles'   => [
				'elements' => [
					'button' => [
						'spacing' => [
							'padding' => [
								'top'    => 'var( --wp--custom--spacing--25 )',
								'bottom' => 'var( --wp--custom--spacing--25 )',
								'left'   => 'var( --wp--preset--spacing--40 )',
								'right'  => 'var( --wp--preset--spacing--40 )',
							],
						],
					],
				],
			],
			'settings' => [
				'custom'  => [
					'spacing' => [
						'25' => '0.75rem', // 12px.
					],
				],
				'spacing' => [
					'spacingSizes' => [
						[
							'slug' => '40',
							'size' => '1.5rem', // 24px.
							'name' => '40',
						],
					],
				],
			],
		];

		$padding = $this->resolve_padding( $raw );

		$this->assertSame( '12px', $padding['top'], 'top padding (custom var 0.75rem) must be 12px' );
		$this->assertSame( '12px', $padding['bottom'], 'bottom padding (custom var 0.75rem) must be 12px' );
		$this->assertSame( '24px', $padding['left'], 'left padding (preset spacing 40 = 1.5rem) must be 24px' );
		$this->assertSame( '24px', $padding['right'], 'right padding (preset spacing 40 = 1.5rem) must be 24px' );
	}

	/**
	 * Padding resolver: sides with unresolvable values are omitted; resolvable sides remain.
	 */
	public function test_padding_resolver_omits_unresolvable_sides() {
		$raw = [
			'styles' => [
				'elements' => [
					'button' => [
						'spacing' => [
							'padding' => [
								'top'    => '8px',
								'bottom' => '50%', // Not email-safe, must be omitted.
								'left'   => '16px',
							],
						],
					],
				],
			],
		];

		$padding = $this->resolve_padding( $raw );

		$this->assertSame( '8px', $padding['top'] );
		$this->assertArrayNotHasKey( 'bottom', $padding, 'non-px side must be omitted' );
		$this->assertSame( '16px', $padding['left'] );
		$this->assertArrayNotHasKey( 'right', $padding, 'absent side must be omitted' );
	}

	/**
	 * Flag on, block-theme mock: build() emits spacing.padding with correct px values.
	 *
	 * A mock theme injected via wp_theme_json_data_theme filter provides the same
	 * var structure as the real newspack-block-theme. No live theme required.
	 */
	public function test_build_emits_button_padding_for_block_theme_scenario() {
		// Inject a mock theme that defines button padding with the block-theme var values.
		$inject_padding = function ( $theme_json ) {
			return $theme_json->update_with(
				[
					'version'  => 3,
					'styles'   => [
						'elements' => [
							'button' => [
								'spacing' => [
									'padding' => [
										'top'    => 'var( --wp--custom--spacing--25 )',
										'bottom' => 'var( --wp--custom--spacing--25 )',
										'left'   => 'var( --wp--preset--spacing--40 )',
										'right'  => 'var( --wp--preset--spacing--40 )',
									],
								],
							],
						],
					],
					'settings' => [
						'custom'  => [
							'spacing' => [
								'25' => '0.75rem',
							],
						],
						'spacing' => [
							'spacingSizes' => [
								[
									'slug' => '40',
									'size' => '1.5rem',
									'name' => '40',
								],
							],
						],
					],
				]
			);
		};

		add_filter( 'wp_theme_json_data_theme', $inject_padding );
		// Clear the resolver's static cache so the filter above is picked up.
		\WP_Theme_JSON_Resolver::clean_cached_data();
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		// try/finally so the global filters + resolver cache are always cleaned up,
		// even if build() throws — otherwise leaked state cascades into other tests.
		try {
			$theme = Theme_Json_Builder::build( get_post( self::factory()->post->create() ) );
		} finally {
			remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
			remove_filter( 'wp_theme_json_data_theme', $inject_padding );
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}

		$padding = $theme['styles']['elements']['button']['spacing']['padding'] ?? null;

		$this->assertNotNull( $padding, 'spacing.padding must be present when theme defines button padding' );
		$this->assertSame( '12px', $padding['top'], 'top padding must be 12px' );
		$this->assertSame( '12px', $padding['bottom'], 'bottom padding must be 12px' );
		$this->assertSame( '24px', $padding['left'], 'left padding must be 24px' );
		$this->assertSame( '24px', $padding['right'], 'right padding must be 24px' );
	}
}
