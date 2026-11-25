<?php
/**
 * Newspack override of the WC email-editor core/cover renderer.
 *
 * The package paints the cover's background image but never its overlay: the overlay
 * color is only used as a background when there is no image, gradients are dropped,
 * and `dimRatio` is ignored. White text on a busy photo, readable in the editor, can become unreadable
 * in the email.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Cover as Package_Cover;

defined( 'ABSPATH' ) || exit;

/**
 * Renders core/cover with its overlay over the background image.
 */
class Cover extends Package_Cover {

	/**
	 * Overlay color when the block sets a dim ratio but no color. Core's cover styles
	 * use black for `has-background-dim` without a color.
	 */
	const DEFAULT_OVERLAY_COLOR = '#000000';

	/**
	 * Render the cover, then layer its overlay over the background image.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$html     = parent::render_content( $block_content, $parsed_block, $rendering_context );
		$attrs    = $parsed_block['attrs'] ?? [];
		$ratio    = isset( $attrs['dimRatio'] ) ? (int) $attrs['dimRatio'] : 100;
		$gradient = self::overlay_gradient( $attrs );
		if ( null !== $gradient ) {
			return self::apply_gradient_overlay( $html, $gradient, $ratio );
		}
		return self::apply_overlay( $html, self::overlay_color( $attrs, $rendering_context ), $ratio );
	}

	/**
	 * The cover's gradient overlay as CSS, from a custom gradient or a preset slug.
	 *
	 * @param array $attrs Block attributes.
	 * @return string|null The gradient, '' when one is set but can't be resolved, or
	 *                     null when the overlay isn't a gradient.
	 */
	private static function overlay_gradient( array $attrs ): ?string {
		if ( ! empty( $attrs['customGradient'] ) && is_string( $attrs['customGradient'] ) ) {
			return self::sanitize_gradient( $attrs['customGradient'] );
		}
		if ( empty( $attrs['gradient'] ) || ! is_string( $attrs['gradient'] ) ) {
			return null;
		}
		$origins = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( [ 'color', 'gradients' ] ) : [];
		// Later origins (theme, then custom) override earlier ones for the same slug.
		$gradient = '';
		foreach ( [ 'default', 'theme', 'custom' ] as $origin ) {
			foreach ( (array) ( $origins[ $origin ] ?? [] ) as $preset ) {
				if ( is_array( $preset ) && ( $preset['slug'] ?? null ) === $attrs['gradient'] && is_string( $preset['gradient'] ?? null ) ) {
					$gradient = $preset['gradient'];
				}
			}
		}
		return self::sanitize_gradient( $gradient );
	}

	/**
	 * Accept a CSS gradient only if it's a plain linear or radial gradient.
	 *
	 * @param string $gradient CSS gradient.
	 * @return string The gradient, or '' if it isn't safe to put in a style attribute.
	 */
	private static function sanitize_gradient( string $gradient ): string {
		$gradient = trim( $gradient );
		return preg_match( '/^(?:repeating-)?(?:linear|radial)-gradient\([a-z0-9#%.,()\s\/-]+\)$/i', $gradient ) ? $gradient : '';
	}

	/**
	 * Layer a gradient overlay over the cover's background image, at the block's opacity.
	 *
	 * The editor draws the gradient over the image at `dimRatio` opacity. Email has no
	 * overlay element to fade, so each color stop's alpha is scaled instead. The first
	 * stop's color is the fallback background where images aren't shown.
	 *
	 * @param string $html     Rendered cover HTML.
	 * @param string $gradient CSS gradient.
	 * @param int    $ratio    Overlay opacity, 0–100.
	 * @return string
	 */
	public static function apply_gradient_overlay( string $html, string $gradient, int $ratio ): string {
		if ( '' === $gradient || $ratio <= 0 ) {
			return $html;
		}
		$alpha    = min( 100, $ratio ) / 100;
		$fallback = '';
		$dimmed   = preg_replace_callback(
			'/#([0-9a-f]{6}|[0-9a-f]{3})\b|rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})(?:\s*[,\/]\s*([\d.]+)(%?))?\s*\)/i',
			static function ( array $m ) use ( $alpha, &$fallback ): string {
				if ( '' !== $m[1] ) {
					list( $r, $g, $b ) = self::hex_to_rgb( $m[1] );
					$a                 = 1.0;
				} else {
					list( $r, $g, $b ) = [ (int) $m[2], (int) $m[3], (int) $m[4] ];
					$a                 = isset( $m[5] ) && '' !== $m[5] ? (float) $m[5] / ( '%' === ( $m[6] ?? '' ) ? 100 : 1 ) : 1.0;
				}
				if ( '' === $fallback ) {
					$fallback = sprintf( 'rgb(%d, %d, %d)', $r, $g, $b );
				}
				return self::rgba( $r, $g, $b, $a * $alpha );
			},
			$gradient
		);
		return self::layer_over_image( $html, (string) $dimmed, $fallback );
	}

	/**
	 * Resolve the overlay color as a hex value.
	 *
	 * @param array             $attrs             Block attributes.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string Hex color, or '' when the color isn't hex.
	 */
	private static function overlay_color( array $attrs, Rendering_Context $rendering_context ): string {
		$color = '';
		if ( ! empty( $attrs['customOverlayColor'] ) && is_string( $attrs['customOverlayColor'] ) ) {
			$color = $attrs['customOverlayColor'];
		} elseif ( ! empty( $attrs['overlayColor'] ) && is_string( $attrs['overlayColor'] ) ) {
			$color = $rendering_context->translate_slug_to_color( $attrs['overlayColor'] );
		} else {
			$color = self::DEFAULT_OVERLAY_COLOR;
		}
		$hex = sanitize_hex_color( trim( (string) $color ) );
		return is_string( $hex ) ? $hex : '';
	}

	/**
	 * Layer a translucent overlay over the cover's background image.
	 *
	 * Uses a single-color `linear-gradient` on top of the image, the email-safe way to
	 * tint a background image, and sets the overlay color as `background-color` so the
	 * text stays readable where background images aren't shown (images blocked, or
	 * classic Outlook). Covers without a background image already render the overlay
	 * color as their background, so they are left unchanged.
	 *
	 * @param string $html  Rendered cover HTML.
	 * @param string $color Overlay hex color.
	 * @param int    $ratio Overlay opacity, 0–100.
	 * @return string
	 */
	public static function apply_overlay( string $html, string $color, int $ratio ): string {
		if ( '' === $color || $ratio <= 0 ) {
			return $html;
		}
		list( $r, $g, $b ) = self::hex_to_rgb( ltrim( $color, '#' ) );
		$rgba              = self::rgba( $r, $g, $b, min( 100, $ratio ) / 100 );
		return self::layer_over_image( $html, sprintf( 'linear-gradient(%1$s, %1$s)', $rgba ), $color );
	}

	/**
	 * Put a background layer over the cover's background image, and set the fallback
	 * background color.
	 *
	 * @param string $html     Rendered cover HTML.
	 * @param string $layer    CSS background layer (a gradient).
	 * @param string $fallback Background color for clients that don't show the image.
	 * @return string
	 */
	private static function layer_over_image( string $html, string $layer, string $fallback ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag(
			[
				'tag_name'   => 'table',
				'class_name' => 'email-block-cover',
			]
		) ) {
			return $html;
		}
		$style = (string) $processor->get_attribute( 'style' );
		if ( ! preg_match( '/background-image:\s*(url\(\s*([\'"]).*?\2\s*\))/i', $style, $matches ) ) {
			return $html;
		}
		$style = str_replace( $matches[0], 'background-image: ' . $layer . ', ' . $matches[1], $style );
		if ( '' !== $fallback ) {
			$style = rtrim( trim( $style ), ';' ) . '; background-color: ' . $fallback . ';';
		}
		$processor->set_attribute( 'style', $style );
		return $processor->get_updated_html();
	}

	/**
	 * Split a hex color (without `#`, 3 or 6 digits) into its channels.
	 *
	 * @param string $hex Hex color.
	 * @return int[] Red, green and blue.
	 */
	private static function hex_to_rgb( string $hex ): array {
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return [ (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) ];
	}

	/**
	 * Format an `rgba()` value.
	 *
	 * @param int   $r     Red.
	 * @param int   $g     Green.
	 * @param int   $b     Blue.
	 * @param float $alpha Opacity, 0–1.
	 * @return string
	 */
	private static function rgba( int $r, int $g, int $b, float $alpha ): string {
		return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' ) );
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/cover', Cover::class );
