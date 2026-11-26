<?php
/**
 * Newspack override of the WC email-editor core/list-item renderer.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\List_Item as Package_List_Item;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a list item with the gap the editor shows above it.
 *
 * The package renders list items flush: it drops the block gap for them, since a list
 * item can't take the spacer wrapper other blocks get. In the newsletter editor each list
 * item is padded like any other block, so items sit a block gap apart. The gap goes on the
 * `<li>` itself as a top margin.
 */
class List_Item extends Package_List_Item {
	/**
	 * Render the list item, with the block gap as its top margin.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$html = parent::render_content( $block_content, $parsed_block, $rendering_context );
		$gap  = $parsed_block['email_attrs']['margin-top'] ?? '';
		if ( ! is_string( $gap ) || ! preg_match( '/^\d+(?:\.\d+)?px$/', $gap ) ) {
			return $html;
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( $processor->next_tag( 'li' ) ) {
			$style = trim( (string) $processor->get_attribute( 'style' ) );
			$processor->set_attribute( 'style', ( '' === $style ? '' : rtrim( $style, ';' ) . ';' ) . 'margin-top:' . $gap . ';' );
		}
		return $processor->get_updated_html();
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/list-item', List_Item::class );
