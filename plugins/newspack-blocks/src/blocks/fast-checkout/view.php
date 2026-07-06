<?php
/**
 * Fast Checkout block — server-side registration.
 *
 * @package Newspack_Blocks
 */

namespace Newspack_Blocks\Fast_Checkout_Block;

/**
 * Register the block.
 */
function register_block() {
	register_block_type_from_metadata( __DIR__ . '/block.json' );
}
add_action( 'init', __NAMESPACE__ . '\\register_block' );

/**
 * Enqueue the frontend view assets when the block renders on a page.
 */
function enqueue_assets() {
	if ( is_admin() || ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! has_block( 'newspack-blocks/fast-checkout', $post ) ) {
		return;
	}
	\Newspack_Blocks::enqueue_view_assets( 'fast-checkout' );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
