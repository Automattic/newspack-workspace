<?php
/**
 * WooCommerce integration class.
 * https://wordpress.org/plugins/woocommerce
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class WooCommerce {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_action( 'wp_loaded', [ __CLASS__, 'disable_wc_author_archive_override' ] );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', [ __CLASS__, 'modify_shop_order_wc_rest_api_payload' ] );
		add_filter( 'woocommerce_create_pages', [ __CLASS__, 'use_shortcodes_for_cart_checkout' ] );
		add_filter( 'get_user_option_default_password_nag', [ __CLASS__, 'disable_default_password_nag' ] );
		add_filter( 'woocommerce_order_again_cart_item_data', [ __CLASS__, 'order_again_cart_item_data' ], 10, 3 );
	}

	/**
	 * Prevent WC from redirecting to shop page from author archives of users who are customers (wc_disable_author_archives_for_customers).
	 */
	public static function disable_wc_author_archive_override() {
		remove_action( 'template_redirect', 'wc_disable_author_archives_for_customers', 10 );
	}

	/**
	 * Remove Newspack internal-use meta data from /order REST API response.
	 * These might cause confusion and should not be used by third parties.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @return WP_REST_Response
	 */
	public static function modify_shop_order_wc_rest_api_payload( $response ) {
		$data = $response->get_data();
		if ( ! isset( $data['meta_data'] ) ) {
			return $response;
		}
		foreach ( $data['meta_data'] as $key => $value ) {
			if ( ! isset( $value->get_data()['value'] ) || ! is_string( $value->get_data()['value'] ) ) {
				continue;
			}
			if ( stripos( $value->get_data()['key'], '_newspack' ) === 0 ) {
				unset( $data['meta_data'][ $key ] );
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Override the default page contents when creating the WooCommerce Cart and Checkout pages.
	 *
	 * @param array $woocommerce_pages Defaults for WooCommerce pages created on install.
	 * @return array
	 */
	public static function use_shortcodes_for_cart_checkout( $woocommerce_pages ) {
		$woocommerce_pages['cart']['content'] = '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->';
		$woocommerce_pages['checkout']['content'] = '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->';

		return $woocommerce_pages;
	}

	/**
	 * Disable WC's password nag ("Your account with <site-title> is using a temporary password.
	 * We emailed you a link to change your password.").
	 *
	 * @param mixed $value User meta value.
	 */
	public static function disable_default_password_nag( $value ) {
		return false;
	}

	/**
	 * Flag reordered cart items.
	 *
	 * @param array     $cart_item_data The cart item data.
	 * @param array     $item           The cart item.
	 * @param \WC_Order $order          The order.
	 *
	 * @return array
	 */
	public static function order_again_cart_item_data( $cart_item_data, $item, $order ) {
		$cart_item_data['newspack_order_again'] = true;
		$cart_item_data['newspack_order_again_order_id'] = $order->get_id();
		return $cart_item_data;
	}
}
WooCommerce::init();
