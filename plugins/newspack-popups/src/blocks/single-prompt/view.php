<?php
/**
 * Front-end render functions for the Prompt block.
 *
 * @package Newspack_Popups
 */

namespace Newspack_Popups\Prompt_Block;

/**
 * Dynamic block registration.
 */
function register_block() {
	// Prompt block attributes.
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
	$content     = '';
	$prompt_id   = $attributes['promptId'];
	$class_names = isset( $attributes['className'] )
		? ' class="' . esc_attr( $attributes['className'] ) . '"'
		: '';
	$in_post_content = doing_filter( 'the_content' );
	$is_block_theme  = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

	if ( empty( $prompt_id ) ) {
		return $content;
	}

	// Get the prompt by id.
	$prompt = \Newspack_Popups_Model::retrieve_popup_by_id( intval( $prompt_id ) );

	// Only show inline or manual-only prompts (should only be selectable in editor, but verify just in case).
	if ( ! empty( $prompt ) && ( \Newspack_Popups_Model::is_inline( $prompt ) || \Newspack_Popups_Model::is_manual_only( $prompt ) ) ) {
		if ( defined( 'WP_NEWSPACK_DEBUG' ) && WP_NEWSPACK_DEBUG ) {
			$content .= '<!-- Newspack Campaigns: Start Prompt ' . $prompt_id . '-->';
		}

		$shortcode = '[newspack-popup id="' . $prompt_id . '"' . $class_names . ']';
		$should_render = ! $in_post_content && $is_block_theme;
		$render_shortcode = apply_filters( 'newspack_popups_render_prompt_shortcode', $should_render, $attributes, $prompt_id );
		$content  .= $render_shortcode
			? do_shortcode( $shortcode )
			: '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->';

		if ( defined( 'WP_NEWSPACK_DEBUG' ) && WP_NEWSPACK_DEBUG ) {
			$content .= '<!-- Newspack Campaigns: End Prompt ' . $prompt_id . '-->';
		}
	}

	return $content;
}

register_block();
