<?php
/**
 * Newspack WC email-editor renderer for the share block.
 *
 * Emits the saved share anchor when the newsletter is public, mirroring the
 * legacy MJML renderer's intent. The block's own server render callback returns
 * an empty string unconditionally, so under the WC engine a public newsletter's
 * valid share link renders empty.
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
 * The share link only makes sense when the newsletter has a public permalink,
 * so the override renders the saved anchor only when the newsletter's
 * `is_public` meta is truthy, and nothing otherwise. The anchor is rebuilt from
 * the saved `href` and `content` attributes (v1 reuses the saved values,
 * matching the legacy MJML path).
 */
class Share extends Abstract_Block_Renderer {
	/**
	 * Render the share block content.
	 *
	 * Resolves the newsletter post, gates on its `is_public` meta, and emits the
	 * saved share anchor. Returns an empty string when the post cannot be resolved
	 * or the newsletter is not public.
	 *
	 * @param string            $block_content     Block content (ignored; rebuilt from attrs).
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
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

		// `content` is an HTML-sourced RichText attribute, so it is not serialized
		// into the block delimiter that email rendering parses — the link text
		// lives in the saved markup. Fall back to the saved anchor's inner HTML
		// (mirroring the legacy renderer, which renders the share block from its
		// inner HTML) so the email link isn't empty.
		if ( '' === $content && preg_match( '/<a\b[^>]*>(.*?)<\/a>/is', (string) ( $parsed_block['innerHTML'] ?? '' ), $matches ) ) {
			$content = $matches[1];
		}

		// Resolve the block's chosen background/text colours. Named presets
		// (`backgroundColor`/`textColor`) resolve through the palette; a custom
		// value lives under `style.color`.
		$background = self::resolve_color( (string) ( $attrs['backgroundColor'] ?? '' ) );
		if ( '' === $background ) {
			$background = (string) ( $attrs['style']['color']['background'] ?? '' );
		}
		$text = self::resolve_color( (string) ( $attrs['textColor'] ?? '' ) );
		if ( '' === $text ) {
			$text = (string) ( $attrs['style']['color']['text'] ?? '' );
		}

		// Resolve the chosen font size. A named preset (`fontSize`) resolves through
		// the font-size scale; a custom value lives under `style.typography`.
		$font_size = self::resolve_font_size( (string) ( $attrs['fontSize'] ?? '' ) );
		if ( '' === $font_size ) {
			$font_size = (string) ( $attrs['style']['typography']['fontSize'] ?? '' );
		}

		return self::build_share_html( $href, $content, $background, $text, $font_size );
	}

	/**
	 * Resolve a colour preset slug (or `var:preset|color|slug`) to its hex value.
	 *
	 * The override builds the share markup itself, so it bypasses the package's
	 * colour handling — and class-based preset colours would inline as unresolved
	 * `var(--wp--preset--color--*)` (dead in email clients). Resolve the slug
	 * against the active (email) theme.json palette so an inline hex can be set.
	 * A value that is already a literal colour (hex / rgb) is returned as-is.
	 *
	 * @param string $value Preset slug, `var:preset|color|slug`, or literal colour.
	 * @return string The hex/literal colour, or an empty string when unresolved.
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
		// Search custom → theme → default and return the first slug match, mirroring
		// WordPress' own origin precedence (user/custom overrides theme overrides
		// default) so a customised preset wins over a colliding theme slug.
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
	 * Like the colour resolution, the override builds the markup itself, so the
	 * block's chosen size must be set inline. A named preset resolves against the
	 * active (email) theme.json font-size scale; a literal size (carrying a digit
	 * or `clamp(`) is returned as-is.
	 *
	 * @param string $value Preset slug or literal size (e.g. `huge`, `44px`).
	 * @return string The resolved size, or an empty string when unresolved.
	 */
	private static function resolve_font_size( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		// Try a named preset first — searching custom → theme → default to mirror
		// WordPress' origin precedence — so a preset slug that happens to carry a
		// digit (e.g. `h1`, `step-2`) isn't misread as a literal size below.
		$sizes = wp_get_global_settings( [ 'typography', 'fontSizes' ] );
		$list  = isset( $sizes['theme'] ) || isset( $sizes['default'] ) || isset( $sizes['custom'] )
			? array_merge( $sizes['custom'] ?? [], $sizes['theme'] ?? [], $sizes['default'] ?? [] )
			: (array) $sizes;
		foreach ( $list as $size ) {
			if ( is_array( $size ) && ( $size['slug'] ?? '' ) === $value ) {
				return (string) ( $size['size'] ?? '' );
			}
		}
		// No preset matched: a value carrying a digit or `clamp(` is a literal size;
		// anything else is an unknown slug we can't resolve.
		if ( preg_match( '/[\d(]/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Build the share anchor markup.
	 *
	 * Pure string builder kept separate from the post resolution so it stays
	 * unit-testable without booting the WC engine. Mirrors the block's saved
	 * markup: a paragraph wrapping a single anchor. Returns an empty string when
	 * there is no link to point at.
	 *
	 * @param string $href       The share link URL.
	 * @param string $content    The link text.
	 * @param string $background Resolved background colour (hex/literal), or ''.
	 * @param string $text       Resolved text colour (hex/literal), or ''.
	 * @param string $font_size  Resolved font size (e.g. `44px`), or ''.
	 * @return string The share anchor HTML, or an empty string when href is empty.
	 */
	public static function build_share_html( string $href, string $content, string $background = '', string $text = '', string $font_size = '' ): string {
		if ( '' === $href ) {
			return '';
		}
		// Apply the block's background/text colours and font size inline on the
		// paragraph so they survive into the email. A background-carrying block also
		// gets the editor canvas's 6px/12px padding so the colour block reads the
		// same.
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
		// Match the editor's default link rendering: underlined and inheriting the
		// text colour (so it follows the block's text colour). The package's CSS
		// inliner otherwise gives the anchor the client-default blue with no
		// underline; it preserves existing inline styles, so styling here wins.
		return sprintf(
			'<p class="newspack-newsletters-share-block"%3$s><a href="%1$s" style="text-decoration: underline; color: inherit;">%2$s</a></p>',
			esc_url( $href, [ 'http', 'https', 'mailto' ] ),
			wp_kses_post( $content ),
			$p_style
		);
	}
}

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'newspack-newsletters/share', Share::class );
