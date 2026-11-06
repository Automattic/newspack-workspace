<?php
/**
 * Styles shared by the content banners: the metering countdown banner and the
 * content gifting CTA.
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * Content banner style helpers.
 */
final class Content_Banner_Style {

	const DEFAULT_STYLE = 'light';

	const STYLES = [ 'light', 'dark', 'theme' ];

	/**
	 * Whether the active theme supports the Theme style.
	 *
	 * The Theme style follows the classic theme's buttons, so it is only offered
	 * on newspack-theme and its child themes.
	 *
	 * @return bool
	 */
	public static function supports_theme_style(): bool {
		return 'newspack-theme' === get_template();
	}

	/**
	 * Sanitize a stored style.
	 *
	 * Theme is kept whatever the active theme, so switching back to the classic
	 * theme restores it.
	 *
	 * @param mixed $style The style.
	 *
	 * @return string One of self::STYLES.
	 */
	public static function sanitize_style( mixed $style ): string {
		return in_array( $style, self::STYLES, true ) ? $style : self::DEFAULT_STYLE;
	}

	/**
	 * Get the style a banner renders with on the active theme.
	 *
	 * @param mixed $style The stored style.
	 *
	 * @return string One of self::STYLES.
	 */
	public static function get_render_style( mixed $style ): string {
		$style = self::sanitize_style( $style );
		if ( 'theme' === $style && ! self::supports_theme_style() ) {
			return self::DEFAULT_STYLE;
		}
		return $style;
	}

	/**
	 * Sanitize a color. Empty means the style's default.
	 *
	 * @param mixed $color The color.
	 *
	 * @return string A hex color, or an empty string.
	 */
	public static function sanitize_color( mixed $color ): string {
		if ( ! is_string( $color ) ) {
			return '';
		}
		return (string) sanitize_hex_color( trim( $color ) );
	}

	/**
	 * Get the variant class for the banner's subscribe button.
	 *
	 * The Theme style sets its own button colors in the banner stylesheet, so
	 * it takes no variant class.
	 *
	 * @param string $style The style the banner renders with.
	 *
	 * @return string The button variant class.
	 */
	public static function get_button_class( string $style ): string {
		return match ( $style ) {
			'dark'  => 'newspack-ui__button--primary-light',
			'theme' => '',
			default => 'newspack-ui__button--primary',
		};
	}

	/**
	 * Get the inline CSS custom properties for a banner.
	 *
	 * Text on each color is black or white, whichever reads better. An empty
	 * color sets nothing, so the stylesheet's defaults apply.
	 *
	 * @param string $style            The style the banner renders with.
	 * @param string $background_color The banner background color.
	 * @param string $button_color     The button background color.
	 *
	 * @return string The declarations, or an empty string.
	 */
	public static function get_inline_style( string $style, string $background_color, string $button_color ): string {
		if ( 'theme' !== $style ) {
			return '';
		}
		$properties       = [];
		$background_color = self::sanitize_color( $background_color );
		$button_color     = self::sanitize_color( $button_color );
		if ( $background_color ) {
			$properties['--newspack-content-banner-bg']  = $background_color;
			$properties['--newspack-content-banner-ink'] = newspack_get_color_contrast( $background_color );
		}
		if ( $button_color ) {
			$properties['--newspack-content-banner-button-bg']  = $button_color;
			$properties['--newspack-content-banner-button-ink'] = newspack_get_color_contrast( $button_color );
		}
		$declarations = [];
		foreach ( $properties as $property => $value ) {
			$declarations[] = $property . ':' . $value;
		}
		return implode( ';', $declarations );
	}

	/**
	 * Get the data the wizard previews need.
	 *
	 * @return array{
	 *     supports_theme_style: bool,
	 *     theme_button_bg: string,
	 *     theme_button_ink: string,
	 *     theme_font_heading: string,
	 *     theme_font_body: string,
	 * }
	 */
	public static function get_wizard_data(): array {
		$data = [
			'supports_theme_style' => self::supports_theme_style(),
			'theme_button_bg'      => '',
			'theme_button_ink'     => '',
			'theme_font_heading'   => '',
			'theme_font_body'      => '',
		];
		if ( ! $data['supports_theme_style'] ) {
			return $data;
		}
		if ( function_exists( 'newspack_get_colors' ) ) {
			$colors                   = newspack_get_colors();
			$data['theme_button_bg']  = self::sanitize_color( $colors['secondary'] ?? '' );
			$data['theme_button_ink'] = $colors['secondary_contrast'] ?? '';
		}
		if ( function_exists( 'newspack_font_family_stacks' ) ) {
			$stacks                     = newspack_font_family_stacks();
			$data['theme_font_heading'] = $stacks['heading'] ?? '';
			$data['theme_font_body']    = $stacks['body'] ?? '';
		}
		return $data;
	}

	/**
	 * Load the theme's web fonts on a wizard page that previews the Theme style.
	 */
	public static function enqueue_wizard_fonts(): void {
		if ( ! self::supports_theme_style() || ! function_exists( 'newspack_sanitize_font_provider_url' ) ) {
			return;
		}
		$font_imports = [
			'custom_font_import_code'           => 'newspack-content-banner-theme-fonts',
			'custom_font_import_code_alternate' => 'newspack-content-banner-theme-fonts-alternate',
		];
		foreach ( $font_imports as $theme_mod => $handle ) {
			$url = newspack_sanitize_font_provider_url( (string) get_theme_mod( $theme_mod, '' ) );
			if ( $url ) {
				wp_enqueue_style( $handle, esc_url_raw( $url ), [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			}
		}
	}
}
