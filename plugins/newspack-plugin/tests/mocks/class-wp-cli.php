<?php
/**
 * Minimal WP_CLI stub for testing CLI command code under PHPUnit.
 *
 * WP-CLI is not loaded in the test bootstrap, so any command path that reports progress
 * would fatal. Only the surface the commands under test touch is stubbed. Messages land on
 * self::$messages keyed by method, so tests can assert on what a command reported, and
 * error() throws so the halting paths stay observable.
 *
 * The `WP_CLI\Utils` function stubs live in wp-cli-utils-mocks.php (a namespaced file
 * cannot also declare this global class).
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Stub of the WP_CLI facade.
	 */
	class WP_CLI {
		/**
		 * Recorded messages, keyed by method name.
		 *
		 * @var array
		 */
		public static $messages = [];

		/**
		 * Reset the recorded messages. Call from set_up().
		 */
		public static function reset() {
			self::$messages = [];
		}

		/**
		 * Log a progress message.
		 *
		 * @param string $message Message.
		 */
		public static function log( $message ) {
			self::$messages['log'][] = $message;
		}

		/**
		 * Print a line.
		 *
		 * @param string $message Message.
		 */
		public static function line( $message = '' ) {
			self::$messages['line'][] = $message;
		}

		/**
		 * Print a success message.
		 *
		 * @param string $message Message.
		 */
		public static function success( $message ) {
			self::$messages['success'][] = $message;
		}

		/**
		 * Print a warning.
		 *
		 * @param string $message Message.
		 */
		public static function warning( $message ) {
			self::$messages['warning'][] = $message;
		}

		/**
		 * Halt with an error. Real WP-CLI exits non-zero; throwing keeps that observable.
		 *
		 * @param string $message Message.
		 * @throws \Exception Always.
		 */
		public static function error( $message ) {
			self::$messages['error'][] = $message;
			throw new \Exception( esc_html( $message ) );
		}
	}
}
