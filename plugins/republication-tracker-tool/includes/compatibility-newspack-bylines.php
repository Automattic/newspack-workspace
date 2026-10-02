<?php
/**
 * Compatibility functionality for Newspack's Custom Bylines feature.
 *
 * Checks Newspack\Bylines's existence inside each callback rather than
 * gating add_filter() at load time (unlike the sibling CAP compatibility
 * file), since load order between this plugin and newspack-plugin isn't
 * guaranteed.
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
 * can override CAP's result when both are active. Author links are stripped
 * so the byline is plain text, like the WP author and CAP bylines.
 *
 * @param String $author_string The string returned by get_the_author() (or a prior byline filter).
 * @return String the byline.
 */
function republication_tracker_tool_byline_filter_newspack_bylines( $author_string ) {
	$custom_byline = republication_tracker_tool_get_newspack_custom_byline();

	if ( empty( $custom_byline ) ) {
		return $author_string;
	}

	return wp_strip_all_tags( $custom_byline );
}

add_filter( 'republication_tracker_tool_byline', 'republication_tracker_tool_byline_filter_newspack_bylines', 20, 1 );

/**
 * Filter the Republication Tracker Tool Byline Format
 *
 * Suppresses this plugin's own "by %s" format when the byline being wrapped
 * is the Custom Byline, since that text already includes its own leading
 * word (e.g. "By ..."). A byline a later filter replaced keeps the format.
 *
 * @param string $format The byline format (should contain a %s placeholder).
 * @param string $byline The resolved byline the format will wrap.
 * @return string
 */
function republication_tracker_tool_byline_format_filter_newspack_bylines( $format, $byline = '' ) {
	$custom_byline = republication_tracker_tool_get_newspack_custom_byline();

	if ( empty( $custom_byline ) || wp_strip_all_tags( $custom_byline ) !== $byline ) {
		return $format;
	}

	return '%s';
}

add_filter( 'republication_tracker_tool_byline_format', 'republication_tracker_tool_byline_format_filter_newspack_bylines', 20, 2 );
