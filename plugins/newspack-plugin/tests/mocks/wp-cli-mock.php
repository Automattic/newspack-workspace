<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FileComment.Missing, Squiz.Commenting.VariableComment.Missing

/**
 * Minimal WP_CLI stub for tests exercising CLI code paths under PHPUnit.
 *
 * The real WP_CLI is not loaded in the test environment; only the logging
 * surface the batch sync/pull drivers touch is stubbed. Messages are recorded
 * so tests can assert CLI output — call reset() in set_up (or at the start of
 * the test) before asserting on $logs / $successes. error() throws so tests
 * can assert pre-flight failures.
 */
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static $logs      = [];
		public static $successes = [];

		public static function reset() {
			self::$logs      = [];
			self::$successes = [];
		}
		public static function log( $message ) {
			self::$logs[] = $message;
		}
		public static function line( $message = '' ) {
			self::$logs[] = $message;
		}
		public static function success( $message ) {
			self::$successes[] = $message;
		}
		public static function error( $message ) {
			throw new \Exception( esc_html( $message ) );
		}
	}
}
