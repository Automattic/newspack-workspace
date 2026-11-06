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
	 * The render style of banners on a site that uses the site Theme. Never stored, so it is not in self::STYLES.
	 */
	const SITE_STYLE = 'site';

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
	 * Whether banners render with the site Theme, which replaces the stored style.
	 *
	 * @return bool
	 */
	public static function uses_site_theme(): bool {
		return Newspack_UI_Theme_Style::is_active();
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
	 * @return string One of self::STYLES, or self::SITE_STYLE when the site Theme is in use.
	 */
	public static function get_render_style( mixed $style ): string {
		if ( self::uses_site_theme() ) {
			return self::SITE_STYLE;
		}
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
	 * it takes no variant class. The site style uses the primary button, which
	 * the site Theme colors.
	 *
	 * @param string $style The style the banner renders with, one of self::STYLES or self::SITE_STYLE.
	 *
	 * @return string The button variant class.
	 */
	public static function get_button_class( string $style ): string {
		return match ( $style ) {
			'dark'           => 'newspack-ui__button--primary-light',
			'theme'          => '',
			self::SITE_STYLE => 'newspack-ui__button--primary',
			default => 'newspack-ui__button--primary',
		};
	}

	/**
	 * Get the inline CSS custom properties for a banner.
	 *
	 * Text on each color is the picked text color, or black or white, whichever
	 * reads better. An empty color sets nothing, so the stylesheet's defaults
	 * apply. Light and Dark print nothing; the Theme and site styles print the
	 * colors that are picked.
	 *
	 * @param string $style             The style the banner renders with, one of self::STYLES or self::SITE_STYLE.
	 * @param string $background_color  The banner background color.
	 * @param string $button_color      The button background color.
	 * @param string $text_color        The banner text color.
	 * @param string $button_text_color The button text color.
	 *
	 * @return string The declarations, or an empty string.
	 */
	public static function get_inline_style( string $style, string $background_color, string $button_color, string $text_color = '', string $button_text_color = '' ): string {
		if ( 'theme' !== $style && self::SITE_STYLE !== $style ) {
			return '';
		}
		$properties        = [];
		$background_color  = self::sanitize_color( $background_color );
		$button_color      = self::sanitize_color( $button_color );
		$text_color        = self::sanitize_color( $text_color );
		$button_text_color = self::sanitize_color( $button_text_color );
		if ( $background_color ) {
			$properties['--newspack-content-banner-bg'] = $background_color;
		}
		$ink = $text_color ? $text_color : ( $background_color ? newspack_get_color_contrast( $background_color ) : '' );
		if ( $ink ) {
			$properties['--newspack-content-banner-ink'] = $ink;
		}
		if ( $button_color ) {
			$properties['--newspack-content-banner-button-bg'] = $button_color;
		}
		$button_ink = $button_text_color ? $button_text_color : ( $button_color ? newspack_get_color_contrast( $button_color ) : '' );
		if ( $button_ink ) {
			$properties['--newspack-content-banner-button-ink'] = $button_ink;
		}
		$declarations = [];
		foreach ( $properties as $property => $value ) {
			$declarations[] = $property . ':' . $value;
		}
		return implode( ';', $declarations );
	}

	/**
	 * Whether a banner style has a shadow option. Light and Dark always have their shadow.
	 *
	 * @param string $style The style the banner renders with.
	 *
	 * @return bool
	 */
	public static function supports_shadow_option( string $style ): bool {
		return 'theme' === $style || self::SITE_STYLE === $style;
	}

	/**
	 * Get the class that removes a banner's shadow.
	 *
	 * @param string $style  The style the banner renders with.
	 * @param bool   $shadow Whether the banner has a shadow.
	 *
	 * @return string The class, or an empty string.
	 */
	public static function get_shadow_class( string $style, bool $shadow ): string {
		return ! $shadow && self::supports_shadow_option( $style ) ? 'has-no-shadow' : '';
	}

	/**
	 * Get the data the wizard previews need.
	 *
	 * @return array{
	 *     supports_theme_style: bool,
	 *     uses_site_theme: bool,
	 *     theme_button_bg: string,
	 *     theme_button_ink: string,
	 *     theme_font_heading: string,
	 *     theme_font_body: string,
	 * }
	 */
	public static function get_wizard_data(): array {
		$data = [
			'supports_theme_style' => self::supports_theme_style(),
			'uses_site_theme'      => self::uses_site_theme(),
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
