<?php // phpcs:ignoreFile
/**
 * Minimal WP-CLI mocks so CLI command methods can be exercised in unit tests,
 * where the real WP-CLI runtime is not loaded. Shared by every test that drives
 * a `Newspack\CLI\*` command method directly.
 *
 * The WP_CLI class records every emitted message on two surfaces so different
 * suites can assert in the style that fits them:
 *
 * - WP_CLI::$output   — flat rendered strings ('Warning: ...', 'Success: ...'),
 *                       convenient for whole-transcript substring assertions.
 * - WP_CLI::$messages — raw `[ level, message ]` pairs ('line', 'log',
 *                       'warning', 'success', and 'table' for format_items),
 *                       convenient for level-filtered assertions.
 *
 * WP_CLI::error() throws WP_CLI_Mock_Exception (an \Exception subclass) so
 * tests can assert an abort — real WP-CLI exits the process. Only the surface
 * used by the Newspack CLI classes is implemented.
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
			 * Every line emitted through the mock as a rendered string, in order.
			 *
			 * @var string[]
			 */
			public static $output = [];

			/**
			 * Every message emitted through the mock as a `[ level, message ]`
			 * pair, in order.
			 *
			 * @var array[]
			 */
			public static $messages = [];

			/**
			 * Level-keyed surfaces mirroring the sibling `wp-cli-mock.php` stub, so
			 * suites written against either mock run under this one — only one WP_CLI
			 * class can exist per process, so this file is the superset both requires
			 * resolve to.
			 *
			 * @var string[]
			 */
			public static $logs      = [];
			public static $successes = [];
			public static $warnings  = [];

			/**
			 * Structured rows recorded by the format_items() stub, one entry per
			 * call: [ 'format' => ..., 'items' => ..., 'fields' => ... ].
			 *
			 * @var array[]
			 */
			public static $tables = [];

			/**
			 * Exit code recorded by halt(), null when halt() was never called.
			 *
			 * @var int|null
			 */
			public static $halt_code = null;

			/**
			 * Clear recorded output. Call from a test's set_up().
			 */
			public static function reset() {
				self::$output    = [];
				self::$messages  = [];
				self::$logs      = [];
				self::$successes = [];
				self::$warnings  = [];
				self::$tables    = [];
				self::$halt_code = null;
			}

			/**
			 * Real WP_CLI::halt() exits the process; the mock records the code so
			 * callers must return immediately after calling it.
			 *
			 * @param int $code Exit code.
			 */
			public static function halt( $code = 0 ) {
				self::$halt_code = $code;
			}

			public static function line( $message = '' ) {
				self::$output[]   = (string) $message;
				self::$messages[] = [ 'line', (string) $message ];
				self::$logs[]     = (string) $message;
			}

			public static function log( $message ) {
				self::$output[]   = (string) $message;
				self::$messages[] = [ 'log', (string) $message ];
				self::$logs[]     = (string) $message;
			}

			public static function warning( $message ) {
				self::$output[]   = 'Warning: ' . $message;
				self::$messages[] = [ 'warning', (string) $message ];
				self::$warnings[] = (string) $message;
			}

			public static function success( $message ) {
				self::$output[]    = 'Success: ' . $message;
				self::$messages[]  = [ 'success', (string) $message ];
				self::$successes[] = (string) $message;
			}

			/**
			 * Real WP_CLI::confirm() prompts on STDIN and exits on anything but 'y';
			 * the mock records the question and returns, standing in for a "yes".
			 *
			 * @param string     $question   The yes/no question.
			 * @param array|null $assoc_args Named args (unused).
			 */
			public static function confirm( $question, $assoc_args = null ) {
				self::$output[]   = 'Confirm: ' . $question;
				self::$messages[] = [ 'confirm', (string) $question ];
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
		// isset(), not array_key_exists() — matching real WP-CLI's implementation.
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
		}
	}

	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			$items               = is_array( $items ) ? $items : iterator_to_array( $items );
			\WP_CLI::$output[]   = sprintf( '(%s: %d row(s))', $format, count( $items ) );
			\WP_CLI::$messages[] = [ 'table', wp_json_encode( array_values( $items ) ) ];
			\WP_CLI::$tables[]   = [
				'format' => $format,
				'items'  => $items,
				'fields' => $fields,
			];
		}
	}

	if ( ! function_exists( 'WP_CLI\Utils\wp_clear_object_cache' ) ) {
		function wp_clear_object_cache() {
			// No-op: the real helper trims caches to bound long-running CLI memory.
		}
	}
}
