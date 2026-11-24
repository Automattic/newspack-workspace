<?php
/**
 * Newspack Block Theme button hover and pressed readability guard.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps button text readable on the hover and pressed fills.
 *
 * Those fills mix the button fill toward the base. On a fill close to the base
 * the mix washes out, and the button text stops reading. When the text on
 * either fill falls below APCA Lc 45, both states mix toward the text side
 * instead, black for light text and white for dark text, through the
 * --wp--custom--button--mix custom property that the hover and pressed rules
 * read. A hover or pressed fill the publisher set in Styles is left alone.
 */
final class Button_Hover_Guard {
	const MIN_LC = 45;

	/**
	 * Initializer.
	 */
	public static function init(): void {
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
	}

	/**
	 * Emit the mix target on the front end and the editor canvas when the guard trips.
	 *
	 * @return void
	 */
	public static function enqueue_block_assets(): void {
		$target = self::get_target();
		if ( null === $target ) {
			return;
		}

		$handle = 'newspack-block-theme-button-hover-guard';
		\wp_register_style( $handle, false, [], \wp_get_theme()->get( 'Version' ) );
		\wp_enqueue_style( $handle );
		\wp_add_inline_style( $handle, sprintf( ':root:root, :root .editor-styles-wrapper { --wp--custom--button--mix: %s; }', $target ) );
	}

	/**
	 * Resolve the mix target for the site's current global styles.
	 *
	 * @return string|null '#000000' or '#ffffff' when the guard trips, null when the default mix is readable or the inputs are not plain colors.
	 */
	public static function get_target(): ?string {
		$button = Contrast::get_button_styles();
		if ( ! empty( $button['color']['gradient'] ) ) {
			return null;
		}

		$fill = Contrast::resolve_color( $button['color']['background'] ?? '' );
		$base = Contrast::resolve_color( Contrast::resolve_palette_color( 'base' ) );
		if ( '' === $fill || '' === $base ) {
			return null;
		}

		$text = Contrast::resolve_color( $button['color']['text'] ?? '' );
		if ( '' === $text ) {
			$text = Contrast::get_text_color( Contrast::resolve_palette_color( 'accent' ) ) ?? '';
		}
		if ( '' === $text ) {
			return null;
		}

		$user    = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
		$skipped = [
			'hover'   => ! empty( $user['styles']['elements']['button'][':hover']['color']['background'] ),
			'pressed' => ! empty( $user['styles']['elements']['button'][':active']['color']['background'] ),
		];

		return self::evaluate(
			$fill,
			$text,
			$base,
			[
				'hover'   => Button_States::get_state_share( 'hover', 0.85 ),
				'pressed' => Button_States::get_state_share( 'pressed', 0.75 ),
			],
			array_keys( array_filter( $skipped ) )
		)['target'];
	}

	/**
	 * Measure the button text on the hover and pressed fills and decide the mix target.
	 *
	 * @param string   $fill    The button fill, a hex color.
	 * @param string   $text    The button text color, a hex color.
	 * @param string   $base    The base color, a hex color.
	 * @param float[]  $shares  The 'hover' and 'pressed' fill shares, in the 0..1 range.
	 * @param string[] $skipped The states to leave out, because the publisher set their fill.
	 * @return array{hover: float, pressed: float, target: string|null} The APCA |Lc| of the text on each mixed fill, and the target when the guard trips.
	 */
	public static function evaluate( string $fill, string $text, string $base, array $shares, array $skipped = [] ): array {
		$lc      = [];
		$tripped = false;
		foreach ( [ 'hover', 'pressed' ] as $state ) {
			$mixed       = Button_States::mix( $fill, $base, $shares[ $state ] );
			$lc[ $state ] = null === $mixed ? 0.0 : Status_Colors::lc( $text, $mixed );
			if ( null !== $mixed && ! in_array( $state, $skipped, true ) && $lc[ $state ] < self::MIN_LC ) {
				$tripped = true;
			}
		}

		$target = null;
		if ( $tripped ) {
			$text_y = Contrast::get_apca_luminance( $text );
			$fill_y = Contrast::get_apca_luminance( $fill );
			$target = $text_y > $fill_y ? '#000000' : '#ffffff';
		}

		return [
			'hover'   => $lc['hover'],
			'pressed' => $lc['pressed'],
			'target'  => $target,
		];
	}
}

Button_Hover_Guard::init();
