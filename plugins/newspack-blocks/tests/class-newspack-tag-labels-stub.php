<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for \Newspack\Tag_Labels.
 *
 * The newspack-blocks test suite runs without newspack-plugin loaded, so the
 * real \Newspack\Tag_Labels class is absent. This lightweight stub lets the
 * tests exercise the tag-label REST pass-through contract in isolation.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates the plugin's \Newspack\Tag_Labels.
namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Tag_Labels' ) ) {
	/**
	 * Minimal stub of the plugin's Tag_Labels class.
	 */
	class Tag_Labels {
		/**
		 * Labels returned by get_labels_for_post(). Set by the test.
		 *
		 * @var array|null
		 */
		public static $stub_labels = null;

		/**
		 * Return the stubbed labels, ignoring the post.
		 *
		 * @param int|\WP_Post|null $post Post to look up (ignored by the stub).
		 * @return array|null
		 */
		public static function get_labels_for_post( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Signature parity with the real class; the stub ignores the post.
			return self::$stub_labels;
		}
	}
}
