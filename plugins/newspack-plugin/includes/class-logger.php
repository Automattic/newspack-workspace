<?php
/**
 * Logger.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
class Logger {
	/**
	 * A logger.
	 *
	 * @param mixed  $payload The payload to log.
	 * @param string $header Log message header.
	 * @param string $type Type of the message.
	 */
	public static function log( $payload, $header = 'NEWSPACK', $type = 'info' ) {
		/**
		 * Controls logging verbosity across Newspack plugins.
		 *
		 * Levels:
		 * - 0: Disabled (default)
		 * - 1: Basic logging
		 * - 2: Include caller function info
		 * - 3+: Include data payload
		 *
		 * @constant NEWSPACK_LOG_LEVEL
		 * @type     int
		 * @default  Logging disabled (0)
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_LOG_LEVEL', 2 );
		 */
		if ( ! defined( 'NEWSPACK_LOG_LEVEL' ) || 0 >= (int) NEWSPACK_LOG_LEVEL ) {
			return;
		}
		$caller = null;

		// Add information about the caller function, if log level is > 1.
		if ( 1 < NEWSPACK_LOG_LEVEL ) {
			try {
				$backtrace = debug_backtrace(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
				if ( 2 < count( $backtrace ) ) {
					$caller_frame = $backtrace[1];
					if ( stripos( $caller_frame['class'], 'Logger' ) !== false ) {
						// Logger was called by another *Logger class, let's move the backtrace one level up.
						if ( isset( $backtrace[2] ) ) {
							$caller_frame = $backtrace[2];
						}
					}
					$caller = $caller_frame['class'] . $caller_frame['type'] . $caller_frame['function'];
				}
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fail silently.
			}
		}

		$message       = 'string' === gettype( $payload ) ? $payload : wp_json_encode( $payload, JSON_PRETTY_PRINT );
		$caller_prefix = $caller ? "[$caller]" : '';
		$type_prefix   = 'info' != $type ? "[$type]" : '';

		error_log( self::get_pid() . '[' . $header . ']' . $caller_prefix . strtoupper( $type_prefix ) . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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

	/**
	 * A logger for errors.
	 *
	 * @param mixed  $payload The payload to log.
	 * @param string $header Log message header.
	 */
	public static function error( $payload, $header = 'NEWSPACK' ) {
		return self::log( $payload, $header, 'error' );
	}

	/**
	 * A logger for newspack manager logging.
	 *
	 * @param string $code      The log code (i.e. newspack_google_login).
	 * @param string $message   The message to log.
	 * @param array  $data      The data to log.
	 *      Optional. Additional parameters.
	 *      @type string $user_email The current users email address.
	 *      @type file   $file       The name of the file to write the local log to.
	 * @param string $type      The type of log. Defaults to 'error'.
	 * @param string $log_level The Log level.
	 */
	public static function newspack_log( $code, $message, $data = [], $type = 'error', $log_level = 2 ) {
		$email = wp_get_current_user()->user_email;
		if ( isset( $data['user_email'] ) ) {
			$email = $data['user_email'];
			unset( $data['user_email'] );
		}
		$file = $code;
		if ( isset( $data['file'] ) ) {
			$file = $data['file'];
			unset( $data['file'] );
		}
		$data = array_merge(
			$data,
			[
				// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : 'N/A',
				'referrer'   => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url( $_SERVER['HTTP_REFERER'] ) : 'N/A',
				// phpcs:enable
			]
		);
		do_action(
			'newspack_log',
			$code,
			$message,
			[
				'type'       => $type,
				'data'       => $data,
				'user_email' => (string) $email,
				'file'       => $file,
				'log_level'  => $log_level,
			]
		);
	}
}
