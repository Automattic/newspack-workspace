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
	 * Default separator width in pixels for the short/default variant. Stored as
	 * a number so the CSS (`{N}px`) and the HTML `width="{N}"` attribute are
	 * composed from one source and can't drift apart.
	 */
	const DEFAULT_WIDTH = 100;

	/**
	 * Default separator color (light gray, matching WP core default).
	 */
	const DEFAULT_COLOR = '#dddddd';

	/**
	 * Recognized CSS named colors (the HTML basic keywords plus a few common
	 * extras). A safety net only — the block editor emits hex, never a bare color
	 * name — kept deliberately small so an unresolved palette slug that happens to
	 * be letters-only (e.g. `primary`) is rejected rather than emitted as an
	 * invalid color.
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

		// The CSS width carries the unit, but the HTML `width` attribute must be a
		// number or a percentage — a `100px` attribute is invalid and some email
		// clients then fall back to full width — so derive a numeric attribute.
		$css_width  = $is_wide ? '100%' : self::DEFAULT_WIDTH . 'px';
		$attr_width = $is_wide ? '100%' : (string) self::DEFAULT_WIDTH;

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
	 * @return string A CSS color safe to interpolate into an inline style, or DEFAULT_COLOR.
	 */
	private function resolve_color( array $attrs, Rendering_Context $rendering_context ): string {
		// 1. Arbitrary inline color (style.color.background).
		$candidate = $attrs['style']['color']['background'] ?? '';

		// 2. Named preset color slug. translate_slug_to_color() returns the slug
		// unchanged when it isn't in the email theme palette, so an unresolved slug
		// surfaces here as its own name (e.g. "vivid-red") — rejected below.
		if ( '' === $candidate ) {
			$bg_slug = $attrs['backgroundColor'] ?? '';
			if ( $bg_slug ) {
				$candidate = (string) $rendering_context->translate_slug_to_color( $bg_slug );
			}
		}

		// 3. Fall back to the default gray unless the value is a recognizable CSS
		// color. This keeps an unresolved slug (or an unexpected author-supplied
		// value) from emitting an invalid color — which email clients drop, leaving
		// no rule — and prevents a crafted value from injecting extra declarations
		// into the cell's inline style.
		return $this->is_css_color( $candidate ) ? $candidate : self::DEFAULT_COLOR;
	}

	/**
	 * Whether a value is a CSS color safe to interpolate into an inline style.
	 *
	 * Accepts hex, `rgb()/rgba()/hsl()/hsla()` with numeric components, and
	 * single-word named colors. Rejects empty values, unresolved color slugs
	 * (which contain hyphens), and anything carrying CSS-structural characters
	 * that could break out of the declaration.
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
		// A named color, from a small whitelist. A bare word is only treated as a
		// color when it's a real CSS keyword, so an unresolved palette slug like
		// `primary` (letters-only, but not a color) is rejected and falls back to
		// DEFAULT_COLOR rather than producing an invalid rule.
		return in_array( strtolower( $value ), self::NAMED_COLORS, true );
	}
}

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/separator', Separator::class );
