<?php
/**
 * Boots the WooCommerce Email Editor package for the newsletters CPT.
 *
 * Initializes the email-editor package container, opts the newsletters CPT
 * into the editor, and registers a wrapping block template that locks the
 * newsletter content into a constrained group. No renderer wiring yet — this
 * only bootstraps the package and registers the template.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

use Automattic\WooCommerce\EmailEditor\Bootstrap;
use Automattic\WooCommerce\EmailEditor\Email_Editor_Container;
use Automattic\WooCommerce\EmailEditor\Engine\Templates\Template;
use Automattic\WooCommerce\EmailEditor\Engine\Templates\Templates_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the WC email-editor package and registers the wrapping template.
 */
class Editor_Bootstrap {
	/**
	 * Plugin namespace used as the prefix of the registered template id.
	 * The package composes the template id as "{namespace}//{slug}", so the
	 * full id is "newspack//newspack-newsletter" — unique to this plugin's
	 * wrapping template (the slug below is newsletters-specific).
	 */
	const TEMPLATE_NAMESPACE = 'newspack';

	/**
	 * Slug of the wrapping block template.
	 */
	const TEMPLATE_SLUG = 'newspack-newsletter';

	/**
	 * Boot the package and register the editor hooks.
	 *
	 * @return void
	 */
	public static function init() {
		static $did_init = false;
		if ( $did_init ) {
			return;
		}
		if ( ! class_exists( Email_Editor_Container::class ) || ! class_exists( Bootstrap::class ) ) {
			return;
		}
		$did_init = true;

		Email_Editor_Container::container()->get( Bootstrap::class )->init();

		add_filter( 'woocommerce_email_editor_post_types', [ __CLASS__, 'add_post_type' ] );
		add_filter( 'woocommerce_email_editor_register_templates', [ __CLASS__, 'register_template' ] );

		// The package re-registers every opted-in post type on `init` (priority 10)
		// via register_post_type(). Its callback runs after
		// Newspack_Newsletters::register_cpt(), so without this it would overwrite
		// the canonical CPT's scalar args (public, labels, rewrite, menu_icon,
		// rendering mode) with the package's email defaults. Re-assert the canonical
		// definition at a later priority so Newspack's registration stays authoritative.
		add_action( 'init', [ \Newspack_Newsletters::class, 'register_cpt' ], 11 );

		// Inject per-newsletter theme colors at render time. See merge_theme_json().
		add_filter( 'woocommerce_email_editor_theme_json', [ __CLASS__, 'merge_theme_json' ] );

		// Override the package's per-block renderers with Newspack's (e.g. the
		// columns percentage-width fix). Hooks block_type_metadata_settings at a
		// later priority than the package, which runs before core blocks register
		// on init:10, so the override lands in time.
		Block_Renderer_Registry::init();
	}

	/**
	 * Merge per-newsletter theme colors into the editor theme at render time.
	 *
	 * ThemeController applies the `woocommerce_email_editor_theme_json` filter
	 * with no post argument, so resolve the render post from Renderer_Controller
	 * first (set during render_wc), falling back to the global $post only when not
	 * actively rendering.
	 *
	 * The built WP_Theme_JSON is memoized per post: get_theme() — and therefore
	 * this filter — fires several times during one render, and WP_Theme_JSON::merge()
	 * reads but never mutates its argument, so the cached instance is safe to reuse.
	 * The cache is request-scoped (static) and a newsletter's color meta is stable
	 * within a single render.
	 *
	 * @param \WP_Theme_JSON $theme The editor theme being assembled.
	 * @return \WP_Theme_JSON The theme with per-newsletter colors merged in.
	 */
	public static function merge_theme_json( $theme ) {
		$post = Renderer_Controller::get_rendering_post();
		if ( ! $post ) {
			$post = get_post();
		}
		if ( ! $post || \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT !== $post->post_type ) {
			return $theme;
		}

		static $cache = [];
		if ( ! isset( $cache[ $post->ID ] ) ) {
			$cache[ $post->ID ] = new \WP_Theme_JSON( Theme_Json_Builder::build( $post ), 'default' );
		}

		$theme->merge( $cache[ $post->ID ] );
		return $theme;
	}

	/**
	 * Opt the newsletters CPT into the email editor.
	 *
	 * The package expects each entry to be an array with `name` and `args`
	 * keys. We pass empty `args` and opt the CPT in only for the editor's
	 * post-type-aware features (templates, REST fields). The package re-registers
	 * opted-in post types, so `init()` re-asserts the canonical
	 * Newspack_Newsletters CPT definition at a later priority to keep it
	 * authoritative (see init()).
	 *
	 * @param array $post_types List of email editor post types.
	 * @return array Modified list of post types.
	 */
	public static function add_post_type( $post_types ) {
		$post_types[] = [
			'name' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			'args' => [],
		];
		return $post_types;
	}

	/**
	 * Register the wrapping block template with the package registry.
	 *
	 * @param Templates_Registry $registry The templates registry instance.
	 * @return Templates_Registry The templates registry instance.
	 */
	public static function register_template( $registry ) {
		$content = file_get_contents( __DIR__ . '/templates/newspack-newsletter.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin template file, not a remote resource.
		if ( false === $content ) {
			\Newspack_Newsletters_Logger::log( 'Email editor: could not read the wrapping template file; skipping template registration.' );
			return $registry;
		}

		$template = new Template(
			self::TEMPLATE_NAMESPACE,
			self::TEMPLATE_SLUG,
			__( 'Newsletter', 'newspack-newsletters' ),
			__( 'Newspack newsletter email template.', 'newspack-newsletters' ),
			$content,
			[ \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ]
		);

		$registry->register( $template );

		return $registry;
	}
}
