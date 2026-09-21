<?php
/**
 * Compatibility functionality for Newspack's Custom Bylines feature.
 *
 * Registers unconditionally (unlike the sibling Co-Authors Plus compatibility
 * file, which gates registration on function_exists() at load time) and
 * checks Newspack\Bylines's existence inside each callback instead. That's
 * the more robust choice here, since load order between this plugin and
 * newspack-plugin isn't guaranteed.
 *
 * @link https://github.com/Automattic/newspack-plugin/blob/trunk/includes/bylines/class-bylines.php
 * @package Republication_Tracker_Tool
 */

/**
 * Get the active Custom Byline HTML for the current post, or null if
 * Newspack\Bylines isn't available (missing, or a version that doesn't have
 * this method) or there's no active Custom Byline for the post.
 *
 * @return string|null
 */
function republication_tracker_tool_get_newspack_custom_byline() {
	if ( ! class_exists( 'Newspack\Bylines' ) || ! method_exists( 'Newspack\Bylines', 'get_custom_byline_html' ) ) {
		return null;
	}

	return \Newspack\Bylines::get_custom_byline_html();
}

/**
 * Filter the Republication Tracker Tool Byline
 *
 * Gives an active Custom Byline (Newspack\Bylines) precedence over CAP
 * guest authors and the WP post author, matching the Byline block's own
 * order. Runs after the CAP compatibility filter (priority 20 vs 10) so it
 * can override CAP's result when both are active.
 *
 * @param String $author_string The string returned by get_the_author() (or a prior byline filter).
 * @return String the byline (may contain HTML author links).
 */
function republication_tracker_tool_byline_filter_newspack_bylines( $author_string ) {
	$custom_byline = republication_tracker_tool_get_newspack_custom_byline();

	if ( empty( $custom_byline ) ) {
		return $author_string;
	}

	return $custom_byline;
}

add_filter( 'republication_tracker_tool_byline', 'republication_tracker_tool_byline_filter_newspack_bylines', 20, 1 );

/**
 * Filter the Republication Tracker Tool Byline Prefix
 *
 * Suppresses this plugin's own "by " prefix when a Custom Byline is active,
 * since that text already includes its own leading text.
 *
 * @param string $prefix The byline prefix.
 * @return string
 */
function republication_tracker_tool_byline_prefix_filter_newspack_bylines( $prefix ) {
	if ( empty( republication_tracker_tool_get_newspack_custom_byline() ) ) {
		return $prefix;
	}

	return '';
}

add_filter( 'republication_tracker_tool_byline_prefix', 'republication_tracker_tool_byline_prefix_filter_newspack_bylines', 20, 1 );
