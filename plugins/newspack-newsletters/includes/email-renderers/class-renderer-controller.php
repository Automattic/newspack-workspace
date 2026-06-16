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
	 * The newsletter currently being rendered by render_wc().
	 *
	 * Made available to the `woocommerce_email_editor_theme_json` filter so it can
	 * apply per-newsletter colors. The package's ThemeController applies that
	 * filter with no post argument and Renderer::render() does not set up the
	 * global $post, so a filter resolving the post via get_post() would get null
	 * during a REST round-trip. This static carries the post explicitly instead.
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
	public static function get_post_renderer( $post_id ) {
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
	public static function stamp_renderer( $post_id, $engine ) {
		update_post_meta( $post_id, self::RENDERER_META, self::ENGINE_WC === $engine ? self::ENGINE_WC : self::ENGINE_MJML );
	}

	/**
	 * The post currently being rendered by render_wc(), or null when idle.
	 *
	 * The `woocommerce_email_editor_theme_json` filter reads this to apply
	 * per-newsletter colors without depending on the global $post.
	 *
	 * @return \WP_Post|null The render post, or null when not rendering.
	 */
	public static function get_rendering_post(): ?\WP_Post {
		return self::$rendering_post;
	}

	/**
	 * Render a newsletter to email-safe HTML via the WC email-editor engine.
	 *
	 * Sets the render post on a static accessor before delegating to the package
	 * renderer so the theme.json filter can apply per-newsletter colors, then
	 * clears it in a finally block so it is reset even if rendering throws.
	 *
	 * Returns an empty string (never fatals) when the post is invalid, the WC
	 * email-editor package is unavailable, or the renderer throws.
	 *
	 * @param \WP_Post|null $post Newsletter post to render.
	 * @return string Rendered email HTML, or an empty string when unavailable.
	 */
	public static function render_wc( ?\WP_Post $post ): string {
		if ( ! $post instanceof \WP_Post || ! class_exists( \Automattic\WooCommerce\EmailEditor\Email_Editor_Container::class ) ) {
			return '';
		}

		// Save/restore rather than clear so a nested render_wc() (post B mid-render
		// of post A) leaves the outer render's post intact when the inner one returns.
		$previous             = self::$rendering_post;
		self::$rendering_post = $post;
		try {
			$container = \Automattic\WooCommerce\EmailEditor\Email_Editor_Container::container();
			$renderer  = $container->get( \Automattic\WooCommerce\EmailEditor\Engine\Renderer\Renderer::class );
			$preheader = (string) get_post_meta( $post->ID, 'preview_text', true );
			$result    = $renderer->render(
				$post,
				(string) $post->post_title,
				$preheader,
				(string) get_bloginfo( 'language' ),
				'',
				Editor_Bootstrap::TEMPLATE_SLUG
			);
			return isset( $result['html'] ) ? (string) $result['html'] : '';
		} catch ( \Throwable $e ) {
			\Newspack_Newsletters_Logger::log( 'Email editor: WC render failed — ' . $e->getMessage() );
			return '';
		} finally {
			self::$rendering_post = $previous;
		}
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
