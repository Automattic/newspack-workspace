<?php // phpcs:ignoreFile
/**
 * Minimal WP_CLI mock for unit-testing CLI classes outside a real WP-CLI run.
 *
 * Captures warning messages in a public static array so tests can assert on
 * operator-facing output; the other methods are no-ops except error(), which
 * throws so a test never sails past a hard CLI abort.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * WP_CLI test double.
	 */
	class WP_CLI {
		/**
		 * Captured warning messages, in call order.
		 *
		 * @var string[]
		 */
		public static $warnings = [];

		/**
		 * Capture a warning.
		 *
		 * @param string $message The warning message.
		 */
		public static function warning( $message ) {
			self::$warnings[] = (string) $message;
		}

		/**
		 * No-op line output.
		 *
		 * @param string $message The message.
		 */
		public static function line( $message = '' ) {}

		/**
		 * No-op success output.
		 *
		 * @param string $message The message.
		 */
		public static function success( $message ) {}

		/**
		 * Hard abort — surfaced as an exception so tests fail loudly.
		 *
		 * @param string $message The error message.
		 *
		 * @throws \Exception Always.
		 */
		public static function error( $message ) {
			throw new \Exception( (string) $message );
		}
	}
}
