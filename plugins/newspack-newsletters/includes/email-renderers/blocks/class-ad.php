<?php
/**
 * Newspack email-renderer for the newspack-newsletters/ad block.
 *
 * The block has no save output (`html: false` in block.json), so the WC engine
 * receives empty `$block_content` and the fallback renderer emits nothing. This
 * override resolves the ad post, renders it through the active WC email pipeline,
 * and marks it inserted — mirroring the MJML renderer's ad case
 * (class-newspack-newsletters-renderer.php:1702).
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
	 * Ad post IDs currently on the render stack, used to break ad→ad cycles.
	 *
	 * @var int[]
	 */
	private static $render_stack = array();

	/**
	 * Render the ad block.
	 *
	 * Resolves the ad post, renders its content through the WC email pipeline,
	 * and marks it inserted. Returns '' when no ad post is found.
	 *
	 * @param string            $block_content     Block content (always empty — no save output).
	 * @param array             $parsed_block      Parsed block data.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string Rendered ad HTML, or ''.
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$attrs   = $parsed_block['attrs'] ?? array();
		$ad_id   = isset( $attrs['adId'] ) ? (string) $attrs['adId'] : '';
		$ad_post = $this->resolve_ad_post( $ad_id );

		if ( ! $ad_post instanceof \WP_Post ) {
			return '';
		}

		// Guard against ad→ad recursion: a self- or mutually-referencing ad
		// re-enters this renderer via do_blocks() and would recurse indefinitely.
		if ( in_array( $ad_post->ID, self::$render_stack, true ) ) {
			return '';
		}

		// do_blocks() routes inner blocks through the WC email pipeline because
		// Content_Renderer::initialize() keeps its render_block hook active during render_wc().
		self::$render_stack[] = $ad_post->ID;
		try {
			$html = (string) do_blocks( $ad_post->post_content );
			// Route ad links through process_links() with the ad's own post context, so ad
			// URLs get UTM params and are proxied through the click endpoint. Click tracking
			// only proxies ad links, so this must run with the ad post (not the newsletter);
			// mirrors the MJML ad path (post_to_mjml_components → process_links( …, $ad_post )).
			// The newsletter-context process_links() pass in render_wc() then skips these via
			// its dedup, so they are not double-processed.
			if ( class_exists( '\Newspack_Newsletters_Renderer' ) ) {
				$html = (string) \Newspack_Newsletters_Renderer::process_links( $html, $ad_post );
			}
		} finally {
			array_pop( self::$render_stack );
		}

		// Mark inserted after rendering (mirrors MJML) so a failed render doesn't
		// persist a phantom insertion while auto-selection still dedupes correctly.
		$newsletter = Renderer_Controller::get_rendering_post();
		if ( $newsletter instanceof \WP_Post ) {
			Ads::mark_ad_inserted( $newsletter->ID, $ad_post->ID );
		}

		return $html;
	}

	/**
	 * Resolve the ad WP_Post from the adId attribute.
	 *
	 * Resolution order (mirrors the MJML renderer):
	 * 1. `placement:<term_id>` — ad assigned to that placement.
	 * 2. Non-empty string — direct post ID, verified active and ads-CPT-only
	 *    to prevent stale/wrong IDs leaking arbitrary post content.
	 * 3. Empty / absent — auto-select the first un-inserted active ad.
	 *
	 * Returns null (not false) so callers can use instanceof.
	 *
	 * @param string $ad_id Raw adId attribute value, or empty string.
	 * @return \WP_Post|null Resolved ad post, or null.
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

		// 2. Direct post ID — verify active and ads-CPT-only so a stale ID doesn't
		// leak arbitrary post content into the email.
		if ( '' !== $ad_id ) {
			$post = get_post( (int) $ad_id );
			if ( ! $post instanceof \WP_Post || Ads::CPT !== $post->post_type ) {
				return null;
			}
			$newsletter = Renderer_Controller::get_rendering_post();
			$nl_id      = $newsletter instanceof \WP_Post ? $newsletter->ID : null;
			return Ads::is_ad_active( $post->ID, $nl_id ) ? $post : null;
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

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'newspack-newsletters/ad', Ad::class );
