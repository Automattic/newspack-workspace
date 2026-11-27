<?php
/**
 * Newspack Block Theme button state colors.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Gives a button with a custom background its hover and press colors.
 *
 * The stylesheet can mix the palette's own colors, but a publisher's custom fill
 * only exists as an inline style, so the theme mixes it here, in oklab like
 * every other state, and hands the results to the stylesheet as custom
 * properties on the button.
 */
final class Button_States {
	/**
	 * APCA screen luminance (Y, soft-clamped) below which a fill counts as near-black.
	 *
	 * A fill this dark can't darken visibly, so it lightens toward its text
	 * instead, like newspack-ui's dark button. The cut-off sits just above the
	 * theme's default contrast color (#1E1E1E), at about #272727.
	 *
	 * @var float
	 */
	const NEAR_BLACK_Y = 0.0125;

	/**
	 * Initializer.
	 */
	public static function init(): void {
		\add_filter( 'render_block_core/button', [ __CLASS__, 'render_block' ], 10, 2 );
	}

	/**
	 * Add the hover and press colors to a button with a custom background.
	 *
	 * Palette backgrounds are left to the stylesheet, and so is a translucent
	 * fill, whose hover would turn opaque; those keep the stylesheet's overlay.
	 * The custom fill darkens by the theme's hover and press shares toward black,
	 * or, when it is near-black, lightens toward the button's text color (white
	 * when none is set) by the same shares, never by less than pure black would
	 * get from the lighten shares. Every share is read from the
	 * settings.custom.state tokens.
	 *
	 * @param mixed $block_content The rendered block markup.
	 * @param mixed $block         The parsed block.
	 * @return mixed The block markup.
	 */
	public static function render_block( $block_content, $block ) {
		if ( ! is_string( $block_content ) || '' === $block_content || ! is_array( $block ) ) {
			return $block_content;
		}

		$attrs = $block['attrs'] ?? [];
		$fill  = self::get_custom_background( is_array( $attrs ) ? $attrs : [] );
		if ( null === $fill ) {
			return $block_content;
		}

		$colors = self::get_state_colors( $fill, self::get_text_color( $attrs ) );
		if ( null === $colors ) {
			return $block_content;
		}

		$declaration = \safecss_filter_attr( sprintf( '--newspack-button-bg-hover: %s; --newspack-button-bg-pressed: %s', $colors['hover'], $colors['pressed'] ) );
		if ( '' === $declaration ) {
			return $block_content;
		}

		$processor = new \WP_HTML_Tag_Processor( $block_content );
		if ( ! $processor->next_tag( [ 'class_name' => 'wp-block-button__link' ] ) ) {
			return $block_content;
		}

		$style = $processor->get_attribute( 'style' );
		$style = is_string( $style ) ? rtrim( trim( $style ), ';' ) : '';
		$processor->set_attribute( 'style', '' === $style ? $declaration : $style . ';' . $declaration );

		return $processor->get_updated_html();
	}

	/**
	 * Compute the hover and press colors for a fill.
	 *
	 * @param string $fill The fill as a hex color.
	 * @param string $text The text color as a hex color.
	 * @return string[]|null [ 'hover' => '#rrggbb', 'pressed' => '#rrggbb' ], or null when a color is not parseable.
	 */
	public static function get_state_colors( string $fill, string $text ): ?array {
		if ( null === self::parse_hex( $fill ) ) {
			return null;
		}

		$shares = [ self::get_state_share( 'hover', 0.85 ), self::get_state_share( 'pressed', 0.75 ) ];
		if ( Contrast::get_apca_luminance( $fill ) >= self::NEAR_BLACK_Y ) {
			$hover   = self::mix( $fill, '#000000', $shares[0] );
			$pressed = self::mix( $fill, '#000000', $shares[1] );
		} else {
			$floors  = [ 1 - self::get_state_share( 'lighten-hover', 0.25 ), 1 - self::get_state_share( 'lighten-pressed', 0.4 ) ];
			$hover   = self::lighter( self::mix( $fill, $text, $shares[0] ), self::mix( '#000000', $text, $floors[0] ) );
			$pressed = self::lighter( self::mix( $fill, $text, $shares[1] ), self::mix( '#000000', $text, $floors[1] ) );
		}
		if ( null === $hover || null === $pressed ) {
			return null;
		}

		return [
			'hover'   => $hover,
			'pressed' => $pressed,
		];
	}

	/**
	 * Pick the lighter of two hex colors by oklab lightness.
	 *
	 * @param string|null $a The first hex color.
	 * @param string|null $b The second hex color.
	 * @return string|null The lighter color, or null when either is missing.
	 */
	private static function lighter( ?string $a, ?string $b ): ?string {
		if ( null === $a || null === $b ) {
			return null;
		}
		$lab_a = self::to_oklab( $a );
		$lab_b = self::to_oklab( $b );

		return $lab_b[0] > $lab_a[0] ? $b : $a;
	}

	/**
	 * Read a custom background from a button's attributes.
	 *
	 * A palette slug in backgroundColor is left to the stylesheet. A value in
	 * style.color.background is used when it is an opaque hex color, or a palette
	 * reference that resolves to one.
	 *
	 * @param array $attrs The block attributes.
	 * @return string|null The hex color, or null when the background is not an opaque custom color.
	 */
	private static function get_custom_background( array $attrs ): ?string {
		$slug = $attrs['backgroundColor'] ?? null;
		if ( is_string( $slug ) && '' !== $slug ) {
			return null;
		}

		$fill = self::resolve_color( $attrs['style']['color']['background'] ?? null );
		if ( null !== $fill && 9 === strlen( $fill ) && 'ff' !== strtolower( substr( $fill, -2 ) ) ) {
			return null;
		}

		return $fill;
	}

	/**
	 * Read a button's text color, falling back to white.
	 *
	 * @param array $attrs The block attributes.
	 * @return string The hex color.
	 */
	private static function get_text_color( array $attrs ): string {
		$slug = $attrs['textColor'] ?? null;
		$text = is_string( $slug ) && '' !== $slug ? self::resolve_color( 'var:preset|color|' . $slug ) : self::resolve_color( $attrs['style']['color']['text'] ?? null );

		return $text ?? '#ffffff';
	}

	/**
	 * Resolve a color attribute value to a hex color.
	 *
	 * @param mixed $value A hex color, a var:preset|color|<slug> reference, or anything else.
	 * @return string|null The hex color, or null when it does not resolve to one.
	 */
	private static function resolve_color( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		if ( preg_match( '/^var:preset\|color\|([a-z0-9-]+)$/i', $value, $matches ) ) {
			$value = self::get_palette_color( $matches[1] );
		}

		return null === $value || null === self::parse_hex( $value ) ? null : $value;
	}

	/**
	 * Resolve a palette slug to its color, custom origin first.
	 *
	 * The default origin is left out, as Contrast does, since the theme disables
	 * the default palette.
	 *
	 * @param string $slug The palette slug.
	 * @return string|null The color, or null when no origin defines the slug.
	 */
	private static function get_palette_color( string $slug ): ?string {
		$palette = \wp_get_global_settings( [ 'color', 'palette' ] );
		if ( ! is_array( $palette ) ) {
			return null;
		}

		foreach ( [ 'custom', 'theme' ] as $origin ) {
			foreach ( $palette[ $origin ] ?? [] as $entry ) {
				if ( isset( $entry['slug'], $entry['color'] ) && $slug === $entry['slug'] ) {
					return $entry['color'];
				}
			}
		}

		return null;
	}

	/**
	 * Read one of the theme's state shares from the settings.custom.state tokens.
	 *
	 * @param string $state   The token name.
	 * @param float  $default The theme's own value, used when the token is unavailable.
	 * @return float The share in the 0..1 range.
	 */
	public static function get_state_share( string $state, float $default ): float {
		$value = \wp_get_global_settings( [ 'custom', 'state', $state ] );
		if ( is_string( $value ) && preg_match( '/^\s*(\d{1,3}(?:\.\d+)?)%\s*$/', $value, $matches ) && (float) $matches[1] <= 100 ) {
			return (float) $matches[1] / 100;
		}

		return $default;
	}

	/**
	 * Mix two hex colors in oklab, as color-mix( in oklab, <a> <share>, <b> ) does.
	 *
	 * @param string $a     The first hex color.
	 * @param string $b     The second hex color.
	 * @param float  $share The first color's share, in the 0..1 range.
	 * @return string|null The mixed '#rrggbb' color, or null when a color is not parseable.
	 */
	public static function mix( string $a, string $b, float $share ): ?string {
		$lab_a = self::to_oklab( $a );
		$lab_b = self::to_oklab( $b );
		if ( null === $lab_a || null === $lab_b ) {
			return null;
		}

		$mixed = [];
		foreach ( $lab_a as $i => $component ) {
			$mixed[ $i ] = $share * $component + ( 1 - $share ) * $lab_b[ $i ];
		}

		return self::from_oklab( $mixed );
	}

	/**
	 * Convert a hex color to oklab.
	 *
	 * @param string $hex The hex color.
	 * @return float[]|null [ L, a, b ], or null when unparseable.
	 */
	public static function to_oklab( string $hex ): ?array {
		$channels = self::parse_hex( $hex );
		if ( null === $channels ) {
			return null;
		}

		[ $r, $g, $b ] = array_map( [ __CLASS__, 'to_linear' ], $channels );
		$l = pow( 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b, 1 / 3 );
		$m = pow( 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b, 1 / 3 );
		$s = pow( 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b, 1 / 3 );

		return [
			0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
			1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
			0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
		];
	}

	/**
	 * Convert an oklab color to a hex color, clamping to the sRGB gamut.
	 *
	 * @param float[] $lab [ L, a, b ].
	 * @return string The '#rrggbb' color.
	 */
	private static function from_oklab( array $lab ): string {
		[ $big_l, $a, $b ] = $lab;
		$l = pow( $big_l + 0.3963377774 * $a + 0.2158037573 * $b, 3 );
		$m = pow( $big_l - 0.1055613458 * $a - 0.0638541728 * $b, 3 );
		$s = pow( $big_l - 0.0894841775 * $a - 1.2914855480 * $b, 3 );

		$channels = [
			4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
			-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
			-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
		];

		return sprintf( '#%02x%02x%02x', ...array_map( [ __CLASS__, 'to_srgb' ], $channels ) );
	}

	/**
	 * Decode an 8-bit sRGB channel to linear light.
	 *
	 * @param int $channel The channel, 0..255.
	 * @return float
	 */
	private static function to_linear( int $channel ): float {
		$value = $channel / 255;
		return $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * Encode a linear-light channel as an 8-bit sRGB channel.
	 *
	 * @param float $value The linear channel, clamped to 0..1.
	 * @return int
	 */
	private static function to_srgb( float $value ): int {
		$value = max( 0.0, min( 1.0, $value ) );
		return (int) round( 255 * ( $value <= 0.0031308 ? $value * 12.92 : 1.055 * pow( $value, 1 / 2.4 ) - 0.055 ) );
	}

	/**
	 * Parse a hex color into its 0..255 channels.
	 *
	 * Accepts #rgb, #rrggbb and #rrggbbaa, with or without the leading #.
	 *
	 * @param string $hex The hex color.
	 * @return int[]|null [ r, g, b ], or null when unparseable.
	 */
	public static function parse_hex( string $hex ): ?array {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		} elseif ( 8 === strlen( $hex ) ) {
			$hex = substr( $hex, 0, 6 );
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return null;
		}

		return [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
	}
}

Button_States::init();
