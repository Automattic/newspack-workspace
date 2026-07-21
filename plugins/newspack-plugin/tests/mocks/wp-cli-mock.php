<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FileComment.Missing

/**
 * Minimal WP_CLI stub for tests exercising CLI code paths under PHPUnit.
 *
 * The real WP_CLI is not loaded in the test environment; only the logging
 * surface the batch sync/pull drivers touch is stubbed. error() throws so
 * tests can assert pre-flight failures.
 */
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function log( $message ) {}
		public static function line( $message = '' ) {}
		public static function success( $message ) {}
		public static function error( $message ) {
			throw new \Exception( esc_html( $message ) );
		}
	}
}
