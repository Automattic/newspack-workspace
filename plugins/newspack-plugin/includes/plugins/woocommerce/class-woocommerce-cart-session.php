<?php
/**
 * Recover from WooCommerce session carts that can no longer be read.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a session cart that no longer unserializes from breaking every request.
 *
 * A domain search-replace that rewrites a URL inside the cart nested in the
 * session row, without fixing the length declared before it, leaves the cart
 * unreadable. For a user with a saved-cart merge pending, which every login
 * sets, WC_Cart_Session::get_cart_from_session() then throws a TypeError from
 * array_merge(). WooCommerce clears the pending merge only after that call, so
 * without this reset the user hits the same fatal on every request.
 */
final class WooCommerce_Cart_Session {
	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		\add_action( 'woocommerce_load_cart_from_session', [ __CLASS__, 'reset_unreadable_cart' ] );
	}

	/**
	 * Remove a session cart that isn't an array, so WooCommerce treats the session as having no cart.
	 *
	 * Runs before WooCommerce reads the cart. The reset is saved with the
	 * session, so a broken session is logged when it is repaired rather than on
	 * every request.
	 */
	public static function reset_unreadable_cart(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$cart = WC()->session->get( 'cart', null );
		if ( null === $cart || is_array( $cart ) ) {
			return;
		}
		WC()->session->set( 'cart', null );
		Logger::newspack_log(
			'newspack_woocommerce_unreadable_session_cart',
			'Reset a WooCommerce session cart that could not be read.',
			[ 'value_type' => gettype( $cart ) ]
		);
	}
}
WooCommerce_Cart_Session::init();
