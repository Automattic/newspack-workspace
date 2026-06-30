<?php
/**
 * Full-bleed restructuring for top-level alignfull background sections.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Re-threads the rendered WC email so top-level `align:full` groups that carry a
 * background color bleed to the full email width, while their content — and every
 * other block — stays constrained to the content width.
 *
 * The WC email-editor renders the whole email inside one content-width container (a
 * `max-width` div for modern clients, an MSO fixed-width table for Outlook), so an
 * alignfull group's background can only reach the content edge, never beyond. This
 * post-processor splits the email at each top-level alignfull-background group and
 * re-emits that group as a body-level full-width row — the cross-client full-bleed
 * structure (`<table width="100%" bgcolor>` with a re-centered content-width inner) —
 * matching how the legacy MJML renderer treated `full-width` sections. Normal blocks
 * stay in the original content-width wrapper.
 *
 * It is a strict no-op when there are no qualifying groups or the expected package
 * structure is absent, so non-alignfull emails — and any future package output
 * change — fall back to the unmodified render.
 */
class Full_Bleed_Sections {

	/**
	 * Default horizontal root padding (content gutter) to inset band content by so it
	 * lines up with normal blocks, when it can't be read from the rendered email.
	 */
	const DEFAULT_ROOT_PADDING = '24px';

	/**
	 * Default vertical padding for a background group that sets none. The email editor
	 * canvas gives background groups this default padding, but the package render emits
	 * zero — so a no-padding band would otherwise be tighter in the email than the editor.
	 */
	const DEFAULT_VERTICAL_PADDING = '12px';

	/**
	 * Email content width in pixels — the package `contentSize` the band content is
	 * re-centered to so it lines up with normal blocks.
	 */
	const CONTENT_WIDTH = 660;

	/**
	 * Restructure top-level alignfull background groups into body-level full-width rows.
	 *
	 * @param string $html Rendered (CSS-inlined) email HTML from the WC renderer.
	 * @return string Transformed HTML, or the input unchanged when not applicable.
	 */
	public static function transform( string $html ): string {
		// Fast bail: nothing to bleed without an alignfull background group.
		if ( false === strpos( $html, 'alignfull' ) || false === strpos( $html, 'has-background' ) ) {
			return $html;
		}

		$dom = self::load_dom( $html );
		if ( ! $dom ) {
			return $html;
		}
		$xpath = new \DOMXPath( $dom );

		// The template wraps post-content in one constrained group; its content cell is
		// the first `email-block-group-content`, and the flat list of top-level blocks
		// lives in the single `<td>` of that group's layout table.
		$cell = $xpath->query( "//td[contains(concat(' ', normalize-space(@class), ' '), ' email-block-group-content ')]" )->item( 0 );
		if ( ! $cell ) {
			return $html;
		}
		$container = $xpath->query( "./div[contains(concat(' ', normalize-space(@class), ' '), ' email-block-layout ')]/table/tbody/tr/td", $cell )->item( 0 );
		if ( ! $container ) {
			return $html;
		}

		// Enumerate top-level blocks in order, classifying each as a full-bleed band or
		// normal content. The band group must be the block's OWN immediate table (child
		// axis) — a normal Columns/Group block that merely contains a nested alignfull
		// background group deeper down must not be hoisted wholesale.
		$band_query = './table['
			. "contains(concat(' ', normalize-space(@class), ' '), ' email-block-group ') and "
			. "contains(concat(' ', normalize-space(@class), ' '), ' alignfull ') and "
			. "contains(concat(' ', normalize-space(@class), ' '), ' has-background ')]";

		$root_padding = self::detect_root_padding( $xpath, $container );
		$nodes        = iterator_to_array( $container->childNodes ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
		$node_count   = count( $nodes );
		$segments     = [];
		$band_count   = 0;
		$run          = '';
		for ( $i = 0; $i < $node_count; $i++ ) {
			$node = $nodes[ $i ];

			// Drop a band's own per-block MSO ghost-table wrapper: build_band emits a fresh
			// one, so skip the opening comment that immediately precedes a band element.
			if ( self::is_mso_open( $node ) && isset( $nodes[ $i + 1 ] ) && self::is_band( $nodes[ $i + 1 ], $xpath, $band_query ) ) {
				continue;
			}

			if ( self::is_band( $node, $xpath, $band_query ) ) {
				if ( '' !== $run ) {
					$segments[] = [ 'normal', $run ];
					$run        = '';
				}
				$group      = $xpath->query( $band_query, $node )->item( 0 );
				$segments[] = [ 'band', self::build_band( $group, $dom, $xpath, $root_padding ) ];
				++$band_count;
				// Skip the band's closing ghost-table comment too.
				if ( isset( $nodes[ $i + 1 ] ) && self::is_mso_close( $nodes[ $i + 1 ] ) ) {
					++$i;
				}
				continue;
			}

			// Normal node — an element block, its surrounding MSO ghost-table comments, or
			// whitespace. Keep it verbatim so Outlook's per-block gutter and spacing (which
			// live in those conditional comments, not the CSS) survive into the rebuilt body.
			$run .= $dom->saveHTML( $node );
		}
		if ( '' !== $run ) {
			$segments[] = [ 'normal', $run ];
		}

		// No qualifying band — leave the render untouched.
		if ( 0 === $band_count ) {
			return $html;
		}

		$shell = self::shell_fragments( $html );
		if ( ! $shell ) {
			return $html;
		}

		// Assemble: the preheader once at the top (so it wins the inbox preview slot even
		// when the first block is a band), then a vertical stack of content-width runs and
		// full-width band rows. A run is any string segment; a band is already full markup.
		$out = $shell['head'] . $shell['preheader'];
		foreach ( $segments as $segment ) {
			$out .= 'band' === $segment[0] ? $segment[1] : $shell['open'] . $segment[1] . $shell['close'];
		}
		$out .= $shell['tail'];

		return $out;
	}

	/**
	 * Parse the email HTML into a DOMDocument, preserving MSO conditional comments.
	 *
	 * @param string $html Email HTML.
	 * @return \DOMDocument|null Document, or null on failure.
	 */
	private static function load_dom( string $html ): ?\DOMDocument {
		$dom = new \DOMDocument();
		$use = libxml_use_internal_errors( true );
		$ok  = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $use );
		return $ok ? $dom : null;
	}

	/**
	 * Read the horizontal content gutter (root padding) from a normal block so band
	 * content can be inset to match. Falls back to the default when none is found.
	 *
	 * @param \DOMXPath   $xpath     XPath over the document.
	 * @param \DOMElement $container The top-level block container cell.
	 * @return string Padding value (e.g. `24px`).
	 */
	private static function detect_root_padding( \DOMXPath $xpath, \DOMElement $container ): string {
		$node = $xpath->query( ".//div[contains(concat(' ', normalize-space(@class), ' '), ' email-root-padding ')]", $container )->item( 0 );
		if ( $node instanceof \DOMElement ) {
			$left = self::style_value( $node->getAttribute( 'style' ), 'padding-left' );
			if ( '' !== $left ) {
				return $left;
			}
		}
		return self::DEFAULT_ROOT_PADDING;
	}

	/**
	 * Build a body-level full-width band row from an alignfull background group.
	 *
	 * @param \DOMElement  $group        The alignfull background group table.
	 * @param \DOMDocument $dom          Owner document (for serializing inner content).
	 * @param \DOMXPath    $xpath        XPath over the document.
	 * @param string       $root_padding Horizontal inset to align band content with normal blocks.
	 * @return string Full-width band HTML.
	 */
	private static function build_band( \DOMElement $group, \DOMDocument $dom, \DOMXPath $xpath, string $root_padding ): string {
		$group_style = $group->getAttribute( 'style' );
		$bg          = self::style_value( $group_style, 'background-color' );
		if ( '' === $bg ) {
			$bg = self::style_value( $group_style, 'background' );
		}
		$color = self::style_value( $group_style, 'color' );

		$content_cell = $xpath->query( "./tbody/tr/td[contains(concat(' ', normalize-space(@class), ' '), ' email-block-group-content ')]", $group )->item( 0 );
		$cell_style   = $content_cell instanceof \DOMElement ? $content_cell->getAttribute( 'style' ) : '';
		$pad_top      = self::style_value( $cell_style, 'padding-top' );
		$pad_bottom   = self::style_value( $cell_style, 'padding-bottom' );

		$inner = '';
		if ( $content_cell instanceof \DOMElement ) {
			foreach ( $content_cell->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
				$inner .= $dom->saveHTML( $node );
			}
		}

		$cell_padding = sprintf(
			'padding-top:%s;padding-bottom:%s;',
			'' !== $pad_top ? $pad_top : self::DEFAULT_VERTICAL_PADDING,
			'' !== $pad_bottom ? $pad_bottom : self::DEFAULT_VERTICAL_PADDING
		);
		// border-box so the content width matches normal blocks: the content-size cap
		// includes the horizontal gutter, leaving the same inner content width.
		$inner_style = sprintf(
			'box-sizing:border-box;max-width:%1$dpx;margin:0 auto;padding-left:%2$s;padding-right:%2$s;%3$s',
			self::CONTENT_WIDTH,
			$root_padding,
			$color ? 'color:' . $color . ';' : ''
		);

		return '<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%" bgcolor="' . esc_attr( $bg ) . '" style="' . esc_attr( 'width:100%;background-color:' . $bg . ';' ) . '">'
			. '<tbody><tr><td align="center" style="' . esc_attr( $cell_padding ) . '">'
			. '<!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" width="' . self::CONTENT_WIDTH . '" style="width:' . self::CONTENT_WIDTH . 'px"><tr><td><![endif]-->'
			. '<div style="' . esc_attr( $inner_style ) . '">' . $inner . '</div>'
			. '<!--[if mso | IE]></td></tr></table><![endif]-->'
			. '</td></tr></tbody></table>';
	}

	/**
	 * Extract the email shell fragments (from the package template canvas) used to wrap
	 * each content-width run. Returns null when the expected markers are absent.
	 *
	 * Assumes the canvas places nothing of substance between the layout wrapper and
	 * `</body>` (verified against the current `template-canvas.php`: only the MSO close
	 * comment and whitespace; styles are parked in `<head>`). `tail` is therefore a
	 * literal close; if a future package version emits a footer there it would be dropped
	 * in banded emails — revisit this on package bumps.
	 *
	 * @param string $html Email HTML.
	 * @return array{head:string,preheader:string,open:string,close:string,tail:string}|null
	 */
	private static function shell_fragments( string $html ): ?array {
		$body_pos = strpos( $html, '<body' );
		$mso_pos  = strpos( $html, '<!--[if mso | IE]><table align="center"' );
		$cw_pos   = strpos( $html, '<td class="email_content_wrapper"' );
		if ( false === $body_pos || false === $mso_pos || false === $cw_pos ) {
			return null;
		}
		$body_open_end = strpos( $html, '>', $body_pos );
		$cw_open_end   = strpos( $html, '>', $cw_pos );
		if ( false === $body_open_end || false === $cw_open_end ) {
			return null;
		}

		$open = substr( $html, $mso_pos, $cw_open_end + 1 - $mso_pos );

		// Lift the (hidden) preheader row out of the wrapper so it is emitted once at the
		// top of the body — before any band — and the per-run wrapper carries no preheader.
		$preheader = '';
		if ( preg_match( '#<tr>\s*<td class="email_preheader".*?</td>\s*</tr>#is', $open, $match ) ) {
			$preheader = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tbody>' . $match[0] . '</tbody></table>';
			$stripped  = preg_replace( '#<tr>\s*<td class="email_preheader".*?</td>\s*</tr>#is', '', $open );
			$open      = is_string( $stripped ) ? $stripped : $open;
		}

		return [
			'head'      => substr( $html, 0, $body_open_end + 1 ),
			'preheader' => $preheader,
			'open'      => $open,
			'close'     => '</td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]-->',
			'tail'      => "\n</body></html>",
		];
	}

	/**
	 * Whether a node is a top-level band: an element directly wrapping an alignfull
	 * background group table.
	 *
	 * @param \DOMNode  $node       Node to test.
	 * @param \DOMXPath $xpath      XPath over the document.
	 * @param string    $band_query Relative XPath matching the band group table.
	 * @return bool
	 */
	private static function is_band( \DOMNode $node, \DOMXPath $xpath, string $band_query ): bool {
		return $node instanceof \DOMElement && $xpath->query( $band_query, $node )->item( 0 ) instanceof \DOMElement;
	}

	/**
	 * Whether a node is an MSO ghost-table OPENING conditional comment (contains a
	 * `<table` open tag).
	 *
	 * @param \DOMNode $node Node to test.
	 * @return bool
	 */
	private static function is_mso_open( \DOMNode $node ): bool {
		return $node instanceof \DOMComment && false !== strpos( self::comment_value( $node ), '><table' );
	}

	/**
	 * Whether a node is an MSO ghost-table CLOSING conditional comment (closes a table
	 * but opens none).
	 *
	 * @param \DOMNode $node Node to test.
	 * @return bool
	 */
	private static function is_mso_close( \DOMNode $node ): bool {
		$value = self::comment_value( $node );
		return $node instanceof \DOMComment && false !== strpos( $value, '</table>' ) && false === strpos( $value, '><table' );
	}

	/**
	 * Read a comment node's text.
	 *
	 * @param \DOMNode $node Comment node.
	 * @return string
	 */
	private static function comment_value( \DOMNode $node ): string {
		return (string) $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
	}

	/**
	 * Read a single declaration value out of an inline style string.
	 *
	 * @param string $style Inline style attribute value.
	 * @param string $prop  CSS property name.
	 * @return string Trimmed value, or empty string when absent.
	 */
	private static function style_value( string $style, string $prop ): string {
		if ( preg_match( '/(?:^|;)\s*' . preg_quote( $prop, '/' ) . '\s*:\s*([^;]+)/i', $style, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}
}
