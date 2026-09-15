<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Newspack_Popups_Criteria stub for tests.
 *
 * Declares the real class and method names the capability probe in
 * Promoted_Fields::supported_matching_function() looks for, so its success
 * branch can run in this suite (CI runs each plugin's suite alone, so the real
 * class is never loaded here). Probing the genuine names is the point: a
 * mistyped class or method in the probe makes method_exists() return false and
 * fails the test that loads this stub. Load it per-test, not in the bootstrap —
 * the degrade-path test must run before the class exists.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'Newspack_Popups_Criteria' ) ) {
	/**
	 * Minimal Newspack_Popups_Criteria stub. Only the probe surface.
	 */
	class Newspack_Popups_Criteria {
		/**
		 * Mirrors the real SUPPORTED_MATCHING_FUNCTIONS roster.
		 *
		 * @var string[]
		 */
		const SUPPORTED_MATCHING_FUNCTIONS = [ 'default', 'range', 'list__in', 'list__not_in', 'date_range' ];

		/**
		 * Whether a matching function can be resolved by this build.
		 *
		 * @param string $matching_function The matching function name.
		 * @return bool
		 */
		public static function supports_matching_function( $matching_function ) {
			return in_array( $matching_function, self::SUPPORTED_MATCHING_FUNCTIONS, true );
		}

		/**
		 * No-op registration, so code that follows a successful probe with a
		 * registration call doesn't fatal against the stub.
		 *
		 * @param string $id     The criteria ID.
		 * @param array  $config The criteria config.
		 */
		public static function register_criteria( $id, $config = [] ) {}
	}
}
