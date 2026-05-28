<?php
/**
 * Newspack Newsletters Compatibility File
 *
 * @package Newspack
 */

/**
 * Enqueue Block Editor styles.
 */
function newspack_newsletters_enqueue_editor_styles() {
	add_editor_style( 'styles/newspack-newsletters-editor.css' );
}
add_action( 'newspack_newsletters_enqueue_block_editor_assets', 'newspack_newsletters_enqueue_editor_styles' );
