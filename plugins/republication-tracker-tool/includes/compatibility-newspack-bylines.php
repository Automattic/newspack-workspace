<?php
/**
 * Compatibility functionality for Newspack's Custom Bylines feature.
 *
 * @link https://github.com/Automattic/newspack-plugin/blob/trunk/includes/bylines/class-bylines.php
 * @package Republication_Tracker_Tool
 */

/**
 * Filter the Republication Tracker Tool Byline
 *
 * Gives an active Custom Byline (Newspack\Bylines) precedence over CAP
 * guest authors and the WP post author, matching the Byline block's own
 * order. Runs after the CAP compatibility filter (priority 20 vs 10) so it
 * can override CAP's result when both are active.
 *
 * @param String $author_string The string returned by get_the_author() (or a prior byline filter).
 * @return String the plain-text byline
 */
function republication_tracker_tool_byline_filter_newspack_bylines( $author_string ) {
	if ( ! class_exists( 'Newspack\Bylines' ) ) {
		return $author_string;
	}

	$custom_byline = \Newspack\Bylines::get_custom_byline_html();

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
	if ( ! class_exists( 'Newspack\Bylines' ) ) {
		return $prefix;
	}

	if ( empty( \Newspack\Bylines::get_custom_byline_html() ) ) {
		return $prefix;
	}

	return '';
}

add_filter( 'republication_tracker_tool_byline_prefix', 'republication_tracker_tool_byline_prefix_filter_newspack_bylines', 20, 1 );
