<?php
/**
 * Stand-in for the WP-CLI helpers the command classes call, for the test suite.
 *
 * @package Newspack_Network
 */

namespace WP_CLI\Utils;

if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
	/**
	 * Read an associative argument, falling back to a default when it was not passed.
	 *
	 * @param array  $assoc_args    The associative arguments.
	 * @param string $flag          The flag name.
	 * @param mixed  $default_value Value to return when the flag is absent.
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $default_value = null ) {
		return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default_value;
	}
}
