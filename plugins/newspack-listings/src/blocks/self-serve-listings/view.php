<?php
/**
 * Front-end render functions for the Self-Serve Listing block.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings\Self_Serve_Block;

use Newspack_Listings\Core;
use Newspack_Listings\Products;
use Newspack_Listings\Utils;

/**
 * Dynamic block registration.
 */
function register_block() {
	register_block_type(
		__DIR__,
		[
			'render_callback' => __NAMESPACE__ . '\render_block',
		]
	);
}

/**
 * Block render callback.
 *
 * @param array $attributes Block attributes.
 * @return string $content content.
 */
function render_block( $attributes ) {
	// Only render if self-serve listings features are active.
	if ( ! Products::is_active() || ! Products::validate_products() ) {
		return '';
	}

	$content = Utils\template_include(
		'self-serve-form',
		[
			'attributes' => $attributes,
		]
	);

	return $content;
}

register_block();
