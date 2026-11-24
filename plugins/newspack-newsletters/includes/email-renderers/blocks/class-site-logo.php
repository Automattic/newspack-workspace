<?php
/**
 * Newspack override of the WC email-editor rendering of core/site-logo.
 *
 * The package has no site-logo renderer, so the block goes through its fallback, which
 * wraps the web markup in a table cell. The logo `<img>` stays inline there and sits
 * on the text baseline, leaving a descender gap under it: in a header row whose
 * columns are vertically centered, the logo ends up a few pixels higher than the text
 * beside it. The web-only image attributes (lazy loading, srcset) also pass through.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Fallback;

defined( 'ABSPATH' ) || exit;

/**
 * Renders core/site-logo as a block-level image.
 */
class Site_Logo extends Fallback {

	/**
	 * Image attributes that only matter on the web; email clients ignore them.
	 */
	const WEB_ONLY_ATTRIBUTES = [ 'loading', 'decoding', 'srcset', 'sizes', 'fetchpriority' ];

	/**
	 * Render the site logo.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		return parent::render_content(
			self::prepare_image( (string) $block_content, (string) ( $parsed_block['attrs']['align'] ?? '' ) ),
			$parsed_block,
			$rendering_context
		);
	}

	/**
	 * Make the logo image block-level and drop its web-only attributes.
	 *
	 * A block-level image no longer reserves descender space. Its alignment then comes
	 * from auto margins instead of the cell's text alignment, which still covers clients
	 * that ignore `display` on images (classic Outlook).
	 *
	 * @param string $html  Site logo markup.
	 * @param string $align Block alignment (`left`, `center`, `right` or empty).
	 * @return string
	 */
	public static function prepare_image( string $html, string $align ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( 'img' ) ) {
			return $html;
		}
		foreach ( self::WEB_ONLY_ATTRIBUTES as $attribute ) {
			$processor->remove_attribute( $attribute );
		}
		$margins = [
			'center' => 'margin-left:auto;margin-right:auto;',
			'right'  => 'margin-left:auto;',
		];
		$style   = trim( (string) $processor->get_attribute( 'style' ) );
		$style   = ( '' === $style ? '' : rtrim( $style, ';' ) . ';' )
			. 'display:block;vertical-align:middle;'
			. ( $margins[ $align ] ?? '' );
		$processor->set_attribute( 'style', $style );
		return $processor->get_updated_html();
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/site-logo', Site_Logo::class );
