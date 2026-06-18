<?php
/**
 * Newspack override of the WC email-editor core/column renderer.
 *
 * Extends the package's Column renderer and restores percentage column widths
 * that the package strips to bare pixels.
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
 * The package's Column wrapper sets the cell width via
 * `Styles_Helper::parse_value( $width )`, whose regex grabs the leading number
 * and drops the unit — so a `70%` column renders `width="70"` (= 70px) and
 * collapses the layout. This subclass extends the package renderer (so it reuses
 * the package's column markup AND inherits its no-op `add_spacer()` — columns
 * render side by side and must NOT be spacer-wrapped) and then restores the
 * percent on the wrapper cell.
 */
class Column extends Package_Column {
	/**
	 * Render the column content, restoring its percentage width.
	 *
	 * Delegates to the package's `render_content()` for the column markup, then
	 * restores the percentage width the package stripped to bare pixels.
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
	 * Restore a percentage column width that the package stripped to pixels.
	 *
	 * Pure string transform so it stays unit-testable in isolation. The package
	 * emits the width via `Styles_Helper::parse_value( $width )`, which casts the
	 * leading number to a float and drops the unit — so `70%` becomes `width="70"`
	 * and `33.33%` becomes `width="33.33"`. We reproduce that canonical numeric
	 * (`(float) "30.0" === 30.0`, rendered as `30`) and rewrite the first wrapper
	 * cell carrying it back to a percentage. The wrapper `<td>` is the only cell
	 * with a numeric width (inner cells have none; the inner table is
	 * `width="100%"`) and it is the first `<td>` in the column output, so the
	 * first-occurrence callback targets exactly that cell.
	 *
	 * When the width is empty or not a percentage there is nothing to restore, so
	 * the HTML is returned unchanged.
	 *
	 * @param string $html  The rendered column HTML.
	 * @param string $width The original column width attribute (e.g. `70%`).
	 * @return string The HTML with the percentage width restored.
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

		// Restore the percent on the first wrapper <td> carrying that numeric width.
		// preg_replace_callback avoids replacement-string backreference hazards. The
		// canonical numeric (not the raw input) is re-percented, so `30.0%` and `30%`
		// both normalize to `width="30%"`.
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
