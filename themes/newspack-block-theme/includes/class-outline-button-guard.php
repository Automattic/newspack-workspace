<?php
/**
 * Newspack Block Theme Outline button readability guard.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the Outline button style readable on the base color.
 *
 * The Outline style takes the accent for its text and border. When the accent
 * on the base falls below APCA Lc 45, the bar for 16px semibold button labels,
 * the text and border use the theme's text color instead, through the
 * --wp--custom--button--outline custom property that the Outline rules read.
 * A text color the publisher set for the Outline style in Styles is left alone.
 */
final class Outline_Button_Guard {
	const MIN_LC = 45;

	/**
	 * Initializer.
	 */
	public static function init(): void {
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
	}

	/**
	 * Emit the Outline color on the front end and the editor canvas when the guard trips.
	 *
	 * @return void
	 */
	public static function enqueue_block_assets(): void {
		if ( ! self::is_tripped() ) {
			return;
		}

		$handle = 'newspack-block-theme-outline-button-guard';
		\wp_register_style( $handle, false, [], \wp_get_theme()->get( 'Version' ) );
		\wp_enqueue_style( $handle );
		\wp_add_inline_style( $handle, ':root:root, :root .editor-styles-wrapper { --wp--custom--button--outline: var(--wp--preset--color--contrast); }' );
	}

	/**
	 * Whether the accent is too faint on the site's base color for the Outline button.
	 *
	 * @return bool False when the accent reads, or when the colors are not plain colors or the publisher set the Outline text color.
	 */
	public static function is_tripped(): bool {
		$user = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
		if ( ! empty( $user['styles']['blocks']['core/button']['variations']['outline']['color']['text'] ) ) {
			return false;
		}

		$accent = Contrast::resolve_color( Contrast::resolve_palette_color( 'accent' ) );
		$base   = Contrast::resolve_color( Contrast::resolve_palette_color( 'base' ) );
		if ( '' === $accent || '' === $base ) {
			return false;
		}

		return Status_Colors::lc( $accent, $base ) < self::MIN_LC;
	}
}

Outline_Button_Guard::init();
