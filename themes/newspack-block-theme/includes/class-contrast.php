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
	 * The WCAG 2 contrast ratio the picked text color has to reach: AA for normal text.
	 *
	 * @var float
	 */
	const WCAG_FLOOR = 4.5;

	/**
	 * The theme's own hover and press shares, used when theme.json's state tokens are unavailable.
	 *
	 * @var float[]
	 */
	const DEFAULT_STATE_SHARES = [
		'hover'   => 0.85,
		'pressed' => 0.75,
	];

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
	 * button hover and press states. The hover color is the rest color unless
	 * WCAG 2 AA forces the other one on the darkened fills, so the text normally
	 * keeps its color across states and only the fill changes. The values
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

		$contrast = self::get_text_color_for_backgrounds( [ $accent ] );
		if ( null !== $contrast ) {
			$declarations[] = sprintf( '--wp--preset--color--accent-contrast: %s;', $contrast );
		}

		$contrast_hover = null === $contrast ? null : self::get_accent_contrast_hover( $accent, $contrast );
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
	 * Both states share one text color: the rest color, unless its worst-case
	 * WCAG 2 ratio across the resolved hover and press backgrounds is under the
	 * floor while the other color reaches it. Each background is read from global
	 * styles and interpreted as follows:
	 *
	 * - Absent: the theme default, the accent mixed toward black in oklab by the
	 *   theme's hover share. An absent press background is skipped, since press
	 *   then shows the hover background.
	 * - A color-mix of the accent var with black, in oklab or srgb, by a literal
	 *   percentage or one of the theme's state tokens (the theme default is
	 *   var( --wp--custom--state--hover ) and --pressed): the accent mixed toward
	 *   black in that space by that share, which is what the color-mix produces.
	 * - A palette reference (var:preset|color|<slug> or var( --wp--preset--color--<slug> )):
	 *   the palette color the slug resolves to.
	 * - A plain parseable hex: that hex.
	 * - Anything else (other color-mix forms, rgb()/hsl(), gradients): not derivable.
	 *   No hover property is emitted when the hover background is not derivable,
	 *   and a press background that is not derivable is skipped.
	 *
	 * @param string $accent The resolved accent palette color.
	 * @param string $rest   The text color picked for the rest state.
	 * @return string|null '#000000' or '#ffffff', or null when not derivable.
	 */
	private static function get_accent_contrast_hover( string $accent, string $rest ): ?string {
		$hover_background = self::get_button_state_background( ':hover' );
		$hover            = self::resolve_state_background( $accent, $hover_background, 'hover' );
		if ( null === $hover ) {
			return null;
		}

		$backgrounds      = [ $hover ];
		$press_background = self::get_button_state_background( ':active' );
		if ( null !== $press_background ) {
			$press = self::resolve_state_background( $accent, $press_background, 'pressed' );
			if ( null !== $press ) {
				$backgrounds[] = $press;
			}
		}

		return self::apply_wcag_floor( $backgrounds, $rest );
	}

	/**
	 * Resolve a button state background to a hex color.
	 *
	 * @param string      $accent     The resolved accent palette color.
	 * @param string|null $background The state background from global styles, or null when absent.
	 * @param string      $state      The state token the theme default uses: 'hover' or 'pressed'.
	 * @return string|null The hex color, or null when not derivable.
	 */
	private static function resolve_state_background( string $accent, ?string $background, string $state ): ?string {
		if ( null === $background ) {
			$share = self::get_state_share( $state );
			return null === $share ? null : self::mix_toward_black( $accent, $share, 'oklab' );
		}

		$mix = self::parse_accent_black_mix( $background );
		if ( null !== $mix ) {
			return self::mix_toward_black( $accent, $mix['share'], $mix['space'] );
		}

		return self::resolve_color_reference( $background );
	}

	/**
	 * Read one of the theme's state shares from the settings.custom.state tokens.
	 *
	 * @param string $state The token name, such as 'hover' or 'pressed'.
	 * @return float|null The share in the 0..1 range, or null when the token is neither set nor a theme default.
	 */
	private static function get_state_share( string $state ): ?float {
		$value = \wp_get_global_settings( [ 'custom', 'state', $state ] );
		if ( is_string( $value ) && preg_match( '/^\s*(\d{1,3}(?:\.\d+)?)%\s*$/', $value, $matches ) ) {
			$percent = (float) $matches[1];
			return $percent <= 100 ? $percent / 100 : null;
		}

		return self::DEFAULT_STATE_SHARES[ $state ] ?? null;
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
	 * Read the color space and accent share of a color-mix of the accent with black.
	 *
	 * Matches color-mix( in <oklab|srgb>, <accent> <share>, black ), where <accent>
	 * is the accent preset in either the var:preset|color|accent internal form or
	 * the var( --wp--preset--color--accent ) CSS form, and <share> is a literal
	 * percentage or a settings.custom.state token in either form. The match is
	 * whitespace-flexible and case-insensitive, but every component is anchored:
	 * any other color space, mixed-in color, or accent-prefixed slug (such as
	 * accent-2 or accent-contrast) falls through to null, so the caller never
	 * scores the wrong background.
	 *
	 * @param string $value The resolved background value.
	 * @return array|null [ 'space' => 'oklab'|'srgb', 'share' => float ], or null when the value is not such a mix.
	 */
	private static function parse_accent_black_mix( string $value ): ?array {
		if ( false === stripos( $value, 'color-mix' ) ) {
			return null;
		}

		if ( ! preg_match(
			'/^color-mix\(\s*in\s+(oklab|srgb)\s*,\s*(?:var\(\s*--wp--preset--color--accent(?![\w-])\s*\)|var:preset\|color\|accent(?![\w-]))\s+(?:(\d{1,3}(?:\.\d+)?)%|var\(\s*--wp--custom--state--([\w-]+)\s*\)|var:custom\|state\|([\w-]+))\s*,\s*black\s*\)$/i',
			trim( $value ),
			$matches
		) ) {
			return null;
		}

		if ( '' !== $matches[2] ) {
			$percent = (float) $matches[2];
			$share   = $percent <= 100 ? $percent / 100 : null;
		} else {
			$share = self::get_state_share( '' !== $matches[3] ? $matches[3] : $matches[4] );
		}

		return null === $share ? null : [
			'space' => strtolower( $matches[1] ),
			'share' => $share,
		];
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
	 * Mix a hex color toward black, as color-mix( in <space>, <color> <share>, black ) does.
	 *
	 * In srgb the mix multiplies each gamma-encoded channel by the share. In oklab
	 * black is the origin of the space, so the mix scales L, a and b by the share;
	 * both ends of Oklab's conversion are linear and the middle is a cube root,
	 * so that is the same as scaling each linear-light channel by the share cubed.
	 *
	 * @param string $hex   Hexadecimal color.
	 * @param float  $share The color's share of the mix, in the 0..1 range.
	 * @param string $space 'oklab' or 'srgb'.
	 * @return string|null The mixed '#RRGGBB' color, or null when unparseable.
	 */
	private static function mix_toward_black( string $hex, float $share, string $space ): ?string {
		$channels = self::parse_hex_channels( $hex );
		if ( null === $channels ) {
			return null;
		}

		if ( 'oklab' === $space ) {
			$scale    = pow( $share, 3 );
			$channels = array_map(
				function ( int $channel ) use ( $scale ): float {
					return 255 * self::linear_to_srgb( $scale * self::srgb_to_linear( $channel / 255 ) );
				},
				$channels
			);
		} else {
			$channels = array_map(
				function ( int $channel ) use ( $share ): float {
					return $channel * $share;
				},
				$channels
			);
		}

		return sprintf( '#%02x%02x%02x', (int) round( $channels[0] ), (int) round( $channels[1] ), (int) round( $channels[2] ) );
	}

	/**
	 * Decode a gamma-encoded sRGB channel to linear light.
	 *
	 * @param float $channel The channel in the 0..1 range.
	 * @return float
	 */
	private static function srgb_to_linear( float $channel ): float {
		return $channel <= 0.04045 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * Encode a linear-light channel as gamma-encoded sRGB.
	 *
	 * @param float $channel The linear channel, clamped to the 0..1 range.
	 * @return float
	 */
	private static function linear_to_srgb( float $channel ): float {
		$channel = max( 0.0, min( 1.0, $channel ) );
		return $channel <= 0.0031308 ? $channel * 12.92 : 1.055 * pow( $channel, 1 / 2.4 ) - 0.055;
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
	 * Pick either black or white text, whichever reads better on the given backgrounds.
	 *
	 * Scores pure black and pure white as text against every background and keeps
	 * the color with the greater worst-case APCA lightness contrast (Lc); ties fall
	 * to black. The APCA scoring matches Newspack_Blocks::get_color_for_contrast(),
	 * with the SA98G constants from apca-w3 0.1.9, and is self-contained so the
	 * theme stays standalone. WCAG 2 AA is then the floor, see apply_wcag_floor().
	 *
	 * @param string[] $hexes Hexadecimal background colors (#RGB, #RRGGBB or #RRGGBBAA, with or without #).
	 * @return string|null '#000000' or '#ffffff', or null when a color is not parseable as hex.
	 */
	private static function get_text_color_for_backgrounds( array $hexes ): ?string {
		$black_y  = self::get_apca_luminance( '#000000' );
		$white_y  = self::get_apca_luminance( '#ffffff' );
		$black_lc = INF;
		$white_lc = INF;

		foreach ( $hexes as $hex ) {
			$background_y = self::get_apca_luminance( $hex );
			if ( null === $background_y ) {
				return null;
			}
			$black_lc = min( $black_lc, abs( self::get_apca_contrast( $background_y, $black_y ) ) );
			$white_lc = min( $white_lc, abs( self::get_apca_contrast( $background_y, $white_y ) ) );
		}

		return self::apply_wcag_floor( $hexes, $white_lc > $black_lc ? '#ffffff' : '#000000' );
	}

	/**
	 * Hold a text color to the WCAG 2 AA floor across backgrounds.
	 *
	 * When the color's worst-case ratio is under 4.5:1 and the other color
	 * reaches it, the other color wins. When neither reaches it the given color
	 * stands.
	 *
	 * @param string[] $hexes Hexadecimal background colors.
	 * @param string   $text  '#000000' or '#ffffff'.
	 * @return string|null The text color to use, or null when a background is not parseable as hex.
	 */
	private static function apply_wcag_floor( array $hexes, string $text ): ?string {
		$other       = '#ffffff' === $text ? '#000000' : '#ffffff';
		$text_ratio  = self::get_min_wcag_ratio( $hexes, $text );
		$other_ratio = self::get_min_wcag_ratio( $hexes, $other );
		if ( null === $text_ratio || null === $other_ratio ) {
			return null;
		}

		return $text_ratio < self::WCAG_FLOOR && $other_ratio >= self::WCAG_FLOOR ? $other : $text;
	}

	/**
	 * Compute the worst-case WCAG 2 contrast ratio of black or white text across backgrounds.
	 *
	 * @param string[] $hexes Hexadecimal background colors.
	 * @param string   $text  '#000000' or '#ffffff'.
	 * @return float|null The lowest ratio, or null when a background is not parseable as hex.
	 */
	private static function get_min_wcag_ratio( array $hexes, string $text ): ?float {
		$ratio = INF;
		foreach ( $hexes as $hex ) {
			$luminance = self::get_wcag_luminance( $hex );
			if ( null === $luminance ) {
				return null;
			}
			$ratio = min( $ratio, '#ffffff' === $text ? 1.05 / ( $luminance + 0.05 ) : ( $luminance + 0.05 ) / 0.05 );
		}

		return $ratio;
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
