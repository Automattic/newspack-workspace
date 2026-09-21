<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Newspack\Bylines mock for tests.
 *
 * Declares the real class and method names the Custom Bylines compatibility
 * filter in compatibility-newspack-bylines.php looks for, so this plugin's
 * isolated suite can exercise that integration without loading newspack-plugin
 * itself (CI runs each plugin's suite alone, so the real class is never
 * loaded here).
 *
 * @package Republication_Tracker_Tool
 */

namespace Newspack;

if ( ! class_exists( 'Newspack\Bylines' ) ) {
	/**
	 * Minimal Newspack\Bylines mock. Only the surface the compatibility
	 * filter depends on.
	 */
	class Bylines {
		const META_KEY_ACTIVE = '_newspack_byline_active';
		const META_KEY_BYLINE = '_newspack_byline';

		/**
		 * Whether the (mocked) Custom Bylines feature is enabled.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			return true;
		}

		/**
		 * Get the post custom byline HTML markup.
		 *
		 * @param bool $include_avatars Unused; matches the real class's signature.
		 * @param bool $byline_wrapper Unused; matches the real class's signature.
		 * @param int  $post_id Optional post ID. Defaults to current post.
		 * @return false|string The post custom byline HTML markup or false if not available.
		 */
		public static function get_post_byline_html( $include_avatars = true, $byline_wrapper = true, $post_id = null ) {
			if ( ! $post_id ) {
				$post_id = \get_the_ID();
			}

			if ( ! \get_post_meta( $post_id, self::META_KEY_ACTIVE, true ) ) {
				return false;
			}

			$byline = \get_post_meta( $post_id, self::META_KEY_BYLINE, true );
			if ( ! $byline ) {
				return false;
			}

			return $byline;
		}
	}
}
