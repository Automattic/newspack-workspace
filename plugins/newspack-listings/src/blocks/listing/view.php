<?php
/**
 * Front-end render functions for the Listing block.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings\Listing_Block;

use Newspack_Listings\Core;
use Newspack_Listings\Utils;

/**
 * Dynamic block registration.
 */
function register_blocks() {
	// Listings block attributes.
	$block_json = json_decode(
		file_get_contents( __DIR__ . '/block.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		true
	);

	// Parent Curated List block attributes.
	$parent_block_json = json_decode(
		file_get_contents( dirname( __DIR__ ) . '/curated-list/block.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		true
	);

	// Combine attributes with parent attributes, so parent can pass data to InnerBlocks.
	$attributes = array_merge( $block_json['attributes'], $parent_block_json['attributes'] );

	// Register a block for each listing type.
	foreach ( Core::NEWSPACK_LISTINGS_POST_TYPES as $label => $post_type ) {
		register_block_type(
			'newspack-listings/' . $label,
			[
				'api_version'     => $block_json['apiVersion'],
				'attributes'      => $attributes,
				'render_callback' => __NAMESPACE__ . '\render_block',
			]
		);
	}
}

/**
 * Block render callback.
 *
 * @param array $attributes Block attributes (including parent attributes inherited from Curated List container block).
 */
function render_block( $attributes ) {
	// Bail if there's no listing post ID for this block.
	if ( empty( $attributes['listing'] ) ) {
		return;
	}

	// Get the listing post by post ID.
	$post = get_post( intval( $attributes['listing'] ) );

	// Bail if there's no published post with the saved ID.
	if ( empty( $post ) || 'publish' !== $post->post_status ) {
		return;
	}

	$content = Utils\template_include(
		'listing',
		[
			'attributes' => $attributes,
			'post'       => $post,
		]
	);

	return $content;
}

register_blocks();
