<?php
/**
 * Newspack override of the WC email-editor core/quote renderer.
 *
 * Corrects two quote styles that differ from the editor canvas:
 * 1. Cite: the package sets `fontStyle: italic`; the canvas renders it upright.
 * 2. Border: the package uses 1px; Newspack uses 2px (matching the post editor).
 *
 * Both are applied via a `woocommerce_email_editor_theme_json` filter at
 * priority 11 (after the package's priority-10 defaults) so the CSS inliner
 * picks up the corrected values. The renderer class is a structural shim — the
 * registry requires a package class extension; all render logic is inherited.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Quote as Package_Quote;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a core/quote block, inheriting the package renderer unchanged.
 *
 * The cite-italic and 1px-border fixes are delivered via the theme.json filter
 * below — before CSS inlining — not via render_content(). Extending Package_Quote
 * satisfies the registry type-guard and inherits all quote layout logic.
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
	 * Runs at priority 11, after the Core Initializer's priority-10 defaults, so
	 * the CSS inliner sees `font-style: normal` and a 2px left border.
	 *
	 * Guarded to the newsletter CPT: `woocommerce_email_editor_theme_json` is a
	 * global hook shared with WC transactional emails — an unguarded override
	 * would restyle core/quote in WC emails too.
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

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/quote', Quote::class );
