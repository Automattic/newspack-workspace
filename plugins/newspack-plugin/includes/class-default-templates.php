<?php
/**
 * Default templates for new posts and pages.
 *
 * Lets editors choose a default template that newly created posts and pages
 * receive. The available templates depend on the active theme: the classic
 * Newspack theme exposes a fixed list, while block themes expose their
 * theme.json customTemplates plus any site-created templates.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Default_Templates class.
 */
final class Default_Templates {

	/**
	 * Template options for the classic Newspack theme.
	 *
	 * Mirrors the list the classic theme declares in the Customizer.
	 *
	 * @return array[] List of [ 'label' => string, 'value' => string ].
	 */
	public static function get_classic_template_options() {
		return [
			[
				'label' => __( 'With sidebar', 'newspack-plugin' ),
				'value' => 'default',
			],
			[
				'label' => __( 'One Column', 'newspack-plugin' ),
				'value' => 'single-feature.php',
			],
			[
				'label' => __( 'One Column Wide', 'newspack-plugin' ),
				'value' => 'single-wide.php',
			],
		];
	}

	/**
	 * Get the available template options for the active theme.
	 *
	 * @return array {
	 *     @type array[] $post Options for posts.
	 *     @type array[] $page Options for pages.
	 * }
	 */
	public static function get_template_options() {
		$classic = self::get_classic_template_options();
		return [
			'post' => $classic,
			'page' => $classic,
		];
	}

	/**
	 * Reduce block templates to the ones assignable to a post type.
	 *
	 * Mirrors the block editor's "Template" panel: a template is assignable if
	 * it is a site-created (custom) template, or if its post_types include the
	 * post type. Base hierarchy templates (no post_types) are excluded.
	 *
	 * @param array  $templates Array of WP_Block_Template objects.
	 * @param string $post_type Post type slug.
	 * @return array[] List of [ 'label' => string, 'value' => string ].
	 */
	public static function filter_templates_for_post_type( $templates, $post_type ) {
		$options = [];
		foreach ( $templates as $template ) {
			$post_types = isset( $template->post_types ) && is_array( $template->post_types ) ? $template->post_types : [];
			$is_custom  = isset( $template->source ) && 'custom' === $template->source;
			if ( ! $is_custom && ! in_array( $post_type, $post_types, true ) ) {
				continue;
			}
			$options[] = [
				'label' => is_string( $template->title ) ? $template->title : $template->slug,
				'value' => $template->slug,
			];
		}
		return $options;
	}
}
