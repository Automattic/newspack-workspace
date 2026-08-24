<?php
/**
 * Newspack override of the WC email-editor core/column renderer.
 *
 * Restores percentage column widths that the package strips to bare pixels.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Column as Package_Column;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a core/column block, preserving percentage widths.
 *
 * The package's `Styles_Helper::parse_value()` drops the unit from `70%`,
 * emitting `width="70"` (70px) and collapsing the layout. This subclass
 * delegates to the package (inheriting its no-op `add_spacer()` — columns
 * must not be spacer-wrapped) and restores the percent on the wrapper cell.
 */
class Column extends Package_Column {
	/**
	 * Render the column, then restore its percentage width.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		return self::preserve_percentage_width(
			parent::render_content( $block_content, $parsed_block, $rendering_context ),
			(string) ( $parsed_block['attrs']['width'] ?? '' )
		);
	}

	/**
	 * Restore a percentage column width stripped to pixels by the package.
	 *
	 * `Styles_Helper::parse_value()` casts the leading number and drops the unit
	 * (`70%` → `width="70"`). We reproduce that canonical numeric and rewrite the
	 * first wrapper `<td>` carrying it back to a percentage. Returns HTML unchanged
	 * when the width is empty or not a percentage.
	 *
	 * @param string $html  Rendered column HTML.
	 * @param string $width Original column width attribute (e.g. `70%`).
	 * @return string HTML with percentage width restored.
	 */
	public static function preserve_percentage_width( string $html, string $width ): string {
		if ( '' === $width || '%' !== substr( $width, -1 ) ) {
			return $html;
		}

		$num = rtrim( $width, '%' );
		if ( ! is_numeric( $num ) ) {
			return $html;
		}

		// The canonical numeric the package emits, e.g. `30` for `30.0%`, `33.33` for `33.33%`.
		$canonical = (string) ( (float) $num );

		// Re-add % on the first wrapper <td> carrying that numeric width.
		// preg_replace_callback avoids backreference hazards in the replacement string.
		$did_replace = false;
		return preg_replace_callback(
			'/<td\b[^>]*\bwidth="' . preg_quote( $canonical, '/' ) . '"/',
			static function ( $matches ) use ( $canonical, &$did_replace ) {
				if ( $did_replace ) {
					return $matches[0];
				}
				$did_replace = true;
				return str_replace(
					'width="' . $canonical . '"',
					'width="' . $canonical . '%"',
					$matches[0]
				);
			},
			$html
		);
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/column', Column::class );
