<?php
/**
 * Newspack override of the WC email-editor core/button renderer.
 *
 * The package renderer ignores the `is-style-outline` block style: it renders an
 * outline button identically to a filled one (solid theme background, white
 * text, no border). The editor canvas (and vanilla WP) render outline buttons as
 * transparent with a colored border and matching text.
 *
 * The button's fill colour and text colour are applied by the email CSS inliner
 * from theme.json *after* block render, so they aren't visible to a per-block
 * post-process. Instead, for outline buttons this override writes explicit
 * border / transparent-background / text-colour values onto the block's `style`
 * attribute before deferring to the package renderer. The package emits those as
 * inline styles, which beat the theme stylesheet during inlining — producing a
 * transparent button with a 2px border and text in the accent colour.
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
		$class_name = (string) ( $parsed_block['attrs']['className'] ?? '' );
		return false !== strpos( $class_name, 'is-style-outline' )
			|| false !== strpos( $block_content, 'is-style-outline' );
	}

	/**
	 * Resolve the outline accent colour (used for the border and text).
	 *
	 * A custom background colour on the button wins (a red button yields a red
	 * outline); otherwise fall back to the theme's button background so the
	 * outline matches the colour the filled button would have used.
	 *
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string The accent colour, or '' when none can be resolved.
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
	 * Write transparent-background / border / text-colour onto the block style.
	 *
	 * @param array  $parsed_block Parsed block.
	 * @param string $accent       Resolved accent colour.
	 * @return array The parsed block with outline styles applied.
	 */
	private static function apply_outline_attrs( array $parsed_block, string $accent ): array {
		$parsed_block['attrs']['style']['color']['background'] = 'transparent';
		$parsed_block['attrs']['style']['color']['text']       = $accent;
		// Clear any preset text colour so the accent text colour above takes effect.
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

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/button', Button::class );
