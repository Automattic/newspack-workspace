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
	 * Options for this request, by `<product ID>:<user ID>:<grace or strict>`: whether
	 * a product sold both ways keeps its one-time option depends on who is buying, and
	 * on whether the payment-recovery grace applies to the evaluation in progress. A
	 * product's own configuration, before any per-reader rule, is kept under
	 * `<product ID>:configured`.
	 *
	 * @var array<string, Purchase_Option[]>
	 */
	private static array $options = [];

	/**
	 * Verdicts of only_sells_as_subscription(), by
	 * `<product ID>:<plan key>:<user ID>:<grace or strict>`.
	 *
	 * @var array<string, bool>
	 */
	private static array $subscription_only = [];

	/**
	 * Cached find_products() results, for this request.
	 *
	 * @var array<string, \WC_Product[]>
	 */
	private static array $found = [];

	/**
	 * Whether options are being read as the product itself configures them.
	 *
	 * @var bool
	 */
	private static bool $reading_configuration = false;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_new_product', [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_update_product', [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_update_product_variation', [ __CLASS__, 'flush_cache' ] );
		Plans_Model::init();
	}

	/**
	 * Drop the per-request caches.
	 */
	public static function flush_cache(): void {
		self::$options           = [];
		self::$found             = [];
		self::$subscription_only = [];
		Plans_Model::flush_cache();
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
	 * type, and for an instance with a plan applied (a cart item on a chosen plan).
	 * A plan-based product reads false until a plan is applied, even one sold only
	 * on plans; a bare catalog instance has no plan applied yet.
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
	 * Whether the product can only ever be bought as a subscription: no purchase
	 * option is one-time, so a reader can never end up with a one-time purchase
	 * of it. True for every legacy subscription type (which never offers one) and
	 * for a plan-based product forced onto its plans, even on the bare catalog
	 * instance before any plan is chosen; false for a product sold both ways, a
	 * plain product, or anything the facade cannot resolve.
	 *
	 * @param \WC_Product|int|false $product Product, variation, or ID.
	 */
	public static function is_subscription_only( $product ): bool {
		$product = self::resolve( $product );
		if ( ! $product || ! self::offers_subscription( $product ) ) {
			return false;
		}
		foreach ( self::get_purchase_options( $product ) as $option ) {
			if ( Purchase_Option::KIND_ONE_TIME === $option->kind ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether buying this instance can only start a subscription: it is being bought
	 * as one (is_purchased_as_subscription()), or the product has no one-time option
	 * for the current reader (is_subscription_only()). A bare hybrid reads false and
	 * the same product with a plan applied reads true, so the verdict is remembered
	 * per product, plan and reader: price filters ask it on every price read.
	 *
	 * @param \WC_Product $instance Product instance.
	 */
	public static function only_sells_as_subscription( \WC_Product $instance ): bool {
		$key = implode( ':', [ $instance->get_id(), Plans_Model::get_active_plan_key( $instance ), get_current_user_id(), self::grace_segment() ] );
		if ( ! isset( self::$subscription_only[ $key ] ) ) {
			self::$subscription_only[ $key ] = self::is_purchased_as_subscription( $instance ) || self::is_subscription_only( $instance );
		}
		return self::$subscription_only[ $key ];
	}

	/**
	 * Whether the product's own configuration sells it both one-time and on a plan,
	 * whatever any per-reader rule withdraws for the current viewer. False for a
	 * legacy subscription (never sold one-time), a product sold only on plans, and a
	 * plain product.
	 *
	 * @param \WC_Product|int|false $product Product, variation, or ID.
	 */
	public static function is_sold_both_ways( $product ): bool {
		$product = self::resolve( $product );
		if ( ! $product || 'plans' !== self::model_of( $product ) ) {
			return false;
		}
		$key = $product->get_id() . ':configured';
		if ( ! isset( self::$options[ $key ] ) ) {
			self::$reading_configuration = true;
			try {
				self::$options[ $key ] = Plans_Model::get_options( self::stored( $product ) );
			} finally {
				self::$reading_configuration = false;
			}
		}
		$kinds = wp_list_pluck( self::$options[ $key ], 'kind' );
		return in_array( Purchase_Option::KIND_ONE_TIME, $kinds, true ) && in_array( Purchase_Option::KIND_PLAN, $kinds, true );
	}

	/**
	 * Whether the facade is reading a product's own configuration. A per-reader rule
	 * that changes what a product offers (by filtering whether it is forced onto its
	 * plans) must leave the answer alone while this is true.
	 */
	public static function is_reading_configuration(): bool {
		return self::$reading_configuration;
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
		$key = implode( ':', [ $product->get_id(), get_current_user_id(), self::grace_segment() ] );
		if ( ! isset( self::$options[ $key ] ) ) {
			$stored = self::stored( $product );
			switch ( self::model_of( $stored ) ) {
				case 'legacy':
					self::$options[ $key ] = Legacy_Model::get_options( $stored );
					break;
				case 'plans':
					self::$options[ $key ] = Plans_Model::get_options( $stored );
					break;
				default:
					self::$options[ $key ] = [];
			}
		}
		return self::$options[ $key ];
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
	public static function get_purchased_plan_key( \WC_Order_Item_Product $item ): string {
		return Plans_Model::is_available() ? Plans_Model::get_item_plan_key( $item ) : '';
	}

	/**
	 * The plan option the current request posted for the product going in the cart,
	 * or null when it posted none, chose a one-time purchase, or the product does not
	 * sell on plans. Posting nothing is not "no plan" to WooCommerce, which then falls
	 * back to the product's default plan; callers decide what that means for them.
	 *
	 * @param \WC_Product $target Simple product or variation being added.
	 */
	public static function get_posted_plan_option( \WC_Product $target ): ?Purchase_Option {
		if ( 'plans' !== self::model_of( $target ) ) {
			return null;
		}
		$field_id = $target->is_type( 'variation' ) ? (int) $target->get_parent_id() : (int) $target->get_id();
		$plan_key = Plans_Model::get_posted_plan_key( $field_id );
		if ( '' === $plan_key ) {
			return null;
		}
		foreach ( self::get_purchase_options( $target ) as $option ) {
			if ( Purchase_Option::KIND_PLAN === $option->kind && $option->plan_key === $plan_key && $option->product_id === (int) $target->get_id() ) {
				return $option;
			}
		}
		return null;
	}

	/**
	 * The payment-recovery segment of a per-reader memo key. A per-reader rule decides
	 * through Subscriber_Eligibility, whose verdict changes with the grace that
	 * Access_Rules::with_evaluation_context() swaps around each gate, so an answer
	 * reached in one context must not be served in the other.
	 */
	private static function grace_segment(): string {
		return Access_Rules::get_evaluation_context( 'payment_recovery_grace', true ) ? 'grace' : 'strict';
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
	 * The product as stored, rather than the instance a caller holds. WooCommerce keeps
	 * plan state on each instance, and a cart item restored for a renewal, resubscribe
	 * or retry carries its own, so an answer built from whichever instance was asked
	 * first would be served to every later caller.
	 *
	 * @param \WC_Product $product Product instance.
	 */
	private static function stored( \WC_Product $product ): \WC_Product {
		$stored = $product->get_id() ? self::resolve( (int) $product->get_id() ) : null;
		return $stored ? $stored : $product;
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
