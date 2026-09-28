<?php
/**
 * What Newspack knows about subscription products, under either product model.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Subscription_Products\Legacy_Model;
use Newspack\Subscription_Products\Plans_Model;
use Newspack\Subscription_Products\Purchase_Option;

defined( 'ABSPATH' ) || exit;

/**
 * The one place Newspack asks whether and how a product sells as a subscription.
 *
 * WooCommerce Subscriptions 9 sells subscriptions two ways: legacy subscription
 * product types, and ordinary products carrying subscription plans. Each product
 * belongs to exactly one model, and nothing outside the two model classes should
 * need to know which.
 */
final class Subscription_Products {
	/**
	 * Options by product ID, for this request.
	 *
	 * @var array<int, Purchase_Option[]>
	 */
	private static $options = [];

	/**
	 * Cached find_products() results, for this request.
	 *
	 * @var array<string, \WC_Product[]>
	 */
	private static $found = [];

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_new_product', [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_update_product', [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_update_product_variation', [ __CLASS__, 'flush_cache' ] );
	}

	/**
	 * Drop the per-request caches.
	 */
	public static function flush_cache() {
		self::$options = [];
		self::$found   = [];
	}

	/**
	 * Whether the product can be bought as a subscription, under either model.
	 *
	 * @param \WC_Product|int|false $product Product, variation, or ID.
	 */
	public static function offers_subscription( $product ): bool {
		$product = self::resolve( $product );
		return $product && '' !== self::model_of( $product );
	}

	/**
	 * Whether this instance is being bought as a subscription: true for a legacy
	 * type, for a cart item on a chosen plan, and for a product sold only on plans.
	 * A product sold both ways answers false until a plan is chosen.
	 *
	 * @param \WC_Product $product Product instance.
	 */
	public static function is_purchased_as_subscription( \WC_Product $product ): bool {
		if ( class_exists( 'WC_Subscriptions_Product' ) ) {
			return (bool) \WC_Subscriptions_Product::is_subscription( $product );
		}
		return $product->is_type( [ 'subscription', 'variable-subscription', 'subscription_variation' ] );
	}

	/**
	 * Every way a reader can buy the product. A variable product lists its variations'.
	 *
	 * @param \WC_Product|int|false $product Product, variation, or ID.
	 * @return Purchase_Option[]
	 */
	public static function get_purchase_options( $product ): array {
		$product = self::resolve( $product );
		if ( ! $product ) {
			return [];
		}
		$id = (int) $product->get_id();
		if ( ! isset( self::$options[ $id ] ) ) {
			switch ( self::model_of( $product ) ) {
				case 'legacy':
					self::$options[ $id ] = Legacy_Model::get_options( $product );
					break;
				case 'plans':
					self::$options[ $id ] = Plans_Model::get_options( $product );
					break;
				default:
					self::$options[ $id ] = [];
			}
		}
		return self::$options[ $id ];
	}

	/**
	 * Top-level products (not variations) that offer a subscription.
	 *
	 * @param array $args `status`: status or statuses; defaults to WooCommerce's
	 *                    product query default (draft, pending, private, publish).
	 * @return \WC_Product[]
	 */
	public static function find_products( array $args = [] ): array {
		$statuses = isset( $args['status'] ) ? (array) $args['status'] : [ 'draft', 'pending', 'private', 'publish' ];
		$cache    = implode( ',', $statuses );
		if ( isset( self::$found[ $cache ] ) ) {
			return self::$found[ $cache ];
		}
		$products = [];
		if ( class_exists( 'WC_Subscriptions_Product' ) && function_exists( 'wc_get_products' ) ) {
			foreach ( Legacy_Model::find_products( $statuses ) as $product ) {
				$products[ $product->get_id() ] = $product;
			}
		}
		if ( Plans_Model::is_available() ) {
			foreach ( Plans_Model::find_product_ids( $statuses ) as $id ) {
				$product = \wc_get_product( $id );
				if ( $product instanceof \WC_Product && 'plans' === self::model_of( $product ) ) {
					$products[ $id ] = $product;
				}
			}
		}
		self::$found[ $cache ] = array_values( $products );
		return self::$found[ $cache ];
	}

	/**
	 * The product instance to price and sell for an option: for a plan, a fresh
	 * instance with that plan applied, so WooCommerce prices and bills it as the plan.
	 *
	 * @param Purchase_Option $option Option.
	 */
	public static function get_option_product( Purchase_Option $option ): ?\WC_Product {
		if ( Purchase_Option::KIND_PLAN === $option->kind ) {
			return Plans_Model::with_plan( $option->product_id, (string) $option->plan_key );
		}
		$product = \wc_get_product( $option->product_id );
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * The option an instance currently represents: its plan when one is applied,
	 * its legacy option for a legacy product, else null.
	 *
	 * @param \WC_Product $instance Product instance.
	 */
	public static function get_instance_option( \WC_Product $instance ): ?Purchase_Option {
		$model = self::model_of( $instance );
		if ( 'legacy' === $model ) {
			foreach ( Legacy_Model::get_options( $instance ) as $option ) {
				if ( $option->product_id === (int) $instance->get_id() ) {
					return $option;
				}
			}
			return null;
		}
		if ( 'plans' === $model ) {
			$plan_key = Plans_Model::get_active_plan_key( $instance );
			foreach ( self::get_purchase_options( $instance ) as $option ) {
				if ( $plan_key && $option->plan_key === $plan_key ) {
					return $option;
				}
			}
		}
		return null;
	}

	/**
	 * The plan an order or subscription line item was bought on, or '' when none.
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 */
	public static function get_purchased_plan_key( $item ): string {
		return Plans_Model::is_available() ? Plans_Model::get_item_plan_key( $item ) : '';
	}

	/**
	 * Resolve a product, variation, or ID to a product instance.
	 *
	 * @param \WC_Product|int|false $product Product, variation, or ID.
	 */
	private static function resolve( $product ): ?\WC_Product {
		if ( is_numeric( $product ) && (int) $product > 0 && function_exists( 'wc_get_product' ) ) {
			$product = \wc_get_product( (int) $product );
		}
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Which model a product belongs to: 'legacy', 'plans', or '' for neither.
	 * Legacy is checked first; WooCommerce never applies plans to legacy types.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function model_of( \WC_Product $product ): string {
		if ( class_exists( 'WC_Subscriptions_Product' ) && Legacy_Model::applies_to( $product ) ) {
			return 'legacy';
		}
		if ( Plans_Model::applies_to( $product ) ) {
			return 'plans';
		}
		return '';
	}
}
Subscription_Products::init();
