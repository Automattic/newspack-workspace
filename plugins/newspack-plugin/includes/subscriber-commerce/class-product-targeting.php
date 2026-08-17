<?php
/**
 * Newspack Subscriber Commerce - product targeting.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves which products a subscriber-commerce rule covers. Single source of
 * truth for targeting semantics, shared by subscriber-only products and
 * subscriber discounts:
 *
 * - 'products': the products listed in `product_ids`. A variation is covered
 *   when it is listed itself or when its parent is.
 * - 'category': products in any of `category_ids`, cascading to child
 *   categories (`product_cat` is hierarchical, matching WooCommerce
 *   Memberships). Variations carry no terms, so they match through their
 *   parent's categories.
 * - 'all': every product.
 *
 * 'category' and 'all' honor `excluded_product_ids`: a product is excluded
 * when its own ID or its parent's ID is listed.
 */
class Product_Targeting {

	const TARGETING_PRODUCTS = 'products';
	const TARGETING_CATEGORY = 'category';
	const TARGETING_ALL      = 'all';

	/**
	 * The WooCommerce product category taxonomy.
	 */
	const PRODUCT_CATEGORY_TAXONOMY = 'product_cat';

	/**
	 * Matching rules per (rule-set, product), keyed by "{rules hash}:{product_id}".
	 * WooCommerce evaluates purchasability and prices many times per product per
	 * request (catalog loop, single template, cart), so the resolution is memoized.
	 *
	 * @var array<string, array>
	 */
	private static array $matching_rules = [];

	/**
	 * Category IDs expanded with their descendants, keyed by the hash of the
	 * requested IDs.
	 *
	 * @var array<string, int[]>
	 */
	private static array $expanded_categories = [];

	/**
	 * Namespace a per-request cache key to the current site.
	 *
	 * Product, term and user IDs are per-site: under switch_to_blog() term 5 on
	 * two sites would otherwise share an entry and return each other's answers.
	 *
	 * @param string $key The key.
	 *
	 * @return string
	 */
	private static function cache_key( string $key ): string {
		return get_current_blog_id() . ':' . $key;
	}

	/**
	 * Get the rules from a rule set that cover a product, memoized per request.
	 *
	 * Inactive rules are never returned.
	 *
	 * @param array           $rules   Rules shaped per Subscriber_Commerce::sanitize_base_rule().
	 * @param \WC_Product|int $product The product (or variation), or its ID.
	 *
	 * @return array The matching rules, in the order given.
	 */
	public static function get_matching_rules( array $rules, $product ): array {
		$product = $product instanceof \WC_Product ? $product : ( function_exists( 'wc_get_product' ) ? wc_get_product( $product ) : null );
		if ( ! $product instanceof \WC_Product || empty( $rules ) ) {
			return [];
		}

		$cache_key = self::cache_key( md5( wp_json_encode( $rules ) ) . ':' . $product->get_id() );
		if ( ! isset( self::$matching_rules[ $cache_key ] ) ) {
			self::$matching_rules[ $cache_key ] = array_values(
				array_filter(
					$rules,
					function ( $rule ) use ( $product ) {
						return ! empty( $rule['active'] ) && self::rule_covers_product( $rule, $product );
					}
				)
			);
		}
		return self::$matching_rules[ $cache_key ];
	}

	/**
	 * Whether a rule's targeting covers a product, ignoring the rule's active flag.
	 *
	 * @param array       $rule    The rule.
	 * @param \WC_Product $product The product (or variation).
	 *
	 * @return bool
	 */
	public static function rule_covers_product( array $rule, $product ): bool {
		$product_id = (int) $product->get_id();
		$parent_id  = (int) $product->get_parent_id();
		$targeting  = $rule['targeting'] ?? self::TARGETING_PRODUCTS;

		if ( self::TARGETING_PRODUCTS === $targeting ) {
			$targeted = array_map( 'intval', $rule['product_ids'] ?? [] );
			// A variation is covered when it is listed itself or when its parent is.
			return ! empty( array_intersect( array_filter( [ $product_id, $parent_id ] ), $targeted ) );
		}

		if ( self::is_excluded( $rule, $product_id, $parent_id ) ) {
			return false;
		}

		if ( self::TARGETING_ALL === $targeting ) {
			return true;
		}

		$category_ids = self::expand_category_ids( array_map( 'intval', $rule['category_ids'] ?? [] ) );
		if ( empty( $category_ids ) ) {
			return false;
		}

		// Product categories live on the parent product; a variation never carries them.
		$categorized_id = $parent_id ? $parent_id : $product_id;
		return (bool) has_term( $category_ids, self::PRODUCT_CATEGORY_TAXONOMY, $categorized_id );
	}

	/**
	 * Whether a product is excluded from a rule.
	 *
	 * Tests the product's own ID and its parent's, so excluding a variable
	 * product also excludes its variations. Note this does NOT reach the products
	 * sold under a grouped product: excluding a grouped container is a no-op on
	 * its children, which are standalone products in their own right. Whether
	 * that should change is a product decision (see PR #742 review) — until it is
	 * made, an exclusion means exactly the IDs listed and their variations.
	 *
	 * @param array $rule       The rule.
	 * @param int   $product_id The product (or variation) ID.
	 * @param int   $parent_id  The parent product ID, 0 for a non-variation.
	 *
	 * @return bool
	 */
	private static function is_excluded( array $rule, int $product_id, int $parent_id ): bool {
		$excluded = array_map( 'intval', $rule['excluded_product_ids'] ?? [] );
		return ! empty( array_intersect( array_filter( [ $product_id, $parent_id ] ), $excluded ) );
	}

	/**
	 * Expand category IDs to include their descendants, memoized per request.
	 *
	 * Without this, a rule covering "Premium" would leave every product filed
	 * under "Premium > Merch" out of the rule.
	 *
	 * @param int[] $category_ids The category IDs.
	 *
	 * @return int[] The category IDs including all descendants.
	 */
	public static function expand_category_ids( array $category_ids ): array {
		if ( empty( $category_ids ) ) {
			return [];
		}
		$cache_key = self::cache_key( md5( wp_json_encode( $category_ids ) ) );
		if ( ! isset( self::$expanded_categories[ $cache_key ] ) ) {
			$expanded = $category_ids;
			foreach ( $category_ids as $category_id ) {
				$children = get_term_children( $category_id, self::PRODUCT_CATEGORY_TAXONOMY );
				if ( ! is_wp_error( $children ) ) {
					$expanded = array_merge( $expanded, array_map( 'intval', $children ) );
				}
			}
			self::$expanded_categories[ $cache_key ] = array_values( array_unique( $expanded ) );
		}
		return self::$expanded_categories[ $cache_key ];
	}

	/**
	 * Flush the per-request caches. For tests and for callers that mutate rules
	 * or the category tree mid-request.
	 */
	public static function flush_cache(): void {
		self::$matching_rules      = [];
		self::$expanded_categories = [];
	}
}
