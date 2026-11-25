<?php
/**
 * Newspack override of the WC email-editor core/column renderer.
 *
 * Restores percentage column widths that the package strips to bare pixels, and
 * writes a valid vertical alignment for vertically centered columns.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Column as Package_Column;
use Newspack\Newsletters\Email_Renderers\Block_Normalizer;

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
	 * Block vertical alignments mapped to their table-cell `vertical-align` value. The
	 * package writes the block value straight into CSS, and `center` isn't a valid
	 * `vertical-align`: Chrome drops it and falls back to `middle`, but Firefox (and
	 * email clients that are stricter still) align the cell on its baseline, which drops
	 * text beside a block-level image to the bottom of the row.
	 */
	const VERTICAL_ALIGN = [
		'top'    => 'top',
		'center' => 'middle',
		'bottom' => 'bottom',
	];

	/**
	 * Render the column, then restore its percentage width and vertical alignment.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$valign = self::VERTICAL_ALIGN[ $parsed_block['attrs']['verticalAlignment'] ?? '' ] ?? null;
		if ( null !== $valign ) {
			$parsed_block['attrs']['verticalAlignment'] = $valign;
		}
		$html = self::preserve_percentage_width(
			parent::render_content( $block_content, $parsed_block, $rendering_context ),
			(string) ( $parsed_block['attrs']['width'] ?? '' )
		);
		if ( ! empty( $parsed_block['attrs'][ Block_Normalizer::GAP_ATTR ] ) ) {
			$html = self::mark_gap_padding( $html );
		}
		return null === $valign ? $html : self::add_valign( $html, $valign );
	}

	/**
	 * Tag the cell that holds the column's padding when that padding carries the
	 * columns gap, so the email's phone styles can drop it once columns stack.
	 *
	 * @param string $html Rendered column HTML.
	 * @return string
	 */
	private static function mark_gap_padding( string $html ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( $processor->next_tag(
			[
				'tag_name'   => 'table',
				'class_name' => 'email-block-column-content',
			]
		) && $processor->next_tag( 'td' ) ) {
			$processor->add_class( Block_Normalizer::GAP_CLASS );
		}
		return $processor->get_updated_html();
	}

	/**
	 * Set the `valign` attribute on the column cell, which email clients that strip CSS
	 * still honor.
	 *
	 * @param string $html   Rendered column HTML.
	 * @param string $valign `top`, `middle` or `bottom`.
	 * @return string
	 */
	private static function add_valign( string $html, string $valign ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( $processor->next_tag(
			[
				'tag_name'   => 'td',
				'class_name' => 'email-block-column',
			]
		) ) {
			$processor->set_attribute( 'valign', $valign );
		}
		return $processor->get_updated_html();
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
