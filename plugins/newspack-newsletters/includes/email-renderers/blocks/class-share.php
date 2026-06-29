<?php
/**
 * Newspack WC email-editor renderer for the share block.
 *
 * The block's server callback always returns empty, so a public newsletter's
 * share link renders empty under the WC engine. This override emits the saved
 * anchor when the newsletter is public — mirroring the legacy MJML renderer's intent.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a newspack-newsletters/share block under the WC engine.
 *
 * Renders the saved anchor only when the newsletter's `is_public` meta is set;
 * rebuilds it from the saved `href` and `content` attrs (matching the MJML path).
 */
class Share extends Abstract_Block_Renderer {
	/**
	 * Render the share block content.
	 *
	 * @param string            $block_content     Ignored; rebuilt from attrs.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string Share anchor HTML, or '' when post is unresolvable or not public.
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$post = Renderer_Controller::get_rendering_post();
		if ( ! $post instanceof \WP_Post ) {
			$post = $GLOBALS['post'] ?? null;
		}
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		// Only public newsletters have a permalink the share link can point to.
		if ( ! get_post_meta( $post->ID, 'is_public', true ) ) {
			return '';
		}

		$attrs   = $parsed_block['attrs'] ?? [];
		$href    = (string) ( $attrs['href'] ?? '' );
		$content = (string) ( $attrs['content'] ?? '' );

		// `content` is an HTML-sourced RichText attr not serialized into the block
		// delimiter, so fall back to the saved anchor's inner HTML (mirrors the
		// legacy renderer) to avoid an empty link text.
		if ( '' === $content && preg_match( '/<a\b[^>]*>(.*?)<\/a>/is', (string) ( $parsed_block['innerHTML'] ?? '' ), $matches ) ) {
			$content = $matches[1];
		}

		// Resolve colors: named presets via the palette, custom via style.color.
		$background = self::resolve_color( (string) ( $attrs['backgroundColor'] ?? '' ) );
		if ( '' === $background ) {
			$background = (string) ( $attrs['style']['color']['background'] ?? '' );
		}
		$text = self::resolve_color( (string) ( $attrs['textColor'] ?? '' ) );
		if ( '' === $text ) {
			$text = (string) ( $attrs['style']['color']['text'] ?? '' );
		}

		// Resolve font size: named preset via the scale, custom via style.typography.
		$font_size = self::resolve_font_size( (string) ( $attrs['fontSize'] ?? '' ) );
		if ( '' === $font_size ) {
			$font_size = (string) ( $attrs['style']['typography']['fontSize'] ?? '' );
		}

		return self::build_share_html( $href, $content, $background, $text, $font_size );
	}

	/**
	 * Resolve a colour preset slug or `var:preset|color|slug` to its hex value.
	 *
	 * Class-based presets would inline as dead `var(--wp--preset--color--*)` in
	 * email clients, so we resolve against the active theme.json palette instead.
	 * Already-literal colours (hex, rgb) pass through unchanged.
	 *
	 * @param string $value Preset slug, `var:preset|color|slug`, or literal colour.
	 * @return string Resolved colour, or '' when unresolved.
	 */
	private static function resolve_color( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( '#' === $value[0] || 0 === strpos( $value, 'rgb' ) ) {
			return $value;
		}
		if ( 0 === strpos( $value, 'var:preset|color|' ) ) {
			$value = substr( $value, strlen( 'var:preset|color|' ) );
		}
		$palette = wp_get_global_settings( [ 'color', 'palette' ] );
		// Search custom → theme → default to mirror WordPress origin precedence.
		$colors = isset( $palette['theme'] ) || isset( $palette['default'] ) || isset( $palette['custom'] )
			? array_merge( $palette['custom'] ?? [], $palette['theme'] ?? [], $palette['default'] ?? [] )
			: (array) $palette;
		foreach ( $colors as $color ) {
			if ( is_array( $color ) && ( $color['slug'] ?? '' ) === $value ) {
				return (string) ( $color['color'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Resolve a font-size preset slug (e.g. `huge`) to its value.
	 *
	 * Resolves named presets (custom → theme → default) against the active
	 * theme.json scale. Already-literal sizes (carrying a digit or `clamp(`) pass through.
	 *
	 * @param string $value Preset slug or literal size (e.g. `huge`, `44px`).
	 * @return string Resolved size, or '' when unresolved.
	 */
	private static function resolve_font_size( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		// Named-preset lookup first (custom → theme → default) so a slug containing
		// a digit (e.g. `step-2`) isn't misread as a literal size below.
		$sizes = wp_get_global_settings( [ 'typography', 'fontSizes' ] );
		$list  = isset( $sizes['theme'] ) || isset( $sizes['default'] ) || isset( $sizes['custom'] )
			? array_merge( $sizes['custom'] ?? [], $sizes['theme'] ?? [], $sizes['default'] ?? [] )
			: (array) $sizes;
		foreach ( $list as $size ) {
			if ( is_array( $size ) && ( $size['slug'] ?? '' ) === $value ) {
				return (string) ( $size['size'] ?? '' );
			}
		}
		// No preset matched: a digit or `clamp(` means a literal size; otherwise unknown slug.
		if ( preg_match( '/[\d(]/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Build the share anchor markup.
	 *
	 * Pure function — kept separate so it stays unit-testable without the WC engine.
	 *
	 * @param string $href       Share link URL.
	 * @param string $content    Link text.
	 * @param string $background Background colour (hex/literal), or ''.
	 * @param string $text       Text colour (hex/literal), or ''.
	 * @param string $font_size  Font size (e.g. `44px`), or ''.
	 * @return string Share anchor HTML, or '' when href is empty.
	 */
	public static function build_share_html( string $href, string $content, string $background = '', string $text = '', string $font_size = '' ): string {
		if ( '' === $href ) {
			return '';
		}
		// Inline the block's colors and font size. A background also gets the
		// editor canvas's 6px/12px padding so the color block reads the same.
		$p_styles = [];
		if ( '' !== $background ) {
			$p_styles[] = 'background-color: ' . $background;
			$p_styles[] = 'padding: 6px 12px';
		}
		if ( '' !== $text ) {
			$p_styles[] = 'color: ' . $text;
		}
		if ( '' !== $font_size ) {
			$p_styles[] = 'font-size: ' . $font_size;
		}
		$p_style = empty( $p_styles ) ? '' : ' style="' . esc_attr( implode( '; ', $p_styles ) . ';' ) . '"';
		// Style the anchor underlined + inherit color so it follows the block's text
		// color. The CSS inliner preserves existing inline styles, so this wins.
		return sprintf(
			'<p class="newspack-newsletters-share-block"%3$s><a href="%1$s" style="text-decoration: underline; color: inherit;">%2$s</a></p>',
			esc_url( $href, [ 'http', 'https', 'mailto' ] ),
			wp_kses_post( $content ),
			$p_style
		);
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'newspack-newsletters/share', Share::class );
