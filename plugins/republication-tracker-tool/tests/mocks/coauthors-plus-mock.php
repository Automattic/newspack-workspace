<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Co-Authors Plus mock for tests.
 *
 * Declares the coauthors() template tag compatibility-co-authors-plus.php
 * looks for, so this plugin's isolated suite can exercise that integration
 * without CAP actually installed. Must be loaded before the plugin bootstraps
 * (from tests/bootstrap.php, not a test's set_up()) since that compatibility
 * file's own registration is gated on function_exists( 'coauthors' ) at
 * plugin-load time.
 *
 * @package Republication_Tracker_Tool
 */

if ( ! function_exists( 'coauthors' ) ) {
	/**
	 * Minimal coauthors() mock. Returns (or echoes) $GLOBALS['_test_cap_coauthors']
	 * when a test sets it; otherwise falls back to the WP post author, matching
	 * real CAP's own behavior for a post with no coauthors configured.
	 *
	 * @param string|null $between     Unused; matches the real signature.
	 * @param string|null $between_last Unused; matches the real signature.
	 * @param string|null $before      Unused; matches the real signature.
	 * @param string|null $after       Unused; matches the real signature.
	 * @param bool        $echo        Whether to echo the result.
	 * @return string|true The mocked coauthor names, or true if echoed.
	 */
	function coauthors( $between = null, $between_last = null, $before = null, $after = null, $echo = true ) {
		$names = $GLOBALS['_test_cap_coauthors'] ?? get_the_author();

		if ( $echo ) {
			echo $names; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return true;
		}

		return $names;
	}
}
