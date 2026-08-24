<?php
/**
 * Newspack override of the WC email-editor core/separator renderer.
 *
 * The package has no dedicated separator renderer — `core/separator` falls
 * through to Fallback, which wraps the bare `<hr>` in a table cell but adds no
 * email-safe dimensions. Without the `.wp-block-separator` stylesheet (not loaded
 * in email clients), color, width, and alignment all vanish.
 *
 * This override replaces the bare `<hr>` with a table-based rule whose color,
 * width, and alignment are set inline.
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
	 * Width in pixels for the short/default variant. Shared by the CSS value and
	 * the HTML `width` attribute so they can't drift apart.
	 */
	const DEFAULT_WIDTH = 100;

	/**
	 * Default separator color (light gray, matching WP core default).
	 */
	const DEFAULT_COLOR = '#dddddd';

	/**
	 * CSS named-color whitelist. Safety net to reject unresolved palette slugs
	 * (e.g. `primary`) that happen to be letters-only but aren't valid colors.
	 * The block editor emits hex, never bare color names, so this path is rare.
	 *
	 * @var string[]
	 */
	const NAMED_COLORS = array(
		'aqua',
		'black',
		'blue',
		'fuchsia',
		'gray',
		'grey',
		'green',
		'lime',
		'maroon',
		'navy',
		'olive',
		'orange',
		'purple',
		'red',
		'silver',
		'teal',
		'transparent',
		'white',
		'yellow',
	);

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

		// HTML `width` must be a bare number or %, not `100px` — some clients
		// fall back to full width on an invalid value.
		$css_width  = $is_wide ? '100%' : self::DEFAULT_WIDTH . 'px';
		$attr_width = $is_wide ? '100%' : (string) self::DEFAULT_WIDTH;

		// The <td> itself is the rule — no content, just a border-top.
		$rule_td_style = sprintf(
			'border-top: 1px %s %s; height: 0; line-height: 0; font-size: 0;',
			esc_attr( $border ),
			esc_attr( $color )
		);

		// render_cell = false: we're supplying the full <td> directly.
		$cell_html = sprintf(
			'<td style="%s">&nbsp;</td>',
			$rule_td_style
		);

		// Outer table: centered, explicit width.
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
	 * Priority (mirrors the MJML renderer): 1. `style.color.background` (inline),
	 * 2. `backgroundColor` slug (resolved via the palette), 3. DEFAULT_COLOR.
	 * Note: the MJML renderer uses `style.color.background` for the divider color
	 * despite the `background` naming — we follow the same convention.
	 *
	 * @param array             $attrs             Block attributes.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string CSS color, or DEFAULT_COLOR.
	 */
	private function resolve_color( array $attrs, Rendering_Context $rendering_context ): string {
		// 1. Arbitrary inline color (style.color.background).
		$candidate = $attrs['style']['color']['background'] ?? '';

		// 2. Named preset slug. translate_slug_to_color() returns the slug unchanged
		// when not in the email palette — an unresolved slug is rejected below.
		if ( '' === $candidate ) {
			$bg_slug = $attrs['backgroundColor'] ?? '';
			if ( $bg_slug ) {
				$candidate = (string) $rendering_context->translate_slug_to_color( $bg_slug );
			}
		}

		// 3. Reject unresolved slugs and unexpected values to prevent an invalid
		// color (email clients drop it, leaving no rule) or style injection.
		return $this->is_css_color( $candidate ) ? $candidate : self::DEFAULT_COLOR;
	}

	/**
	 * Whether a value is a CSS color safe to interpolate into an inline style.
	 *
	 * Accepts hex, rgb/rgba/hsl/hsla with numeric components, and whitelisted named
	 * colors. Rejects unresolved slugs (hyphenated) and CSS-structural characters.
	 *
	 * @param string $value Candidate color value.
	 * @return bool
	 */
	private function is_css_color( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}
		// Hex: #rgb, #rgba, #rrggbb, #rrggbbaa.
		if ( preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return true;
		}
		// Functional notation with numeric components only.
		if ( preg_match( '/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/]+\)$/i', $value ) ) {
			return true;
		}
		// Named color from the whitelist — letters-only unresolved slugs (e.g.
		// `primary`) are rejected here and fall back to DEFAULT_COLOR.
		return in_array( strtolower( $value ), self::NAMED_COLORS, true );
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/separator', Separator::class );
