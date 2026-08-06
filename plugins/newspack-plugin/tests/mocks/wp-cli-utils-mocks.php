<?php
/**
 * Minimal `WP_CLI\Utils` stubs for testing CLI command code under PHPUnit.
 *
 * Companion to wp-cli-mock.php, which stubs the WP_CLI facade itself.
 *
 * @package Newspack\Tests
 */

namespace WP_CLI\Utils;

require_once __DIR__ . '/wp-cli-mock.php';

if ( ! function_exists( 'WP_CLI\Utils\wp_clear_object_cache' ) ) {
	/**
	 * No-op stand-in for the real cache flush (nothing accumulates in the mocks).
	 */
	function wp_clear_object_cache() {}
}

if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
	/**
	 * Read an associative arg, mirroring WP-CLI's own helper.
	 *
	 * @param array  $assoc_args    Associative args.
	 * @param string $flag          Flag name.
	 * @param mixed  $default_value Value when the flag is absent.
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $default_value = null ) {
		return $assoc_args[ $flag ] ?? $default_value;
	}
}

if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	/**
	 * Recording stand-in for the table renderer: tests assert on the rows, not the ASCII.
	 *
	 * @param string $format Output format.
	 * @param array  $items  Rows.
	 * @param array  $fields Columns.
	 */
	function format_items( $format, $items, $fields ) {
		\WP_CLI::$tables[] = [
			'format' => $format,
			'items'  => $items,
			'fields' => $fields,
		];
	}
}
