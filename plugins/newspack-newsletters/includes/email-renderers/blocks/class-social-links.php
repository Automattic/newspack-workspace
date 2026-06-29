<?php
/**
 * Newspack override of the WC email-editor core/social-links renderer.
 *
 * The package renders icons as `display: inline-table` pills concatenated with
 * no spacing — unlike the editor canvas, which spaces them with the block's gap.
 * The block's `spacing` attribute only affects the wrapper, never between icons,
 * so spacing must be injected into the rendered markup.
 *
 * This override defers to the package for all icon/markup logic, then adds a
 * horizontal margin to each pill so the email matches the canvas.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Social_Links as Package_Social_Links;

defined( 'ABSPATH' ) || exit;

/**
 * Adds inter-icon spacing to the package's core/social-links email render.
 */
class Social_Links extends Package_Social_Links {

	/**
	 * Horizontal margin applied to each side of an icon pill. 6px per side
	 * yields a 12px gap between adjacent icons, matching the canvas block gap.
	 */
	const ICON_SIDE_MARGIN = '6px';

	/**
	 * Pattern matching each icon pill's style marker. The package ends each pill
	 * style with `display:inline-table;float:none;` — a margin is appended to
	 * this marker to space the icons. Whitespace-tolerant in case of formatting changes.
	 *
	 * Couples to package-internal markup; `test_social_links_icons_are_spaced`
	 * pins it and fails loudly if the marker changes.
	 */
	const PILL_STYLE_PATTERN = '/display:\s*inline-table;\s*float:\s*none;/';

	/**
	 * Render the social-links block, then space the icons.
	 *
	 * Defers to the package renderer for all markup, then injects a horizontal
	 * margin on each icon pill so the email matches the editor canvas.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$parsed_block = self::apply_justification( $parsed_block );
		$html         = parent::render_content( $block_content, $parsed_block, $rendering_context );
		return $this->space_icons( $html );
	}

	/**
	 * Map `layout.justifyContent` to `textAlign` for the package renderer.
	 *
	 * The package derives alignment from `textAlign`/`align`, not from the flex
	 * layout's `justifyContent` — so a centered row in the editor renders
	 * left-aligned in email. Only applied when `textAlign` isn't already set.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return array Parsed block, possibly with `attrs.textAlign` set.
	 */
	private static function apply_justification( array $parsed_block ): array {
		if ( ! empty( $parsed_block['attrs']['textAlign'] ) ) {
			return $parsed_block;
		}
		$justify = $parsed_block['attrs']['layout']['justifyContent'] ?? '';
		$map     = [
			'left'   => 'left',
			'center' => 'center',
			'right'  => 'right',
		];
		if ( isset( $map[ $justify ] ) ) {
			$parsed_block['attrs']['textAlign'] = $map[ $justify ];
		}
		return $parsed_block;
	}

	/**
	 * Add a horizontal margin to each icon pill so icons are spaced like the editor.
	 *
	 * Returns HTML unchanged if the package marker is absent (no breakage).
	 * Coalesces to input on PCRE error — a null return would violate the `: string`
	 * type and, since the package has no per-block try/catch, collapse the whole
	 * newsletter body.
	 *
	 * @param string $html Rendered social-links HTML.
	 * @return string
	 */
	private function space_icons( string $html ): string {
		return preg_replace(
			self::PILL_STYLE_PATTERN,
			sprintf( '$0 margin-left: %1$s; margin-right: %1$s;', self::ICON_SIDE_MARGIN ),
			$html
		) ?? $html;
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/social-links', Social_Links::class );
