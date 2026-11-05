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
 * Keep in sync with Newspack_Block_Theme\Button_States and Newspack_Block_Theme\Status_Colors in the block theme: status_steps() is a port of Status_Colors::steps(), and both are pinned by the same vectors.
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

	public const ERROR_TEXT_STEPS = [ '#b32d2e', '#8a2424', '#691c1c' ];

	public const ERROR_EDGE_STEPS = [ '#d63638', '#b32d2e', '#8a2424' ];

	public const ERROR_DARK_STEPS = [ '#d63638', '#facfd2', '#fcf0f1' ];

	public const SUCCESS_TEXT_STEPS = [ '#007017' ];

	public const SUCCESS_MARK_STEPS = [ '#008a20', '#007017' ];

	public const SUCCESS_DARK_STEPS = [ '#008a20', '#b8e6bf' ];

	public const WARNING_TEXT_STEPS = [ '#755100' ];

	public const WARNING_MARK_STEPS = [ '#bd8600', '#996800', '#755100' ];

	public const WARNING_DARK_STEPS = [ '#dba617', '#fcf9e8' ];

	public const RED = '#d63638';

	public const RED_HUE_THRESHOLD = 25;

	public const RED_NEAR_HUE = 12;

	public const RED_LIGHTNESS_MARGIN = 0.02;

	public const MIN_CHROMA = 0.04;

	public const TEXT_LC = 60;

	public const MARK_LC = 25;

	public const INK_ON_PRESSED_LC = 60;

	public const INK_ON_PRESSED_MIN_LC = 45;

	public const PRESSED_SHARE = 20;

	public const PRESSED_SHARE_FLOOR = 14;

	public const TINT_SHARE = 12;

	public const BORDER_SHARE = 24;

	public const NOTICE_SHARE = 4;

	public const DISABLED_INK_SHARE = 45;

	/**
	 * Neutral status values: the tints and borders multiplied over a light base, and values never emitted.
	 */
	public const STATUS_DEFAULTS = [
		'error'              => '#d63638',
		'error-text'         => '#b32d2e',
		'error-tint'         => '#fcf0f1',
		'error-border'       => '#facfd2',
		'error-tint-hover'   => '#fcf0f1',
		'error-tint-pressed' => '#facfd2',
		'success'            => '#008a20',
		'success-text'       => '#007017',
		'success-tint'       => '#edfaef',
		'success-border'     => '#b8e6bf',
		'warning'            => '#bd8600',
		'warning-text'       => '#755100',
		'warning-tint'       => '#fcf9e8',
		'warning-border'     => '#f5e6ab',
		'warning-dot'        => '#996800',
		'disabled-ink'       => '#919191',
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
	 * Pick the status colors for a base and a brand.
	 *
	 * Keep in sync with Newspack_Block_Theme\Status_Colors::steps(): the theme's SCSS reads newspack-ui's status
	 * tokens first, so the two must give the same values. Change the rule and the shared vectors together.
	 *
	 * Text reaches APCA Lc 60 on the base and marks Lc 25. On a light base each role starts at its default step
	 * and moves only darker; on a dark base it takes the lightest scale steps. A red brand moves error away from
	 * the brand's lightness. Light tints are the default tint multiplied over the base; dark tints are 12% of the
	 * mark over the base.
	 *
	 * @param string $base_hex     Base color.
	 * @param string $brand_hex    Brand color.
	 * @param string $contrast_hex Text color on the base; white on a dark base and black on a light one when unusable.
	 *
	 * @return array{error: array{text: string, edge: string, tint: string}, success: array{text: string, mark: string, tint: string}, warning: array{text: string, mark: string, tint: string}, notice: array{tint: string}}
	 */
	public static function status_steps( string $base_hex, string $brand_hex, string $contrast_hex = '' ): array {
		$base         = self::normalize_hex( $base_hex );
		$base         = '' === $base ? '#ffffff' : $base;
		$brand_hex    = self::normalize_hex( $brand_hex );
		$brand_hex    = '' === $brand_hex ? $base : $brand_hex;
		$contrast_hex = self::normalize_hex( $contrast_hex );
		$dark         = self::is_dark( $base );

		$text_steps = $dark ? self::ERROR_DARK_STEPS : self::ERROR_TEXT_STEPS;
		$start      = self::pick( $text_steps, $base, self::TEXT_LC );
		$index      = self::move_from_brand( $text_steps, $start, $brand_hex, $dark );
		$index      = self::pick( $text_steps, $base, self::TEXT_LC, $index );
		if ( $dark ) {
			$edge_start = self::pick( self::ERROR_DARK_STEPS, $base, self::MARK_LC );
			$edge       = self::ERROR_DARK_STEPS[ min( $edge_start + $index - $start, count( self::ERROR_DARK_STEPS ) - 1 ) ];
		} else {
			$edge = self::ERROR_EDGE_STEPS[ self::pick( self::ERROR_EDGE_STEPS, $base, self::MARK_LC, $index ) ];
		}
		$error_text = $text_steps[ $index ];

		$success_text = self::first( $dark ? self::SUCCESS_DARK_STEPS : self::SUCCESS_TEXT_STEPS, $base, self::TEXT_LC );
		$success_mark = self::first( $dark ? self::SUCCESS_DARK_STEPS : self::SUCCESS_MARK_STEPS, $base, self::MARK_LC );
		$warning_text = self::first( $dark ? self::WARNING_DARK_STEPS : self::WARNING_TEXT_STEPS, $base, self::TEXT_LC );
		$warning_mark = self::first( $dark ? self::WARNING_DARK_STEPS : self::WARNING_MARK_STEPS, $base, self::MARK_LC );

		if ( '' === $contrast_hex ) {
			$contrast_hex = $dark ? '#ffffff' : '#000000';
		}

		if ( $dark ) {
			$tints = [
				'error'   => self::mix( $edge, self::TINT_SHARE, $base ),
				'success' => self::mix( $success_mark, self::TINT_SHARE, $base ),
				'warning' => self::mix( $warning_mark, self::TINT_SHARE, $base ),
				'notice'  => self::mix( $contrast_hex, self::NOTICE_SHARE, $base ),
			];
		} else {
			$tints = [
				'error'   => self::multiply( self::STATUS_DEFAULTS['error-tint'], $base ),
				'success' => self::multiply( self::STATUS_DEFAULTS['success-tint'], $base ),
				'warning' => self::multiply( self::STATUS_DEFAULTS['warning-tint'], $base ),
				'notice'  => self::multiply( self::SUBTLE_LIGHT_TINT, $base ),
			];
		}

		return [
			'error'   => [
				'text' => $error_text,
				'edge' => $edge,
				'tint' => $tints['error'],
			],
			'success' => [
				'text' => $success_text,
				'mark' => $success_mark,
				'tint' => $tints['success'],
			],
			'warning' => [
				'text' => $warning_text,
				'mark' => $warning_mark,
				'tint' => $tints['warning'],
			],
			'notice'  => [ 'tint' => $tints['notice'] ],
		];
	}

	/**
	 * The newspack-ui status tokens, keyed without the --newspack-ui-color- prefix, that differ from Neutral.
	 *
	 * Roles the block theme covers map as error.edge to error, error.text to error-text, error.tint to
	 * error-tint, and likewise for success and warning (mark to success and warning). The rest are derived
	 * from their covered sibling the way Neutral relates them, against the same base: borders from the mark,
	 * warning-dot one step darker than the warning mark, error-tint-hover as the tint, error-tint-pressed one step
	 * stronger than the tint (never the border), disabled-ink as the contrast color mixed into the base.
	 *
	 * @param string $base_hex     Base color.
	 * @param string $brand_hex    Brand color.
	 * @param string $contrast_hex Text color on the base.
	 *
	 * @return array<string,string> Token name to '#rrggbb', without values equal to the Neutral default.
	 */
	public static function status_properties( string $base_hex, string $brand_hex, string $contrast_hex = '' ): array {
		$base     = self::normalize_hex( $base_hex );
		$base     = '' === $base ? '#ffffff' : $base;
		$dark     = self::is_dark( $base );
		$contrast = self::normalize_hex( $contrast_hex );
		$contrast = '' === $contrast ? ( $dark ? '#ffffff' : '#000000' ) : $contrast;
		$steps    = self::status_steps( $base, $brand_hex, $contrast );

		$borders = [];
		foreach ( [
			'error'   => $steps['error']['edge'],
			'success' => $steps['success']['mark'],
			'warning' => $steps['warning']['mark'],
		] as $role => $mark ) {
			$borders[ $role ] = self::status_border( self::STATUS_DEFAULTS[ $role . '-border' ], $mark, $base, $dark );
		}

		$pressed = self::error_tint_pressed( $steps['error'], $base, $dark );

		$properties = [
			'error'              => $steps['error']['edge'],
			'error-text'         => $steps['error']['text'],
			'error-tint'         => $steps['error']['tint'],
			'error-border'       => $borders['error'],
			'error-tint-hover'   => $steps['error']['tint'],
			'error-tint-pressed' => $pressed,
			'success'            => $steps['success']['mark'],
			'success-text'       => $steps['success']['text'],
			'success-tint'       => $steps['success']['tint'],
			'success-border'     => $borders['success'],
			'warning'            => $steps['warning']['mark'],
			'warning-text'       => $steps['warning']['text'],
			'warning-tint'       => $steps['warning']['tint'],
			'warning-border'     => $borders['warning'],
			'warning-dot'        => self::warning_dot( $steps['warning']['mark'], $base, $dark ),
			'disabled-ink'       => self::mix( $contrast, self::DISABLED_INK_SHARE, $base ),
		];

		return array_diff_assoc( $properties, self::STATUS_DEFAULTS );
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
	 * Normalize a plain hex color to lowercase #rrggbb.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The color, or an empty string when it is not a plain 3 or 6 digit hex color.
	 */
	private static function normalize_hex( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', trim( $value ) ) ) {
			return '';
		}
		$hex = strtolower( ltrim( trim( $value ), '#' ) );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return '#' . $hex;
	}

	/**
	 * Whether white text reads better than black on a base by APCA.
	 *
	 * @param string $base Base hex color.
	 *
	 * @return bool
	 */
	private static function is_dark( string $base ): bool {
		return 'white' === newspack_get_color_contrast( $base );
	}

	/**
	 * Index of the first step that reaches a contrast, or of the last step.
	 *
	 * @param string[] $steps Steps, in the order they are tried.
	 * @param string   $base  Base hex color.
	 * @param float    $min   Minimum Lc.
	 * @param int      $from  Index to start at.
	 *
	 * @return int
	 */
	private static function pick( array $steps, string $base, float $min, int $from = 0 ): int {
		$last = count( $steps ) - 1;
		for ( $i = $from; $i < $last; $i++ ) {
			if ( self::lc( $steps[ $i ], $base ) >= $min ) {
				return $i;
			}
		}

		return $last;
	}

	/**
	 * First step that reaches a contrast, or the last step.
	 *
	 * @param string[] $steps Steps, in the order they are tried.
	 * @param string   $base  Base hex color.
	 * @param float    $min   Minimum Lc.
	 *
	 * @return string
	 */
	private static function first( array $steps, string $base, float $min ): string {
		return $steps[ self::pick( $steps, $base, $min ) ];
	}

	/**
	 * Move the error step away from a red brand.
	 *
	 * @param string[] $steps Error text steps, in the order they are tried.
	 * @param int      $index Chosen step.
	 * @param string   $brand Brand hex color.
	 * @param bool     $dark  Whether the base is dark.
	 *
	 * @return int Step index after the move, clamped to the scale.
	 */
	private static function move_from_brand( array $steps, int $index, string $brand, bool $dark ): int {
		$brand_lab = self::to_oklab( $brand );
		if ( null === $brand_lab || hypot( $brand_lab[1], $brand_lab[2] ) < self::MIN_CHROMA ) {
			return $index;
		}

		$distance = abs( self::oklch_hue( $brand ) - self::oklch_hue( self::RED ) );
		$distance = min( $distance, 360 - $distance );
		if ( $distance > self::RED_HUE_THRESHOLD ) {
			return $index;
		}

		$step_lightness = self::oklab_lightness( $steps[ $index ] );
		$near           = $dark
			? $brand_lab[0] <= $step_lightness + self::RED_LIGHTNESS_MARGIN
			: $brand_lab[0] >= $step_lightness - self::RED_LIGHTNESS_MARGIN;
		if ( ! $near ) {
			return $index;
		}

		return min( $index + ( $distance <= self::RED_NEAR_HUE ? 2 : 1 ), count( $steps ) - 1 );
	}

	/**
	 * A status border: the default border multiplied over a light base, or the mark mixed into a dark one.
	 *
	 * It must stay as visible against the base as Neutral's border is against white (at most Lc 25); when it
	 * is not, it steps toward the mark, up to the mark itself.
	 *
	 * @param string $default_border Neutral border.
	 * @param string $mark           Mark of the role.
	 * @param string $base           Base hex color.
	 * @param bool   $dark           Whether the base is dark.
	 *
	 * @return string
	 */
	private static function status_border( string $default_border, string $mark, string $base, bool $dark ): string {
		$border   = $dark ? self::mix( $mark, self::BORDER_SHARE, $base ) : self::multiply( $default_border, $base );
		$required = min( self::MARK_LC, self::lc( $default_border, '#ffffff' ) );

		return self::step_toward( $border, $mark, $required, $base );
	}

	/**
	 * Step a color toward a target, 5% of the target at a time, until it reaches an Lc on a background.
	 *
	 * @param string $color      Hex color.
	 * @param string $target     Hex color to step toward; the last step is the target itself.
	 * @param float  $min        Minimum Lc of the color on the background.
	 * @param string $background Hex background.
	 *
	 * @return string
	 */
	private static function step_toward( string $color, string $target, float $min, string $background ): string {
		$start = $color;
		$share = 5;
		while ( self::lc( $color, $background ) < $min && $share <= 100 ) {
			$color  = self::mix( $target, $share, $start );
			$share += 5;
		}

		return $color;
	}

	/**
	 * The pressed destructive tint, one step stronger than the error tint and never derived from the border.
	 *
	 * A light base multiplies the pressed default over it; a dark base mixes 20% of the error edge into it.
	 * The error text must read on it at Lc 60 (or as well as Neutral's pressed tint does, if that is lower), or at Lc 45 where that is unreachable: a light base steps toward
	 * the tint, a dark base lowers the share, never below the floor that keeps it above the hover tint.
	 *
	 * @param array  $error Error text, edge and tint.
	 * @param string $base  Base hex color.
	 * @param bool   $dark  Whether the base is dark.
	 *
	 * @return string
	 */
	private static function error_tint_pressed( array $error, string $base, bool $dark ): string {
		$ink        = $error['text'];
		$candidates = [];
		if ( $dark ) {
			for ( $share = self::PRESSED_SHARE; $share >= self::PRESSED_SHARE_FLOOR; $share-- ) {
				$candidates[] = self::mix( $error['edge'], $share, $base );
			}
		} else {
			$start = self::multiply( self::STATUS_DEFAULTS['error-tint-pressed'], $base );
			for ( $share = 0; $share <= 100; $share += 5 ) {
				$candidates[] = self::mix( $error['tint'], $share, $start );
			}
		}

		$target   = min( self::INK_ON_PRESSED_LC, self::lc( self::STATUS_DEFAULTS['error-text'], self::STATUS_DEFAULTS['error-tint-pressed'] ) );
		$fallback = null;
		foreach ( $candidates as $candidate ) {
			$lc = self::lc( $ink, $candidate );
			if ( $lc >= $target ) {
				return $candidate;
			}
			if ( null === $fallback && $lc >= self::INK_ON_PRESSED_MIN_LC ) {
				$fallback = $candidate;
			}
		}

		return $fallback ?? end( $candidates );
	}

	/**
	 * The warning dot: the mark itself on a dark base, one step darker than the mark on a light one.
	 *
	 * @param string $mark Warning mark.
	 * @param string $base Base hex color.
	 * @param bool   $dark Whether the base is dark.
	 *
	 * @return string
	 */
	private static function warning_dot( string $mark, string $base, bool $dark ): string {
		if ( $dark ) {
			return $mark;
		}
		$steps = self::WARNING_MARK_STEPS;
		$index = array_search( $mark, $steps, true );
		$dot   = $steps[ min( false === $index ? 0 : $index + 1, count( $steps ) - 1 ) ];
		$index = array_search( $dot, $steps, true );

		return $steps[ self::pick( $steps, $base, self::MARK_LC, (int) $index ) ];
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
