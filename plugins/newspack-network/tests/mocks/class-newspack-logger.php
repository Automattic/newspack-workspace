<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Mock of newspack-plugin's Logger.
 *
 * Newspack-plugin is not loaded in this test env, and this plugin skips
 * `newspack_log()` when the Logger is missing, so nothing would be recorded.
 * Captures `newspack_log()` calls for tests to assert on. `log()` and `error()`
 * are no-ops: the real ones write only when NEWSPACK_LOG_LEVEL is set, which
 * tests never do.
 *
 * @package Newspack_Network_Hub
 */

namespace Newspack;

if ( ! class_exists( Logger::class ) ) {
	/**
	 * Capturing Logger stand-in.
	 */
	class Logger {
		/**
		 * Captured newspack_log() calls.
		 *
		 * @var array
		 */
		public static $entries = [];

		/**
		 * Capture an always-on log entry.
		 *
		 * @param string $code    The log code.
		 * @param string $message The message.
		 * @param array  $data    Additional data.
		 * @param string $type    The log type.
		 */
		public static function newspack_log( $code, $message, $data = [], $type = 'error' ) {
			self::$entries[] = compact( 'code', 'message', 'data', 'type' );
		}

		/**
		 * No-op.
		 *
		 * @param string $payload The payload.
		 * @param string $header  The header.
		 */
		public static function log( $payload, $header = 'NEWSPACK' ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		/**
		 * No-op.
		 *
		 * @param string $payload The payload.
		 * @param string $header  The header.
		 */
		public static function error( $payload, $header = 'NEWSPACK' ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	}
}
