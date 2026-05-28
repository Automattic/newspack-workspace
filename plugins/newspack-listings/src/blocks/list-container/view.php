<?php
/**
 * Front-end render functions for the Curated List Container block.
 * This is a blank wrapper block that contains listing items.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings\Curated_List_Container_Block;

use Newspack_Listings\Core;
use Newspack_Listings\Utils;

/**
 * Dynamic block registration.
 */
function register_block() {
	// Parent Curated List block attributes.
	$parent_block_json = json_decode(
		file_get_contents( dirname( __DIR__ ) . '/curated-list/block.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		true
	);

	// Register Curated List Container block.
	register_block_type(
		'newspack-listings/list-container',
		[
			'api_version'     => 3,
			'attributes'      => $parent_block_json['attributes'],
			'render_callback' => __NAMESPACE__ . '\render_block',
		]
	);
}

/**
 * Block render callback.
 *
 * @param array  $attributes Block attributes.
 * @param string $inner_content InnerBlock content.
 */
function render_block( $attributes, $inner_content ) {
	$content = '';

	// If showing sort UI.
	if ( $attributes['showSortUi'] ) {
		$content .= Utils\template_include(
			'sort-ui',
			[ 'attributes' => $attributes ]
		);
	}

	// Bail if there's no InnerBlock content to display.
	if ( $attributes['queryMode'] || empty( trim( $inner_content ) ) ) {
		return $content;
	}

	// Begin front-end output.
	ob_start();

	?>
	<ol class="newspack-listings__list-container">
		<?php echo wp_kses_post( $inner_content ); ?>
	</ol>
	<?php

	$content .= ob_get_clean();

	return $content;
}

register_block();
