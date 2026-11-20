<?php
/**
 * Stand-ins for the WooCommerce functions that Events' event builders call,
 * which the suite doesn't load. Each is declared inside its guard because PHP
 * declares a top-level function at compile time.
 *
 * @package Newspack_Network
 */

if ( ! function_exists( 'wc_format_decimal' ) ) {
	/**
	 * Stand-in for wc_format_decimal(): the number as given.
	 *
	 * @param mixed $number   Number.
	 * @param mixed $decimals Decimal places.
	 * @return string
	 */
	function wc_format_decimal( $number, $decimals = false ) {
		return (string) $number;
	}
}

if ( ! function_exists( 'wc_rest_prepare_date_response' ) ) {
	/**
	 * Stand-in for wc_rest_prepare_date_response(): the date as ISO 8601.
	 *
	 * @param mixed $date Date.
	 * @return string|null
	 */
	function wc_rest_prepare_date_response( $date ) {
		return $date instanceof DateTimeInterface ? $date->format( 'Y-m-d\TH:i:s' ) : null;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * Stand-in for wc_get_order().
	 *
	 * Tests register orders by ID in `$GLOBALS['newspack_network_test_orders']`; any other
	 * ID loads as false, as a deleted order does.
	 *
	 * @param int $id Order ID.
	 * @return object|false
	 */
	function wc_get_order( $id ) {
		return $GLOBALS['newspack_network_test_orders'][ $id ] ?? false;
	}
}
