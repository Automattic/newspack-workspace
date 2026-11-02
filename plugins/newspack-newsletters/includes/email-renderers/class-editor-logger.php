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
 * editor logs routine info and debug lines on every request while it boots. On a site with
 * debug logging enabled, that buries everything else in the log.
 *
 * When WooCommerce's block email editor is enabled, WooCommerce replaces this logger with
 * its own on `woocommerce_init`. Its threshold (also warning by default) then applies, and
 * entries go to the WooCommerce logs instead of debug.log.
 */
class Editor_Logger extends Default_Email_Editor_Logger {
	/**
	 * Routine levels, kept out so debug.log stays readable.
	 */
	private const SKIPPED_LEVELS = [ self::NOTICE, self::INFO, self::DEBUG ];

	/**
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
