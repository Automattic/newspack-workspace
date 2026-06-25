<?php
/**
 * Newspack override of the WC email-editor core/separator renderer.
 *
 * The WC package has no dedicated separator renderer — `core/separator` falls
 * through to the Fallback renderer, which wraps the bare `<hr>` in a table
 * cell but adds no email-safe dimensions. The `.wp-block-separator` stylesheet
 * (which gives it an explicit `height`, `border`, and a short `width`) is NOT
 * loaded in email clients, so:
 *
 * - A default-style separator degrades to a full-width gray browser `<hr>`.
 * - A colored separator's color appears only as a class but has no email impact.
 * - Width/alignment differences between style variants are invisible.
 *
 * This override replaces the bare `<hr>` with a table-based horizontal rule:
 * a centered `<table>` with a single `<td>` carrying an explicit `border-top`
 * so color, width, and alignment all survive without any external CSS.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;
use Automattic\WooCommerce\EmailEditor\Integrations\Utils\Table_Wrapper_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a core/separator block in an email-safe way.
 *
 * Emits a centered `<table>` with a single `<td>` carrying an explicit
 * `border-top` (color + width + alignment) so the separator looks right in
 * email without relying on the `.wp-block-separator` stylesheet.
 *
 * Variants:
 * - Default (is-style-default or no class): 100px wide, centered.
 * - Wide (is-style-wide): 100% wide.
 * - Dots (is-style-dots): dotted border-top, 100px wide, centered.
 */
class Separator extends Abstract_Block_Renderer {

	/**
	 * Default separator width for the short/default variant.
	 */
	const DEFAULT_WIDTH = '100px';

	/**
	 * Default separator color (light gray, matching WP core default).
	 */
	const DEFAULT_COLOR = '#dddddd';

	/**
	 * Render the separator block as an email-safe table-based horizontal rule.
	 *
	 * @param string            $block_content     Original block content (bare `<hr>`).
	 * @param array             $parsed_block      Parsed block data including attrs.
	 * @param Rendering_Context $rendering_context Rendering context for color resolution.
	 * @return string Email-safe HTML for the separator.
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$attrs      = $parsed_block['attrs'] ?? array();
		$class_name = $attrs['className'] ?? '';

		$is_wide = str_contains( $class_name, 'is-style-wide' );
		$is_dots = str_contains( $class_name, 'is-style-dots' );

		$color  = $this->resolve_color( $attrs, $rendering_context );
		$border = $is_dots ? 'dotted' : 'solid';

		// The CSS width carries the unit, but the HTML `width` attribute must be a
		// number or a percentage — a `100px` attribute is invalid and some email
		// clients then fall back to full width — so derive a numeric attribute.
		$css_width  = $is_wide ? '100%' : self::DEFAULT_WIDTH;
		$attr_width = $is_wide ? '100%' : (string) (int) self::DEFAULT_WIDTH;

		// Build the rule cell style: the `<td>` itself IS the line.
		$rule_td_style = sprintf(
			'border-top: 1px %s %s; height: 0; line-height: 0; font-size: 0;',
			esc_attr( $border ),
			esc_attr( $color )
		);

		// Use render_cell = false so the `<td>` we supply IS the row content,
		// not nested inside another auto-generated `<td>`.
		$cell_html = sprintf(
			'<td style="%s">&nbsp;</td>',
			$rule_td_style
		);

		// Outer table: centered, explicit width. Use render_cell = false because
		// we are already supplying the full `<td>`.
		$table_attrs = array(
			'align' => 'center',
			'width' => $attr_width,
			'style' => sprintf( 'width: %s; margin: 0 auto;', esc_attr( $css_width ) ),
		);

		return Table_Wrapper_Helper::render_table_wrapper( $cell_html, $table_attrs, array(), array(), false );
	}

	/**
	 * Resolve the separator color from block attributes.
	 *
	 * Priority (mirrors the MJML renderer in class-newspack-newsletters-renderer.php):
	 * 1. `style.color.background` (arbitrary inline color).
	 * 2. `backgroundColor` slug (resolved via the rendering context palette).
	 * 3. Fallback: DEFAULT_COLOR (light gray).
	 *
	 * Note: the MJML renderer checks `style.color.background` for the divider
	 * color, even though the attribute is named `background`. We follow the same
	 * convention here so both renderers stay consistent.
	 *
	 * @param array             $attrs             Block attributes.
	 * @param Rendering_Context $rendering_context Rendering context for slug resolution.
	 * @return string Hex color value (e.g. `#cf2e2e`).
	 */
	private function resolve_color( array $attrs, Rendering_Context $rendering_context ): string {
		// 1. Arbitrary inline color (style.color.background).
		$inline_color = $attrs['style']['color']['background'] ?? '';
		if ( $inline_color ) {
			return $inline_color;
		}

		// 2. Named preset color slug.
		$bg_slug = $attrs['backgroundColor'] ?? '';
		if ( $bg_slug ) {
			$resolved = $rendering_context->translate_slug_to_color( $bg_slug );
			if ( $resolved ) {
				return $resolved;
			}
		}

		return self::DEFAULT_COLOR;
	}
}

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/separator', Separator::class );
