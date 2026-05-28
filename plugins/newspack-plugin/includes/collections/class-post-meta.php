<?php
/**
 * Post Meta handler.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the post meta fields and related operations.
 */
class Post_Meta {

	use Traits\Meta_Handler;

	/**
	 * Get meta definitions.
	 *
	 * @return array Array of meta definitions. See `Traits\Meta_Handler::get_meta_definitions()` for more details.
	 */
	public static function get_meta_definitions() {
		return [
			'is_cover_story' => [
				'type'              => 'boolean',
				'label'             => __( 'Cover Story', 'newspack-plugin' ),
				'description'       => __( 'Mark this post as a cover story in collections. If enabled, this post will appear at the top of the collection page.', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
				'default'           => false,
			],
			'post_order'     => [
				'type'              => 'integer',
				'label'             => __( 'Order', 'newspack-plugin' ),
				'description'       => __( 'Set the order of this post within collections.', 'newspack-plugin' ),
				'single'            => true,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
				'default'           => 0,
			],
		];
	}

	/**
	 * Initialize the meta fields handler.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'current_screen', [ __CLASS__, 'output_post_meta_data_for_admin_scripts' ] );
	}

	/**
	 * Register meta fields for the post post type.
	 */
	public static function register_meta() {
		self::register_meta_for_object( 'post', 'post' );
	}

	/**
	 * Output post meta data for admin scripts.
	 *
	 * @param WP_Screen $current_screen The current screen object.
	 */
	public static function output_post_meta_data_for_admin_scripts( $current_screen ) {
		if (
			'post' === $current_screen->base &&
			'post' === $current_screen->post_type
		) {
			Enqueuer::add_data(
				'postMeta',
				[
					'metaDefinitions' => self::get_frontend_meta_definitions(),
					'panelTitle'      => _x( 'Collection Settings', 'title for collection settings panel', 'newspack-plugin' ),
				]
			);
		}
	}
}
