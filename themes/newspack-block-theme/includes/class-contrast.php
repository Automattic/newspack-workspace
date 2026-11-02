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
	 * Emit the derived accent-contrast custom properties on the front end and the
	 * editor canvas.
	 *
	 * Two properties are derived and each is emitted only when it resolves:
	 * --wp--preset--color--accent-contrast for the button rest state (scored
	 * against the accent) and --wp--preset--color--accent-contrast-hover for the
	 * button hover and press states (scored against both resolved backgrounds). The values
	 * are derived, not pickable, so they are intentionally not registered as palette
	 * presets and never appear in color pickers. enqueue_block_assets runs in both
	 * the front end and the iframed editor canvas.
	 *
	 * @return void
	 */
	public static function enqueue_block_assets() {
		$accent = self::get_accent_color();
		if ( empty( $accent ) ) {
			return;
		}

		$declarations = [];

		$contrast = self::get_color_for_contrast( $accent );
		if ( null !== $contrast ) {
			$declarations[] = sprintf( '--wp--preset--color--accent-contrast: %s;', $contrast );
		}

		$contrast_hover = self::get_accent_contrast_hover( $accent );
		if ( null !== $contrast_hover ) {
			$declarations[] = sprintf( '--wp--preset--color--accent-contrast-hover: %s;', $contrast_hover );
		}

		if ( empty( $declarations ) ) {
			// Nothing derivable, so leave the properties unset and let the
			// theme.json fallbacks apply.
			return;
		}

		$handle = 'newspack-block-theme-accent-contrast';
		\wp_register_style( $handle, false, [], \wp_get_theme()->get( 'Version' ) );
		\wp_enqueue_style( $handle );
		\wp_add_inline_style( $handle, sprintf( ':root, .editor-styles-wrapper { %s }', implode( ' ', $declarations ) ) );
	}

	/**
	 * Derive the contrast text color for the button hover and press states.
	 *
	 * Both states share one text color, so it is picked by the worst-case WCAG 2
	 * ratio across the resolved hover and press backgrounds. Each background is
	 * read from global styles and interpreted as follows:
	 *
	 * - Absent: the theme default, the accent darkened per channel by 0.85 for
	 *   hover. An absent press background is skipped, since press then shows the
	 *   hover background.
	 * - A color-mix of the accent var with black in srgb (the theme default is 85%
	 *   for hover and 75% for press): the accent darkened per channel by that
	 *   share, which is exactly what the color-mix produces.
	 * - A palette reference (var:preset|color|<slug> or var( --wp--preset--color--<slug> )):
	 *   the palette color the slug resolves to.
	 * - A plain parseable hex: that hex.
	 * - Anything else (other color-mix forms, rgb()/hsl(), gradients): not derivable.
	 *   No hover property is emitted when the hover background is not derivable,
	 *   and a press background that is not derivable is skipped.
	 *
	 * @param string $accent The resolved accent palette color.
	 * @return string|null '#000000' or '#ffffff', or null when not derivable.
	 */
	private static function get_accent_contrast_hover( $accent ) {
		$hover_background = self::get_button_state_background( ':hover' );
		$hover            = self::resolve_state_background( $accent, $hover_background, 0.85 );
		if ( null === $hover ) {
			return null;
		}

		$backgrounds      = [ $hover ];
		$press_background = self::get_button_state_background( ':active' );
		if ( null !== $press_background ) {
			$press = self::resolve_state_background( $accent, $press_background, 0.75 );
			if ( null !== $press ) {
				$backgrounds[] = $press;
			}
		}

		return self::get_wcag_color_for_backgrounds( $backgrounds );
	}

	/**
	 * Resolve a button state background to a hex color.
	 *
	 * @param string      $accent     The resolved accent palette color.
	 * @param string|null $background The state background from global styles, or null when absent.
	 * @param float       $factor     The theme default darkening factor for the state.
	 * @return string|null The hex color, or null when not derivable.
	 */
	private static function resolve_state_background( string $accent, ?string $background, float $factor ): ?string {
		if ( null === $background ) {
			return self::darken_hex( $accent, $factor );
		}

		$mix_factor = self::get_accent_black_mix_factor( $background );
		if ( null !== $mix_factor ) {
			return self::darken_hex( $accent, $mix_factor );
		}

		return self::resolve_color_reference( $background );
	}

	/**
	 * Read a resolved button state background from global styles.
	 *
	 * Pseudo keys such as ':hover' and ':active' are first-class keys under the
	 * button element node, so they are addressable directly in the path. A
	 * non-string result (an absent leaf makes _wp_array_get fall back to the whole
	 * styles array) is treated as absent.
	 *
	 * @param string $state The pseudo-class key, such as ':hover' or ':active'.
	 * @return string|null The resolved background value, or null when absent.
	 */
	private static function get_button_state_background( string $state ): ?string {
		$value = \wp_get_global_styles( [ 'elements', 'button', $state, 'color', 'background' ] );

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Read the accent share of a color-mix of the accent with black.
	 *
	 * Matches color-mix( in srgb, <accent> <percent>, black ), where <accent> is the
	 * accent preset in either the var:preset|color|accent internal form or the
	 * var( --wp--preset--color--accent ) CSS form. The match is whitespace-flexible
	 * and case-insensitive, but every component is anchored: any other color space,
	 * mixed-in color, or accent-prefixed slug (such as accent-2 or accent-contrast)
	 * falls through to null, so the caller never scores the wrong background.
	 *
	 * @param string $value The resolved background value.
	 * @return float|null The accent share, such as 0.85 for 85%, or null when the value is not such a mix.
	 */
	private static function get_accent_black_mix_factor( string $value ): ?float {
		if ( false === stripos( $value, 'color-mix' ) ) {
			return null;
		}

		if ( ! preg_match(
			'/^color-mix\(\s*in\s+srgb\s*,\s*(?:var\(\s*--wp--preset--color--accent(?![\w-])\s*\)|var:preset\|color\|accent(?![\w-]))\s+(\d{1,3}(?:\.\d+)?)%\s*,\s*black\s*\)$/i',
			trim( $value ),
			$matches
		) ) {
			return null;
		}

		$percent = (float) $matches[1];
		return $percent <= 100 ? $percent / 100 : null;
	}

	/**
	 * Resolve a CSS color reference to a hex string.
	 *
	 * Accepts a palette reference in either the var:preset|color|<slug> internal
	 * form or the var( --wp--preset--color--<slug> ) CSS form, resolving the slug
	 * against the palette, or a plain parseable hex passed through unchanged.
	 *
	 * @param string $value The resolved background value.
	 * @return string|null The hex color, or null when not a resolvable reference.
	 */
	private static function resolve_color_reference( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$value = trim( $value );

		if (
			preg_match( '/^var:preset\|color\|([\w-]+)$/', $value, $matches )
			|| preg_match( '/^var\(\s*--wp--preset--color--([\w-]+)\s*\)$/', $value, $matches )
		) {
			$color = self::resolve_palette_color( $matches[1] );
			return '' === $color ? null : $color;
		}

		if ( null !== self::parse_hex_channels( $value ) ) {
			return $value;
		}

		return null;
	}

	/**
	 * Darken a hex color by multiplying each RGB channel by a factor.
	 *
	 * Operates in gamma-encoded srgb, so a 0.85 factor reproduces exactly what
	 * color-mix( in srgb, <color> 85%, black ) produces, and likewise for 0.75.
	 *
	 * @param string $hex    Hexadecimal color.
	 * @param float  $factor Multiplier applied to each channel.
	 * @return string|null The darkened '#RRGGBB' color, or null when unparseable.
	 */
	private static function darken_hex( $hex, $factor ) {
		$channels = self::parse_hex_channels( $hex );
		if ( null === $channels ) {
			return null;
		}

		return sprintf(
			'#%02x%02x%02x',
			(int) round( $channels[0] * $factor ),
			(int) round( $channels[1] * $factor ),
			(int) round( $channels[2] * $factor )
		);
	}

	/**
	 * Resolve the effective accent palette color.
	 *
	 * @return string The accent color, or an empty string if none resolves.
	 */
	private static function get_accent_color() {
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
	private static function resolve_palette_color( $slug ) {
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
	 * Pick black or white text by the best worst-case WCAG 2 ratio across backgrounds.
	 *
	 * Ties fall to black.
	 *
	 * @param string[] $hexes Hexadecimal background colors.
	 * @return string|null '#000000' or '#ffffff', or null when a color is not parseable as hex.
	 */
	private static function get_wcag_color_for_backgrounds( array $hexes ): ?string {
		$black = INF;
		$white = INF;

		foreach ( $hexes as $hex ) {
			$luminance = self::get_wcag_luminance( $hex );
			if ( null === $luminance ) {
				return null;
			}
			$black = min( $black, ( $luminance + 0.05 ) / 0.05 );
			$white = min( $white, 1.05 / ( $luminance + 0.05 ) );
		}

		return $white > $black ? '#ffffff' : '#000000';
	}

	/**
	 * Compute the WCAG 2 relative luminance of a hex color.
	 *
	 * @param string $hex Hexadecimal color.
	 * @return float|null Relative luminance in the 0..1 range, or null when unparseable.
	 */
	private static function get_wcag_luminance( string $hex ): ?float {
		$channels = self::parse_hex_channels( $hex );
		if ( null === $channels ) {
			return null;
		}

		$linear = array_map(
			function ( int $channel ): float {
				$value = $channel / 255;
				return $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
			},
			$channels
		);

		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
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
	private static function parse_hex_channels( $hex ) {
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
	private static function get_apca_luminance( $hex ) {
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
