<?php
/**
 * Newspack Insights — Donation Product Classifier (NPPD-1616).
 *
 * Wraps the canonical `\Newspack\Donations::is_donation_product()` path
 * with an aggressive cache so Tab 6 (and future Tab 7) SQL queries can
 * thread a precomputed `:donation_product_ids` parameter into NOT IN
 * filters without re-running the per-product detection logic.
 *
 * `\Newspack\Donations::is_donation_product()` runs `get_post_meta()`
 * and `wc_get_product()` calls per call site; the metric layer needs
 * the entire donation product ID set up front, so the wrapper computes
 * and caches the full union of all three detection paths once per hour.
 *
 * Detection paths (from
 * `~/Sites/insights-docs/formulas/subscription-donation-schema.md`):
 *
 * - Path 1: Products with `_newspack_is_donation = 'yes'` postmeta
 *   (added v6.41.0, May 2026; nascent adoption as of June 2026).
 * - Path 2: Variations of Path 1 parents — variations inherit the
 *   flag. The schema doc's check is parent-flag-on-the-variation; here
 *   we expand to all variation IDs so SQL NOT IN filters cover them.
 * - Path 3: The canonical Newspack donation family — `grouped` parent
 *   ID from the `newspack_donation_product_id` option plus the three
 *   children (once / month / year). The universal path on all
 *   Newspack publishers today.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Donations;
use Newspack\WooCommerce_Products;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Cached union of donation product IDs across all three detection paths.
 */
class Donation_Product_Classifier {

	/**
	 * Cache key for the donation product ID set.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'newspack_insights_donation_product_ids';

	/**
	 * Cache TTL in seconds (1 hour). Donation products change rarely;
	 * caching aggressively reduces per-metric query overhead. Cache busts
	 * automatically on configured/flagged changes via
	 * {@see self::flush_cache()}; an unrelated change at TTL boundary
	 * gets picked up within the hour.
	 *
	 * @var int
	 */
	const TRANSIENT_TTL = HOUR_IN_SECONDS;

	/**
	 * Get all donation product IDs across all three detection paths.
	 *
	 * Cached for 1h. Returns an empty array on a fully-empty publisher
	 * (no flagged products and no canonical donation family configured).
	 *
	 * @return int[] Sorted, deduplicated integer product IDs.
	 */
	public static function get_donation_product_ids(): array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = self::compute_donation_product_ids();
		set_transient( self::TRANSIENT_KEY, $ids, self::TRANSIENT_TTL );
		return $ids;
	}

	/**
	 * Check whether a single product ID is in the donation set.
	 *
	 * Uses the cached set rather than per-call `Donations::is_donation_product()`.
	 * Suitable for hot loops (e.g. classifying a series of order line items).
	 *
	 * @param int $product_id Product ID to test.
	 * @return bool True if the product is in the donation set.
	 */
	public static function is_donation_product( int $product_id ): bool {
		return in_array( $product_id, self::get_donation_product_ids(), true );
	}

	/**
	 * Flush the cached donation product ID set.
	 *
	 * Wired by {@see self::register_hooks()} to the relevant Woo
	 * configuration changes (donation product option, flag postmeta).
	 * Also callable from the future NPPD-1605 cache-invalidation layer.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Register invalidation hooks. Called from
	 * {@see \Newspack\Insights_Section_Subscribers::register_hooks()}
	 * during the tab boot so the cache flushes immediately on relevant
	 * Woo configuration changes (rather than waiting up to 1h for the
	 * TTL to expire).
	 *
	 * Triggers:
	 *  - `update_option_newspack_donation_product_id` — fires when the
	 *    canonical Newspack donation parent ID is reconfigured.
	 *  - `added_post_meta` / `updated_post_meta` / `deleted_post_meta`
	 *    on the `_newspack_is_donation` flag — fires when a publisher
	 *    flips the manual donation flag on any product.
	 *
	 * The post_meta hooks fire on every meta change site-wide, so the
	 * callback filters by `$meta_key` and returns early on mismatches —
	 * the work done in `flush_cache()` is a single `delete_transient()`
	 * either way, but the early return avoids any function-call
	 * overhead on hot paths.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action(
			'update_option_' . Donations::DONATION_PRODUCT_ID_OPTION,
			[ self::class, 'flush_cache' ]
		);
		add_action(
			'added_post_meta',
			[ self::class, 'maybe_flush_on_meta_change' ],
			10,
			3
		);
		add_action(
			'updated_post_meta',
			[ self::class, 'maybe_flush_on_meta_change' ],
			10,
			3
		);
		add_action(
			'deleted_post_meta',
			[ self::class, 'maybe_flush_on_meta_change' ],
			10,
			3
		);
	}

	/**
	 * Meta-change callback. Filters to the donation flag key, ignores
	 * everything else. The four meta_id / meta_value args of the
	 * underlying hook are reduced to the three that matter for our
	 * filter.
	 *
	 * @param int    $meta_id  Meta ID (unused).
	 * @param int    $post_id  Post ID (unused — we don't need to know
	 *                         which product; the classifier recomputes
	 *                         from scratch).
	 * @param string $meta_key Meta key being changed.
	 * @return void
	 */
	public static function maybe_flush_on_meta_change( $meta_id, $post_id, $meta_key ): void {
		if ( WooCommerce_Products::DONATION_FLAG_META_KEY !== $meta_key ) {
			return;
		}
		self::flush_cache();
	}

	/**
	 * Compute the union of all three detection paths.
	 *
	 * @return int[]
	 */
	private static function compute_donation_product_ids(): array {
		$ids = [];

		// Path 3: canonical donation family (parent + once/month/year children).
		// This is the universal path on all Newspack publishers today and the
		// only path that fires before v6.41.0 adoption of the flag.
		//
		// Read the parent ID via the public option constant rather than the
		// private Donations::get_parent_donation_product() — the parent is a
		// grouped product that's never directly purchased, but we include it
		// anyway so any edge configuration that does record it is covered.
		if ( class_exists( Donations::class ) ) {
			$parent_id = (int) get_option( Donations::DONATION_PRODUCT_ID_OPTION, 0 );
			if ( $parent_id > 0 ) {
				$ids[] = $parent_id;
			}
			$children = Donations::get_donation_product_child_products_ids();
			foreach ( $children as $child_id ) {
				if ( $child_id ) {
					$ids[] = (int) $child_id;
				}
			}

			// Path 1: products manually flagged as donations via
			// `_newspack_is_donation` postmeta.
			$flagged = Donations::get_flagged_donation_product_ids();
			$ids     = array_merge( $ids, array_map( 'intval', (array) $flagged ) );
		}

		// Path 2: variations whose parents are in the union from paths 1 + 3.
		// Necessary because the order product lookup table records variation
		// product IDs, not parent IDs; a NOT IN filter using only parent IDs
		// would leak variation orders through.
		$parents = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! empty( $parents ) ) {
			global $wpdb;
			$parent_list = implode( ',', array_map( 'intval', $parents ) );
			$variations  = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'product_variation'
				  AND post_parent IN ($parent_list)"
			);
			$ids         = array_merge( $ids, array_map( 'intval', (array) $variations ) );
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		return $ids;
	}
}
