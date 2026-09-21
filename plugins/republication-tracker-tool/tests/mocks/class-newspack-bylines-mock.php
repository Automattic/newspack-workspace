<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Newspack\Bylines mock for tests.
 *
 * Declares the real class and method names the Custom Bylines compatibility
 * filters in compatibility-newspack-bylines.php look for, so this plugin's
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
	 * filters depend on.
	 */
	class Bylines {
		const META_KEY_ACTIVE = '_newspack_byline_active';
		const META_KEY_BYLINE = '_newspack_byline';

		/**
		 * Get the custom byline HTML for a specific post.
		 *
		 * @param int $post_id Optional post ID. Defaults to current post.
		 * @return string|null The custom byline HTML or null if not active.
		 */
		public static function get_custom_byline_html( $post_id = null ) {
			if ( ! $post_id ) {
				$post_id = \get_the_ID();
			}

			if ( ! \get_post_meta( $post_id, self::META_KEY_ACTIVE, true ) ) {
				return null;
			}

			$byline = \get_post_meta( $post_id, self::META_KEY_BYLINE, true );
			if ( ! $byline ) {
				return null;
			}

			return $byline;
		}
	}
}
