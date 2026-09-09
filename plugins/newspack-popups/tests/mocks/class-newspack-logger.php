<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Mock of newspack-plugin's Logger.
 *
 * Newspack-plugin is not loaded in this test env, so the always-on audit path
 * would otherwise fall through to the error log and be unobservable. Captures
 * `newspack_log()` calls; the level-gated methods are no-ops, since the other
 * callers only reach them behind NEWSPACK_LOG_LEVEL, which tests never set.
 *
 * @package Newspack_Popups
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
