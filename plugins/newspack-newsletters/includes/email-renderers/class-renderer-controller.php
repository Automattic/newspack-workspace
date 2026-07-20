<?php
/**
 * Dispatches newsletter rendering between the legacy MJML and WC engines.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Single dispatch point for newsletter HTML rendering.
 */
class Renderer_Controller {
	/**
	 * Post meta key recording which engine produced a sent newsletter's HTML.
	 */
	const RENDERER_META = 'newspack_newsletter_renderer';

	/**
	 * Legacy MJML engine. The default for any newsletter without a stamp.
	 */
	const ENGINE_MJML = 'mjml';

	/**
	 * WC (WooCommerce/block) engine.
	 */
	const ENGINE_WC = 'wc';

	/**
	 * The newsletter post currently being rendered by render_wc().
	 *
	 * The `woocommerce_email_editor_theme_json` hook provides no post argument and
	 * Renderer::render() does not set global $post, so this static carries the
	 * post explicitly for per-newsletter color injection.
	 *
	 * @var \WP_Post|null
	 */
	private static $rendering_post = null;

	/**
	 * Resolve which engine a post's stored HTML was produced by.
	 * Absence of a stamp means the newsletter predates this feature, so it is MJML.
	 *
	 * @param int $post_id Post ID.
	 * @return string One of self::ENGINE_MJML|self::ENGINE_WC.
	 */
	public static function get_post_renderer( int $post_id ): string {
		$stamp = get_post_meta( $post_id, self::RENDERER_META, true );
		return ( self::ENGINE_WC === $stamp ) ? self::ENGINE_WC : self::ENGINE_MJML;
	}

	/**
	 * Stamp the producing engine on a post (called at send time).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $engine  One of self::ENGINE_MJML|self::ENGINE_WC.
	 * @return void
	 */
	public static function stamp_renderer( int $post_id, string $engine ): void {
		update_post_meta( $post_id, self::RENDERER_META, self::ENGINE_WC === $engine ? self::ENGINE_WC : self::ENGINE_MJML );
	}

	/**
	 * The post currently being rendered by render_wc(), or null when idle.
	 *
	 * @return \WP_Post|null
	 */
	public static function get_rendering_post(): ?\WP_Post {
		return self::$rendering_post;
	}

	/**
	 * Render a newsletter to email-safe HTML via the WC email-editor engine.
	 *
	 * Sets $rendering_post before delegating so the theme.json filter can apply
	 * per-newsletter colors; clears it in a finally block. Returns '' on any
	 * failure (never fatals).
	 *
	 * @param \WP_Post|null $post Newsletter post to render.
	 * @return string Rendered email HTML, or '' on failure.
	 */
	public static function render_wc( ?\WP_Post $post ): string {
		if ( ! $post instanceof \WP_Post || ! class_exists( \Automattic\WooCommerce\EmailEditor\Email_Editor_Container::class ) ) {
			return '';
		}

		// Reset ad-insertion tracking before each render — Ads::$inserted_ads is
		// process-global, so a second render in the same request would silently drop all ads.
		if ( class_exists( '\Newspack_Newsletters\Ads' ) && method_exists( '\Newspack_Newsletters\Ads', 'reset_inserted_ads' ) ) {
			\Newspack_Newsletters\Ads::reset_inserted_ads( $post->ID );
		}

		// Reset the per-render theme.json memoization — it's keyed by post ID and
		// process-global, so a repeated render must rebuild from current meta.
		Editor_Bootstrap::reset_theme_json_cache();

		// Apply the newspack_newsletters_newsletter_content filter to inject ad blocks,
		// mirroring what the MJML renderer does. Feed the result to the package via a
		// render-scoped `the_content` filter rather than the object cache — the package
		// re-fetches the post by ID, and swapping the `posts` cache entry (a persistent
		// group) would expose ad content to concurrent requests reading the post mid-render.
		$filtered_content          = (string) apply_filters( 'newspack_newsletters_newsletter_content', $post->post_content, $post );
		$render_post               = clone $post;
		$render_post->post_content = $filtered_content;

		$inject_content = static function ( $content ) use ( $post, $filtered_content ) {
			return ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post && (int) $GLOBALS['post']->ID === (int) $post->ID )
				? $filtered_content
				: $content;
		};
		add_filter( 'the_content', $inject_content, 0 );

		// Wrap conditional-content blocks in their ESP merge-tag guards, mirroring the MJML
		// path's <mj-raw> conditional wrapping (class-newspack-newsletters-renderer.php). A
		// block carrying conditionalBefore/conditionalAfter is shown/hidden per recipient
		// segment by the ESP; without the wrap the guarded block ships unguarded to everyone.
		//
		// Runs at priority 20 — after the package's own render_block callback (priority 10),
		// which replaces a structural block's content with its composed email table — so the
		// guards wrap the package's final email HTML for the block rather than being discarded.
		$wrap_conditionals = static function ( $block_content, $block ) {
			$attrs = $block['attrs'] ?? [];
			if ( ! empty( $attrs['conditionalBefore'] ) && ! empty( $attrs['conditionalAfter'] ) ) {
				return $attrs['conditionalBefore'] . $block_content . $attrs['conditionalAfter'];
			}
			return $block_content;
		};
		add_filter( 'render_block', $wrap_conditionals, 20, 2 );

		// Save/restore so nested render_wc() calls leave the outer post intact.
		$previous             = self::$rendering_post;
		self::$rendering_post = $post;
		// Attribute ad click-tracking to the source newsletter: Click::process_link()
		// reads Newspack_Newsletters_Renderer::$newsletter_id when proxying ad links.
		$previous_newsletter_id                        = \Newspack_Newsletters_Renderer::$newsletter_id;
		\Newspack_Newsletters_Renderer::$newsletter_id = $post->ID;
		try {
			$container = \Automattic\WooCommerce\EmailEditor\Email_Editor_Container::container();
			$renderer  = $container->get( \Automattic\WooCommerce\EmailEditor\Engine\Renderer\Renderer::class );
			// Route the preheader through the legacy resolver for parity: it falls back
			// to a trimmed text version of the newsletter when preview_text meta is empty
			// and strips ESP merge tags, matching the MJML path.
			$preheader = (string) \Newspack_Newsletters_Renderer::get_preview_text( $post );
			$result    = $renderer->render(
				$render_post,
				(string) $post->post_title,
				$preheader,
				(string) get_bloginfo( 'language' ),
				'',
				Editor_Bootstrap::TEMPLATE_SLUG
			);
			if ( ! isset( $result['html'] ) ) {
				return '';
			}
			$html = Full_Bleed_Sections::transform( (string) $result['html'] );
			return self::finalize_html( $html, $post );
		} catch ( \Throwable $e ) {
			\Newspack_Newsletters_Logger::log( 'Email editor: WC render failed — ' . $e->getMessage() );
			return '';
		} finally {
			self::$rendering_post                          = $previous;
			\Newspack_Newsletters_Renderer::$newsletter_id = $previous_newsletter_id;
			remove_filter( 'the_content', $inject_content, 0 );
			remove_filter( 'render_block', $wrap_conditionals, 20 );
		}
	}

	/**
	 * Apply the final send-path post-processing the MJML path bakes into its saved HTML,
	 * so a flag-on newsletter ships with the same link tracking, custom CSS and open pixel.
	 *
	 * @param string   $html Rendered email HTML.
	 * @param \WP_Post $post Newsletter post.
	 * @return string Finalized email HTML.
	 */
	private static function finalize_html( string $html, \WP_Post $post ): string {
		// UTM + click-tracking link rewriting. Ad links are already processed with the
		// ad's own post context inside the ad block renderer (click tracking only proxies
		// ad links); the process_links() dedup skips them on this newsletter-context pass.
		if ( class_exists( '\Newspack_Newsletters_Renderer' ) ) {
			$html = (string) \Newspack_Newsletters_Renderer::process_links( $html, $post );
		}
		$html = self::inject_custom_css( $html, $post );
		$html = self::inject_tracking_pixel( $html, $post );
		return $html;
	}

	/**
	 * Inject the newsletter's custom_css meta into the email <head>, mirroring the MJML
	 * template which appends custom_css to its <style> block (and esc_html()s it).
	 *
	 * @param string   $html Email HTML.
	 * @param \WP_Post $post Newsletter post.
	 * @return string
	 */
	private static function inject_custom_css( string $html, \WP_Post $post ): string {
		$custom_css = (string) \get_post_meta( $post->ID, 'custom_css', true );
		if ( '' === trim( $custom_css ) ) {
			return $html;
		}
		$style = '<style type="text/css">' . \esc_html( $custom_css ) . '</style>';
		$pos   = stripos( $html, '</head>' );
		if ( false !== $pos ) {
			// substr_replace (not preg) so a `$`/`\` in the CSS isn't read as a backreference.
			return substr_replace( $html, $style . '</head>', $pos, strlen( '</head>' ) );
		}
		return $style . $html;
	}

	/**
	 * Inject the open-tracking pixel before </body> when tracking is enabled, mirroring
	 * the MJML path's newspack_newsletters_editor_mjml_body hook.
	 *
	 * @param string   $html Email HTML.
	 * @param \WP_Post $post Newsletter post.
	 * @return string
	 */
	private static function inject_tracking_pixel( string $html, \WP_Post $post ): string {
		if ( ! class_exists( '\Newspack_Newsletters\Tracking\Pixel' ) ) {
			return $html;
		}
		$pixel = \Newspack_Newsletters\Tracking\Pixel::get_pixel_markup( $post->ID );
		if ( '' === $pixel ) {
			return $html;
		}
		$pos = stripos( $html, '</body>' );
		if ( false !== $pos ) {
			return substr_replace( $html, $pixel . '</body>', $pos, strlen( '</body>' ) );
		}
		return $html . $pixel;
	}

	/**
	 * Resolve which engine should render new newsletters right now.
	 *
	 * @return string self::ENGINE_WC when the WC renderer flag is on, else self::ENGINE_MJML.
	 */
	public static function active_engine(): string {
		return Feature_Flag::is_enabled() ? self::ENGINE_WC : self::ENGINE_MJML;
	}
}
