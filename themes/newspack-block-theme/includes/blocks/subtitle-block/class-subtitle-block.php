<?php
/**
 * Subtitle Block.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;


/**
 * Subtitle Block class.
 */
final class Subtitle_Block {
	const POST_META_NAME = 'newspack_post_subtitle';

	/**
	 * Initializer.
	 */
	public static function init() {
		\add_action( 'init', [ __CLASS__, 'register_block_and_post_meta' ] );
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
		\add_filter( 'is_protected_meta', [ __CLASS__, 'protect_post_meta' ], 10, 3 );
	}

	/**
	 * Register the block.
	 */
	public static function register_block_and_post_meta() {
		register_block_type_from_metadata(
			__DIR__,
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);

		register_post_meta(
			'post',
			self::POST_META_NAME,
			[
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				// Managed by the editor subtitle UI via REST. An explicit
				// auth_callback keeps it REST-editable while protect_post_meta()
				// marks it protected (see that method).
				'auth_callback' => function ( $allowed, $meta_key, $object_id ) {
					return current_user_can( 'edit_post', $object_id );
				},
			]
		);
	}

	/**
	 * Mark the subtitle meta as protected.
	 *
	 * The subtitle is edited through the editor subtitle UI (saved via the REST
	 * API), not the classic "Custom Fields" box. Leaving it unprotected lets
	 * that box resubmit a stale value on save and silently overwrite the
	 * REST-saved value when a classic meta box triggers the block editor's
	 * meta-box save. Protecting it removes it from the Custom Fields box; the
	 * explicit auth_callback on the registration keeps it REST-editable.
	 *
	 * @param bool   $protected Whether the meta key is considered protected.
	 * @param string $meta_key  The meta key.
	 * @param string $meta_type The type of object the meta belongs to (post, term, user, etc.).
	 * @return bool Whether the meta key is protected.
	 */
	public static function protect_post_meta( $protected, $meta_key, $meta_type ) {
		if ( 'post' !== $meta_type ) {
			return $protected;
		}
		return self::POST_META_NAME === $meta_key ? true : $protected;
	}

	/**
	 * Block render callback.
	 */
	public static function render_block() {
		$post_subtitle = get_post_meta( get_the_ID(), self::POST_META_NAME, true );
		$wrapper_attributes = get_block_wrapper_attributes();
		return sprintf( '<p %1$s>%2$s</p>', $wrapper_attributes, esc_html( $post_subtitle ) );
	}

	/**
	 * Enqueue block editor subtitle assets for the appropriate editor context.
	 */
	public static function enqueue_block_assets() {
		if ( ! \wp_should_load_block_editor_scripts_and_styles() ) {
			return;
		}

		$script_data = [
			'post_meta_name' => self::POST_META_NAME,
		];

		global $pagenow;
		if ( $pagenow === 'site-editor.php' ) {
			$handle = 'newspack-block-theme-subtitle-block-site-editor';
			$asset  = require \get_theme_file_path( 'dist/subtitle-block-site-editor.asset.php' );
			\wp_enqueue_script( $handle, \get_theme_file_uri( 'dist/subtitle-block-site-editor.js' ), $asset['dependencies'], $asset['version'], true );
			\wp_localize_script( $handle, 'newspack_block_theme_subtitle_block', $script_data );
		} elseif ( \get_current_screen() && \get_current_screen()->post_type === 'post' ) {
			$handle = 'newspack-block-theme-subtitle-block-post-editor';
			$asset  = require \get_theme_file_path( 'dist/subtitle-block-post-editor.asset.php' );
			\wp_enqueue_script( $handle, \get_theme_file_uri( 'dist/subtitle-block-post-editor.js' ), $asset['dependencies'], $asset['version'], true );
			\wp_localize_script( $handle, 'newspack_block_theme_subtitle_block', $script_data );
		}
	}
}
Subtitle_Block::init();
