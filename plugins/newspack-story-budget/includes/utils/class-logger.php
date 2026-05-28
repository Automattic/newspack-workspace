<?php
/**
 * Newspack Story Budget - utility class for logging debug messages, warnings, and errors.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * Logger class.
 */
class Logger {
	/**
	 * Log a message or payload.
	 *
	 * @param any    $payload The message or payload to log.
	 * @param string $header Log message header.
	 * @param string $type Type of the message.
	 */
	public static function log( $payload, $header = 'NEWSPACK-STORY-BUDGET', $type = 'info' ) {
		if ( method_exists( 'Newspack\Logger', 'log' ) ) {
			return \Newspack\Logger::log( $payload, $header, $type );
		}
		$message    = 'string' === gettype( $payload ) ? $payload : wp_json_encode( $payload, JSON_PRETTY_PRINT );
		$type_prefix = 'info' != $type ? "[$type]" : '';

		error_log( self::get_pid() . '[' . $header . ']' . strtoupper( $type_prefix ) . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * A logger for errors.
	 *
	 * @param any    $payload The payload to log.
	 * @param string $header Log message header.
	 */
	public static function error( $payload, $header = 'NEWSPACK-STORY-BUDGET' ) {
		return self::log( $payload, $header, 'error' );
	}

	/**
	 * Get the current process ID and format it to the output in a way that keeps it aligned.
	 *
	 * @return string The process ID surrounded by brackets and followed by spaces to always match at least 7 characters.
	 */
	private static function get_pid() {
		$pid = '[' . getmypid() . ']';
		$len = strlen( $pid );
		while ( $len < 7 ) {
			$pid .= ' ';
			$len++;
		}
		return $pid;
	}
}
