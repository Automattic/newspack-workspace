<?php
/**
 * WooCommerce Subscriptions subscription plans.
 *
 * @package Newspack
 */

namespace Newspack\Subscription_Products;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription plans on ordinary products.
 *
 * Stub for Task 1: reports no plans anywhere, so the facade behaves as if only
 * legacy products exist. Task 2 fills this in with the real WCS_ATT_* integration.
 *
 * @internal Only Newspack\Subscription_Products calls this.
 */
final class Plans_Model {
	/**
	 * Whether the subscription plans API is present on this site.
	 */
	public static function is_available(): bool {
		return false;
	}

	/**
	 * Whether the product carries any subscription plans.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function applies_to( \WC_Product $product ): bool {
		return false;
	}

	/**
	 * The purchase options a product's plans offer.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return Purchase_Option[]
	 */
	public static function get_options( \WC_Product $product ): array {
		return [];
	}

	/**
	 * IDs of top-level products carrying subscription plans, in the given statuses.
	 *
	 * @param string[] $statuses Post statuses.
	 * @return int[]
	 */
	public static function find_product_ids( array $statuses ): array {
		return [];
	}

	/**
	 * A fresh product instance with the given plan applied.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $plan_key   Plan key.
	 */
	public static function with_plan( int $product_id, string $plan_key ): ?\WC_Product {
		return null;
	}

	/**
	 * The plan key currently active on a product instance, or '' when none.
	 *
	 * @param \WC_Product $product Product instance.
	 */
	public static function get_active_plan_key( \WC_Product $product ): string {
		return '';
	}

	/**
	 * The plan key a line item was bought on, or '' when none.
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 */
	public static function get_item_plan_key( $item ): string {
		return '';
	}
}
