<?php
/**
 * Newspack Theme: Typography
 *
 * @package Newspack
 */

/**
 * Generate the CSS for custom typography.
 *
 * The editor override targets the root element as well as the wrapper: in the
 * iframed canvas the font-family presets compute on the iframe's root element,
 * which a wrapper-scoped rule cannot reach. html:root outweighs the compiled
 * editor defaults, which land later in the canvas and would win a plain-:root
 * tie on document order.
 */
function newspack_custom_typography_css() {

	$font_body   = newspack_font_stack( get_theme_mod( 'font_body', '' ), get_theme_mod( 'font_body_stack', 'serif' ) );
	$font_header = newspack_font_stack( get_theme_mod( 'font_header', '' ), get_theme_mod( 'font_header_stack', 'serif' ) );

	$css_blocks        = '';
	$editor_css_blocks = '';

	if ( get_theme_mod( 'font_header', '' ) ) {
		$css_blocks .= '
			:root {
				--newspack-theme-font-heading: ' . $font_header . ';
			}
		';

		$editor_css_blocks .= '
			html:root,
			:root .editor-styles-wrapper {
				--newspack-theme-font-heading: ' . $font_header . ';
			}
		';
	}

	if ( get_theme_mod( 'font_body', '' ) ) {
		$css_blocks .= '
			:root {
				--newspack-theme-font-body: ' . $font_body . ';
			}
		';

		$editor_css_blocks .= '
			html:root,
			:root .editor-styles-wrapper {
				--newspack-theme-font-body: ' . $font_body . ';
			}
		';
	}

	if ( true === get_theme_mod( 'accent_allcaps', true ) ) {
		$css_blocks .= '
			.tags-links span:first-child,
			.cat-links,
			.page-title,
			.highlight-menu .menu-label {
				text-transform: uppercase;
			}
		';

		$editor_css_blocks .= '
			.block-editor-block-list__layout .block-editor-block-list__block .cat-links,
			.block-editor-block-list__layout .block-editor-block-list__block #jp-relatedposts h3.jp-relatedposts-headline {
				text-transform: uppercase;
			}
		';

		if ( ! is_child_theme() ) {
			$css_blocks        .= '
				.accent-header,
				#secondary .widgettitle,
				.article-section-title {
					text-transform: uppercase;
				}
			';
			$editor_css_blocks .= '
				.block-editor-block-list__layout .block-editor-block-list__block.accent-header,
				.block-editor-block-list__layout .block-editor-block-list__block .article-section-title {
					text-transform: uppercase;
				}
			';
		}
	}

	if ( '' !== $css_blocks ) {
		$theme_css = $css_blocks;
	} else {
		$theme_css = '';
	}

	if ( '' !== $editor_css_blocks ) {
		$editor_css = $editor_css_blocks;
	} else {
		$editor_css = '';
	}

	if ( function_exists( 'register_block_type' ) && is_admin() ) {
		$theme_css = $editor_css;
	}

	return $theme_css;
}

/**
 * Generate link elements for custom typography stylesheets.
 */
function newspack_custom_typography_link( $theme_mod ) {
	$font_code = get_theme_mod( $theme_mod, '' );
	if ( ! $font_code ) {
		return false;
	}
	return $font_code;
}

/**
 * Fallback font stacks
 */
function newspack_get_font_stacks() {
	return array(
		'serif'      => array(
			'name'  => __( 'Serif', 'newspack-theme' ),
			'fonts' => array(
				'Georgia',
				'serif',
			),
		),
		'sans_serif' => array(
			'name'  => __( 'Sans Serif', 'newspack-theme' ),
			'fonts' => array(
				'Helvetica',
				'sans-serif',
			),
		),
		'display'    => array(
			'name'  => __( 'Display', 'newspack-theme' ),
			'fonts' => array(
				'Impact',
				'Arial Black',
				'sans-serif',
			),
		),
		'monospace'  => array(
			'name'  => __( 'Monospace', 'newspack-theme' ),
			'fonts' => array(
				'Consolas',
				'Courier New',
				'Courier',
				'monospace',
			),
		),
	);
}

/**
 * Prepare fallback font stacks for use in a Select element
 */
function newspack_get_font_stacks_as_select_choices() {
	$stacks = array();
	foreach ( newspack_get_font_stacks() as $key => $value ) {
		$stacks[ $key ] = wp_kses( $value['name'], null );
	}
	return $stacks;
}

/**
 * Prepare a font-family definition with a primary font and fallbacks.
 *
 * Values flow into inline styles and theme.json preset values, and core never
 * passes the theme origin through its insecure-property filtering, so names
 * are emitted as quoted CSS strings with string delimiters escaped and markup
 * and control characters removed. Generic family keywords stay unquoted so a
 * stack always ends in a real generic.
 *
 * Mirrored by newspack-newsletters' test fixture at
 * tests/email-renderers/fixtures/theme-font-functions.php, which stands in for
 * this function when the theme is not loaded; changes to the escaping here need
 * to be carried over there.
 *
 * @param string $primary_font Primary font name.
 * @param string $fallback_id  Key of newspack_get_font_stacks(); unknown ids
 *                             take the serif stack.
 * @return string Comma-joined, pre-escaped CSS font-family list.
 */
function newspack_font_stack( $primary_font, $fallback_id ) {
	$stacks   = newspack_get_font_stacks();
	$fonts    = isset( $stacks[ $fallback_id ] ) ? $stacks[ $fallback_id ]['fonts'] : $stacks['serif']['fonts'];
	$generics = array( 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui' );
	array_unshift( $fonts, $primary_font );
	foreach ( $fonts as &$font ) {
		$font = preg_replace( '/[\x00-\x1F\x7F<>]/', '', stripslashes( $font ) );
		if ( ! in_array( strtolower( $font ), $generics, true ) ) {
			$font = '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $font ) . '"';
		}
	}
	unset( $font );
	return implode( ',', $fonts );
}

/**
 * The current Header and Body font stacks: the Customizer font when set,
 * otherwise the active theme's default, mirroring
 * sass/variables-site/_fonts.scss and each style variation's overrides.
 *
 * @return array Stacks keyed heading/body.
 */
function newspack_font_family_stacks() {
	$system   = '-apple-system,blinkmacsystemfont,"Segoe UI","Roboto","Oxygen","Ubuntu","Cantarell","Fira Sans","Droid Sans","Helvetica Neue",sans-serif';
	$defaults = array(
		'heading' => $system,
		'body'    => 'georgia,garamond,"Times New Roman",serif',
	);

	$variations = array(
		'newspack-joseph'    => array(
			'heading' => '"Old Standard TT",georgia,serif',
			'body'    => '"EB Garamond",georgia,serif',
		),
		'newspack-katharine' => array(
			'heading' => '"Barlow","Helvetica",sans-serif',
			'body'    => '"Barlow","Helvetica",sans-serif',
		),
		'newspack-nelson'    => array(
			'heading' => '"Montserrat","Helvetica",sans-serif',
			'body'    => $system,
		),
		'newspack-sacha'     => array(
			'heading' => '"IBM Plex Serif",georgia,serif',
		),
		'newspack-scott'     => array(
			'heading' => '"Fira Sans Condensed","Helvetica",sans-serif',
		),
	);
	if ( isset( $variations[ get_stylesheet() ] ) ) {
		$defaults = array_merge( $defaults, $variations[ get_stylesheet() ] );
	}

	/**
	 * Filter the default Header and Body font stacks.
	 *
	 * @param array $defaults Stacks keyed heading/body.
	 */
	$defaults = array_merge( $defaults, (array) apply_filters( 'newspack_font_family_default_stacks', $defaults ) );

	$mods   = array(
		'heading' => array( 'font_header', 'font_header_stack' ),
		'body'    => array( 'font_body', 'font_body_stack' ),
	);
	$stacks = array();
	foreach ( $mods as $key => $mod_names ) {
		$font           = get_theme_mod( $mod_names[0], '' );
		$stacks[ $key ] = $font ? newspack_font_stack( $font, get_theme_mod( $mod_names[1], 'serif' ) ) : $defaults[ $key ];
	}

	return $stacks;
}

/**
 * Register the Customizer fonts as editor font family presets.
 *
 * The values reference the theme's own CSS variables so saved content tracks
 * Customizer font changes with no re-save; the resolved stack rides along as
 * the var() fallback for feeds and other contexts that print global styles
 * without the theme stylesheet. Existing theme-origin presets are appended to,
 * not replaced. With the Gutenberg plugin active the resolver passes
 * WP_Theme_JSON_Data_Gutenberg, a sibling class rather than a subclass, so the
 * parameter stays untyped.
 *
 * @param WP_Theme_JSON_Data|WP_Theme_JSON_Data_Gutenberg $theme_json Theme JSON data.
 * @return WP_Theme_JSON_Data|WP_Theme_JSON_Data_Gutenberg
 */
function newspack_font_family_presets( $theme_json ) {
	$data     = $theme_json->get_data();
	$families = $data['settings']['typography']['fontFamilies'] ?? array();
	// WP_Theme_JSON keys presets by origin internally, so a non-empty list comes
	// back nested under 'theme'; appending to the nested array would double-nest
	// it on the way back in and drop the existing presets.
	if ( isset( $families['theme'] ) ) {
		$families = $families['theme'];
	}

	$stacks     = newspack_font_family_stacks();
	$families[] = array(
		'slug'       => 'newspack-header',
		'name'       => _x( 'Header', 'font family name', 'newspack-theme' ),
		'fontFamily' => 'var(--newspack-theme-font-heading,' . $stacks['heading'] . ')',
	);
	$families[] = array(
		'slug'       => 'newspack-body',
		'name'       => _x( 'Body', 'font family name', 'newspack-theme' ),
		'fontFamily' => 'var(--newspack-theme-font-body,' . $stacks['body'] . ')',
	);

	return $theme_json->update_with(
		array(
			'version'  => 3,
			'settings' => array(
				'typography' => array(
					'fontFamilies' => $families,
				),
			),
		)
	);
}
add_filter( 'wp_theme_json_data_theme', 'newspack_font_family_presets', 10, 1 );
