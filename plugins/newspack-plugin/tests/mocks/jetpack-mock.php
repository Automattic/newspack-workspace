<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Jetpack class stub for tests.
 *
 * Tests that exercise code paths gated on Jetpack's presence (e.g. the search
 * overlay block's instant-search handoff) include this file. The class is
 * defined globally once and behavior is toggled via `Jetpack::$test_active_modules`.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'Jetpack' ) ) {
	/**
	 * Minimal Jetpack stub. Only the surface the plugin under test actually
	 * touches is implemented.
	 */
	class Jetpack {
		/**
		 * Module slugs the stub should report as active.
		 *
		 * @var string[]
		 */
		public static $test_active_modules = [];

		/**
		 * Whether the stub should report Jetpack as active/connected.
		 *
		 * @var bool
		 */
		public static $test_is_active = false;

		/**
		 * Whether `$module` is in the active list for this test.
		 *
		 * @param string $module Module slug.
		 * @return bool
		 */
		public static function is_module_active( $module ) {
			return in_array( $module, self::$test_active_modules, true );
		}

		/**
		 * Whether Jetpack reports itself as active/connected for this test.
		 *
		 * @return bool
		 */
		public static function is_active() {
			return self::$test_is_active;
		}
	}
}
