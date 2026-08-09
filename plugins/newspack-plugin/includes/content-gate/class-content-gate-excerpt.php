<?php
/**
 * Keep non-public block content out of auto-generated excerpts.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Content_Gate_Excerpt class.
 */
class Content_Gate_Excerpt {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Core registers wp_trim_excerpt() here at priority 10. Replace it with a
		// wrapper that hands core the same work over sanitized content, rather than
		// reimplementing the trimming: two copies of that logic already exist in this
		// monorepo and both have drifted from core.
		remove_filter( 'get_the_excerpt', 'wp_trim_excerpt', 10 );
		add_filter( 'get_the_excerpt', [ __CLASS__, 'filter_get_the_excerpt' ], 10, 2 );
	}

	/**
	 * Build the excerpt from content with non-public blocks removed.
	 *
	 * @param string   $text The post excerpt, empty when auto-generating.
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	public static function filter_get_the_excerpt( $text, $post = null ) {
		if ( ! $post instanceof \WP_Post ) {
			return wp_trim_excerpt( $text, $post );
		}

		// A manually written excerpt is the author's own words; core returns it
		// untouched and so do we.
		if ( '' !== trim( (string) $text ) ) {
			return wp_trim_excerpt( $text, $post );
		}

		$sanitized               = clone $post;
		$sanitized->post_content = Block_Visibility::strip_blocks_hidden_from_public( $post->post_content );

		// Gated blocks never contribute to a teaser. A post whose readable content is
		// entirely gated gets a blank excerpt, matching what its article page already
		// shows a non-member.
		return wp_trim_excerpt( $text, $sanitized );
	}
}
Content_Gate_Excerpt::init();
