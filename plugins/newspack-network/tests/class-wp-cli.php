<?php
/**
 * Minimal WP_CLI shim for the test suite.
 *
 * The CLI command classes talk to WP_CLI for all of their output and for their exit codes, so
 * without a stand-in they cannot be called from PHPUnit at all -- which is what kept assign() and
 * verify() untested. This captures output in memory and turns WP_CLI::error() ( which halts the
 * process with a non-zero exit code ) into a WP_CLI_Halt exception tests can assert on.
 *
 * The WP_CLI constant is deliberately NOT defined: the plugin registers its commands only when it
 * is, and add_command() has no stand-in here.
 *
 * @package Newspack_Network
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Captures what a command would print.
	 */
	class WP_CLI {

		/**
		 * Everything printed since the last reset().
		 *
		 * @var string[]
		 */
		public static $output = [];

		/**
		 * Discard captured output.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$output = [];
		}

		/**
		 * All captured output as a single string.
		 *
		 * @return string
		 */
		public static function get_output() {
			return implode( "\n", self::$output );
		}

		/**
		 * Capture a line.
		 *
		 * @param string $message The message.
		 * @return void
		 */
		public static function line( $message = '' ) {
			self::$output[] = (string) $message;
		}

		/**
		 * Capture a log line.
		 *
		 * @param string $message The message.
		 * @return void
		 */
		public static function log( $message = '' ) {
			self::$output[] = (string) $message;
		}

		/**
		 * Capture a warning.
		 *
		 * @param string $message The message.
		 * @return void
		 */
		public static function warning( $message ) {
			self::$output[] = 'Warning: ' . $message;
		}

		/**
		 * Capture a success message.
		 *
		 * @param string $message The message.
		 * @return void
		 */
		public static function success( $message ) {
			self::$output[] = 'Success: ' . $message;
		}

		/**
		 * Capture an error and halt, as WP_CLI does with a non-zero exit code.
		 *
		 * @param string $message The message.
		 * @return void
		 * @throws \WP_CLI_Halt Always.
		 */
		public static function error( $message ) {
			self::$output[] = 'Error: ' . $message;
			throw new \WP_CLI_Halt( esc_html( $message ) );
		}
	}
}
