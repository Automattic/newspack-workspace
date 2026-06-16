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
