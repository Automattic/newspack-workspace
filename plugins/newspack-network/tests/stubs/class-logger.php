<?php
/**
 * Stand-in for the Newspack plugin's Logger.
 *
 * The suite loads this plugin without the Newspack plugin, so code that reports
 * through `\Newspack\Logger::newspack_log()` would otherwise take its fallback
 * path and leave nothing for a test to observe. This stub fires the same
 * `newspack_log` action with the same arguments as the real method. `log()` and
 * `error()` are no-ops, which is what the real ones do when NEWSPACK_LOG_LEVEL
 * is not defined, as in this suite.
 *
 * @package Newspack_Network
 */

namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Logger', false ) ) {
	/**
	 * Minimal Logger with the real class's public surface.
	 */
	class Logger {
		/**
		 * No-op, as the real method is without NEWSPACK_LOG_LEVEL.
		 *
		 * @param mixed  $payload Message.
		 * @param string $header  Header.
		 * @param string $type    Type.
		 */
		public static function log( $payload, $header = 'NEWSPACK', $type = 'info' ) {}

		/**
		 * No-op, as the real method is without NEWSPACK_LOG_LEVEL.
		 *
		 * @param mixed  $payload Message.
		 * @param string $header  Header.
		 */
		public static function error( $payload, $header = 'NEWSPACK' ) {}

		/**
		 * Fire the `newspack_log` action the way the real method does.
		 *
		 * @param string $code      Log code.
		 * @param string $message   Message.
		 * @param array  $data      Data.
		 * @param string $type      Type.
		 * @param int    $log_level Log level.
		 */
		public static function newspack_log( $code, $message, $data = [], $type = 'error', $log_level = 2 ) {
			do_action(
				'newspack_log',
				$code,
				$message,
				[
					'type'       => $type,
					'data'       => $data,
					'user_email' => (string) wp_get_current_user()->user_email,
					'file'       => $code,
					'log_level'  => $log_level,
				]
			);
		}
	}
}
