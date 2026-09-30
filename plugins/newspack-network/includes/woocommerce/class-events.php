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

		Data_Events::register_listener( 'woocommerce_order_status_changed', 'newspack_node_order_changed', [ __CLASS__, 'order_changed' ] );
		// Trashing and deleting an order don't go through the status hook, so the hub's copy
		// would keep a trashed paid order granting access. The WooCommerce hooks cover the
		// order tables and the order data store; the post hooks cover the classic orders
		// screen, which trashes, restores and deletes order posts directly. Restoring from
		// the order tables saves the order, which fires the status hook.
		Data_Events::register_listener( 'woocommerce_trash_order', 'newspack_node_order_changed', [ __CLASS__, 'order_trashed' ] );
		Data_Events::register_listener( 'woocommerce_before_delete_order', 'newspack_node_order_changed', [ __CLASS__, 'order_deleted' ] );
		Data_Events::register_listener( 'trashed_post', 'newspack_node_order_changed', [ __CLASS__, 'order_post_trashed' ] );
		Data_Events::register_listener( 'untrashed_post', 'newspack_node_order_changed', [ __CLASS__, 'order_post_untrashed' ] );
		Data_Events::register_listener( 'before_delete_post', 'newspack_node_order_changed', [ __CLASS__, 'order_post_deleted' ] );
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
	 * Callback for the order Data Events API listener.
	 *
	 * @param int       $item_id     The Order ID.
	 * @param string    $status_from The status before the change.
	 * @param string    $status_to   The status after the change.
	 * @param \WC_Order $order       The Order object.
	 * @return array
	 */
	public static function order_changed( $item_id, $status_from, $status_to, $order ) {
		$result             = self::item_changed( $item_id, $status_from, $status_to, $order );
		$result['products'] = self::get_order_products( $order );
		return $result;
	}

	/**
	 * Callback for the order trashed listener.
	 *
	 * @param int $order_id The Order ID.
	 * @return array|null
	 */
	public static function order_trashed( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return null;
		}
		return self::order_changed( $order_id, '', 'trash', $order );
	}

	/**
	 * Callback for the order deleted listener, which fires just before deletion.
	 *
	 * @param int       $order_id The Order ID.
	 * @param \WC_Order $order    The Order object.
	 * @return array|null
	 */
	public static function order_deleted( $order_id, $order = null ) {
		if ( ! $order || ! is_object( $order ) || 'shop_order' !== $order->get_type() ) {
			return null;
		}
		return self::order_changed( $order_id, $order->get_status(), 'trash', $order );
	}

	/**
	 * Callback for a trashed post, for orders stored as posts.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function order_post_trashed( $post_id ) {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return null;
		}
		return self::order_trashed( $post_id );
	}

	/**
	 * Callback for a restored post, for orders stored as posts. Fires after the restore.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function order_post_untrashed( $post_id ) {
		if ( 'shop_order' !== get_post_type( $post_id ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			return null;
		}
		return self::order_changed( $post_id, 'trash', $order->get_status(), $order );
	}

	/**
	 * Callback for a post about to be deleted, for orders stored as posts.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function order_post_deleted( $post_id ) {
		if ( 'shop_order' !== get_post_type( $post_id ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		return self::order_deleted( $post_id, wc_get_order( $post_id ) );
	}

	/**
	 * An order's line items, so the hub can tell which products a reader bought.
	 *
	 * Items whose product no longer exists are left out: whether they were
	 * subscriptions can't be told, and counting a renewal as a one-time purchase
	 * would grant access nobody sold.
	 *
	 * @param \WC_Order $order The order.
	 * @return array[] Each with id (the parent, for a variation), variation_id, name and subscription.
	 */
	public static function get_order_products( $order ) {
		$products = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$products[] = [
				'id'           => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'name'         => $item->get_name(),
				'subscription' => self::is_subscription_product( $product ),
			];
		}
		return $products;
	}

	/**
	 * Whether a product is a subscription product, including a variation of one.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	private static function is_subscription_product( $product ) {
		if ( class_exists( '\WC_Subscriptions_Product' ) ) {
			return \WC_Subscriptions_Product::is_subscription( $product );
		}
		return $product->is_type( [ 'subscription', 'variable-subscription', 'subscription_variation' ] );
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
		$result['products'] = self::get_subscription_products( $item );

		return $result;
	}

	/**
	 * A subscription's products, keyed by ID.
	 *
	 * Line items whose product was deleted are left out rather than failing the event.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return array[] Each with id, name and slug.
	 */
	public static function get_subscription_products( $subscription ) {
		$products = [];
		foreach ( $subscription->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$products[ $product->get_id() ] = [
				'id'   => $product->get_id(),
				'name' => $product->get_name(),
				'slug' => $product->get_slug(),
			];
		}
		return $products;
	}
}
