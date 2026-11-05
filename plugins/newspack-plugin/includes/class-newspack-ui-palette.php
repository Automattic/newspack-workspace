<?php
/**
 * Newspack UI palette colour math.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Pure colour helpers for deriving the Newspack UI palette from a theme palette.
 *
 * Keep in sync with Newspack_Block_Theme\Button_States and Newspack_Block_Theme\Status_Colors in the block theme.
 */
class Newspack_UI_Palette {

	/**
	 * Lc targets for the solved mixes.
	 */
	public const TARGETS = [
		'text-muted'   => 60,
		'text-subtle'  => 45,
		'border'       => 30,
		'input-border' => 30,
		'focus-halo'   => 30,
	];

	/**
	 * Share of the contrast color in the subtle surface on a dark base.
	 */
	private const SUBTLE_DARK_SHARE = 4;

	/**
	 * Tint multiplied over a light base for the subtle surface.
	 */
	private const SUBTLE_LIGHT_TINT = '#f7f7f7';

	/**
	 * Smallest mix percent, at or above the start, whose mix reaches an Lc target on the base.
	 *
	 * @param string $contrast_hex  Color mixed in.
	 * @param string $base_hex      Color mixed into.
	 * @param int    $start_percent First percent to try.
	 * @param int    $target_lc     Minimum Lc of the mix on the base.
	 * @param string $surface_hex   Optional surface the mix must also reach the target on.
	 *
	 * @return int Percent, at most 100.
	 */
	public static function solve_mix( string $contrast_hex, string $base_hex, int $start_percent, int $target_lc, string $surface_hex = '' ): int {
		$percent = max( 0, $start_percent );
		while ( $percent < 100 ) {
			$mixed = self::mix( $contrast_hex, $percent, $base_hex );
			if ( self::lc( $mixed, $base_hex ) >= $target_lc && ( '' === $surface_hex || self::lc( $mixed, $surface_hex ) >= $target_lc ) ) {
				break;
			}
			++$percent;
		}

		return min( 100, $percent );
	}

	/**
	 * Subtle surface color: the block theme's notice tint.
	 *
	 * On a light base the tint is multiplied over it; on a dark base the contrast color is mixed in at 4%.
	 *
	 * @param string $contrast_hex Text color on the base.
	 * @param string $base_hex     Base color.
	 *
	 * @return string The '#rrggbb' color.
	 */
	public static function surface_subtle( string $contrast_hex, string $base_hex ): string {
		if ( 'white' === newspack_get_color_contrast( $base_hex ) ) {
			return self::mix( $contrast_hex, self::SUBTLE_DARK_SHARE, $base_hex );
		}

		return self::multiply( self::SUBTLE_LIGHT_TINT, $base_hex );
	}

	/**
	 * Mix two hex colors in oklab, as CSS color-mix( in oklab, <fg> <percent>%, <bg> ) does.
	 *
	 * @param string $foreground_hex Foreground hex color.
	 * @param float  $percent        Foreground share, 0..100.
	 * @param string $background_hex Background hex color.
	 *
	 * @return string The mixed '#rrggbb' color, or the background argument when either input is unparseable.
	 */
	public static function mix( string $foreground_hex, float $percent, string $background_hex ): string {
		$fg = self::to_oklab( $foreground_hex );
		$bg = self::to_oklab( $background_hex );
		if ( null === $fg || null === $bg ) {
			return $background_hex;
		}

		$share = max( 0.0, min( 100.0, $percent ) ) / 100;
		$mixed = [];
		foreach ( $fg as $i => $component ) {
			$mixed[ $i ] = $share * $component + ( 1 - $share ) * $bg[ $i ];
		}

		return self::from_oklab( $mixed );
	}

	/**
	 * Hue of a hex color in OKLCH.
	 *
	 * @param string $hex Hex color.
	 *
	 * @return float Degrees, 0..360. Unparseable input returns 0.
	 */
	public static function oklch_hue( string $hex ): float {
		$lab = self::to_oklab( $hex );
		if ( null === $lab ) {
			return 0.0;
		}

		$degrees = rad2deg( atan2( $lab[2], $lab[1] ) );

		return $degrees < 0 ? $degrees + 360 : $degrees;
	}

	/**
	 * OKLab lightness of a hex color.
	 *
	 * @param string $hex Hex color.
	 *
	 * @return float Lightness, 0..1. Unparseable input returns 0.
	 */
	public static function oklab_lightness( string $hex ): float {
		$lab = self::to_oklab( $hex );

		return null === $lab ? 0.0 : $lab[0];
	}

	/**
	 * Absolute APCA lightness contrast (Lc) of text on a background.
	 *
	 * @param string $text_hex       Text hex color.
	 * @param string $background_hex Background hex color.
	 *
	 * @return float Lc magnitude, sign-free. Unparseable input returns 0.
	 */
	public static function lc( string $text_hex, string $background_hex ): float {
		if ( null === newspack_parse_hex_channels( $text_hex ) || null === newspack_parse_hex_channels( $background_hex ) ) {
			return 0.0;
		}

		return abs(
			newspack_get_apca_contrast(
				newspack_get_apca_luminance( $background_hex ),
				newspack_get_apca_luminance( $text_hex )
			)
		);
	}

	/**
	 * Multiply a color over a background, per sRGB channel.
	 *
	 * @param string $color      Hex color.
	 * @param string $background Hex background.
	 *
	 * @return string The '#rrggbb' color, or the color when either is unparseable.
	 */
	private static function multiply( string $color, string $background ): string {
		$a = newspack_parse_hex_channels( $color );
		$b = newspack_parse_hex_channels( $background );
		if ( null === $a || null === $b ) {
			return $color;
		}

		return sprintf( '#%02x%02x%02x', (int) round( $a[0] * $b[0] / 255 ), (int) round( $a[1] * $b[1] / 255 ), (int) round( $a[2] * $b[2] / 255 ) );
	}

	/**
	 * Convert a hex color to oklab.
	 *
	 * @param string $hex Hex color.
	 *
	 * @return float[]|null [ L, a, b ], or null when unparseable.
	 */
	private static function to_oklab( string $hex ): ?array {
		$channels = newspack_parse_hex_channels( $hex );
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
	 *
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
	 * @param int $channel Channel, 0..255.
	 *
	 * @return float
	 */
	private static function to_linear( int $channel ): float {
		$value = $channel / 255;

		return $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * Encode a linear-light channel as an 8-bit sRGB channel.
	 *
	 * @param float $value Linear channel, clamped to 0..1.
	 *
	 * @return int
	 */
	private static function to_srgb( float $value ): int {
		$value = max( 0.0, min( 1.0, $value ) );

		return (int) round( 255 * ( $value <= 0.0031308 ? $value * 12.92 : 1.055 * pow( $value, 1 / 2.4 ) - 0.055 ) );
	}
}
