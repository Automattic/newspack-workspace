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
		\add_action( 'admin_init', [ __CLASS__, 'prevent_classic_metabox_meta_clobber' ] );
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
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			]
		);
	}

	/**
	 * Stop the classic "Custom Fields" box from clobbering the subtitle meta.
	 *
	 * The subtitle is edited through the editor subtitle UI and saved via the
	 * REST API. When the "Custom Fields" panel is enabled, the editor also fires
	 * a separate classic meta-box save (the `meta-box-loader` request) that
	 * resubmits the box's page-load value and writes it through edit_post(),
	 * landing just after the REST save and silently overwriting it.
	 *
	 * Rather than protecting the key (which would remove it from the Custom
	 * Fields box and block publishers who manage it there), we drop it from the
	 * meta-box-loader payload only. Intentional edits made with the box's own
	 * Add/Update buttons save through a separate admin-ajax request and are
	 * unaffected.
	 *
	 * @return void
	 */
	public static function prevent_classic_metabox_meta_clobber() {
		// Only the block editor's auxiliary meta-box save carries this flag; a
		// genuine classic-editor save does not, and must keep writing normally.
		if ( ! isset( $_REQUEST['meta-box-loader'], $_POST['post_ID'], $_POST['_wpnonce'], $_POST['meta'] ) ) {
			return;
		}

		// edit_post() processes $_POST['meta'] only after core verifies this nonce
		// for the 'editpost' action (wp-admin/post.php). This runs earlier (on
		// admin_init), so verify the same nonce before touching the payload.
		$post_id = (int) $_POST['post_ID'];
		$nonce   = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
		if ( ! $post_id || ! wp_verify_nonce( $nonce, 'update-post_' . $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the meta-row key is read and sanitized below; the row value is never used, and the nonce is verified above.
		foreach ( array_keys( (array) $_POST['meta'] ) as $mid ) {
			$key = isset( $_POST['meta'][ $mid ]['key'] )
				? sanitize_text_field( wp_unslash( $_POST['meta'][ $mid ]['key'] ) )
				: '';
			if ( self::POST_META_NAME === $key ) {
				unset( $_POST['meta'][ $mid ] );
			}
		}
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
