<?php
/**
 * Global styles tests.
 *
 * @package Newspack_Block_Theme
 */

/**
 * Guards the shape of the theme's global styles and how core resolves them.
 */
class Test_Global_Styles extends WP_UnitTestCase {
	/**
	 * The resolved global styles, with every CSS variable replaced by its value.
	 *
	 * @return array
	 */
	private function resolved_styles(): array {
		return wp_get_global_styles( [], [ 'transforms' => [ 'resolve-variables' ] ] );
	}

	/**
	 * The theme's styles merged with one style variation, with every CSS variable replaced by its value.
	 *
	 * @param string $file Path of the variation file inside `styles/`.
	 * @return array
	 */
	private function resolved_variation_styles( string $file ): array {
		$theme = WP_Theme_JSON_Resolver::get_theme_data();
		$theme->merge( new WP_Theme_JSON( wp_json_file_decode( dirname( __DIR__ ) . '/' . $file, [ 'associative' => true ] ), 'theme' ) );
		$data = WP_Theme_JSON::resolve_variables( $theme )->get_raw_data();
		return $data['styles'] ?? [];
	}

	/**
	 * Every JSON file that defines global styles, relative to the theme root.
	 *
	 * @return array
	 */
	public function style_files(): array {
		$files = [ 'theme.json' => [ 'theme.json' ] ];
		foreach ( glob( dirname( __DIR__ ) . '/styles/*.json' ) as $path ) {
			$files[ 'styles/' . basename( $path ) ] = [ 'styles/' . basename( $path ) ];
		}
		return $files;
	}

	/**
	 * Spaces inside var() stop core's resolver from matching the reference.
	 *
	 * @dataProvider style_files
	 *
	 * @param string $file Path relative to the theme root.
	 */
	public function test_no_spaced_var_references( $file ) {
		$this->assertDoesNotMatchRegularExpression( '/var\(\s|var\([^()]*\s\)/', file_get_contents( dirname( __DIR__ ) . '/' . $file ) );
	}

	/**
	 * A resolver that eats a closing parenthesis leaves unbalanced values behind.
	 *
	 * @dataProvider style_files
	 *
	 * @param string $file Path relative to the theme root.
	 */
	public function test_resolved_values_have_balanced_parentheses( $file ) {
		$styles = $this->styles_for( $file );
		$this->assertNotEmpty( $styles );
		$bad = [];
		array_walk_recursive(
			$styles,
			function ( $value, $key ) use ( &$bad ) {
				if ( is_string( $value ) && substr_count( $value, '(' ) !== substr_count( $value, ')' ) ) {
					$bad[] = $key . ': ' . $value;
				}
			}
		);
		$this->assertSame( [], $bad );
	}

	/**
	 * The button element resolves to literal values newspack-ui can read.
	 */
	public function test_button_element_resolves_to_literals() {
		$button = $this->resolved_styles()['elements']['button'];

		$this->assertSame( $this->palette()['accent'], strtolower( $button['color']['background'] ) );
		$this->assertSame( '0.5rem', $button['border']['radius'] );
		$this->assertStringNotContainsString( 'var(', $button['typography']['fontFamily'] );
		$this->assertStringNotContainsString( 'var(', $button['typography']['fontSize'] );
		$this->assertStringNotContainsString( 'var( ', $button['color']['text'] );
		$this->assertStringContainsString( 'accent-contrast, ' . $this->palette()['base'], strtolower( $button['color']['text'] ) );
	}

	/**
	 * The theme palette keyed by slug, lowercased.
	 *
	 * @return array
	 */
	private function palette(): array {
		return array_map( 'strtolower', wp_list_pluck( wp_get_global_settings( [ 'color', 'palette', 'theme' ] ), 'color', 'slug' ) );
	}

	/**
	 * The raw element declarations of the theme.
	 *
	 * @return array
	 */
	private function raw_elements(): array {
		return WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data()['styles']['elements'];
	}

	/**
	 * The select element declares the same values as text inputs, so the two fields stay alike.
	 */
	public function test_select_element_matches_text_input() {
		$elements = $this->raw_elements();
		$this->assertEquals( $elements['textInput'], $elements['select'] );
	}

	/**
	 * Field elements resolve to literals newspack-ui can read.
	 */
	public function test_field_elements_resolve_to_literals() {
		$elements = $this->resolved_styles()['elements'];
		foreach ( [ 'textInput', 'select' ] as $name ) {
			$this->assertStringNotContainsString( 'var(', $elements[ $name ]['color']['background'] );
			$this->assertStringNotContainsString( 'var(', $elements[ $name ]['border']['color'] );
			$this->assertStringNotContainsString( 'var(', $elements[ $name ]['typography']['fontFamily'] );
		}
	}

	/**
	 * Hover and pressed fills mix the accent toward the base, as newspack-ui does.
	 */
	public function test_button_hover_and_pressed_mix_toward_base() {
		$button = $this->resolved_styles()['elements']['button'];
		$accent = $this->palette()['accent'];

		$this->assertSame( sprintf( 'color-mix( in oklab, %s 85%%, var(--wp--preset--color--base) )', $accent ), strtolower( $button[':hover']['color']['background'] ) );
		$this->assertSame( sprintf( 'color-mix( in oklab, %s 75%%, var(--wp--preset--color--base) )', $accent ), strtolower( $button[':active']['color']['background'] ) );
	}

	/**
	 * The status color roles in theme.json match the derivation's defaults.
	 */
	public function test_status_color_roles_match_defaults() {
		$colors = wp_get_global_settings( [ 'custom', 'color' ] );

		foreach ( Newspack_Block_Theme\Status_Colors::DEFAULTS as $role => $value ) {
			$this->assertArrayHasKey( $role, $colors );
			$this->assertSame( strtolower( $value ), strtolower( $colors[ $role ] ), $role );
		}
	}

	/**
	 * The button mix target defaults to the base preset.
	 */
	public function test_button_mix_target_defaults_to_base() {
		$this->assertSame( 'var(--wp--preset--color--base)', wp_get_global_settings( [ 'custom', 'button', 'mix' ] ) );
	}

	/**
	 * The button text colour does not change between states.
	 */
	public function test_button_text_is_the_same_in_every_state() {
		$button = $this->resolved_styles()['elements']['button'];

		$this->assertSame( $button['color']['text'], $button[':hover']['color']['text'] );
		$this->assertSame( $button['color']['text'], $button[':active']['color']['text'] );
	}

	/**
	 * The state shares match the newspack-ui hover and pressed mixes.
	 */
	public function test_state_shares_match_newspack_ui() {
		$state = wp_get_global_settings( [ 'custom', 'state' ] );

		$this->assertSame( '85%', $state['hover'] );
		$this->assertSame( '75%', $state['pressed'] );
	}

	/**
	 * The resolved state backgrounds keep their parentheses balanced.
	 */
	public function test_resolved_hover_background_is_not_broken() {
		$button = $this->resolved_styles()['elements']['button'];
		foreach ( [ ':hover', ':active' ] as $state ) {
			$value = $button[ $state ]['color']['background'];
			$this->assertSame( substr_count( $value, '(' ), substr_count( $value, ')' ), $value );
			$this->assertStringEndsWith( ')', $value );
		}
	}

	/**
	 * No style variation overrides the button states or inline padding.
	 */
	public function test_no_variation_overrides_button_states() {
		foreach ( glob( dirname( __DIR__ ) . '/styles/*.json' ) as $path ) {
			$button = wp_json_file_decode( $path, [ 'associative' => true ] )['styles']['elements']['button'] ?? [];
			$this->assertArrayNotHasKey( ':hover', $button, basename( $path ) );
			$this->assertArrayNotHasKey( ':active', $button, basename( $path ) );
			$this->assertArrayNotHasKey( 'padding', $button['spacing'] ?? [], basename( $path ) );
		}
	}

	/**
	 * Default buttons use the same inline padding as newspack-ui buttons.
	 */
	public function test_button_inline_padding_matches_newspack_ui() {
		$padding = $this->resolved_styles()['elements']['button']['spacing']['padding'];

		$this->assertSame( '1.25rem', $padding['left'] );
		$this->assertSame( '1.25rem', $padding['right'] );
	}

	/**
	 * The resolved styles for a theme.json or style variation file.
	 *
	 * @param string $file Path relative to the theme root.
	 * @return array
	 */
	private function styles_for( string $file ): array {
		return 'theme.json' === $file ? $this->resolved_styles() : $this->resolved_variation_styles( $file );
	}

	/**
	 * The caption element declares every value newspack-ui reads, as literals.
	 *
	 * @dataProvider style_files
	 *
	 * @param string $file Path relative to the theme root.
	 */
	public function test_caption_element_is_complete( $file ) {
		$caption = $this->styles_for( $file )['elements']['caption'] ?? [];

		foreach ( [ [ 'typography', 'fontFamily' ], [ 'typography', 'fontSize' ], [ 'typography', 'lineHeight' ], [ 'color', 'text' ] ] as $path ) {
			$value = $caption[ $path[0] ][ $path[1] ] ?? '';
			$this->assertNotEmpty( $value, $file . ' ' . implode( '.', $path ) );
			$this->assertStringNotContainsString( 'var(', $value, $file . ' ' . implode( '.', $path ) );
		}
	}

	/**
	 * Captions use the same font as the body in every variation.
	 *
	 * @dataProvider style_files
	 *
	 * @param string $file Path relative to the theme root.
	 */
	public function test_caption_font_follows_body_font( $file ) {
		$styles = $this->styles_for( $file );

		$this->assertSame( $styles['typography']['fontFamily'], $styles['elements']['caption']['typography']['fontFamily'], $file );
	}

	/**
	 * Links have a color and drop the underline on hover.
	 *
	 * @dataProvider style_files
	 *
	 * @param string $file Path relative to the theme root.
	 */
	public function test_link_element_is_complete( $file ) {
		$link = $this->styles_for( $file )['elements']['link'] ?? [];

		$this->assertNotEmpty( $link['color']['text'] ?? '', $file );
		$this->assertSame( 'none', $link[':hover']['typography']['textDecoration'] ?? '', $file );
	}
}
