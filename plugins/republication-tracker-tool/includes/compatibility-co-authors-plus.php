<?php
/**
 * Compatibility functionality for Co-Authors Plus
 *
 * @link https://wpvip.com/plugins/co-authors-plus/
 * @link https://github.com/Automattic/Co-Authors-Plus
 * @package Republication_Tracker_Tool
 */

/**
 * Filter the Republication Tracker Tool Byline
 *
 * The reason that we're implementing a separate filter here is because
 * CAP's filter on the_author isn't triggering here.
 *
 * @link https://github.com/Automattic/Co-Authors-Plus/blob/3.4.3/php/class-coauthors-template-filters.php#L18-L20 the filter that does not trigger
 *
 * @param String $author_string The string returned by get_the_author.
 * @since Co-Authors Plus 3.4.3
 * @uses coauthors https://github.com/Automattic/Co-Authors-Plus/blob/3.4.3/template-tags.php#L210-L227
 * @return String the plain-text byline
 */
function republication_tracker_tool_byline_filter_cap( $author_string ) {
	return coauthors( null, null, null, null, false );
}

if ( function_exists( 'coauthors' ) ) {
	add_filter( 'republication_tracker_tool_byline', 'republication_tracker_tool_byline_filter_cap', 10, 1 );
}
