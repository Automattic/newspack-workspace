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

			return self::replace_author_shortcodes( $byline );
		}

		/**
		 * Replace author shortcodes with author links, mirroring the real
		 * class's markup so tests exercise the same output shape production
		 * renders.
		 *
		 * @param string $byline Byline with author shortcodes on it.
		 * @return string
		 */
		private static function replace_author_shortcodes( $byline ) {
			return preg_replace_callback(
				'/\[Author id=(\d+)\](.*?)\[\/Author\]/',
				function ( $matches ) {
					$author_id = $matches[1];

					$author = \get_user_by( 'id', $author_id );
					if ( ! $author ) {
						return $matches[2];
					}

					return sprintf(
						'<span class="author vcard"><a class="url fn n" href="%1$s">%2$s</a></span>',
						\esc_url( \get_author_posts_url( $author_id ) ),
						\esc_html( \get_the_author_meta( 'display_name', $author_id ) )
					);
				},
				$byline
			);
		}
	}
}
