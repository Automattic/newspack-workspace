<?php
/**
 * Content Loop block patterns.
 *
 * @package Newspack_Content_Blocks
 */

namespace Newspack_Content_Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Content Loop (Homepage Posts) block patterns and their category.
 */
class Patterns {

	/**
	 * Number of Content Loop patterns shipped in src/block-patterns.
	 *
	 * @var int
	 */
	const HOMEPAGE_POSTS_COUNT = 31;

	/**
	 * Add hooks and filters.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'register_block_patterns_and_categories' ] );
	}

	/**
	 * Registers block patterns and categories.
	 */
	public static function register_block_patterns_and_categories() {
		if ( function_exists( 'register_block_pattern' ) && self::should_register_patterns() ) {
			for ( $i = 1; $i <= self::HOMEPAGE_POSTS_COUNT; $i++ ) {
				$block_pattern = 'homepage-posts-' . $i;
				register_block_pattern(
					'newspack/' . $block_pattern,
					require NEWSPACK_CONTENT_BLOCKS_PLUGIN_DIR . 'src/block-patterns/' . $block_pattern . '.php'
				);
			}
		}

		if ( function_exists( 'register_block_pattern_category' ) ) {
			register_block_pattern_category(
				'newspack-homepage-posts',
				[ 'label' => __( 'Newspack Content Loop', 'newspack-content-blocks' ) ]
			);
		}
	}

	/**
	 * Whether the Content Loop patterns apply to the current request. They are for
	 * pages only: when the admin request names a post type (directly or through the
	 * post being edited), any other post type skips them.
	 *
	 * @return bool
	 */
	private static function should_register_patterns() {
		$post_type = isset( $_REQUEST['post_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['post_type'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id   = isset( $_REQUEST['post'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['post'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $post_type ) && ! empty( $post_id ) ) {
			$post_type = get_post_type( $post_id );
		}

		return empty( $post_type ) || 'page' === $post_type;
	}
}
