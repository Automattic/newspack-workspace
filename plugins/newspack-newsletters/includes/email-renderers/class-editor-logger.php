<?php
/**
 * Logger for the WooCommerce Email Editor package.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

use Automattic\WooCommerce\EmailEditor\Engine\Logger\Default_Email_Editor_Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the package's warnings and errors in debug.log and drops its routine messages.
 *
 * The package's default logger writes every level whenever WP_DEBUG_LOG is on, and the
 * editor logs four info lines on every request while it boots. On a site with debug
 * logging enabled, that buries everything else in the log.
 */
class Editor_Logger extends Default_Email_Editor_Logger {
	/**
	 * Levels that are not written.
	 */
	const SKIPPED_LEVELS = [ self::NOTICE, self::INFO, self::DEBUG ];

	/**
	 * Write the entry unless its level is skipped.
	 *
	 * Every level method on the parent routes through here, so this is the only override needed.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 */
	public function log( string $level, string $message, array $context = [] ): void {
		if ( in_array( $level, self::SKIPPED_LEVELS, true ) ) {
			return;
		}
		parent::log( $level, $message, $context );
	}
}
