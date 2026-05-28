<?php
/**
 * Newspack Accessibility Statement Page functionality.
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * Accessibility Statement Page class.
 */
class Accessibility_Statement_Page {

	/**
	 * Add hooks.
	 */
	public static function init() {
		// Register REST routes.
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		// Add post status to accessibility statement page.
		add_filter( 'display_post_states', [ __CLASS__, 'post_status' ], 10, 2 );
	}

	/**
	 * Create an accessibility statement page.
	 *
	 * @param bool $force_create Whether to force creation of a new page even if theme_mod exists.
	 * @return array|null|WP_Error The created page data, null if it has been trashed or deleted, or error.
	 */
	public static function create_page( $force_create = false ) {
		// Check if page ID is stored in theme_mods.
		$page_id = get_theme_mod( 'accessibility_statement_page_id' );
		if ( $page_id && ! $force_create ) {
			$page = get_post( $page_id );
			// Check if page exists and is either published or draft.
			if ( $page && 'page' === $page->post_type && in_array( get_post_status( $page->ID ), [ 'publish', 'draft' ] ) ) {
				$page_data = [
					'editUrl' => get_edit_post_link( $page->ID, 'raw' ),
					'status'  => get_post_status( $page->ID ),
					'pageUrl' => get_permalink( $page->ID ),
				];
				return $page_data;
			}
			// If page doesn't exist or is in trash, return null but keep the ID stored.
			return null;
		}

		// Get the Accessibility Statement boilerplate content.
		ob_start();
		require __DIR__ . '/class-accessibility-statement-boilerplate.php';
		$page_content = ob_get_clean();

		// If no page ID is stored, create a new page.
		$page_id = wp_insert_post(
			[
				'post_title'   => __( 'Accessibility Statement', 'newspack-plugin' ),
				'post_name'    => 'accessibility-statement',
				'post_status'  => 'draft',
				'post_type'    => 'page',
				'post_content' => $page_content,
			]
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		// Save the page ID.
		set_theme_mod( 'accessibility_statement_page_id', $page_id );

		$page_data = [
			'editUrl' => get_edit_post_link( $page_id, 'raw' ),
			'status'  => 'draft',
			'pageUrl' => get_permalink( $page_id ),
		];
		return $page_data;
	}

	/**
	 * Get accessibility statement page data.
	 * The function to actually output the link lives in the Classic Theme.
	 *
	 * TODO: Create a block for the Block Theme that outputs the format we need there.
	 *
	 * @return array|false The page data or false if not set.
	 */
	public static function get_page() {
		$page_id = get_theme_mod( 'accessibility_statement_page_id' );
		if ( ! $page_id ) {
			// If no page ID exists, create a new page.
			$result = self::create_page();
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			return false;
		}

		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type || 'trash' === get_post_status( $page->ID ) ) {
			// If page doesn't exist, isn't a page, or is in trash, return false but keep theme_mod.
			return false;
		}

		$page_data = [
			'editUrl' => get_edit_post_link( $page->ID, 'raw' ),
			'pageUrl' => get_permalink( $page->ID ),
			'status'  => get_post_status( $page->ID ),
			'title'   => get_the_title( $page->ID ),
		];
		return $page_data;
	}

	/**
	 * Register REST API endpoints.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'newspack/v1',
			'/wizard/newspack-settings/accessibility-statement',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'api_create_page' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			'newspack/v1',
			'/wizard/newspack-settings/accessibility-statement',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'api_get_page' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
			]
		);
	}

	/**
	 * API callback for creating accessibility statement page.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public static function api_create_page( $request ) {
		$force_create = $request->get_param( 'force_create' ) === 'true';
		$result = self::create_page( $force_create );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * API callback for getting accessibility statement page.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public static function api_get_page() {
		$page_data = self::get_page();
		return rest_ensure_response( $page_data );
	}

	/**
	 * Check capabilities for using API.
	 *
	 * @return bool|WP_Error
	 */
	public static function api_permissions_check() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Add post status to accessibility statement page.
	 *
	 * @param array   $post_states The post states.
	 * @param WP_Post $post The post object.
	 * @return array The post states.
	 */
	public static function post_status( $post_states, $post ) {
		$page_id = get_theme_mod( 'accessibility_statement_page_id' );
		if ( $page_id === $post->ID ) {
			$post_states['accessibility_statement'] = __( 'Accessibility Statement', 'newspack-plugin' );
		}
		return $post_states;
	}
}
Accessibility_Statement_Page::init();
