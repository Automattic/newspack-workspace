<?php
/**
 * Newspack Network Debugger methods.
 *
 * @package Newspack
 */

namespace Newspack_Network;

/**
 * Class with basic debugger methods
 */
class Debugger {

	/**
	 * Logs a message to the error log
	 *
	 * @param mixed $message If not a string, will be printed with print_r.
	 * @return void
	 */
	public static function log( $message ) {
		/**
		 * Enables debug logging for Newspack Network operations.
		 * Logs are written to the error log.
		 *
		 * @constant NEWSPACK_NETWORK_DEBUG
		 * @type     bool
		 * @default  Debug logging disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NETWORK_DEBUG', true );
		 */
		if ( ! defined( 'NEWSPACK_NETWORK_DEBUG' ) || ! NEWSPACK_NETWORK_DEBUG ) {
			return;
		}
		$caller = debug_backtrace()[0]; //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$pid    = getmypid();
		if ( ! is_string( $message ) || ! is_int( $message ) ) {
			$message = print_r( $message, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}
		error_log( "[{$pid}] {$caller['file']}:{$caller['line']} {$message}" ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
