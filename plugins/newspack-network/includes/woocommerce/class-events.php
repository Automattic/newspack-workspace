<?php
/**
 * Newspack Network Data Listeners for woocommerce.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce;

use Newspack\Data_Events;
use Newspack_Network\Woocommerce_Memberships\Admin as Memberships_Admin;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Class to register additional listeners to the Newspack Data Events API
 */
class Events {

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_listeners' ] );
	}

	/**
	 * Register the listeners to the Newspack Data Events API
	 *
	 * @return void
	 */
	public static function register_listeners() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}

		Data_Events::register_listener( 'woocommerce_order_status_changed', 'newspack_node_order_changed', [ __CLASS__, 'item_changed' ] );
		Data_Events::register_listener( 'woocommerce_subscription_status_changed', 'newspack_node_subscription_changed', [ __CLASS__, 'subscription_changed' ] );
		Data_Events::register_listener( 'newspack_network_save_product', 'newspack_network_product_updated', [ __CLASS__, 'product_updated' ] );
	}

	/**
	 * Triggers a data event when a product's Network ID is updated.
	 *
	 * @param int $product_id The product post ID.
	 * @return array|void
	 */
	public static function product_updated( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$network_id = get_post_meta( $product->get_id(), Product_Admin::NETWORK_ID_META_KEY, true );

		$result = [
			'id'         => $product->get_id(),
			'network_id' => $network_id,
			'name'       => $product->get_name(),
			'slug'       => $product->get_slug(),
		];

		// Include variation IDs so they are also mapped to this Network ID.
		if ( $product->is_type( 'variable-subscription' ) ) {
			$result['variation_ids'] = $product->get_children();
		}

		return $result;
	}

	/**
	 * Callback for the Data Events API listeners
	 *
	 * @param int    $item_id     The Subscription or Order ID.
	 * @param string $status_from The status before the change.
	 * @param string $status_to   The status after the change.
	 * @param object $item        The Subscription or Order object.
	 * @return array
	 */
	public static function item_changed( $item_id, $status_from, $status_to, $item ) {
		$relationship = 'normal';
		if ( function_exists( 'wcs_order_contains_subscription' ) ) {
			if ( wcs_order_contains_subscription( $item_id, 'renewal' ) ) {
				$relationship = 'renewal';
			} elseif ( wcs_order_contains_subscription( $item_id, 'resubscribe' ) ) {
				$relationship = 'resubscribe';
			} elseif ( wcs_order_contains_subscription( $item_id, 'parent' ) ) {
				$relationship = 'parent';
			}
		}
		$result = [
			'id'                        => $item_id,
			'user_id'                   => $item->get_customer_id(),
			'user_name'                 => '',
			'email'                     => $item->get_billing_email(),
			'status_before'             => $status_from,
			'status_after'              => $status_to,
			'formatted_total'           => wp_strip_all_tags( $item->get_formatted_order_total() ),
			'payment_count'             => method_exists( $item, 'get_payment_count' ) ? $item->get_payment_count() : 1,
			'subscription_relationship' => $relationship,
			'currency'                  => $item->get_currency(),
			'total'                     => wc_format_decimal( $item->get_total(), 2 ),
			'payment_method_title'      => $item->get_payment_method_title(),
			'date_created'              => wc_rest_prepare_date_response( $item->get_date_created() ),
		];
		$user   = $item->get_user();
		if ( $user ) {
			$result['user_name'] = $user->display_name;
		}

		return $result;
	}

	/**
	 * Callback for the Data Events API listeners
	 *
	 * @param int    $item_id     The Subscription ID.
	 * @param string $status_from The status before the change.
	 * @param string $status_to   The status after the change.
	 * @param object $item        The Subscription object.
	 * @return array
	 */
	public static function subscription_changed( $item_id, $status_from, $status_to, $item ) {

		$result = self::item_changed( $item_id, $status_from, $status_to, $item );

		$result['start_date'] = $item->get_date( 'start_date' );
		$result['trial_end_date'] = $item->get_date( 'trial_end_date' );
		$result['next_payment_date'] = $item->get_date( 'next_payment_date' );
		$result['last_payment_date'] = $item->get_date( 'last_order_date_created' );
		$result['end_date'] = $item->get_date( 'end_date' );
		$result['products'] = [];

		$items = $item->get_items();
		foreach ( $items as $item ) {
			$product = $item->get_product();
			$result['products'][ $product->get_id() ] = [
				'id'   => $product->get_id(),
				'name' => $product->get_name(),
				'slug' => $product->get_slug(),
			];
		}

		return $result;
	}
}
