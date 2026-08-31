<?php
/**
 * Boots the WooCommerce Email Editor package for the newsletters CPT.
 *
 * Initializes the email-editor package container, opts the newsletters CPT into
 * the editor, and registers a wrapping block template.
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
	 * Plugin namespace prefixing the template id ("newspack//newspack-newsletter").
	 */
	const TEMPLATE_NAMESPACE = 'newspack';

	/**
	 * Slug of the wrapping block template.
	 */
	const TEMPLATE_SLUG = 'newspack-newsletter';

	/**
	 * Per-render memoization of the built WP_Theme_JSON, keyed by post ID.
	 *
	 * The merge_theme_json() filter fires several times per render, so the build
	 * is cached. The cache is process-global, so Renderer_Controller::render_wc() resets it
	 * before each render (see reset_theme_json_cache) — otherwise a second render
	 * of a post whose colors changed would reuse the stale theme, and in the test
	 * suite recycled post IDs would collide across tests.
	 *
	 * @var array<int,\WP_Theme_JSON>
	 */
	private static $theme_json_cache = [];

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

		// The package re-registers opted-in post types on init:10, overwriting the
		// canonical CPT args with email defaults. Re-assert at priority 11 to keep
		// Newspack's registration authoritative — but only when the flag is on, since
		// that is the only time the package opts the CPT in (see add_post_type) and so
		// the only time there is anything to counter. With the flag off the package
		// registers nothing at init:10, and re-registering here would be pure duplicate
		// work every request (and would silently undo a remove_post_type_support() made
		// between init:10 and init:11).
		add_action( 'init', [ __CLASS__, 'reassert_cpt_when_enabled' ], 11 );

		// Inject per-newsletter theme colors at render time. See merge_theme_json().
		add_filter( 'woocommerce_email_editor_theme_json', [ __CLASS__, 'merge_theme_json' ] );

		// Override the package's per-block renderers (e.g. the columns
		// percentage-width fix) via block_type_metadata_settings at priority 11.
		Block_Renderer_Registry::init();

		// Inject Newspack fallback defaults at the theme.json default origin.
		Email_Defaults::init();
	}

	/**
	 * Re-assert Newspack's canonical CPT registration after the package's init:10
	 * pass — only when the WC renderer is on.
	 *
	 * The package overwrites the CPT args with email defaults only for opted-in post
	 * types (see add_post_type), so with the flag off there is nothing to counter and
	 * this is skipped, avoiding a redundant re-registration on every request. Reads the
	 * flag lazily at init:11, matching add_post_type's read at init:10.
	 *
	 * @return void
	 */
	public static function reassert_cpt_when_enabled() {
		if ( Feature_Flag::is_enabled() ) {
			\Newspack_Newsletters::register_cpt();
		}
	}

	/**
	 * Merge per-newsletter theme colors into the editor theme at render time.
	 *
	 * The filter provides no post argument, so the render post is read from
	 * Renderer_Controller (set during render_wc), falling back to global $post.
	 * The WP_Theme_JSON result is memoized per post — this filter fires several
	 * times per render, and merge() never mutates its argument.
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

		if ( ! isset( self::$theme_json_cache[ $post->ID ] ) ) {
			self::$theme_json_cache[ $post->ID ] = new \WP_Theme_JSON( Theme_Json_Builder::build( $post ), 'default' );
		}

		$theme->merge( self::$theme_json_cache[ $post->ID ] );
		return $theme;
	}

	/**
	 * Clear the per-render theme.json memoization.
	 *
	 * Called at the start of each render so a repeated render of the same post ID
	 * rebuilds from current meta instead of returning a stale WP_Theme_JSON.
	 *
	 * @return void
	 */
	public static function reset_theme_json_cache() {
		self::$theme_json_cache = [];
	}

	/**
	 * Opt the newsletters CPT into the email editor — only when the WC renderer is on.
	 *
	 * The package keys its front-end `single_template` takeover
	 * (Email_Editor::load_email_preview_template) off this opt-in list, so gating it keeps a
	 * flag-off site's public newsletters in the theme's standard single template (legacy MJML
	 * behavior) instead of the package's bare email-preview template. See the README
	 * (`Editor_Bootstrap`) for the full rationale, including what the flag-on path still leaves
	 * open (tracked in NPPD-2150).
	 *
	 * The flag is read lazily here, so the option and constant (always set in time) and any
	 * `newspack_newsletters_use_woo_renderer` filter registered before `init:10` are honored —
	 * a filter added later half-engages, since register_email_post_types() reads this list once
	 * at init:10.
	 *
	 * The package expects `name` + `args` entries; empty args is fine.
	 *
	 * @param array $post_types List of email editor post types.
	 * @return array Modified list.
	 */
	public static function add_post_type( $post_types ) {
		if ( ! Feature_Flag::is_enabled() ) {
			return $post_types;
		}
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
