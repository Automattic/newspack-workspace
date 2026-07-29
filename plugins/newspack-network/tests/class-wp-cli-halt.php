<?php
/**
 * Stand-in for WP_CLI::error()'s process halt, so tests can assert a command exits non-zero.
 *
 * @package Newspack_Network
 */

if ( ! class_exists( 'WP_CLI_Halt' ) ) {
	/**
	 * Thrown by the test WP_CLI shim in place of WP-CLI's fatal exit.
	 */
	class WP_CLI_Halt extends \Exception {}
}
