<?php
/**
 * Newspack Block Theme status colors.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Derives error, success and warning colors that stay readable on the site's
 * base color and keeps error and success out of the way of a red or green brand.
 *
 * The newspack-ui "Theme" style is meant to derive the same colors, so
 * tests/test-status-colors.php pins the rule with fixed vectors; change them
 * only together with that style.
 */
final class Status_Colors {
	const ERROR_TEXT_STEPS = [ '#b32d2e', '#8a2424', '#691c1c' ];

	const ERROR_EDGE_STEPS = [ '#d63638', '#b32d2e', '#8a2424' ];

	const ERROR_DARK_STEPS = [ '#d63638', '#facfd2', '#fcf0f1' ];

	const SUCCESS_TEXT_STEPS = [ '#007017', '#005c12', '#00450c' ];

	const SUCCESS_MARK_STEPS = [ '#008a20', '#007017', '#005c12' ];

	const SUCCESS_DARK_STEPS = [ '#008a20', '#b8e6bf', '#edfaef' ];

	const WARNING_TEXT_STEPS = [ '#755100' ];

	const WARNING_MARK_STEPS = [ '#bd8600', '#996800', '#755100' ];

	const WARNING_DARK_STEPS = [ '#dba617', '#fcf9e8' ];

	const RED = '#d63638';

	const GREEN = '#008a20';

	const BRAND_HUE_THRESHOLD = 25;

	const BRAND_NEAR_HUE = 12;

	const BRAND_LIGHTNESS_MARGIN = 0.02;

	const MIN_CHROMA = 0.04;

	const TEXT_LC = 60;

	const MARK_LC = 25;

	const TINT_SHARE = 12;

	const NOTICE_SHARE = 4;

	const DEFAULTS = [
		'error'        => '#d63638',
		'error-text'   => '#b32d2e',
		'error-tint'   => '#fcf0f1',
		'success'      => '#008a20',
		'success-text' => '#007017',
		'success-tint' => '#edfaef',
		'warning'      => '#bd8600',
		'warning-text' => '#755100',
		'warning-tint' => '#fcf9e8',
		'notice-tint'  => '#f7f7f7',
	];

	/**
	 * Initializer.
	 */
	public static function init(): void {
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
	}

	/**
	 * Emit the status colors that differ from the theme.json defaults on the
	 * front end and the editor canvas.
	 *
	 * @return void
	 */
	public static function enqueue_block_assets(): void {
		$base = self::normalize_hex( Contrast::resolve_palette_color( 'base' ) );
		if ( '' === $base ) {
			return;
		}
		$contrast = self::normalize_hex( Contrast::resolve_palette_color( 'contrast' ) );

		$brand = Contrast::resolve_color( Contrast::get_button_styles()['color']['background'] ?? '' );
		if ( '' === $brand ) {
			$brand = self::normalize_hex( Contrast::resolve_palette_color( 'accent' ) );
		}
		if ( '' === $brand ) {
			$brand = '' !== $contrast ? $contrast : $base;
		}

		$steps = self::steps( $base, $brand, $contrast );
		$roles = [
			'error'        => $steps['error']['edge'],
			'error-text'   => $steps['error']['text'],
			'error-tint'   => $steps['error']['tint'],
			'success'      => $steps['success']['mark'],
			'success-text' => $steps['success']['text'],
			'success-tint' => $steps['success']['tint'],
			'warning'      => $steps['warning']['mark'],
			'warning-text' => $steps['warning']['text'],
			'warning-tint' => $steps['warning']['tint'],
			'notice-tint'  => $steps['notice']['tint'],
		];

		$declarations = '';
		foreach ( $roles as $role => $value ) {
			if ( self::DEFAULTS[ $role ] !== $value ) {
				$declarations .= sprintf( ' --wp--custom--color--%s: %s;', $role, $value );
			}
		}
		if ( '' === $declarations ) {
			return;
		}

		$handle = 'newspack-block-theme-status-colors';
		\wp_register_style( $handle, false, [], \wp_get_theme()->get( 'Version' ) );
		\wp_enqueue_style( $handle );
		\wp_add_inline_style( $handle, ':root:root, :root .editor-styles-wrapper {' . $declarations . ' }' );
	}

	/**
	 * Pick the status colors for a base and a brand.
	 *
	 * Text reaches APCA Lc 60 on the base and marks (borders, edges) Lc 25. On a
	 * light base each role starts at its default step and moves only darker; on a
	 * dark base it takes the lightest scale steps. A red brand moves error away
	 * from the brand's lightness, and a green brand moves success the same way.
	 * On a light base each tint is the role's default tint multiplied over the
	 * base; on a dark base it is 12% of the mark over the base.
	 *
	 * @param string $base_hex     The base color.
	 * @param string $brand_hex    The brand color.
	 * @param string $contrast_hex The text color on the base, black or white by APCA when empty.
	 * @return array{error: array{text: string, edge: string, tint: string}, success: array{text: string, mark: string, tint: string}, warning: array{text: string, mark: string, tint: string}, notice: array{tint: string}}
	 */
	public static function steps( string $base_hex, string $brand_hex, string $contrast_hex = '' ): array {
		$base         = self::normalize_hex( $base_hex );
		$base         = '' === $base ? '#ffffff' : $base;
		$brand_hex    = self::normalize_hex( $brand_hex );
		$brand_hex    = '' === $brand_hex ? $base : $brand_hex;
		$contrast_hex = self::normalize_hex( $contrast_hex );
		$dark         = self::is_dark( $base );

		[ $error_text, $edge ]           = self::role_steps( self::ERROR_TEXT_STEPS, self::ERROR_EDGE_STEPS, self::ERROR_DARK_STEPS, self::RED, $base, $brand_hex, $dark );
		[ $success_text, $success_mark ] = self::role_steps( self::SUCCESS_TEXT_STEPS, self::SUCCESS_MARK_STEPS, self::SUCCESS_DARK_STEPS, self::GREEN, $base, $brand_hex, $dark );
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
				'error'   => self::multiply( self::DEFAULTS['error-tint'], $base ),
				'success' => self::multiply( self::DEFAULTS['success-tint'], $base ),
				'warning' => self::multiply( self::DEFAULTS['warning-tint'], $base ),
				'notice'  => self::multiply( self::DEFAULTS['notice-tint'], $base ),
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
	 * OKLab lightness of a hex color.
	 *
	 * @param string $hex The color.
	 * @return float
	 */
	public static function oklab_lightness( string $hex ): float {
		$lab = Button_States::to_oklab( $hex );
		return null === $lab ? 0.0 : $lab[0];
	}

	/**
	 * OKLCH hue of a hex color, in degrees from 0 to 360.
	 *
	 * @param string $hex The color.
	 * @return float
	 */
	public static function oklch_hue( string $hex ): float {
		$lab = Button_States::to_oklab( $hex );
		if ( null === $lab ) {
			return 0.0;
		}
		$hue = rad2deg( atan2( $lab[2], $lab[1] ) );

		return $hue < 0 ? $hue + 360 : $hue;
	}

	/**
	 * Mix a color over a background, as color-mix( in oklab, <fg> <percent>%, <bg> ) does.
	 *
	 * @param string $foreground The foreground hex color.
	 * @param float  $percent    The foreground's share, 0 to 100.
	 * @param string $background The background hex color.
	 * @return string The '#rrggbb' color, or the background when a color is not parseable.
	 */
	public static function mix( string $foreground, float $percent, string $background ): string {
		return Button_States::mix( $foreground, $background, $percent / 100 ) ?? $background;
	}

	/**
	 * Absolute APCA Lc of text on a background.
	 *
	 * @param string $text       The text hex color.
	 * @param string $background The background hex color.
	 * @return float
	 */
	public static function lc( string $text, string $background ): float {
		$text_y       = Contrast::get_apca_luminance( $text );
		$background_y = Contrast::get_apca_luminance( $background );
		if ( null === $text_y || null === $background_y ) {
			return 0.0;
		}

		return abs( Contrast::get_apca_contrast( $background_y, $text_y ) );
	}

	/**
	 * Normalize a plain hex color to lowercase #rrggbb.
	 *
	 * @param mixed $value The value.
	 * @return string The color, or an empty string when it is not a plain hex color.
	 */
	private static function normalize_hex( mixed $value ): string {
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
	 * Multiply a color over a background, per sRGB channel.
	 *
	 * @param string $color      The hex color.
	 * @param string $background The hex background.
	 * @return string The '#rrggbb' color.
	 */
	private static function multiply( string $color, string $background ): string {
		$a = Button_States::parse_hex( $color );
		$b = Button_States::parse_hex( $background );
		if ( null === $a || null === $b ) {
			return $color;
		}

		return sprintf( '#%02x%02x%02x', (int) round( $a[0] * $b[0] / 255 ), (int) round( $a[1] * $b[1] / 255 ), (int) round( $a[2] * $b[2] / 255 ) );
	}

	/**
	 * Whether white text reads better than black on a base by APCA.
	 *
	 * @param string $base The base hex color.
	 * @return bool
	 */
	public static function is_dark( string $base ): bool {
		return '#ffffff' === Contrast::get_text_color( $base );
	}

	/**
	 * The index of the first step that reaches a contrast, or the last step.
	 *
	 * @param string[] $steps The steps, in the order they are tried.
	 * @param string   $base  The base hex color.
	 * @param float    $min   The minimum Lc.
	 * @param int      $from  The index to start at.
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
	 * The first step that reaches a contrast, or the last step.
	 *
	 * @param string[] $steps The steps, in the order they are tried.
	 * @param string   $base  The base hex color.
	 * @param float    $min   The minimum Lc.
	 * @return string
	 */
	private static function first( array $steps, string $base, float $min ): string {
		return $steps[ self::pick( $steps, $base, $min ) ];
	}

	/**
	 * The text and mark of a status role.
	 *
	 * The text reaches Lc 60 on the base, after moving away from a brand of the
	 * role's hue. On a light base the mark starts at the text's step on a scale
	 * one step lighter; on a dark base it moves as far as the text did.
	 *
	 * @param string[] $text_steps The text steps on a light base, in the order they are tried.
	 * @param string[] $mark_steps The mark steps on a light base, one step lighter than the text steps.
	 * @param string[] $dark_steps The text and mark steps on a dark base.
	 * @param string   $hue        The hex color of the role's hue.
	 * @param string   $base       The base hex color.
	 * @param string   $brand      The brand hex color.
	 * @param bool     $dark       Whether the base is dark.
	 * @return string[] The text and the mark.
	 */
	private static function role_steps( array $text_steps, array $mark_steps, array $dark_steps, string $hue, string $base, string $brand, bool $dark ): array {
		$steps = $dark ? $dark_steps : $text_steps;
		$start = self::pick( $steps, $base, self::TEXT_LC );
		$index = self::move_from_brand( $steps, $start, $brand, $dark, $hue );
		$index = self::pick( $steps, $base, self::TEXT_LC, $index );
		if ( $dark ) {
			$mark_start = self::pick( $dark_steps, $base, self::MARK_LC );
			$mark       = $dark_steps[ min( $mark_start + $index - $start, count( $dark_steps ) - 1 ) ];
		} else {
			$mark = $mark_steps[ self::pick( $mark_steps, $base, self::MARK_LC, $index ) ];
		}

		return [ $steps[ $index ], $mark ];
	}

	/**
	 * Move a status text step away from a brand of the role's hue.
	 *
	 * @param string[] $steps The text steps, in the order they are tried.
	 * @param int      $index The chosen step.
	 * @param string   $brand The brand hex color.
	 * @param bool     $dark  Whether the base is dark.
	 * @param string   $hue   The hex color of the role's hue.
	 * @return int The step index after the move, clamped to the scale.
	 */
	private static function move_from_brand( array $steps, int $index, string $brand, bool $dark, string $hue ): int {
		$brand_lab = Button_States::to_oklab( $brand );
		if ( null === $brand_lab || hypot( $brand_lab[1], $brand_lab[2] ) < self::MIN_CHROMA ) {
			return $index;
		}

		$distance = abs( self::oklch_hue( $brand ) - self::oklch_hue( $hue ) );
		$distance = min( $distance, 360 - $distance );
		if ( $distance > self::BRAND_HUE_THRESHOLD ) {
			return $index;
		}

		$step_lightness = self::oklab_lightness( $steps[ $index ] );
		$near           = $dark
			? $brand_lab[0] <= $step_lightness + self::BRAND_LIGHTNESS_MARGIN
			: $brand_lab[0] >= $step_lightness - self::BRAND_LIGHTNESS_MARGIN;
		if ( ! $near ) {
			return $index;
		}

		return min( $index + ( $distance <= self::BRAND_NEAR_HUE ? 2 : 1 ), count( $steps ) - 1 );
	}
}

Status_Colors::init();
