<?php
/**
 * Redirection plugin integration.
 *
 * Forces the third-party Redirection plugin's redirect/404 logging off
 * (with a code-level escape hatch) and optionally suppresses its hit counter.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Redirection {
	/**
	 * Whether Redirection's redirect/404 logging is enabled.
	 *
	 * Default: false (Newspack forces logging off). Precedence:
	 * constant NEWSPACK_REDIRECTION_LOGGING_ENABLED wins, then the
	 * `newspack_redirection_logging_enabled` filter, then the default.
	 *
	 * @return bool
	 */
	public static function is_logging_enabled() {
		if ( defined( 'NEWSPACK_REDIRECTION_LOGGING_ENABLED' ) ) {
			return (bool) NEWSPACK_REDIRECTION_LOGGING_ENABLED;
		}
		/**
		 * Filters whether the Redirection plugin's redirect/404 logging is enabled.
		 *
		 * @param bool $enabled Default false (Newspack forces logging off).
		 */
		return (bool) apply_filters( 'newspack_redirection_logging_enabled', false );
	}

	/**
	 * Whether Redirection's per-redirect hit counter is enabled.
	 *
	 * Default: true (left unchanged). Precedence:
	 * constant NEWSPACK_REDIRECTION_HIT_TRACKING_ENABLED wins, then the
	 * `newspack_redirection_hit_tracking_enabled` filter, then the default.
	 *
	 * @return bool
	 */
	public static function is_hit_tracking_enabled() {
		if ( defined( 'NEWSPACK_REDIRECTION_HIT_TRACKING_ENABLED' ) ) {
			return (bool) NEWSPACK_REDIRECTION_HIT_TRACKING_ENABLED;
		}
		/**
		 * Filters whether the Redirection plugin's per-redirect hit counter is enabled.
		 *
		 * @param bool $enabled Default true (left unchanged).
		 */
		return (bool) apply_filters( 'newspack_redirection_hit_tracking_enabled', true );
	}
}
