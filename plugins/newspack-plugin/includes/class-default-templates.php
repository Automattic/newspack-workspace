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
	 * Passing the post type to get_block_templates() lets WordPress core apply
	 * the same filtering the block editor's "Template" panel uses: only custom
	 * templates (is_custom) whose post_types match are returned, while hierarchy
	 * templates (single, front-page, page, index, ...) are excluded — even when
	 * they have been edited in the Site Editor (which gives them a "custom"
	 * source but leaves is_custom false). This covers theme.json customTemplates
	 * and site-created (DB) templates alike.
	 *
	 * @param string $post_type Post type slug.
	 * @return array[] List of [ 'label' => string, 'value' => string ], "Default" first.
	 */
	public static function get_block_template_options( $post_type ) {
		$options = [
			[
				'label' => __( 'Default', 'newspack-plugin' ),
				'value' => 'default',
			],
		];
		if ( ! function_exists( 'get_block_templates' ) ) {
			return $options;
		}
		$templates = get_block_templates( [ 'post_type' => $post_type ], 'wp_template' );
		foreach ( $templates as $template ) {
			$options[] = [
				'label' => empty( $template->title ) ? $template->slug : $template->title,
				'value' => $template->slug,
			];
		}
		return $options;
	}

	/**
	 * Whether a stored template value is an assignable block template for a post type.
	 *
	 * Block-theme only: this resolves the slug against the active block theme's
	 * templates and will report false for classic-theme slugs. Callers in a
	 * classic-theme context should guard with wp_is_block_theme() or use
	 * get_template_options() instead.
	 *
	 * Resolves a single slug rather than building the full option list. Post-type
	 * scoping is enforced when the value is stored (see sanitize_stored_template),
	 * so a slug existence check is sufficient at insert time.
	 *
	 * @param string $template  Template slug (or 'default').
	 * @param string $post_type Post type slug. Unused; scoping is enforced on write.
	 * @return bool
	 */
	public static function validate_template( $template, $post_type ) {
		if ( 'default' === $template ) {
			return true;
		}
		return null !== get_block_template( get_stylesheet() . '//' . $template, 'wp_template' );
	}

	/**
	 * Apply the configured default template to a newly created post or page.
	 *
	 * Only runs for block themes; the classic Newspack theme applies its own
	 * defaults via its own wp_insert_post handler.
	 *
	 * Applies only to the editor's initial auto-draft creation. Programmatic
	 * inserts, REST-created posts and WXR imports use other statuses (draft,
	 * publish) and are intentionally left untouched, so a bulk import can't
	 * inherit the site default for posts that omit a template.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 * @param bool     $update  Whether this is an update to an existing post.
	 */
	public static function maybe_set_default_template( $post_id, $post, $update ) {
		if ( $update || 'auto-draft' !== $post->post_status ) {
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
	 * Coerce a stored default-template value to one that is currently available.
	 *
	 * Runs on write (via pre_set_theme_mod_*) so the persisted value is always a
	 * valid option for the active theme, falling back to 'default' otherwise.
	 *
	 * @param mixed  $value     Incoming theme-mod value.
	 * @param string $post_type Post type the value applies to ('post' or 'page').
	 * @return string A valid template slug, or 'default'.
	 */
	public static function sanitize_stored_template( $value, $post_type ) {
		$options = self::get_template_options();
		$list    = isset( $options[ $post_type ] ) ? $options[ $post_type ] : [];
		if ( in_array( $value, wp_list_pluck( $list, 'value' ), true ) ) {
			return $value;
		}
		return 'default';
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'wp_insert_post', [ __CLASS__, 'maybe_set_default_template' ], 10, 3 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_filter(
			'pre_set_theme_mod_post_template_default',
			function ( $value ) {
				return self::sanitize_stored_template( $value, 'post' );
			}
		);
		add_filter(
			'pre_set_theme_mod_page_template_default',
			function ( $value ) {
				return self::sanitize_stored_template( $value, 'page' );
			}
		);
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
	 * @return true|\WP_Error True if the request has access, WP_Error otherwise.
	 */
	public static function api_permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * GET callback: available template options for the active theme.
	 *
	 * @return \WP_REST_Response
	 */
	public static function api_get_template_options() {
		return rest_ensure_response( self::get_template_options() );
	}
}

Default_Templates::init();
