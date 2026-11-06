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
 * A text color the publisher set for the Outline style in Styles goes through
 * the same property and wins over the guard, because the theme's Outline rule
 * outranks the variation styles core prints.
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
	 * Emit the Outline color on the front end and the editor canvas when it differs from the accent.
	 *
	 * @return void
	 */
	public static function enqueue_block_assets(): void {
		$color = self::get_color();
		if ( null === $color ) {
			return;
		}

		$handle = 'newspack-block-theme-outline-button-guard';
		\wp_register_style( $handle, false, [], \wp_get_theme()->get( 'Version' ) );
		\wp_enqueue_style( $handle );
		\wp_add_inline_style( $handle, sprintf( ':root:root, :root .editor-styles-wrapper { --wp--custom--button--outline: %s; }', $color ) );
	}

	/**
	 * The Outline text and border color for the site's current global styles.
	 *
	 * @return string|null The publisher's Outline text color from Styles, the theme's text color when the accent is too faint on the base, or null to keep the accent.
	 */
	public static function get_color(): ?string {
		$user = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
		$text = $user['styles']['blocks']['core/button']['variations']['outline']['color']['text'] ?? '';
		$text = is_string( $text ) ? self::to_css_value( $text ) : null;
		if ( null !== $text ) {
			return $text;
		}

		return self::is_tripped() ? 'var(--wp--preset--color--contrast)' : null;
	}

	/**
	 * Whether the accent is too faint on the site's base color for the Outline button.
	 *
	 * @return bool False when the accent reads, or when the colors are not plain colors.
	 */
	public static function is_tripped(): bool {
		$accent = Contrast::resolve_color( Contrast::resolve_palette_color( 'accent' ) );
		$base   = Contrast::resolve_color( Contrast::resolve_palette_color( 'base' ) );
		if ( '' === $accent || '' === $base ) {
			return false;
		}

		return Status_Colors::lc( $accent, $base ) < self::MIN_LC;
	}

	/**
	 * Turn a Styles color value into a CSS value.
	 *
	 * @param string $value A color as global styles store it, such as var:preset|color|accent or #123456.
	 * @return string|null The CSS value, or null when it is empty or holds characters a color never needs.
	 */
	private static function to_css_value( string $value ): ?string {
		$value = preg_replace( '/^var:preset\|([a-z0-9-]+)\|([a-z0-9-]+)$/i', 'var(--wp--preset--$1--$2)', trim( $value ) );
		if ( 1 !== preg_match( '/^[#a-z0-9(),.%\s-]+$/i', $value ) ) {
			return null;
		}

		return $value;
	}
}

Outline_Button_Guard::init();
