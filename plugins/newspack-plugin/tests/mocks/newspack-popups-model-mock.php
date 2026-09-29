<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Newspack_Popups_Model stub for tests.
 *
 * The Perfmatters integration calls
 * \Newspack_Popups_Model::has_published_above_header_prompts() guarded by
 * method_exists(). newspack-popups is not loaded in this test suite, so this stub
 * stands in for it and its return value is toggled via
 * `Newspack_Popups_Model::$has_above_header`.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'Newspack_Popups_Model' ) ) {
	/**
	 * Minimal Newspack_Popups_Model stub. Only the surface the integration touches.
	 */
	class Newspack_Popups_Model {
		/**
		 * Stubbed detection result.
		 *
		 * @var bool
		 */
		public static $has_above_header = false;

		/**
		 * Stubbed detection method.
		 *
		 * @return bool
		 */
		public static function has_published_above_header_prompts() {
			return self::$has_above_header;
		}
	}
}
