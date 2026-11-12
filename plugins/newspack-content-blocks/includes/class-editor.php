<?php
/**
 * Editor assets.
 *
 * @package Newspack_Content_Blocks
 */

namespace Newspack_Content_Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the block editor script and styles, and localizes the data they read.
 */
final class Editor {

	/**
	 * Add hooks and filters.
	 */
	public static function init() {
		add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );
	}

	/**
	 * Enqueue block scripts and styles for the editor.
	 *
	 * Hooked to `enqueue_block_assets`, as in newspack-blocks: that action also fires on
	 * the frontend, where there is nothing to do.
	 */
	public static function enqueue_block_editor_assets() {
		if ( ! is_admin() ) {
			return;
		}

		$script_data = Blocks::script_enqueue_helper( 'dist/editor.js' );
		if ( $script_data ) {
			wp_enqueue_script(
				'newspack-content-blocks-editor',
				$script_data['script_path'],
				$script_data['dependencies'],
				$script_data['version'],
				true
			);

			wp_localize_script(
				'newspack-content-blocks-editor',
				'newspack_content_blocks_data',
				[
					'posts_rest_url'          => rest_url( 'newspack-blocks/v1/newspack-blocks-posts' ),
					'posts_batch_rest_url'    => rest_url( 'newspack-blocks/v1/newspack-blocks-posts-batch' ),
					'posts_batch_max_queries' => API::POSTS_BATCH_MAX_QUERIES,
					'specific_posts_rest_url' => rest_url( 'newspack-blocks/v1/newspack-blocks-specific-posts' ),
					'authors_rest_url'        => rest_url( 'newspack-content-blocks/v1/authors' ),
					'assets_path'             => plugins_url( '/src/assets', NEWSPACK_CONTENT_BLOCKS_PLUGIN_FILE ),
					'post_subtitle'           => get_theme_support( 'post-subtitle' ),
					'custom_taxonomies'       => Blocks::get_custom_taxonomies(),
				]
			);

			wp_set_script_translations(
				'newspack-content-blocks-editor',
				'newspack-content-blocks',
				NEWSPACK_CONTENT_BLOCKS_PLUGIN_DIR . 'languages'
			);
		}

		if ( file_exists( NEWSPACK_CONTENT_BLOCKS_PLUGIN_DIR . 'dist/editor.css' ) ) {
			$handle = 'newspack-content-blocks-editor';
			wp_enqueue_style(
				$handle,
				plugins_url( 'dist/editor.css', NEWSPACK_CONTENT_BLOCKS_PLUGIN_FILE ),
				[],
				Blocks::asset_version( 'editor' )
			);
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
	}
}
