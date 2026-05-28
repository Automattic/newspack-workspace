<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FileComment.Missing

if ( ! function_exists( 'get_coauthors' ) ) {
	function get_coauthors( $post_id = 0 ) {
		return $GLOBALS['_test_cap_coauthors'] ?? [];
	}
}
