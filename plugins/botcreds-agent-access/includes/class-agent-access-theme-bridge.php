<?php
/**
 * Agent Access Theme Bridge — REST API for agent-driven site configuration.
 *
 * Exposes theme_mods and global styles helpers so agents can configure
 * the look and feel of a WordPress site via the REST API.
 *
 * @package Agent Access
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Theme_Bridge {

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes under agent-access/v1/theme-bridge/*.
	 */
	public function register_routes() {

		// GET /wp-json/agent-access/v1/theme-bridge/theme-mods
		register_rest_route( 'agent-access/v1', '/theme-bridge/theme-mods', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_theme_mods' ),
			'permission_callback' => array( $this, 'can_edit_theme' ),
		) );

		// POST /wp-json/agent-access/v1/theme-bridge/theme-mods
		register_rest_route( 'agent-access/v1', '/theme-bridge/theme-mods', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'set_theme_mods' ),
			'permission_callback' => array( $this, 'can_edit_theme' ),
		) );

		// DELETE /wp-json/agent-access/v1/theme-bridge/theme-mods/<key>
		register_rest_route( 'agent-access/v1', '/theme-bridge/theme-mods/(?P<key>[a-z0-9_-]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'remove_theme_mod' ),
			'permission_callback' => array( $this, 'can_edit_theme' ),
		) );

		// GET /wp-json/agent-access/v1/theme-bridge/global-styles-id
		register_rest_route( 'agent-access/v1', '/theme-bridge/global-styles-id', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_global_styles_id' ),
			'permission_callback' => array( $this, 'can_edit_theme' ),
		) );
	}

	/**
	 * Permission check: can edit theme options.
	 */
	public function can_edit_theme() {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * GET theme mods.
	 */
	public function get_theme_mods() {
		return rest_ensure_response( get_theme_mods() );
	}

	/**
	 * POST theme mods — set one or more key/value pairs.
	 */
	public function set_theme_mods( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_Error(
				'invalid_params',
				'Send a JSON object of theme_mod key/value pairs.',
				array( 'status' => 400 )
			);
		}

		foreach ( $params as $key => $value ) {
			set_theme_mod( sanitize_key( $key ), $value );
		}

		return rest_ensure_response( get_theme_mods() );
	}

	/**
	 * DELETE a single theme mod by key.
	 */
	public function remove_theme_mod( WP_REST_Request $request ) {
		$key = sanitize_key( $request['key'] );
		remove_theme_mod( $key );

		return rest_ensure_response( array( 'removed' => $key ) );
	}

	/**
	 * GET the global styles post ID for the active theme.
	 * Creates one if it doesn't exist.
	 */
	public function get_global_styles_id() {
		$stylesheet = get_stylesheet();

		$posts = get_posts( array(
			'post_type'      => 'wp_global_styles',
			'posts_per_page' => 1,
			'post_status'    => array( 'publish', 'draft' ),
			'tax_query'      => array(
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => $stylesheet,
				),
			),
		) );

		if ( ! empty( $posts ) ) {
			return rest_ensure_response( array(
				'id'     => $posts[0]->ID,
				'status' => $posts[0]->post_status,
			) );
		}

		$post_id = wp_insert_post( array(
			'post_type'    => 'wp_global_styles',
			'post_title'   => 'Custom Styles',
			'post_name'    => 'wp-global-styles-' . urlencode( $stylesheet ),
			'post_status'  => 'publish',
			'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true}',
		) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_object_terms( $post_id, $stylesheet, 'wp_theme' );

		return rest_ensure_response( array(
			'id'      => $post_id,
			'created' => true,
		) );
	}
}
