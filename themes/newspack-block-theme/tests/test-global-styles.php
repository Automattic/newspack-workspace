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
	private function resolved_styles() {
		return wp_get_global_styles( [], [ 'transforms' => [ 'resolve-variables' ] ] );
	}

	/**
	 * The theme's styles merged with one style variation, with every CSS variable replaced by its value.
	 *
	 * @param string $file Path of the variation file inside `styles/`.
	 * @return array
	 */
	private function resolved_variation_styles( $file ) {
		$theme = WP_Theme_JSON_Resolver::get_theme_data();
		$theme->merge( new WP_Theme_JSON( wp_json_file_decode( get_stylesheet_directory() . '/' . $file, [ 'associative' => true ] ), 'theme' ) );
		$data = WP_Theme_JSON::resolve_variables( $theme )->get_raw_data();
		return $data['styles'] ?? [];
	}

	/**
	 * Every JSON file that defines global styles, relative to the theme root.
	 *
	 * @return array
	 */
	public function style_files() {
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
		$this->assertStringNotContainsString( 'var( ', file_get_contents( dirname( __DIR__ ) . '/' . $file ) );
	}

	/**
	 * A resolver that eats a closing parenthesis leaves unbalanced values behind.
	 *
	 * @dataProvider style_files
	 *
	 * @param string $file Path relative to the theme root.
	 */
	public function test_resolved_values_have_balanced_parentheses( $file ) {
		$styles = 'theme.json' === $file ? $this->resolved_styles() : $this->resolved_variation_styles( $file );
		$this->assertNotEmpty( $styles );
		$bad = [];
		array_walk_recursive(
			$styles,
			function ( $value, $key ) use ( &$bad ) {
				if ( is_string( $value ) && substr_count( $value, '(' ) !== substr_count( $value, ')' ) ) {
					$bad[ $key ] = $value;
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

		$this->assertSame( '#003da5', strtolower( $button['color']['background'] ) );
		$this->assertSame( '0.5rem', $button['border']['radius'] );
		$this->assertStringNotContainsString( 'var(', $button['typography']['fontFamily'] );
		$this->assertStringNotContainsString( 'var(', $button['typography']['fontSize'] );
		$this->assertStringNotContainsString( 'var( ', $button['color']['text'] );
		$this->assertStringContainsString( 'accent-contrast, #ffffff', strtolower( $button['color']['text'] ) );
	}
}
