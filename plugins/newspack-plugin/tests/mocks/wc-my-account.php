<?php
/**
 * WooCommerce account URL mock.
 *
 * @package Newspack\Tests
 */

if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
	/**
	 * Stub WooCommerce account URL helper for tests.
	 *
	 * @param string $endpoint Endpoint name.
	 *
	 * @return string
	 */
	function wc_get_account_endpoint_url( $endpoint ) {
		$default_url = 'https://example.com/my-account';
		return apply_filters( 'newspack_test_wc_account_url', $default_url, $endpoint );
	}
}
