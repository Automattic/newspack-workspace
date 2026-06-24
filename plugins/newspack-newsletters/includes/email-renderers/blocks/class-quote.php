<?php
/**
 * Newspack override of the WC email-editor core/quote renderer.
 *
 * Adjusts two reader-facing quote styles, both via theme.json so the editor
 * canvas and the rendered email stay in agreement:
 *
 * 1. Cite parity: the package's Integrations/Core/theme.json declares
 *    `fontStyle: italic` for the cite element inside core/quote, which the CSS
 *    inliner injects as `font-style: italic` on the rendered
 *    `<cite class="email-block-quote-citation">`. The editor canvas renders the
 *    cite upright (font-style: normal), so the email must match.
 *
 * 2. Border weight: the package declares a `0 0 0 1px` left border for the
 *    quote. Newspack uses a 2px left bar to match the standard post editor, so
 *    we override the border width here (the editor canvas applies the matching
 *    2px in src/editor/style.scss).
 *
 * Both are applied via a `woocommerce_email_editor_theme_json` filter at
 * priority 11 (after the Core Initializer's priority-10 defaults), which runs
 * before the CSS inliner so the inliner writes the overridden values.
 *
 * The block-renderer class itself is a required structural shim — the registry
 * only wires up overrides that extend a package renderer class. All layout,
 * padding, and wrapper logic are inherited from the package's Quote renderer
 * without modification.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Quote as Package_Quote;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a core/quote block, inheriting the package renderer unchanged.
 *
 * The only behavioral changes are delivered via the theme.json filter
 * registered below, not via render_content() — the filter runs before CSS
 * inlining, which is where the package's cite italic and 1px border are applied.
 * Extending Package_Quote satisfies the registry type-guard and inherits all
 * quote layout logic.
 */
class Quote extends Package_Quote {
	/**
	 * Newspack left-bar weight for the email quote, matching the post editor.
	 *
	 * @var string
	 */
	public const BORDER_WIDTH = '0 0 0 2px';

	/**
	 * Override the package theme.json for core/quote (cite italic + border width).
	 *
	 * The Core Initializer merges the package quote defaults via
	 * `woocommerce_email_editor_theme_json` at priority 10 (cite `fontStyle:
	 * italic`, border `0 0 0 1px`). This callback runs at priority 11 and merges
	 * the Newspack values so the CSS inliner sees `font-style: normal` and a 2px
	 * left border — matching what the editor canvas shows the author.
	 *
	 * Registered from the blocks/ file (not Editor_Bootstrap) so the fix is
	 * co-located with the block it concerns and loaded only through the same
	 * auto-discovery gate as all other overrides.
	 *
	 * `woocommerce_email_editor_theme_json` is a GLOBAL hook shared with the
	 * WooCommerce block-email editor package. On a site running WooCommerce's own
	 * transactional-email editor (same package, same hook), an unguarded override
	 * would restyle core/quote in WC emails too. Guard it to the newsletter CPT,
	 * mirroring Editor_Bootstrap::merge_theme_json() (the sibling on this hook).
	 *
	 * @param \WP_Theme_JSON $theme The assembled email editor theme.
	 * @return \WP_Theme_JSON
	 */
	public static function override_quote_email_styles( \WP_Theme_JSON $theme ): \WP_Theme_JSON {
		$post = \Newspack\Newsletters\Email_Renderers\Renderer_Controller::get_rendering_post();
		if ( ! $post ) {
			$post = \get_post();
		}
		if ( ! $post || \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT !== $post->post_type ) {
			return $theme;
		}

		$theme->merge(
			new \WP_Theme_JSON(
				[
					'version' => 3,
					'styles'  => [
						'blocks' => [
							'core/quote' => [
								'border'   => [
									'width' => self::BORDER_WIDTH,
									'style' => 'solid',
									'color' => 'currentColor',
								],
								'elements' => [
									'cite' => [
										'typography' => [
											'fontStyle' => 'normal',
										],
									],
								],
							],
						],
					],
				],
				'default'
			)
		);
		return $theme;
	}
}

add_filter( 'woocommerce_email_editor_theme_json', [ Quote::class, 'override_quote_email_styles' ], 11 );

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/quote', Quote::class );
