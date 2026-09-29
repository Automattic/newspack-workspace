<?php
/**
 * Test fixture renderer for the registry glob-discovery test.
 *
 * This file lives outside `includes/` so it is NOT classmap-autoloaded and is
 * NOT loaded by the production `blocks/` glob. The only way it can register is
 * if `Block_Renderer_Registry::discover()` globs this directory and requires it
 * — which is exactly what the discovery test exercises. Nothing references the
 * class by name, so a broken glob would leave `test/fixture-block` unmapped.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * A no-op renderer used only to prove the discovery glob loads and registers.
 */
class Test_Fixture_Renderer extends Abstract_Block_Renderer {
	/**
	 * Render the block content (no-op for the fixture).
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

// Self-register exactly like a real override, so loading this file is what maps the block.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'test/fixture-block', Test_Fixture_Renderer::class );
