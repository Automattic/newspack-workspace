<?php
/**
 * Newspack email-renderer for the newspack-newsletters/ad block.
 *
 * The `newspack-newsletters/ad` block is self-closing and has no save output
 * (`html: false` in block.json), so the WC email-editor package receives an
 * empty `$block_content` string and its fallback renderer emits nothing — the
 * ad is silently dropped from the rendered email.
 *
 * This override resolves the ad post from the block's `adId` attribute (or
 * auto-selects the first un-inserted active ad when `adId` is absent), renders
 * the ad post's block content through the active WC email-rendering pipeline,
 * and marks the ad as inserted — mirroring the `newspack-newsletters/ad` case
 * in the legacy MJML renderer (class-newspack-newsletters-renderer.php, line
 * 1702).
 *
 * The WC pipeline is active during a `render_wc()` call because
 * `Content_Renderer::initialize()` hooks `render_block` at priority 10 for
 * the duration of the render. Calling `do_blocks()` on the ad post's content
 * from inside a `render_email_callback` therefore routes each inner block
 * through the same WC email-rendering pass — `render_email_callback` for
 * blocks that have one, the WC fallback otherwise.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;
use Newspack_Newsletters\Ads;
use Newspack_Newsletters\Ads_Placements;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a newspack-newsletters/ad block in an email-safe way.
 *
 * Resolves the ad post from the block's `adId` attribute, renders its block
 * content through the WC email pipeline, and marks the ad as inserted.
 */
class Ad extends Abstract_Block_Renderer {

	/**
	 * Render the ad block.
	 *
	 * Resolves the ad post (by direct ID, placement ID, or auto-selection),
	 * renders its block content through the currently-active WC email pipeline,
	 * then marks the ad as inserted for tracking and de-duplication.
	 *
	 * @param string            $block_content     Original block content (always empty — the block has no save output).
	 * @param array             $parsed_block      Parsed block data including attrs.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string Rendered ad HTML, or empty string when no ad post is found.
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$attrs   = $parsed_block['attrs'] ?? array();
		$ad_id   = isset( $attrs['adId'] ) ? (string) $attrs['adId'] : '';
		$ad_post = $this->resolve_ad_post( $ad_id );

		if ( ! $ad_post instanceof \WP_Post ) {
			return '';
		}

		// Mark the ad as inserted so auto-selection skips it on the next ad
		// block and tracking counts each ad once — mirrors the MJML renderer.
		$newsletter = Renderer_Controller::get_rendering_post();
		if ( $newsletter instanceof \WP_Post ) {
			Ads::mark_ad_inserted( $newsletter->ID, $ad_post->ID );
		}

		// Render the ad post's block content through the currently-active WC
		// email pipeline. Content_Renderer::initialize() hooks render_block at
		// priority 10 for the duration of render_wc(), so do_blocks() here
		// routes each inner block through email-specific renderers automatically.
		return (string) do_blocks( $ad_post->post_content );
	}

	/**
	 * Resolve the ad WP_Post from the adId attribute value.
	 *
	 * Mirrors the resolution order in the MJML renderer's
	 * `render_mjml_component()` case for `newspack-newsletters/ad`:
	 *
	 * 1. `placement:<term_id>` — look up the ad assigned to that placement.
	 * 2. Non-empty string (numeric post ID) — fetch directly via get_post().
	 * 3. Empty / absent — auto-select the first un-inserted active ad for
	 *    the newsletter currently being rendered.
	 *
	 * Returns null (never false) when no ad post can be resolved so the caller
	 * can use a strict instanceof check.
	 *
	 * @param string $ad_id The raw adId block attribute value, or empty string.
	 * @return \WP_Post|null Resolved ad post, or null when none is found.
	 */
	private function resolve_ad_post( string $ad_id ): ?\WP_Post {
		// 1. Placement-based lookup: `placement:<term_id>`.
		if ( str_starts_with( $ad_id, 'placement:' ) ) {
			$placement_id = (int) substr( $ad_id, strlen( 'placement:' ) );
			$newsletter   = Renderer_Controller::get_rendering_post();
			$nl_id        = $newsletter instanceof \WP_Post ? $newsletter->ID : null;
			$post         = Ads_Placements::get_ad_by_placement( $placement_id, $nl_id );
			return $post instanceof \WP_Post ? $post : null;
		}

		// 2. Direct post ID.
		if ( '' !== $ad_id ) {
			$post = get_post( (int) $ad_id );
			return $post instanceof \WP_Post ? $post : null;
		}

		// 3. Auto-select the first un-inserted active ad for this newsletter.
		$newsletter = Renderer_Controller::get_rendering_post();
		if ( ! $newsletter instanceof \WP_Post ) {
			return null;
		}
		$ads = Ads::get_newsletter_ads( $newsletter->ID );
		foreach ( $ads as $ad ) {
			if ( ! Ads::is_ad_inserted( $newsletter->ID, $ad->ID ) ) {
				return $ad;
			}
		}
		return null;
	}
}

// Self-register so the registry discovers this override via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'newspack-newsletters/ad', Ad::class );
