<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Stand-in for the parts of Co-Authors Plus that content distribution calls.
 *
 * Cap_Authors only acts when the global $coauthors_plus is a CoAuthors_Plus
 * instance, so tests assign one in set_up and restore the original afterwards.
 *
 * @package Newspack_Network
 */

if ( ! class_exists( 'CoAuthors_Plus' ) ) {
	/**
	 * Records the bylines assigned to posts instead of storing them.
	 */
	class CoAuthors_Plus {
		/**
		 * Bylines passed to add_coauthors(), keyed by post ID.
		 *
		 * @var array
		 */
		public $added_coauthors = [];

		/**
		 * Record the byline assigned to a post.
		 *
		 * @param int    $post_id    The post ID.
		 * @param array  $coauthors  The co-author identifiers.
		 * @param bool   $append     Whether to append to the existing byline.
		 * @param string $query_type The field the identifiers refer to.
		 *
		 * @return bool
		 */
		public function add_coauthors( $post_id, $coauthors, $append = false, $query_type = 'user_nicename' ) {
			$this->added_coauthors[ $post_id ] = $coauthors;
			return true;
		}
	}
}

if ( ! function_exists( 'get_coauthors' ) ) {
	/**
	 * Stub for get_coauthors.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array
	 */
	function get_coauthors( $post_id = 0 ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
		return [];
	}
}
