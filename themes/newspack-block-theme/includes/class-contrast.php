<?php
/**
 * Newspack Block Theme accent-contrast color.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Derives a readable text color for the accent color and exposes it as a CSS
 * custom property, so button text no longer assumes accent/base always reads.
 */
final class Contrast {
	/**
	 * Initializer.
	 */
	public static function init() {
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
	}

	/**
	 * Emit --wp--preset--color--accent-contrast on the front end and the editor canvas.
	 *
	 * The value is derived, not pickable, so it is intentionally not registered
	 * as a palette preset and never appears in color pickers. enqueue_block_assets
	 * runs in both the front end and the iframed editor canvas.
	 *
	 * @return void
	 */
	public static function enqueue_block_assets() {
		$accent = self::get_accent_color();
		if ( empty( $accent ) ) {
			return;
		}

		$contrast = self::get_color_for_contrast( $accent );
		if ( null === $contrast ) {
			// The accent is not a parseable hex, so leave the property unset and
			// let the theme.json base fallback apply.
			return;
		}

		$handle = 'newspack-block-theme-accent-contrast';
		\wp_register_style( $handle, false, [], \wp_get_theme()->get( 'Version' ) );
		\wp_enqueue_style( $handle );
		\wp_add_inline_style(
			$handle,
			sprintf(
				':root, .editor-styles-wrapper { --wp--preset--color--accent-contrast: %s; }',
				$contrast
			)
		);
	}

	/**
	 * Resolve the effective accent palette color.
	 *
	 * Scans the 'custom' (user override) origin before the 'theme' origin so a
	 * user-selected accent wins.
	 *
	 * @return string The accent color, or an empty string if none resolves.
	 */
	private static function get_accent_color() {
		$palette = \wp_get_global_settings( [ 'color', 'palette' ] );

		foreach ( [ 'custom', 'theme' ] as $origin ) {
			if ( empty( $palette[ $origin ] ) || ! is_array( $palette[ $origin ] ) ) {
				continue;
			}
			foreach ( $palette[ $origin ] as $entry ) {
				if ( isset( $entry['slug'], $entry['color'] ) && 'accent' === $entry['slug'] ) {
					return $entry['color'];
				}
			}
		}

		return '';
	}

	/**
	 * Pick either black or white text, whichever reads better on the given background.
	 *
	 * Scores pure black and pure white as text against the background and returns
	 * whichever produces the greater APCA lightness contrast (Lc); ties fall to
	 * black. The constants are the SA98G set from apca-w3 0.1.9. Self-contained so
	 * the theme stays standalone.
	 *
	 * Keep in sync with Newspack_Blocks::get_color_for_contrast().
	 *
	 * @param string $hex Hexadecimal background color (#RGB, #RRGGBB or #RRGGBBAA, with or without #).
	 * @return string|null '#000000' or '#ffffff', or null when the input is not parseable as hex.
	 */
	private static function get_color_for_contrast( $hex ) {
		$background_y = self::get_apca_luminance( $hex );
		if ( null === $background_y ) {
			return null;
		}
		$black_lc = self::get_apca_contrast( $background_y, self::get_apca_luminance( '#000000' ) );
		$white_lc = self::get_apca_contrast( $background_y, self::get_apca_luminance( '#ffffff' ) );

		return abs( $white_lc ) > abs( $black_lc ) ? '#ffffff' : '#000000';
	}

	/**
	 * Compute the soft-clamped APCA screen luminance (Y) of a hex color.
	 *
	 * Accepts #RGB, #RRGGBB and #RRGGBBAA (the alpha pair is stripped), with or
	 * without the leading #, case-insensitively. Unparseable input returns null so
	 * callers can leave the contrast property unset and fall back to theme.json.
	 *
	 * @param string $hex Hexadecimal color.
	 * @return float|null Soft-clamped luminance in the 0..1 range, or null when unparseable.
	 */
	private static function get_apca_luminance( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		} elseif ( 8 === strlen( $hex ) ) {
			// Drop the alpha pair from #RRGGBBAA.
			$hex = substr( $hex, 0, 6 );
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return null;
		}

		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;

		$y = 0.2126729 * pow( $r, 2.4 ) + 0.7151522 * pow( $g, 2.4 ) + 0.0721750 * pow( $b, 2.4 );

		// APCA soft-clamp of near-black luminance.
		if ( $y <= 0.022 ) {
			$y += pow( 0.022 - $y, 1.414 );
		}

		return $y;
	}

	/**
	 * Compute the APCA lightness contrast (Lc) of text on a background.
	 *
	 * Positive values are dark text on a lighter background; negative values are
	 * light text on a darker background. Both luminances must already be
	 * soft-clamped.
	 *
	 * @param float $background_y Soft-clamped background luminance.
	 * @param float $text_y       Soft-clamped text luminance.
	 * @return float The Lc value.
	 */
	private static function get_apca_contrast( $background_y, $text_y ) {
		if ( abs( $background_y - $text_y ) < 0.0005 ) {
			return 0.0;
		}

		if ( $background_y > $text_y ) {
			$sapc = ( pow( $background_y, 0.56 ) - pow( $text_y, 0.57 ) ) * 1.14;
			return $sapc < 0.1 ? 0.0 : ( $sapc - 0.027 ) * 100;
		}

		$sapc = ( pow( $background_y, 0.65 ) - pow( $text_y, 0.62 ) ) * 1.14;
		return $sapc > -0.1 ? 0.0 : ( $sapc + 0.027 ) * 100;
	}
}

Contrast::init();
