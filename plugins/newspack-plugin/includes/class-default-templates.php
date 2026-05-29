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
	 * Returns an array with 'post' and 'page' keys. For block themes, each key
	 * contains the block template options for that post type (see
	 * get_block_template_options()). For classic themes, both keys contain the
	 * fixed legacy list from get_classic_template_options().
	 *
	 * @return array {
	 *     @type array[] $post Options for posts.
	 *     @type array[] $page Options for pages.
	 * }
	 */
	public static function get_template_options() {
		if ( wp_is_block_theme() ) {
			return [
				'post' => self::get_block_template_options( 'post' ),
				'page' => self::get_block_template_options( 'page' ),
			];
		}
		$classic = self::get_classic_template_options();
		return [
			'post' => $classic,
			'page' => $classic,
		];
	}

	/**
	 * Get assignable block template options for a post type.
	 *
	 * Includes theme.json customTemplates and any site-created (DB) templates.
	 *
	 * @param string $post_type Post type slug.
	 * @return array[] List of [ 'label' => string, 'value' => string ], "Default" first.
	 */
	public static function get_block_template_options( $post_type ) {
		$default = [
			[
				'label' => __( 'Default', 'newspack-plugin' ),
				'value' => 'default',
			],
		];
		if ( ! function_exists( 'get_block_templates' ) ) {
			return $default;
		}
		$templates = get_block_templates( [], 'wp_template' );
		return array_merge( $default, self::filter_templates_for_post_type( $templates, $post_type ) );
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

	/**
	 * Whether a stored template value is currently available for a post type.
	 *
	 * @param string $template  Template slug (or 'default').
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function validate_template( $template, $post_type ) {
		$options = self::get_block_template_options( $post_type );
		foreach ( $options as $option ) {
			if ( $option['value'] === $template ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Apply the configured default template to a newly created post or page.
	 *
	 * Only runs for block themes; the classic Newspack theme applies its own
	 * defaults via its own wp_insert_post handler.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 * @param bool     $update  Whether this is an update to an existing post.
	 */
	public static function maybe_set_default_template( $post_id, $post, $update ) {
		if ( $update ) {
			return;
		}
		if ( ! wp_is_block_theme() ) {
			return;
		}
		if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return;
		}
		$mod_name = 'post' === $post->post_type ? 'post_template_default' : 'page_template_default';
		$template = get_theme_mod( $mod_name, 'default' );
		if ( empty( $template ) || 'default' === $template ) {
			return;
		}
		if ( ! self::validate_template( $template, $post->post_type ) ) {
			return;
		}
		update_post_meta( $post_id, '_wp_page_template', $template );
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'wp_insert_post', [ __CLASS__, 'maybe_set_default_template' ], 10, 3 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/newspack-settings/default-templates',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_template_options' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
			]
		);
	}

	/**
	 * Permission check for the endpoint.
	 *
	 * @return bool
	 */
	public static function api_permissions_check() {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * GET callback: available template options for the active theme.
	 *
	 * @return WP_REST_Response
	 */
	public static function api_get_template_options() {
		return rest_ensure_response( self::get_template_options() );
	}
}

Default_Templates::init();
