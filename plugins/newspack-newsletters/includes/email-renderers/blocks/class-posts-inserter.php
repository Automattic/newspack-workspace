<?php
/**
 * Newspack WC email-editor renderer for the posts-inserter block.
 *
 * Renders each inserted child block through `do_blocks()` so nested blocks are
 * fully rendered and email-processed, instead of leaking raw block-comment
 * delimiters into the email body.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Preprocessors\Blocks_Width_Preprocessor;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a newspack-newsletters/posts-inserter block under the WC engine.
 *
 * The block's server callback concatenates each child's raw `innerHTML`, leaking
 * `<!-- wp:... -->` delimiters when children contain nested blocks. This override
 * wraps each child in its block delimiter and runs it through `do_blocks()` so
 * the WC email callbacks fire automatically.
 */
class Posts_Inserter extends Abstract_Block_Renderer {
	/**
	 * Render the posts-inserter content from `innerBlocksToInsert` attrs.
	 *
	 * Ignores `$block_content` (the block's own callback produces leaky raw HTML).
	 *
	 * @param string            $block_content     Ignored; rebuilt from attrs.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$children = $parsed_block['attrs']['innerBlocksToInsert'] ?? [];
		if ( ! is_array( $children ) ) {
			return '';
		}
		// Width the package computed for this block (the column width when nested). Pass
		// it down so inserted images fit the column, not the full email width.
		$content_width = $parsed_block['email_attrs']['width'] ?? null;
		return self::apply_email_styles( self::render_inserted_blocks( $children, $content_width ) );
	}

	/**
	 * Vertical gap between meta blocks within a post item (title, subtitle, date,
	 * excerpt, continue-reading). Matches the MJML look — tighter than the package's 16px.
	 */
	const META_GAP = '8px';

	/**
	 * Vertical margin between consecutive post items (the package concatenates them with no gap).
	 */
	const POST_GAP = '24px';

	/**
	 * Top/bottom padding for each block in the flat layout (image-on-top or no image).
	 * Matches the editor canvas's per-block 6px padding so email and editor read the same.
	 */
	const FLAT_BLOCK_PAD = '6px';

	/**
	 * Restyle rendered post items to match the MJML newsletter look.
	 *
	 * 1. Links: set underline + inherit color so they follow the block's text color
	 *    (forcing #000 would clobber it). Skips image-wrapping anchors and any
	 *    anchor already carrying a style attribute (no duplicate). The CSS inliner
	 *    preserves existing inline styles, so this wins.
	 * 2. Block spacing: collapse the package's 16px gap to META_GAP.
	 * 3. Root padding: zero the inner 24px root padding to prevent double-indent
	 *    (each child picks up the email's root padding again as a top-level block).
	 *
	 * @param string $html Rendered posts-inserter HTML.
	 * @return string
	 */
	private static function apply_email_styles( string $html ): string {
		$html = (string) preg_replace(
			'/<a\b(?![^>]*\bstyle=)([^>]*)>(?!\s*<img)/i',
			'<a$1 style="text-decoration: underline; color: inherit;">',
			$html
		);
		$html = (string) preg_replace( '/margin-top:\s*16px/i', 'margin-top: ' . self::META_GAP, $html );
		return (string) preg_replace( '/padding-left:\s*24px;\s*padding-right:\s*24px/i', 'padding-left: 0; padding-right: 0', $html );
	}

	/**
	 * Render the inserted child blocks to email-safe HTML.
	 *
	 * Wraps each child in its block delimiter and runs it through `do_blocks()` so
	 * the child's `render_email_callback` fires and nested blocks render fully.
	 * Rendering bare `innerHTML` would leave the outer block (e.g. `core/columns`)
	 * as raw markup — its columns would overflow the email width.
	 *
	 * @param array       $children      The `innerBlocksToInsert` child-block array.
	 * @param string|null $content_width Width to constrain inserted images/columns to
	 *                                   (e.g. `330px`), or null for the full email width.
	 * @return string Concatenated rendered HTML in child order.
	 */
	public static function render_inserted_blocks( array $children, ?string $content_width = null ): string {
		$html  = '';
		$index = 0;
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			// A child without a block name is unexpected — fall back to rendering its
			// inner HTML so content is never silently dropped.
			if ( empty( $child['blockName'] ) ) {
				$html .= self::render_child_markup( (string) ( $child['innerHTML'] ?? '' ), $content_width );
				++$index;
				continue;
			}
			if ( 'core/columns' === $child['blockName'] ) {
				// Side-by-side layout (image left/right): each child is a whole post
				// in a columns block. Add top margin between posts (the package omits it).
				if ( $index > 0 ) {
					$child['attrs']['style']['spacing']['margin']['top'] = self::POST_GAP;
				}
				$html .= self::render_child_markup( self::serialize_inserted_block( $child ), $content_width );
			} else {
				// Flat layout (image on top, or no image): wrap each rendered block in
				// a padded cell to match the editor canvas's per-block 6px padding.
				$child = self::left_align_flat_image( $child );
				$html .= self::wrap_flat_block( self::render_child_markup( self::serialize_inserted_block( $child ), $content_width ) );
			}
			++$index;
		}
		return $html;
	}

	/**
	 * Render inserted block markup, constraining widths to the available width.
	 *
	 * A plain `do_blocks()` pass has no `email_attrs`, so inserted images fall back to
	 * the full email width and overflow a narrow column. When a width is known, run the
	 * package's width preprocessor with it as the content size so each block's
	 * `email_attrs.width` reaches the image renderer; otherwise fall back to `do_blocks()`.
	 *
	 * @param string      $markup        Block markup.
	 * @param string|null $content_width Available width (e.g. `330px`), or null.
	 * @return string Rendered email HTML.
	 */
	private static function render_child_markup( string $markup, ?string $content_width ): string {
		if ( null === $content_width || '' === $content_width || ! class_exists( Blocks_Width_Preprocessor::class ) ) {
			return do_blocks( $markup );
		}
		$blocks = ( new Blocks_Width_Preprocessor() )->preprocess(
			parse_blocks( $markup ),
			[ 'contentSize' => $content_width ],
			[]
		);
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= render_block( $block );
		}
		return $html;
	}

	/**
	 * Left-align a flat-layout featured image.
	 *
	 * The posts-inserter saves the image-on-top featured image as `align: center`, which
	 * the package renders as a centered, fixed-width cell — but it must sit flush-left to
	 * line up with the heading and excerpt below it. Only touches `core/image`.
	 *
	 * @param array $child Parsed child block.
	 * @return array Child with any centered image normalized to left alignment.
	 */
	private static function left_align_flat_image( array $child ): array {
		if ( 'core/image' !== ( $child['blockName'] ?? '' ) ) {
			return $child;
		}
		if ( 'center' === ( $child['attrs']['align'] ?? '' ) ) {
			$child['attrs']['align'] = 'left';
		}
		return $child;
	}

	/**
	 * Wrap a flat-layout block in a padded table cell.
	 *
	 * The package stacks flat-layout blocks flush and ignores their spacing attrs,
	 * so a padded `<td>` re-creates the editor canvas's per-block 6px top/bottom
	 * padding (12px gap between any two blocks, matching the canvas).
	 *
	 * @param string $block_html Rendered email HTML for a single block.
	 * @return string Block wrapped in a padded cell.
	 */
	private static function wrap_flat_block( string $block_html ): string {
		return sprintf(
			'<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="border-collapse: collapse;"><tbody><tr><td style="padding-top: %1$s; padding-bottom: %1$s;">%2$s</td></tr></tbody></table>',
			self::FLAT_BLOCK_PAD,
			$block_html
		);
	}

	/**
	 * Wrap a parsed child block back into block markup for `do_blocks()`.
	 *
	 * Reads from `innerHTML` (inserted children carry `innerHTML`, not necessarily
	 * `innerContent`), mirroring core's `serialize_block()`.
	 *
	 * @param array $child Child block shaped `{ blockName, attrs, innerHTML }`.
	 * @return string Block markup ready for `do_blocks()`.
	 */
	private static function serialize_inserted_block( array $child ): string {
		$name       = (string) $child['blockName'];
		$short_name = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$attrs      = empty( $child['attrs'] ) ? '' : ' ' . serialize_block_attributes( $child['attrs'] );
		$inner_html = (string) ( $child['innerHTML'] ?? '' );

		if ( '' === trim( $inner_html ) ) {
			return "<!-- wp:{$short_name}{$attrs} /-->";
		}
		return "<!-- wp:{$short_name}{$attrs} -->{$inner_html}<!-- /wp:{$short_name} -->";
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'newspack-newsletters/posts-inserter', Posts_Inserter::class );
