<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Contextual Prompt Styles API Test
 *
 * The wizard's Style section rides the existing status/profile endpoints:
 * status carries the style payload, profile save carries optional overrides.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt styles API test case.
 */
class ContextualPromptStylesApiTest extends WP_UnitTestCase {
	/**
	 * Admin user, feature opted in, routes registered.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		delete_option( Newspack_Popups_Contextual_Prompt_Styles::OPTION_NAME );
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init' );
	}

	/**
	 * A test's theme.json data is cached for the whole request — resolved settings
	 * in the theme_json cache group too — so drop all of it.
	 */
	public function tear_down() {
		remove_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'add_numeric_font_size_preset' ] );
		remove_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'disable_default_palette' ] );
		remove_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'disable_default_spacing_sizes' ] );
		remove_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'restrict_spacing_policy' ] );
		remove_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'scope_spacing_policy_to_the_block' ] );
		remove_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'scope_palette_to_the_block' ] );
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * Status carries the style payload.
	 */
	public function test_status_includes_style_payload() {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'is_block_theme', $data );
		// No overrides travels as an empty object, never an empty array.
		$this->assertEquals( (object) [], $data['styles'] );
		$this->assertSame( '{}', wp_json_encode( $data['styles'] ) );
		$this->assertSame( '#f7f7f7', $data['style_defaults']['color']['background'] );
		$this->assertNotEmpty( $data['style_palette'] );
		$this->assertArrayHasKey( 'color', $data['style_palette'][0] );
		$this->assertNotEmpty( $data['style_font_sizes'] );
		$this->assertArrayHasKey( 'size', $data['style_font_sizes'][0] );
		// The padding rows step through the site's spacing presets, so they travel
		// with the name each step shows and the size a resolved default matches on.
		$this->assertNotEmpty( $data['style_spacing_sizes'] );
		foreach ( [ 'name', 'slug', 'size' ] as $key ) {
			$this->assertArrayHasKey( $key, $data['style_spacing_sizes'][0] );
		}
		$this->assertStringContainsString( 'site-editor.php', $data['site_editor_styles_url'] );
	}

	/**
	 * The padding rows also carry the site's spacing policy: whether a custom value
	 * may be given at all, and in which units. Core's own spacing control leaves its
	 * rows preset-only when `spacing.customSpacingSize` is off and filters its unit
	 * list by `spacing.units`, so the wizard's rows can honor the same policy.
	 */
	public function test_status_carries_the_spacing_policy() {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) )->get_data();

		// Core's own defaults for both settings: custom values allowed, offered in the
		// units core's theme.json declares.
		$this->assertTrue( $data['style_spacing_custom'] );
		$this->assertContains( 'px', $data['style_spacing_units'] );
		// A flat list of unit strings, which is all a unit control can read.
		$this->assertSame( array_values( $data['style_spacing_units'] ), $data['style_spacing_units'] );
		$this->assertSame( $data['style_spacing_units'], array_filter( $data['style_spacing_units'], 'is_string' ) );

		add_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'restrict_spacing_policy' ] );
		wp_clean_theme_json_cache();

		$data = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) )->get_data();

		$this->assertFalse( $data['style_spacing_custom'] );
		$this->assertSame( [ 'px', 'em' ], $data['style_spacing_units'] );
	}

	/**
	 * Turn custom spacing values off and narrow the units, as a theme.json may.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function restrict_spacing_policy( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => 2,
				'settings' => [
					'spacing' => [
						'customSpacingSize' => false,
						'units'             => [ 'px', 'em' ],
					],
				],
			]
		);
	}

	/**
	 * The policy is read for the block, not just for the site: the editor resolves
	 * `settings.blocks.<block>` first and falls back to the global scope, so a theme
	 * scoping a spacing policy to the Contextual Prompt block is the policy the
	 * payload carries.
	 */
	public function test_status_carries_a_block_scoped_spacing_policy() {
		add_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'scope_spacing_policy_to_the_block' ] );
		wp_clean_theme_json_cache();

		// The premise: the two scopes disagree, and the block scope really is there.
		$block_context = [ 'block_name' => Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ];
		$this->assertTrue( wp_get_global_settings( [ 'spacing', 'customSpacingSize' ] ) );
		$this->assertFalse( wp_get_global_settings( [ 'spacing', 'customSpacingSize' ], $block_context ) );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) )->get_data();

		$this->assertFalse( $data['style_spacing_custom'] );
		$this->assertSame( [ 'rem' ], $data['style_spacing_units'] );
	}

	/**
	 * Turn custom spacing values off and narrow the units for the block alone, while
	 * the site keeps allowing both.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function scope_spacing_policy_to_the_block( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => 2,
				'settings' => [
					'spacing' => [
						'customSpacingSize' => true,
						'units'             => [ 'px', 'em' ],
					],
					'blocks'  => [
						Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME => [
							'spacing' => [
								'customSpacingSize' => false,
								'units'             => [ 'rem' ],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * A palette scoped to the block stands in for the site's, as the editor's own
	 * pickers resolve it for the block first.
	 */
	public function test_status_carries_a_block_scoped_palette() {
		add_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'scope_palette_to_the_block' ] );
		wp_clean_theme_json_cache();

		$palette = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) )->get_data()['style_palette'];
		$by_slug = wp_list_pluck( $palette, 'color', 'slug' );

		$this->assertSame( '#0a0a0a', $by_slug['prompt-only'] );
		$this->assertArrayNotHasKey( 'site-only', $by_slug );
	}

	/**
	 * Give the block its own color preset while the site registers another.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function scope_palette_to_the_block( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => 2,
				'settings' => [
					'color'  => [
						'palette' => [
							[
								'name'  => 'Site only',
								'slug'  => 'site-only',
								'color' => '#f0f0f0',
							],
						],
					],
					'blocks' => [
						Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME => [
							'color' => [
								'palette' => [
									[
										'name'  => 'Prompt only',
										'slug'  => 'prompt-only',
										'color' => '#0a0a0a',
									],
								],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * A theme.json font size preset may be a bare number; core only unit-izes the
	 * ones registered through add_theme_support. The payload must carry strings
	 * either way: the picker hands back the shape it was given, and the style
	 * sanitizer drops anything that is not a string.
	 */
	public function test_status_normalizes_numeric_font_size_presets() {
		add_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'add_numeric_font_size_preset' ] );
		wp_clean_theme_json_cache();

		// The premise: the number does survive into the global settings.
		$settings = wp_get_global_settings( [ 'typography', 'fontSizes' ] );
		$this->assertSame( 21, $settings['theme'][0]['size'] );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) );
		$sizes    = wp_list_pluck( $response->get_data()['style_font_sizes'], 'size', 'slug' );

		$this->assertSame( '21px', $sizes['numeric'] );
	}

	/**
	 * Add a font size preset carrying a number rather than a CSS string.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function add_numeric_font_size_preset( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => 2,
				'settings' => [
					'typography' => [
						'fontSizes' => [
							[
								'name' => 'Numeric',
								'slug' => 'numeric',
								'size' => 21,
							],
						],
					],
				],
			]
		);
	}

	/**
	 * The palette is merged across origins: a shared slug is taken from the highest
	 * origin defining it, and a slug only a lower origin defines still travels.
	 */
	public function test_palette_presets_merge_across_origins() {
		$merged = Newspack_Popups_API::merge_global_settings_presets(
			[
				'default' => [
					[
						'slug'  => 'base',
						'color' => '#ffffff',
					],
					[
						'slug'  => 'contrast',
						'color' => '#000000',
					],
				],
				'theme'   => [
					[
						'slug'  => 'base',
						'color' => '#fefefe',
					],
					[
						'slug'  => 'accent',
						'color' => '#178f15',
					],
				],
				'custom'  => [
					[
						'slug'  => 'accent',
						'color' => '#123456',
					],
				],
			]
		);
		$by_slug = wp_list_pluck( $merged, 'color', 'slug' );

		$this->assertCount( 3, $merged );
		$this->assertSame( '#123456', $by_slug['accent'] );
		$this->assertSame( '#fefefe', $by_slug['base'] );
		$this->assertSame( '#000000', $by_slug['contrast'] );
	}

	/**
	 * The default origin travels only while the editor shows it: core turns
	 * `color.defaultPalette` off for any classic theme registering an
	 * editor-color-palette, and the wizard must not offer colors the editor hides.
	 */
	public function test_palette_drops_the_default_origin_when_the_editor_hides_it() {
		$default_slugs = wp_list_pluck( wp_get_global_settings( [ 'color', 'palette' ] )['default'], 'slug' );
		$this->assertNotEmpty( $default_slugs );

		$palette = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) )->get_data()['style_palette'];
		$this->assertNotEmpty( array_intersect( $default_slugs, wp_list_pluck( $palette, 'slug' ) ) );

		add_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'disable_default_palette' ] );
		wp_clean_theme_json_cache();
		// The premise: the presets are still there, the editor just hides them.
		$this->assertFalse( wp_get_global_settings( [ 'color', 'defaultPalette' ] ) );
		$this->assertNotEmpty( wp_get_global_settings( [ 'color', 'palette' ] )['default'] );

		$palette = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) )->get_data()['style_palette'];
		$this->assertSame( [], array_intersect( $default_slugs, wp_list_pluck( $palette, 'slug' ) ) );
	}

	/**
	 * Hide the default color palette, as core does for a classic theme that
	 * registers its own.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function disable_default_palette( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => 2,
				'settings' => [ 'color' => [ 'defaultPalette' => false ] ],
			]
		);
	}

	/**
	 * Font sizes are not merged: the editor's picker offers a single origin, the
	 * highest one holding anything, so the wizard offers the same set.
	 */
	public function test_font_size_presets_take_the_highest_origin() {
		$presets = [
			'default' => [
				[
					'slug' => 'medium',
					'size' => '20px',
				],
			],
			'theme'   => [
				[
					'slug' => 'normal',
					'size' => '20px',
				],
				[
					'slug' => 'huge',
					'size' => '44px',
				],
			],
		];

		$this->assertSame( $presets['theme'], Newspack_Popups_API::flatten_global_settings_presets( $presets ) );

		// With that origin empty the next one down stands in.
		$presets['theme'] = [];
		$this->assertSame( $presets['default'], Newspack_Popups_API::flatten_global_settings_presets( $presets ) );
	}

	/**
	 * Spacing sizes are one scale built from every origin, custom beating theme
	 * beating default on a shared slug, and ordered by slug as numbers rather than
	 * by the origin that happened to define each step.
	 */
	public function test_spacing_presets_combine_origins_in_slug_order() {
		$presets = [
			'default' => [
				[
					'slug' => '70',
					'size' => '5.06rem',
				],
				[
					'slug' => '80',
					'size' => '6.75rem',
				],
			],
			'theme'   => [
				[
					'slug' => '20',
					'size' => '10px',
				],
				[
					'slug' => '40',
					'size' => '20px',
				],
			],
			'custom'  => [
				[
					'slug' => '40',
					'size' => '24px',
				],
				[
					'slug' => '60',
					'size' => '30px',
				],
			],
		];

		$sizes = Newspack_Popups_API::sort_spacing_size_presets( Newspack_Popups_API::merge_global_settings_presets( $presets ) );

		$this->assertSame( [ '20', '40', '60', '70', '80' ], wp_list_pluck( $sizes, 'slug' ) );
		$this->assertSame( '24px', wp_list_pluck( $sizes, 'size', 'slug' )['40'] );

		// A named step leaves the scale in its origin order, which is what core's own
		// control does rather than sorting a slug that carries no number.
		$presets['theme'][] = [
			'slug' => 'huge',
			'size' => '9rem',
		];
		$sizes              = Newspack_Popups_API::sort_spacing_size_presets( Newspack_Popups_API::merge_global_settings_presets( $presets ) );

		$this->assertSame( [ '40', '60', '20', 'huge', '70', '80' ], wp_list_pluck( $sizes, 'slug' ) );
	}

	/**
	 * The default origin travels only while the editor shows it: core turns
	 * `spacing.defaultSpacingSizes` off for a theme registering its own scale, and
	 * the wizard must not offer steps the editor hides.
	 */
	public function test_spacing_presets_drop_the_default_origin_when_the_editor_hides_it() {
		$default_slugs = wp_list_pluck( wp_get_global_settings( [ 'spacing', 'spacingSizes' ] )['default'], 'slug' );
		$this->assertNotEmpty( $default_slugs );

		$sizes = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) )->get_data()['style_spacing_sizes'];
		$this->assertNotEmpty( array_intersect( $default_slugs, wp_list_pluck( $sizes, 'slug' ) ) );

		add_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'disable_default_spacing_sizes' ] );
		wp_clean_theme_json_cache();
		// The premise: the presets are still there, the editor just hides them.
		$this->assertFalse( wp_get_global_settings( [ 'spacing', 'defaultSpacingSizes' ] ) );
		$this->assertNotEmpty( wp_get_global_settings( [ 'spacing', 'spacingSizes' ] )['default'] );

		$sizes = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) )->get_data()['style_spacing_sizes'];
		$slugs = wp_list_pluck( $sizes, 'slug' );

		$this->assertSame( [], array_intersect( $default_slugs, $slugs ) );
		// The theme's own step stands, so the scale is hidden defaults rather than
		// nothing at all.
		$this->assertSame( [ '15' ], $slugs );
	}

	/**
	 * Hide the default spacing scale behind a theme's own, as core does for a theme
	 * registering spacing sizes of its own.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function disable_default_spacing_sizes( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => 2,
				'settings' => [
					'spacing' => [
						'defaultSpacingSizes' => false,
						'spacingSizes'        => [
							[
								'name' => 'Tiny',
								'slug' => '15',
								'size' => '4px',
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Nothing to offer travels as an empty list, never as a list of junk. An
	 * already-flat list passes through untouched. Both shapes are read the same way
	 * whether the presets are merged or taken from one origin.
	 */
	public function test_presets_edge_shapes() {
		$empty_origins = [
			'default' => [],
			'theme'   => [],
			'custom'  => [],
		];
		// A missing settings path hands back the whole settings tree, which is not a
		// preset list.
		$settings_tree = [ 'typography' => [ 'fontSizes' => [] ] ];
		$flat          = [
			[
				'slug' => 'small',
				'size' => '13px',
			],
		];

		foreach ( [ 'flatten_global_settings_presets', 'merge_global_settings_presets' ] as $method ) {
			$this->assertSame( [], Newspack_Popups_API::$method( $empty_origins ), $method );
			$this->assertSame( [], Newspack_Popups_API::$method( $settings_tree ), $method );
			$this->assertSame( [], Newspack_Popups_API::$method( 'not an array' ), $method );
			$this->assertSame( $flat, Newspack_Popups_API::$method( $flat ), $method );
		}
	}

	/**
	 * Profile save with styles persists them and echoes them in the response.
	 */
	public function test_save_profile_with_styles() {
		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt/profile' );
		$request->set_body_params(
			[
				'fields' => [],
				'styles' => [
					'color' => [ 'background' => '#123456' ],
					'evil'  => [ 'x' => 'y' ],
				],
			]
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( '#123456', $data['styles']['color']['background'] );
		$this->assertArrayNotHasKey( 'evil', $data['styles'] );
		$this->assertSame(
			[ 'color' => [ 'background' => '#123456' ] ],
			Newspack_Popups_Contextual_Prompt_Styles::get_styles()
		);
	}

	/**
	 * An invalid styles branch fails the whole request before any option write:
	 * no profile field is persisted and the saved styles stand.
	 */
	public function test_save_profile_with_invalid_styles_writes_nothing() {
		Newspack_Popups_Contextual_Prompt_Styles::save_styles( [ 'color' => [ 'text' => '#fedcba' ] ] );

		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt/profile' );
		$request->set_body_params(
			[
				'fields' => [ 'newspack_contextual_prompts_override_label' => 'Give now' ],
				'styles' => [ 'evil' => [ 'x' => 'y' ] ],
			]
		);
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', get_option( 'newspack_contextual_prompts_override_label', '' ) );
		$this->assertSame(
			[ 'color' => [ 'text' => '#fedcba' ] ],
			Newspack_Popups_Contextual_Prompt_Styles::get_styles()
		);
	}

	/**
	 * Omitting styles leaves the stored option untouched; an explicit empty
	 * object clears it.
	 */
	public function test_save_profile_styles_semantics() {
		Newspack_Popups_Contextual_Prompt_Styles::save_styles( [ 'color' => [ 'text' => '#fedcba' ] ] );

		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt/profile' );
		$request->set_body_params( [ 'fields' => [] ] );
		rest_do_request( $request );
		$this->assertNotEmpty( Newspack_Popups_Contextual_Prompt_Styles::get_styles() );

		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt/profile' );
		$request->set_body_params(
			[
				'fields' => [],
				'styles' => [],
			]
		);
		rest_do_request( $request );
		$this->assertSame( [], Newspack_Popups_Contextual_Prompt_Styles::get_styles() );
	}
}
