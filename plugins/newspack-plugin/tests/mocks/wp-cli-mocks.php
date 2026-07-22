<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden, Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- Curly-brace namespace blocks are the only way to stub both the WP_CLI\Utils functions and the global WP_CLI class in one file.
/**
 * Minimal WP_CLI stubs for exercising CLI command classes under PHPUnit, where
 * the real WP_CLI runtime is not loaded. Shared by every test that drives a
 * `Newspack\CLI\*` command method directly. Output is captured in
 * `WP_CLI::$messages` as `[ level, message ]` pairs so tests can assert on the
 * reporting surface; `error()` throws, matching the real abort semantics.
 *
 * @package Newspack\Tests
 */

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
		}
	}
	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$messages[] = [ 'table', wp_json_encode( array_values( $items ) ) ];
		}
	}
}

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			public static $messages = [];

			public static function reset() {
				self::$messages = [];
			}
			public static function log( $message ) {
				self::$messages[] = [ 'log', (string) $message ];
			}
			public static function line( $message = '' ) {
				self::$messages[] = [ 'line', (string) $message ];
			}
			public static function success( $message ) {
				self::$messages[] = [ 'success', (string) $message ];
			}
			public static function warning( $message ) {
				self::$messages[] = [ 'warning', (string) $message ];
			}
			public static function error( $message ) {
				throw new \Exception( esc_html( $message ) );
			}
		}
	}
}
