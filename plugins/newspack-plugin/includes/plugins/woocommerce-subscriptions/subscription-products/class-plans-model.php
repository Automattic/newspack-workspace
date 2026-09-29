<?php
/**
 * WooCommerce Subscriptions subscription plans.
 *
 * @package Newspack
 */

namespace Newspack\Subscription_Products;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription plans on ordinary products (WooCommerce Subscriptions 9.0+, or the
 * standalone All Products for Subscriptions plugin).
 *
 * The only Newspack code that knows the plans API. A plan-based product is a plain
 * simple or variable product; whether it sells as a subscription depends on its plan
 * configuration, and until a plan is chosen its `_subscription_*` data does not exist,
 * so nothing here reads that data from a catalog product.
 *
 * @internal Only Newspack\Subscription_Products calls this.
 */
final class Plans_Model {
	/**
	 * Whether the plans API is loaded.
	 */
	public static function is_available(): bool {
		return class_exists( '\WCS_ATT_Product_Schemes' ) && method_exists( '\WCS_ATT_Product_Schemes', 'has_subscription_schemes' );
	}

	/**
	 * Whether the product sells on at least one plan.
	 *
	 * The legacy check is deliberate belt and braces: WooCommerce excludes legacy types
	 * from plans today, but if that changed, this path would start rewriting the price,
	 * frequency and cart request of every legacy subscription on the site.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function applies_to( \WC_Product $product ): bool {
		if ( ! self::is_available() || Legacy_Model::applies_to( $product ) ) {
			return false;
		}
		if ( ! $product->is_type( [ 'simple', 'variable', 'variation' ] ) ) {
			return false;
		}
		$source = self::plan_source( $product );
		if ( ! $source || 'yes' === $source->get_meta( '_wcsatt_disabled' ) ) {
			return false;
		}
		return (bool) \WCS_ATT_Product_Schemes::has_subscription_schemes( $source );
	}

	/**
	 * The purchase options a product's plans offer: one, or one per variation.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return Purchase_Option[]
	 */
	public static function get_options( \WC_Product $product ): array {
		if ( ! $product->is_type( 'variable' ) ) {
			return self::options_for( $product );
		}
		$options = [];
		foreach ( $product->get_children() as $child_id ) {
			$child = \wc_get_product( $child_id );
			if ( $child instanceof \WC_Product ) {
				$options = array_merge( $options, self::options_for( $child ) );
			}
		}
		return $options;
	}

	/**
	 * Candidate product IDs; the caller confirms each with applies_to().
	 *
	 * Plans can come from the store-wide list (an option, not product data), so no
	 * single query is exact. Products with any plan configuration are candidates,
	 * including `_wcsatt_storewide_selection_mode`, the pre-9.0 meta key WooCommerce
	 * still falls back to for "inherit" when `_wcsatt_schemes_status` was never
	 * written. When a site changes the default mode away from "one-time only",
	 * products with no plan data at all can sell on plans, so every product becomes
	 * a candidate.
	 *
	 * @param string[] $statuses Post statuses.
	 * @return int[]
	 */
	public static function find_product_ids( array $statuses ): array {
		if ( ! self::is_available() ) {
			return [];
		}
		$query = [
			'post_type'      => 'product',
			'post_status'    => $statuses,
			'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- Catalog-scale, memoized per request by the caller.
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];
		if ( ! self::default_mode_offers_plans() ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Narrows candidates before per-product confirmation; memoized per request.
			$query['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => '_wcsatt_schemes_status',
					'value'   => [ 'override', 'inherit' ],
					'compare' => 'IN',
				],
				[
					'key'     => '_wcsatt_storewide_selection_mode',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_wcsatt_schemes',
					'compare' => 'EXISTS',
				],
			];
		}
		return array_map( 'intval', get_posts( $query ) );
	}

	/**
	 * A new instance with the plan applied, so WooCommerce prices and bills it as that plan.
	 * A plan is runtime state on the object, and with WooCommerce's product instance
	 * caching on, wc_get_product() hands every caller the same object: the clone is what
	 * keeps two plans from sharing one, which would price one as the other.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $plan_key   Plan key.
	 */
	public static function with_plan( int $product_id, string $plan_key ): ?\WC_Product {
		if ( ! self::is_available() ) {
			return null;
		}
		$product = \wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		$product = clone $product;
		\WCS_ATT_Product_Schemes::set_subscription_scheme( $product, $plan_key );
		return $product;
	}

	/**
	 * The plan applied to an instance, or '' when none.
	 *
	 * @param \WC_Product $product Product instance.
	 */
	public static function get_active_plan_key( \WC_Product $product ): string {
		if ( ! self::is_available() || ! method_exists( '\WCS_ATT_Product_Schemes', 'get_subscription_scheme' ) ) {
			return '';
		}
		$key = \WCS_ATT_Product_Schemes::get_subscription_scheme( $product );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * The plan a line item was bought on, or '' when none. Given the item's product,
	 * WooCommerce maps a key stored in an older spelling onto the plan the product
	 * defines now; without it, an item bought under standalone All Products for
	 * Subscriptions never matches a current plan. The product is fetched by ID, not
	 * with `$item->get_product()`, which re-enters this lookup through
	 * `woocommerce_order_item_product`.
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 */
	public static function get_item_plan_key( \WC_Order_Item_Product $item ): string {
		if ( ! class_exists( '\WCS_ATT_Order' ) || ! method_exists( '\WCS_ATT_Order', 'get_subscription_scheme' ) ) {
			return '';
		}
		$args       = [];
		$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		$product    = $product_id ? \wc_get_product( $product_id ) : null;
		if ( $product instanceof \WC_Product ) {
			$args['product'] = $product;
		}
		$key = \WCS_ATT_Order::get_subscription_scheme( $item, $args );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * The plan a request posted for a product, or '' when it posted none or chose a
	 * one-time purchase. A variation's plan is posted on its parent's field, which is
	 * the ID WooCommerce reads it with at add-to-cart.
	 *
	 * @param int $product_id Product ID, or a variation's parent ID.
	 */
	public static function get_posted_plan_key( int $product_id ): string {
		if ( ! self::is_available() || ! method_exists( '\WCS_ATT_Product_Schemes', 'get_posted_subscription_scheme' ) ) {
			return '';
		}
		$key = \WCS_ATT_Product_Schemes::get_posted_subscription_scheme( $product_id );
		return is_string( $key ) && '' !== $key && '0' !== $key ? $key : '';
	}

	/**
	 * Options for a simple product or a single variation.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return Purchase_Option[]
	 */
	private static function options_for( \WC_Product $product ): array {
		$options   = [];
		$parent_id = (int) $product->get_parent_id();
		if ( ! \WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product ) ) {
			$options[] = new Purchase_Option(
				[
					'key'        => 'one_time',
					'kind'       => Purchase_Option::KIND_ONE_TIME,
					'product_id' => (int) $product->get_id(),
					'parent_id'  => $parent_id,
				]
			);
		}
		foreach ( (array) \WCS_ATT_Product_Schemes::get_subscription_schemes( $product ) as $scheme ) {
			$plan_key  = (string) $scheme->get_key();
			$options[] = new Purchase_Option(
				[
					'key'          => 'plan:' . $plan_key,
					'kind'         => Purchase_Option::KIND_PLAN,
					'product_id'   => (int) $product->get_id(),
					'parent_id'    => $parent_id,
					'plan_key'     => $plan_key,
					'period'       => (string) $scheme->get_period(),
					'interval'     => max( 1, (int) $scheme->get_interval() ),
					'length'       => (int) $scheme->get_length(),
					'trial_period' => (string) $scheme->get_trial_period(),
					'trial_length' => (int) $scheme->get_trial_length(),
					'sign_up_fee'  => (float) $scheme->get_signup_fee(),
				]
			);
		}
		return $options;
	}

	/**
	 * Plans are configured on the parent of a variable product.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	private static function plan_source( \WC_Product $product ): ?\WC_Product {
		if ( ! $product->is_type( 'variation' ) ) {
			return $product;
		}
		$parent = \wc_get_product( $product->get_parent_id() );
		return $parent instanceof \WC_Product ? $parent : null;
	}

	/**
	 * Whether products with no plan configuration sell on plans by default.
	 */
	private static function default_mode_offers_plans(): bool {
		if ( ! class_exists( '\WCS_ATT_Product' ) || ! method_exists( '\WCS_ATT_Product', 'get_default_subscription_scheme_mode' ) ) {
			return false;
		}
		return 'disable' !== \WCS_ATT_Product::get_default_subscription_scheme_mode();
	}
}
