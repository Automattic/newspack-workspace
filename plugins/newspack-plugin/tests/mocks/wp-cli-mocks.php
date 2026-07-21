<?php // phpcs:ignoreFile
/**
 * Minimal WP-CLI mocks so CLI command methods can be exercised in unit tests.
 *
 * The WP_CLI class records every emitted line on WP_CLI::$output for assertions,
 * and WP_CLI::error() throws WP_CLI_Mock_Exception so tests can assert an abort
 * (real WP-CLI exits the process). Only the surface used by the Newspack CLI
 * classes is implemented.
 *
 * @package Newspack\Tests
 */

namespace {
	if ( ! class_exists( 'WP_CLI_Mock_Exception' ) ) {
		/**
		 * Thrown by the WP_CLI::error() mock in place of a process exit.
		 */
		class WP_CLI_Mock_Exception extends \Exception {}
	}

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Recording mock of the WP_CLI logger surface.
		 */
		class WP_CLI {
			/**
			 * Every line emitted through the mock, in order.
			 *
			 * @var string[]
			 */
			public static $output = [];

			/**
			 * Clear recorded output. Call from a test's set_up().
			 */
			public static function reset() {
				self::$output = [];
			}

			public static function line( $message = '' ) {
				self::$output[] = (string) $message;
			}

			public static function log( $message ) {
				self::$output[] = (string) $message;
			}

			public static function warning( $message ) {
				self::$output[] = 'Warning: ' . $message;
			}

			public static function success( $message ) {
				self::$output[] = 'Success: ' . $message;
			}

			/**
			 * Real WP_CLI::error() prints and exits; the mock throws instead so the
			 * abort is observable and the test process survives.
			 *
			 * @param string $message Error message.
			 * @throws WP_CLI_Mock_Exception Always.
			 */
			public static function error( $message ) {
				throw new \WP_CLI_Mock_Exception( (string) $message );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return \array_key_exists( $flag, $assoc_args ) ? $assoc_args[ $flag ] : $default;
		}
	}

	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$output[] = sprintf( '(%s: %d row(s))', $format, is_array( $items ) ? count( $items ) : iterator_count( $items ) );
		}
	}

	if ( ! function_exists( 'WP_CLI\Utils\wp_clear_object_cache' ) ) {
		function wp_clear_object_cache() {
			// No-op: the real helper trims caches to bound long-running CLI memory.
		}
	}
}
