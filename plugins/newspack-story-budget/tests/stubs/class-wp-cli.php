<?php
/**
 * Minimal stand-in for WP-CLI, so tests can call the plugin's CLI commands.
 *
 * WP-CLI is not loaded under PHPUnit. This stub covers the calls the budget
 * commands make: error() throws instead of exiting, and output is dropped.
 *
 * @package Newspack_Story_Budget
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FunctionComment.Missing

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * WP-CLI stub.
	 */
	class WP_CLI {
		public static function error( $message ) {
			throw new \RuntimeException( is_string( $message ) ? $message : 'WP_CLI::error' );
		}

		public static function warning( $message ) {}

		public static function log( $message ) {}

		public static function success( $message ) {}
	}
}
