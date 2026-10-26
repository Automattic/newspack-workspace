<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Newspack_Segments_Model stub for tests.
 *
 * The newspack-popups plugin is not loaded in this suite. The stub keeps segments in memory
 * behind the two methods the membership-segments migration calls.
 *
 * PHPUnit loads every test file before running any, so once this stub is
 * required the class exists for the whole suite: code that returns early when
 * Campaigns is inactive cannot reach that branch in any test.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'Newspack_Segments_Model' ) ) {
	/**
	 * In-memory Newspack_Segments_Model.
	 */
	class Newspack_Segments_Model {
		/**
		 * Segments keyed by ID.
		 *
		 * @var array[]
		 */
		public static $segments = [];

		/**
		 * Every segment passed to update_segment(), in order.
		 *
		 * @var array[]
		 */
		public static $updates = [];

		/**
		 * All segments.
		 *
		 * @return array[]
		 */
		public static function get_segments() {
			return array_values( self::$segments );
		}

		/**
		 * Store a segment.
		 *
		 * @param array $segment Segment.
		 *
		 * @return array[] All segments.
		 */
		public static function update_segment( $segment ) {
			self::$updates[]                  = $segment;
			self::$segments[ $segment['id'] ] = $segment;
			return self::get_segments();
		}
	}
}
