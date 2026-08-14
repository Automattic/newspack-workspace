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
		$removed = remove_filter( 'get_the_excerpt', 'wp_trim_excerpt', 10 );
		if ( ! $removed ) {
			Logger::log( 'Failed to remove core wp_trim_excerpt filter; excerpts may bypass the content gate.', 'CONTENT-GATE-EXCERPT' );
		}
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
		$resolved = $post instanceof \WP_Post ? $post : get_post( $post );

		if ( ! $resolved instanceof \WP_Post ) {
			return wp_trim_excerpt( $text, $post );
		}

		// Core returns a non-empty $text untouched; the branches below deliberately
		// do not. Confine that difference to posts that actually use the gate, so on
		// every other post -- including every post on a site with gates switched off,
		// where this filter still replaces core's -- an excerpt supplied by a filter
		// below priority 10 survives exactly as it would without Newspack installed.
		if ( ! Block_Visibility::has_access_control( (string) $resolved->post_content ) ) {
			return wp_trim_excerpt( $text, $post );
		}

		// Core is only ever handed the sanitized clone, in both branches below. Any
		// path that lets core rebuild from the post reaches post_content, so handing
		// it the real post anywhere would put gated blocks back in the excerpt.
		$sanitized               = clone $resolved;
		$sanitized->post_content = Block_Visibility::strip_blocks_hidden_from_public( $resolved->post_content );

		// A manually written excerpt is the author's own words; core returns it
		// untouched and so do we. Read it from the post rather than from $text,
		// which is whatever the filter chain holds by the time this runs and is not
		// necessarily the manual excerpt. If an earlier filter blanked $text, core
		// rebuilds — from the sanitized clone, not the original.
		if ( '' !== trim( (string) $resolved->post_excerpt ) ) {
			return wp_trim_excerpt( $text, $sanitized );
		}

		// Pass an empty $text so core rebuilds from the sanitized post.
		// wp_trim_excerpt() returns $text unchanged when it is non-empty, so
		// forwarding a value another filter already produced would skip the
		// sanitized content entirely.
		//
		// Gated blocks never contribute to a teaser. A post whose readable content is
		// entirely gated gets a blank excerpt, matching what its article page already
		// shows a non-member.
		return wp_trim_excerpt( '', $sanitized );
	}
}
Content_Gate_Excerpt::init();
