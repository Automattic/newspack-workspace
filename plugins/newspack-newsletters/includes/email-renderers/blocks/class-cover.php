<?php
/**
 * Newspack override of the WC email-editor core/cover renderer.
 *
 * The package paints the cover's background image but never its overlay: the overlay
 * color is only used as a background when there is no image, and `dimRatio` is
 * ignored. White text on a busy photo, readable in the editor, can become unreadable
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
		$html  = parent::render_content( $block_content, $parsed_block, $rendering_context );
		$attrs = $parsed_block['attrs'] ?? [];
		$color = self::overlay_color( $attrs, $rendering_context );
		$ratio = isset( $attrs['dimRatio'] ) ? (int) $attrs['dimRatio'] : 100;
		return self::apply_overlay( $html, $color, $ratio );
	}

	/**
	 * Resolve the overlay color as a hex value.
	 *
	 * @param array             $attrs             Block attributes.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string Hex color, or '' for no overlay (a gradient overlay, or a color that
	 *                isn't hex).
	 */
	private static function overlay_color( array $attrs, Rendering_Context $rendering_context ): string {
		// A gradient overlay can't be drawn as a single tint; leave the image as the
		// package renders it rather than tint it with the default color.
		if ( ! empty( $attrs['gradient'] ) || ! empty( $attrs['customGradient'] ) ) {
			return '';
		}
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
		$rgba    = self::to_rgba( $color, min( 100, $ratio ) / 100 );
		$layered = sprintf( 'background-image: linear-gradient(%1$s, %1$s), %2$s', $rgba, $matches[1] );
		$style   = str_replace( $matches[0], $layered, $style );
		$style   = rtrim( trim( $style ), ';' ) . '; background-color: ' . $color . ';';
		$processor->set_attribute( 'style', $style );
		return $processor->get_updated_html();
	}

	/**
	 * Convert a hex color to an `rgba()` value.
	 *
	 * @param string $hex   Hex color (`#rgb` or `#rrggbb`).
	 * @param float  $alpha Opacity, 0–1.
	 * @return string
	 */
	private static function to_rgba( string $hex, float $alpha ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return sprintf(
			'rgba(%d, %d, %d, %s)',
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
			rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' )
		);
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/cover', Cover::class );
