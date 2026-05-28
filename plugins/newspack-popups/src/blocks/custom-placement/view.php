<?php
/**
 * Front-end render functions for the Custom Placement block.
 *
 * @package Newspack_Popups
 */

namespace Newspack_Popups\Custom_Placement_Block;

/**
 * Dynamic block registration.
 */
function register_block() {
	// Custom Placement block attributes.
	$block_json = json_decode(
		file_get_contents( __DIR__ . '/block.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		true
	);

	register_block_type(
		$block_json['name'],
		[
			'api_version'     => $block_json['apiVersion'],
			'attributes'      => $block_json['attributes'],
			'render_callback' => __NAMESPACE__ . '\render_block',
		]
	);
}

/**
 * Block render callback.
 *
 * @param array $attributes Block attributes.
 */
function render_block( $attributes ) {
	$content             = '';
	$custom_placement_id = \Newspack_Popups_Custom_Placements::validate_custom_placement_id( $attributes['customPlacement'] );
	$class_names         = isset( $attributes['className'] )
		? ' class="' . esc_attr( $attributes['className'] ) . '"'
		: '';
	$in_post_content     = doing_filter( 'the_content' );
	$is_block_theme      = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

	if ( empty( $custom_placement_id ) ) {
		return $content;
	}

	$post_categories = array_map(
		function( $term ) {
			return $term->term_id;
		},
		get_the_category()
	);

	// Get category-matching prompts for the custom placement.
	$prompts = \Newspack_Popups_Custom_Placements::get_prompts_for_custom_placement( [ $custom_placement_id ], 'ids', $post_categories );

	// If no category-matching prompts, get uncategorized prompts.
	if ( empty( $prompts ) ) {
		$prompts = \Newspack_Popups_Custom_Placements::get_prompts_for_custom_placement( [ $custom_placement_id ], 'ids', [] );
	}

	if ( ! empty( $prompts ) ) {
		if ( defined( 'WP_NEWSPACK_DEBUG' ) && WP_NEWSPACK_DEBUG ) {
			$content .= '<!-- Newspack Campaigns: Start custom placement ' . $custom_placement_id . '-->';
		}
		foreach ( $prompts as $prompt_id ) {
			$shortcode = '[newspack-popup id="' . $prompt_id . '"' . $class_names . ']';
			$should_render = ! $in_post_content && $is_block_theme;
			$render_shortcode = apply_filters( 'newspack_popups_render_custom_placement_shortcode', $should_render, $attributes, $custom_placement_id, $prompt_id );
			$content  .= $render_shortcode
				? do_shortcode( $shortcode )
				: '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->';
		}
		if ( defined( 'WP_NEWSPACK_DEBUG' ) && WP_NEWSPACK_DEBUG ) {
			$content .= '<!-- Newspack Campaigns: End custom placement ' . $custom_placement_id . '-->';
		}
	}

	return $content;
}

register_block();
