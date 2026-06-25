<?php
/**
 * Newspack override of the WC email-editor core/social-links renderer.
 *
 * The package renders each social icon as a `display: inline-table` pill and
 * concatenates them with no spacing, so the icons sit flush against each other
 * in email — unlike the editor canvas (and the standard post editor), which
 * space them with the block's gap. The package applies the block's `spacing`
 * only as padding on the wrapper, never between icons, so there is no attribute
 * to set; spacing has to be added to the rendered markup.
 *
 * This override defers to the package renderer for all icon/service/markup
 * logic, then injects a small horizontal margin on each icon pill so the email
 * matches the canvas.
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
	 * Pattern matching each icon pill's style marker. The package compiles the
	 * pill table style ending in `display:inline-table;float:none;` (compact, via
	 * WP_Style_Engine) and concatenates the pills with no spacing — so appending
	 * a margin to this marker spaces them. Whitespace-tolerant so a later
	 * style-formatting pass (or a package change) can't break the match.
	 *
	 * This couples to package-internal markup; correctness is pinned by
	 * `test_social_links_icons_are_spaced`, which fails loudly if the package
	 * stops emitting this marker. If the email-editor package gains a native
	 * inter-icon spacing attribute, prefer that and retire this seam.
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
		$html = parent::render_content( $block_content, $parsed_block, $rendering_context );
		return $this->space_icons( $html );
	}

	/**
	 * Inject a horizontal margin on each icon pill.
	 *
	 * Appends a side margin to the pill style marker the package emits. If the
	 * marker is absent (a package markup change) or `preg_replace()` errors, the
	 * original HTML is returned unchanged — no gap added, no breakage.
	 *
	 * @param string $html Rendered social-links HTML.
	 * @return string
	 */
	private function space_icons( string $html ): string {
		// Coalesce to the input so a PCRE error (e.g. a backtrack limit or invalid
		// UTF-8) yields the unspaced HTML rather than null — which would violate
		// the `: string` return type and, as the package's render() has no
		// per-block try/catch, collapse the whole newsletter's rendered body.
		return preg_replace(
			self::PILL_STYLE_PATTERN,
			sprintf( '$0 margin-left: %1$s; margin-right: %1$s;', self::ICON_SIDE_MARGIN ),
			$html
		) ?? $html;
	}
}

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/social-links', Social_Links::class );
