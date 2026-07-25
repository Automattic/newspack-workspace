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
	 * A test's theme.json data is cached for the whole request, so drop it.
	 */
	public function tear_down() {
		remove_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'add_numeric_font_size_preset' ] );
		WP_Theme_JSON_Resolver::clean_cached_data();
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
		$this->assertStringContainsString( 'site-editor.php', $data['site_editor_styles_url'] );
	}

	/**
	 * A theme.json font size preset may be a bare number; core only unit-izes the
	 * ones registered through add_theme_support. The payload must carry strings
	 * either way: the picker hands back the shape it was given, and the style
	 * sanitizer drops anything that is not a string.
	 */
	public function test_status_normalizes_numeric_font_size_presets() {
		add_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'add_numeric_font_size_preset' ] );
		WP_Theme_JSON_Resolver::clean_cached_data();

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
	 * Presets are merged across origins: a shared slug is taken from the highest
	 * origin defining it, and a slug only a lower origin defines still travels.
	 */
	public function test_presets_merge_across_origins() {
		$merged = Newspack_Popups_API::flatten_global_settings_presets(
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
	 * Nothing to offer travels as an empty list, never as a list of junk. An
	 * already-flat list passes through untouched.
	 */
	public function test_presets_edge_shapes() {
		$this->assertSame(
			[],
			Newspack_Popups_API::flatten_global_settings_presets(
				[
					'default' => [],
					'theme'   => [],
					'custom'  => [],
				]
			)
		);
		// A missing settings path hands back the whole settings tree, which is not a
		// preset list.
		$this->assertSame(
			[],
			Newspack_Popups_API::flatten_global_settings_presets( [ 'typography' => [ 'fontSizes' => [] ] ] )
		);
		$this->assertSame( [], Newspack_Popups_API::flatten_global_settings_presets( 'not an array' ) );

		$flat = [
			[
				'slug' => 'small',
				'size' => '13px',
			],
		];
		$this->assertSame( $flat, Newspack_Popups_API::flatten_global_settings_presets( $flat ) );
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
