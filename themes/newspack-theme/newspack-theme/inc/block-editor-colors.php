<?php
/**
 * Block Editor Colors Compatibility File
 *
 * Makes the plugin's color overrides render in the iframed block editor canvas.
 *
 * TODO: Remove this file (and its require in functions.php) once the plugin supports the
 * iframe natively. See: https://wordpress.org/support/topic/editor-preview-no-longer-works-in-wordpress-7-0
 *
 * @link https://motopress.com/products/block-editor-colors/
 *
 * @package Newspack
 */

/**
 * Pass Block Editor Colors' override CSS through the `styles` setting, which WP
 * injects into the editor iframe (the plugin's own enqueue loads outside it).
 *
 * @param array $settings Block editor settings.
 * @return array Filtered block editor settings.
 */
function newspack_block_editor_colors_iframe_styles( $settings ) {
	if ( ! class_exists( '\BlockEditorColors\ColorsService' ) ) {
		return $settings;
	}

	$css = \BlockEditorColors\ColorsService::getInstance()->generate_colors_css( true );

	// Rewrite the prefix to a bare class so it matches the iframe's <body class="editor-styles-wrapper">.
	$css = str_replace( 'body .editor-styles-wrapper', '.editor-styles-wrapper', $css );

	if ( $css ) {
		$settings['styles'][] = [ 'css' => $css ];
	}

	return $settings;
}
add_filter( 'block_editor_settings_all', 'newspack_block_editor_colors_iframe_styles', PHP_INT_MAX );
