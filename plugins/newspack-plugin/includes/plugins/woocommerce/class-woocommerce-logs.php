<?php
/**
 * Log WooCommerce events
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Class for logging WooCommerce events.
 */
class WooCommerce_Logs {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_error', [ __CLASS__, 'log_error_notices' ] );
	}

	/**
	 * Log error notices.
	 *
	 * @param string $message The error message.
	 */
	public static function log_error_notices( $message ) {
		// Only log if there is a message.
		if ( empty( $message ) ) {
			return $message;
		}
		$data = [
			'wc_cart' => WC()->cart->get_cart_contents(),
		];
		Logger::newspack_log( 'newspack_woocommerce_error_notice', $message, $data );
		return $message;
	}
}
WooCommerce_Logs::init();
