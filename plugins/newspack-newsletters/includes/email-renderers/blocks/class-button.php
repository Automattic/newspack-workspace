<?php
/**
 * Newspack override of the WC email-editor core/button renderer.
 *
 * The package ignores `is-style-outline` and renders outline buttons identically
 * to filled ones. The fill/text colours are applied by the CSS inliner post-render
 * so they can't be caught by a per-block hook. Instead, this override writes
 * explicit border/transparent-background/text-colour values onto the block's
 * `style` attribute before deferring to the package — the package emits them as
 * inline styles, which beat the inliner's theme stylesheet.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Button as Package_Button;

defined( 'ABSPATH' ) || exit;

/**
 * Renders core/button, adding the missing `is-style-outline` treatment.
 */
class Button extends Package_Button {

	/**
	 * Outline border weight, matching the editor canvas / vanilla WP.
	 */
	const OUTLINE_BORDER_WIDTH = '2px';

	/**
	 * Render the button, pre-applying outline styles when the style is present.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		if ( self::is_outline( $parsed_block, $block_content ) ) {
			$accent = self::resolve_accent( $parsed_block, $rendering_context );
			if ( '' !== $accent ) {
				$parsed_block = self::apply_outline_attrs( $parsed_block, $accent );
			}
		}
		return parent::render_content( $block_content, $parsed_block, $rendering_context );
	}

	/**
	 * Whether the button carries the `is-style-outline` block style.
	 *
	 * @param array  $parsed_block  Parsed block.
	 * @param string $block_content Block content (fallback check on the wrapper class).
	 * @return bool
	 */
	private static function is_outline( array $parsed_block, string $block_content ): bool {
		// Match the class as a whole token so `is-style-outline-thick` (or the
		// string in label/href text) doesn't trip the outline path.
		$class_name = (string) ( $parsed_block['attrs']['className'] ?? '' );
		return (bool) preg_match( '/\bis-style-outline\b/', $class_name )
			|| (bool) preg_match( '/\bis-style-outline\b/', $block_content );
	}

	/**
	 * Resolve the outline accent colour (border and text).
	 *
	 * A custom background on the button wins; otherwise falls back to the theme's
	 * button background so the outline matches what the filled variant would use.
	 *
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string Accent colour, or '' when none can be resolved.
	 */
	private static function resolve_accent( array $parsed_block, Rendering_Context $rendering_context ): string {
		$custom = $parsed_block['attrs']['style']['color']['background'] ?? '';
		if ( is_string( $custom ) && '' !== $custom ) {
			return $custom;
		}
		$styles = $rendering_context->get_theme_styles();
		$theme  = $styles['blocks']['core/button']['color']['background']
			?? $styles['elements']['button']['color']['background']
			?? '';
		return is_string( $theme ) ? $theme : '';
	}

	/**
	 * Apply transparent-background, border, and text-colour styles for outline buttons.
	 *
	 * The accent is written into the block's `style` attrs and serialized by the
	 * package's WP style engine (safecss_filter_attr), so it needs no explicit
	 * CSS-colour whitelist — unlike the Separator override, which hand-concatenates.
	 *
	 * @param array  $parsed_block Parsed block.
	 * @param string $accent       Resolved accent colour.
	 * @return array Parsed block with outline styles applied.
	 */
	private static function apply_outline_attrs( array $parsed_block, string $accent ): array {
		$parsed_block['attrs']['style']['color']['background'] = 'transparent';
		$parsed_block['attrs']['style']['color']['text']       = $accent;
		// Belt-and-suspenders: style.color.text (above) already wins over a textColor
		// slug in the package's style normalization, and this path emits no
		// has-*-color class — drop the slug anyway so the intent is explicit.
		unset( $parsed_block['attrs']['textColor'] );
		$parsed_block['attrs']['style']['border'] = array_merge(
			$parsed_block['attrs']['style']['border'] ?? [],
			[
				'width' => self::OUTLINE_BORDER_WIDTH,
				'style' => 'solid',
				'color' => $accent,
			]
		);
		return $parsed_block;
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/button', Button::class );
