<?php
/**
 * WooCommerce's wc_clean(), for tests whose process doesn't load wc-mocks.php.
 *
 * @package Newspack\Tests
 */

if ( ! function_exists( 'wc_clean' ) ) {
	/**
	 * WooCommerce's wc_clean(), which this process doesn't otherwise load.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wc_clean( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mirrors WooCommerce's own function.
		if ( is_array( $value ) ) {
			return array_map( 'wc_clean', $value );
		}
		return is_scalar( $value ) ? sanitize_text_field( $value ) : $value;
	}
}
