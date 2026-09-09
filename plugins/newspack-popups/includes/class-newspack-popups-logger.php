<?php
/**
 * Newspack Popups Logger
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
class Newspack_Popups_Logger {
	/**
	 * A logger.
	 *
	 * @param string $payload The payload to log.
	 */
	public static function log( $payload ) {
		if ( ! defined( 'NEWSPACK_LOG_LEVEL' ) || 0 > (int) NEWSPACK_LOG_LEVEL || 'string' !== gettype( $payload ) ) {
			return;
		}

		$header = 'NEWSPACK-POPUPS';
		if ( class_exists( '\Newspack\Logger' ) ) {
			\Newspack\Logger::log( $payload, $header );
		} else {
			error_log( '[' . $header . ']: ' . $payload ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Record an event on the always-on log Newspack Manager consumes, rather
	 * than the level-gated one above. For anything that has to stay auditable
	 * fleet-wide on a site that never set NEWSPACK_LOG_LEVEL.
	 *
	 * Falls back to the error log so the record survives a site running
	 * Campaigns without newspack-plugin, where there is no Manager to receive it.
	 *
	 * @param string $code    The log code, e.g. `newspack_contextual_prompts`.
	 * @param string $message The message to log.
	 * @param array  $data    Additional data to record.
	 * @param string $type    The type of log. Defaults to 'info'.
	 */
	public static function audit_log( $code, $message, $data = [], $type = 'info' ) {
		if ( method_exists( '\Newspack\Logger', 'newspack_log' ) ) {
			\Newspack\Logger::newspack_log( $code, $message, $data, $type );
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			'[NEWSPACK-POPUPS]: ' . $message . ' ' . (string) wp_json_encode( $data )
		);
	}
}
