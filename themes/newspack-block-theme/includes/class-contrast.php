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
 * custom property. Buttons use it at rest, on hover and on press, so only the
 * fill changes between states.
 */
final class Contrast {
	/**
	 * Initializer.
	 */
	public static function init(): void {
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
	}

	/**
	 * Emit the derived accent-contrast custom property on the front end and the
	 * editor canvas.
	 *
	 * --wp--preset--color--accent-contrast is the button text color, whichever
	 * of black or white reads better against the accent by APCA, black on a tie.
	 * Buttons keep it on hover and press, where only the fill changes. It is
	 * derived, not pickable, so it is intentionally not registered as a palette
	 * preset and never appears in color pickers. enqueue_block_assets runs in
	 * both the front end and the iframed editor canvas.
	 *
	 * @return void
	 */
	public static function enqueue_block_assets(): void {
		$accent = self::get_accent_color();
		if ( empty( $accent ) ) {
			return;
		}

		$contrast = self::get_text_color( $accent );
		if ( null === $contrast ) {
			// Nothing derivable, so leave the property unset and let the
			// theme.json fallbacks apply.
			return;
		}

		$handle = 'newspack-block-theme-accent-contrast';
		\wp_register_style( $handle, false, [], \wp_get_theme()->get( 'Version' ) );
		\wp_enqueue_style( $handle );
		\wp_add_inline_style( $handle, sprintf( ':root, .editor-styles-wrapper { --wp--preset--color--accent-contrast: %s; }', $contrast ) );
	}

	/**
	 * Resolve the effective accent palette color.
	 *
	 * @return string The accent color, or an empty string if none resolves.
	 */
	private static function get_accent_color(): string {
		return self::resolve_palette_color( 'accent' );
	}

	/**
	 * Resolve a palette color by slug.
	 *
	 * Scans the 'custom' (user override) origin before the 'theme' origin so a
	 * user-selected color wins.
	 *
	 * @param string $slug The palette color slug.
	 * @return string The color, or an empty string if none resolves.
	 */
	private static function resolve_palette_color( string $slug ): string {
		$palette = \wp_get_global_settings( [ 'color', 'palette' ] );

		foreach ( [ 'custom', 'theme' ] as $origin ) {
			if ( empty( $palette[ $origin ] ) || ! is_array( $palette[ $origin ] ) ) {
				continue;
			}
			foreach ( $palette[ $origin ] as $entry ) {
				if ( isset( $entry['slug'], $entry['color'] ) && $slug === $entry['slug'] ) {
					return $entry['color'];
				}
			}
		}

		return '';
	}

	/**
	 * Pick black or white text, whichever reads better on a background by APCA.
	 *
	 * Black wins ties. This is the pick of Newspack_Blocks::get_color_for_contrast()
	 * and the other copies it lists, with the SA98G constants from apca-w3 0.1.9;
	 * it is self-contained so the theme stays standalone.
	 *
	 * @param string $hex Hexadecimal background color (#RGB, #RRGGBB or #RRGGBBAA, with or without #).
	 * @return string|null '#000000' or '#ffffff', or null when the color is not parseable as hex.
	 */
	private static function get_text_color( string $hex ): ?string {
		$background_y = self::get_apca_luminance( $hex );
		if ( null === $background_y ) {
			return null;
		}

		$black_lc = abs( self::get_apca_contrast( $background_y, self::get_apca_luminance( '#000000' ) ) );
		$white_lc = abs( self::get_apca_contrast( $background_y, self::get_apca_luminance( '#ffffff' ) ) );

		return $white_lc > $black_lc ? '#ffffff' : '#000000';
	}

	/**
	 * Parse a hex color into its 0..255 RGB channels.
	 *
	 * Accepts #RGB, #RRGGBB and #RRGGBBAA (the alpha pair is stripped), with or
	 * without the leading #, case-insensitively. Unparseable input returns null.
	 *
	 * @param string $hex Hexadecimal color.
	 * @return int[]|null [ $r, $g, $b ] as 0..255 integers, or null when unparseable.
	 */
	private static function parse_hex_channels( $hex ): ?array {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		} elseif ( 8 === strlen( $hex ) ) {
			// Drop the alpha pair from #RRGGBBAA.
			$hex = substr( $hex, 0, 6 );
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return null;
		}

		return [
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		];
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
	private static function get_apca_luminance( $hex ): ?float {
		$channels = self::parse_hex_channels( $hex );
		if ( null === $channels ) {
			return null;
		}

		$r = $channels[0] / 255;
		$g = $channels[1] / 255;
		$b = $channels[2] / 255;

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
	private static function get_apca_contrast( float $background_y, float $text_y ): float {
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
