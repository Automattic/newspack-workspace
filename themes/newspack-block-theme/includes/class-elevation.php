<?php
/**
 * Newspack Block Theme elevation tokens.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Switches the elevation tokens behind the shadow presets to their dark values
 * when the resolved base color is dark, whichever palette or style variation
 * set it.
 *
 * The newspack-ui "Theme" style prints the same values for its own tokens, so
 * DARK must match Newspack_UI_Palette::ELEVATION_DARK; a Jest test in
 * newspack-plugin pins the two together.
 */
final class Elevation {
	const DARK = [
		'ink'      => '#000',
		'edge'     => 'color-mix(in oklab, var(--wp--preset--color--contrast) 14%, transparent)',
		'strength' => '2.5',
	];

	/**
	 * Initializer.
	 */
	public static function init(): void {
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
	}

	/**
	 * Emit the dark elevation tokens on the front end and the editor canvas
	 * when the base color is dark. A light base keeps the theme.json defaults.
	 *
	 * @return void
	 */
	public static function enqueue_block_assets(): void {
		$base = Contrast::resolve_color( Contrast::resolve_palette_color( 'base' ) );
		if ( '' === $base || ! Status_Colors::is_dark( $base ) ) {
			return;
		}

		$declarations = '';
		foreach ( self::DARK as $name => $value ) {
			$declarations .= sprintf( ' --wp--custom--elevation--%s: %s;', $name, $value );
		}

		$handle = 'newspack-block-theme-elevation';
		\wp_register_style( $handle, false, [], \wp_get_theme()->get( 'Version' ) );
		\wp_enqueue_style( $handle );
		\wp_add_inline_style( $handle, ':root:root, :root .editor-styles-wrapper {' . $declarations . ' }' );
	}
}

Elevation::init();
