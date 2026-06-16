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
	 * The package composes the template id as "{namespace}//{slug}".
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
		if ( ! class_exists( Email_Editor_Container::class ) ) {
			return;
		}

		Email_Editor_Container::container()->get( Bootstrap::class )->init();

		add_filter( 'woocommerce_email_editor_post_types', [ __CLASS__, 'add_post_type' ] );
		add_filter( 'woocommerce_email_editor_register_templates', [ __CLASS__, 'register_template' ] );
	}

	/**
	 * Opt the newsletters CPT into the email editor.
	 *
	 * The package expects each entry to be an array with `name` and `args`
	 * keys. We pass empty `args` so the canonical CPT definition registered by
	 * Newspack_Newsletters remains authoritative; this entry only opts the CPT
	 * into the editor's post-type-aware features (templates, REST fields).
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
		$content = (string) file_get_contents( __DIR__ . '/templates/newspack-newsletter.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin template file, not a remote resource.

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
