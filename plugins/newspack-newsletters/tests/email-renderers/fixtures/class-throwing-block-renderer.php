<?php
/**
 * Test fixture: a valid block renderer subclass that throws on construction.
 *
 * Lives in `fixtures/` (not `fixtures/block-renderers/`) so the discovery glob
 * never loads it; the fail-closed test requires it explicitly. It passes the
 * is_subclass_of() guard but throws in its constructor, exercising the
 * registry's try/catch around instantiation.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * A renderer whose constructor always throws.
 */
class Throwing_Block_Renderer extends Abstract_Block_Renderer {
	/**
	 * Always throws, to simulate a renderer that cannot be instantiated.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __construct() {
		throw new \RuntimeException( 'fixture constructor boom' );
	}

	/**
	 * Never reached; required to satisfy the abstract base.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		return $block_content;
	}
}
