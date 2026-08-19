<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for \Newspack\Content_Gate.
 *
 * The newspack-blocks test suite runs without newspack-plugin loaded, so the real
 * \Newspack\Content_Gate class is absent. This stub lets the tests verify the
 * wiring that keeps a gated post's body out of the excerpts this plugin builds
 * itself; the restriction logic is tested in newspack-plugin.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates the plugin's \Newspack\Content_Gate.
namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Content_Gate' ) ) {
	/**
	 * Minimal stub of the plugin's Content_Gate class.
	 */
	class Content_Gate {

		/**
		 * Posts the stub reports as withheld from the reader.
		 *
		 * @var int[]
		 */
		public static $withheld_post_ids = [];

		/**
		 * Teaser the stub hands back for a withheld post.
		 *
		 * @var string
		 */
		public static $teaser = '';

		/**
		 * Whether the post's body is withheld from the current reader.
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		public static function should_withhold_content( $post_id ) {
			return in_array( (int) $post_id, self::$withheld_post_ids, true );
		}

		/**
		 * The teaser shown in place of a withheld post's body.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public static function get_withheld_teaser( $post_id ) {
			return self::$teaser;
		}

		/**
		 * Forget what a test configured.
		 *
		 * @return void
		 */
		public static function reset_for_tests() {
			self::$withheld_post_ids = [];
			self::$teaser            = '';
		}
	}
}
