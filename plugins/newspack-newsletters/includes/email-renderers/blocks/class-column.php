<?php
/**
 * Newspack override of the WC email-editor core/column renderer.
 *
 * Delegates to the package's Column renderer, then restores percentage
 * column widths that the package strips to bare pixels.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Column as Package_Column;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a core/column block, preserving percentage widths.
 *
 * The package's Column wrapper sets the cell width via
 * `Styles_Helper::parse_value( $width )`, whose regex grabs the leading
 * number and drops the unit — so a `70%` column renders `width="70"` (= 70px)
 * and collapses the layout. This subclass delegates to the package renderer
 * and then restores the percent on the wrapper cell.
 */
class Column extends Abstract_Block_Renderer {
	/**
	 * Render the column content, restoring its percentage width.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$html = ( new Package_Column() )->render( $block_content, $parsed_block, $rendering_context );
		return self::preserve_percentage_width( $html, (string) ( $parsed_block['attrs']['width'] ?? '' ) );
	}

	/**
	 * Restore a percentage column width that the package stripped to pixels.
	 *
	 * Pure string transform so it stays unit-testable in isolation. When the
	 * width is empty or not a percentage there is nothing to restore, so the
	 * HTML is returned unchanged. Otherwise the package emitted the stripped
	 * numeric (e.g. `70` for `70%`) as `width="70"`; the first such occurrence
	 * on the wrapper cell is rewritten back to `width="70%"`.
	 *
	 * @param string $html  The rendered column HTML.
	 * @param string $width The original column width attribute (e.g. `70%`).
	 * @return string The HTML with the percentage width restored.
	 */
	public static function preserve_percentage_width( string $html, string $width ): string {
		if ( '' === $width || '%' !== substr( $width, -1 ) ) {
			return $html;
		}
		$stripped = rtrim( $width, '%' );
		return preg_replace(
			'/width="' . preg_quote( $stripped, '/' ) . '"/',
			'width="' . $width . '"',
			$html,
			1
		);
	}
}
