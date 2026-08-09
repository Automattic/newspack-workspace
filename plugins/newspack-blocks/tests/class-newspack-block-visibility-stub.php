<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for \Newspack\Block_Visibility.
 *
 * The newspack-blocks test suite runs without newspack-plugin loaded, so the
 * real \Newspack\Block_Visibility class is absent. This lightweight stub lets the
 * tests verify the wiring that routes content through the block visibility
 * sanitization contract.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates the plugin's \Newspack\Block_Visibility.
namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Block_Visibility' ) ) {
	/**
	 * Minimal stub of the plugin's Block_Visibility class.
	 */
	class Block_Visibility {
		/**
		 * Whether the stub's strip_blocks_hidden_from_public was called.
		 *
		 * @var bool
		 */
		public static $sanitization_was_called = false;

		/**
		 * Strip blocks withheld from public (non-authenticated) readers.
		 *
		 * This stub records that the method was called (verifying the integration
		 * point), and removes a trivial marker string. The real implementation logic
		 * is tested in newspack-plugin; this test verifies only the WIRING.
		 *
		 * @param string $content Block markup.
		 * @return string Content with the stub-marked text removed.
		 */
		public static function strip_blocks_hidden_from_public( $content ) {
			self::$sanitization_was_called = true;
			// Remove the fixture marker that represents gated content.
			// This stub verifies the integration point, not the real stripping logic.
			return str_replace( 'SECRETMARK', '', $content );
		}

		/**
		 * Reset the sanitization-was-called flag for tests.
		 *
		 * @return void
		 */
		public static function reset_sanitization_for_tests() {
			self::$sanitization_was_called = false;
		}
	}
}
