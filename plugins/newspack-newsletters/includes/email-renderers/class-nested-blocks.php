<?php
/**
 * Render block markup nested inside another block under the WC email engine.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Preprocessors\Blocks_Width_Preprocessor;

defined( 'ABSPATH' ) || exit;

/**
 * Renders markup that a block override pulls in from elsewhere (posts inserted by the
 * Posts Inserter, an ad's content) so it fits the block that hosts it.
 *
 * During a WC render the package swaps in its own block parser, which runs the whole
 * email preprocessing pipeline on every `parse_blocks()` call. Nested markup is
 * therefore treated as top-level email content: each block is given the email's root
 * padding (another 24px gutter on each side) and a width computed from the full email
 * content size. Inside a host block that already sits in the gutter, that indents the
 * content twice and either shrinks images by 48px or, when the width isn't reduced,
 * overflows the email.
 *
 * This helper drops the distributed root and container padding again and recomputes
 * widths against the host block's own width.
 */
class Nested_Blocks {

	/**
	 * `email_attrs` keys the package's spacing preprocessor uses to distribute the
	 * email's root and container padding to top-level blocks.
	 */
	const PADDING_ATTRS = [
		'root-padding-left',
		'root-padding-right',
		'container-padding-left',
		'container-padding-right',
	];

	/**
	 * Render nested block markup to email HTML.
	 *
	 * @param string      $markup Block markup.
	 * @param string|null $width  Width available inside the host block (e.g. `612px`),
	 *                            or null when unknown.
	 * @return string Rendered email HTML.
	 */
	public static function render( string $markup, ?string $width = null ): string {
		$blocks = self::strip_root_padding( parse_blocks( $markup ) );
		if ( null !== $width && '' !== $width && class_exists( Blocks_Width_Preprocessor::class ) ) {
			$blocks = ( new Blocks_Width_Preprocessor() )->preprocess( $blocks, [ 'contentSize' => $width ], [] );
		}
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= render_block( $block );
		}
		return $html;
	}

	/**
	 * Remove the root and container padding the package distributed to parsed blocks.
	 *
	 * Outside a WC render `parse_blocks()` adds no `email_attrs`, so this is a no-op there.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Blocks without distributed root/container padding.
	 */
	public static function strip_root_padding( array $blocks ): array {
		foreach ( $blocks as $key => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			foreach ( self::PADDING_ATTRS as $attr ) {
				unset( $blocks[ $key ]['email_attrs'][ $attr ] );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $key ]['innerBlocks'] = self::strip_root_padding( $block['innerBlocks'] );
			}
		}
		return $blocks;
	}
}
