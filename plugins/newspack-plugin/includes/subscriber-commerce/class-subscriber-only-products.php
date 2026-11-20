<?php
/**
 * Newspack Subscriber Commerce - subscriber-only products.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for subscriber-only product restrictions.
 *
 * A restriction says "these store products are purchasable only by subscribers
 * of any of these subscriptions". A "Purchase" restriction leaves the product
 * and its price visible and blocks only the purchase; a "Purchase and
 * visibility" one also hides the product from everyone else. These replace
 * WooCommerce Memberships' product "purchase" and "view" restrictions.
 *
 * Rules are a handful per site with no per-rule content, so they live in a
 * single option rather than a post type: one read, no meta queries.
 */
class Subscriber_Only_Products {

	/**
	 * Option holding the restrictions.
	 */
	const OPTION_NAME = 'newspack_subscriber_product_restrictions';

	/**
	 * Option holding the feature's settings.
	 */
	const SETTINGS_OPTION_NAME = 'newspack_subscriber_product_restrictions_settings';

	/**
	 * Restriction types. "view" is shown to publishers as "Purchase and
	 * visibility": hiding a product also stops its purchase.
	 */
	const RESTRICTION_TYPE_PURCHASE = 'purchase';
	const RESTRICTION_TYPE_VIEW     = 'view';

	/**
	 * What the product page of a hidden product does for a reader who can't see
	 * it: keep the page and show only the notice, answer 404, or redirect.
	 */
	const PRODUCT_PAGE_HIDE_CONTENT = 'hide_content';
	const PRODUCT_PAGE_HIDE         = 'hide';
	const PRODUCT_PAGE_REDIRECT     = 'redirect';

	/**
	 * Get every restriction, newest first.
	 *
	 * @return array[] The restrictions.
	 */
	public static function get_rules(): array {
		$rules = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $rules ) ) {
			return [];
		}
		// Rows without an ID can't be matched, updated or deleted, so they're
		// dropped at the boundary rather than warning on every front-end request.
		$rules = array_filter(
			$rules,
			function ( $rule ) {
				return is_array( $rule ) && ! empty( $rule['id'] );
			}
		);
		// A rule without a type is a "Purchase" restriction.
		return array_values(
			array_map(
				function ( $rule ) {
					$rule['restriction_type'] = self::sanitize_restriction_type( $rule['restriction_type'] ?? '' );
					return $rule;
				},
				$rules
			)
		);
	}

	/**
	 * Sanitize a restriction: the fields every subscriber-commerce rule shares,
	 * plus the restriction type.
	 *
	 * @param array $rule The rule.
	 *
	 * @return array The sanitized rule.
	 */
	public static function sanitize_rule( array $rule ): array {
		$sanitized                     = Subscriber_Commerce::sanitize_base_rule( $rule );
		$sanitized['restriction_type'] = self::sanitize_restriction_type( $rule['restriction_type'] ?? '' );
		return $sanitized;
	}

	/**
	 * Absent or unrecognized reads as "Purchase", the narrower restriction:
	 * garbage must never be what hides a product.
	 *
	 * @param mixed $restriction_type The stored or submitted type.
	 *
	 * @return string
	 */
	private static function sanitize_restriction_type( $restriction_type ): string {
		return self::RESTRICTION_TYPE_VIEW === $restriction_type ? self::RESTRICTION_TYPE_VIEW : self::RESTRICTION_TYPE_PURCHASE;
	}

	/**
	 * Get the restrictions that are enforced right now.
	 *
	 * A restriction naming no subscription names no way in, which would make
	 * its products unbuyable by everyone. That is far more likely to be a
	 * half-finished rule than an intent to withdraw the products from sale, so
	 * it is skipped — the same fail-open reading the content gate takes. A rule
	 * set to "all subscriptions" names its audience through the mode, so an
	 * empty list there is the rule working as written.
	 *
	 * @return array[] The active, enforceable restrictions.
	 */
	public static function get_active_rules(): array {
		return array_values(
			array_filter(
				self::get_rules(),
				function ( $rule ) {
					return ! empty( $rule['active'] ) && Subscriber_Commerce::has_audience( $rule );
				}
			)
		);
	}

	/**
	 * Get a restriction by ID.
	 *
	 * @param string $id The rule ID.
	 *
	 * @return array|null The rule, or null if there is none.
	 */
	public static function get_rule( $id ) {
		foreach ( self::get_rules() as $rule ) {
			if ( $rule['id'] === $id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Create or update a restriction.
	 *
	 * @param array $rule The rule. Without an ID, a new one is created.
	 *
	 * @return array The saved rule.
	 */
	public static function save_rule( array $rule ): array {
		$sanitized = self::sanitize_rule( $rule );
		if ( empty( $sanitized['id'] ) ) {
			$sanitized['id'] = Subscriber_Commerce::generate_rule_id();
		}

		$rules   = self::get_rules();
		$updated = false;
		foreach ( $rules as $index => $existing ) {
			if ( $existing['id'] === $sanitized['id'] ) {
				// Keep the original creation date: saving a rule doesn't re-create it.
				$sanitized['created_at'] = $existing['created_at'] ?? $sanitized['created_at'];
				$rules[ $index ]         = $sanitized;
				$updated                 = true;
				break;
			}
		}
		if ( ! $updated ) {
			array_unshift( $rules, $sanitized );
		}

		self::update_rules( $rules );
		return $sanitized;
	}

	/**
	 * Delete a restriction.
	 *
	 * @param string $id The rule ID.
	 *
	 * @return bool Whether a rule was deleted.
	 */
	public static function delete_rule( $id ): bool {
		$rules     = self::get_rules();
		$remaining = array_values(
			array_filter(
				$rules,
				function ( $rule ) use ( $id ) {
					return $rule['id'] !== $id;
				}
			)
		);
		if ( count( $remaining ) === count( $rules ) ) {
			return false;
		}
		self::update_rules( $remaining );
		return true;
	}

	/**
	 * Persist the rules and drop the caches keyed on them.
	 *
	 * @param array[] $rules The rules.
	 */
	private static function update_rules( array $rules ): void {
		update_option( self::OPTION_NAME, $rules );
		Product_Targeting::flush_cache();
		Product_Purchase_Restriction::flush_cache();
	}

	/**
	 * Get the feature's settings.
	 *
	 * @return array The settings.
	 */
	public static function get_settings(): array {
		$settings = get_option( self::SETTINGS_OPTION_NAME, [] );
		return self::sanitize_settings( is_array( $settings ) ? $settings : [] );
	}

	/**
	 * Sanitize the feature's settings.
	 *
	 * @param array $settings The settings.
	 *
	 * @return array The sanitized settings.
	 */
	private static function sanitize_settings( array $settings ): array {
		$product_page = $settings['product_page'] ?? '';
		if ( ! in_array( $product_page, [ self::PRODUCT_PAGE_HIDE_CONTENT, self::PRODUCT_PAGE_HIDE, self::PRODUCT_PAGE_REDIRECT ], true ) ) {
			// The default, and the one that never changes what a URL answers.
			$product_page = self::PRODUCT_PAGE_HIDE_CONTENT;
		}
		return [
			// Off by default. Applies to "Purchase and visibility" products only:
			// a "Purchase" product is meant to be seen.
			'hide_from_product_lists' => ! empty( $settings['hide_from_product_lists'] ),
			'product_page'            => $product_page,
			'redirect_page_id'        => absint( $settings['redirect_page_id'] ?? 0 ),
		];
	}

	/**
	 * Whether "Purchase and visibility" products are left out of product lists
	 * for readers who can't see them.
	 *
	 * "Hide completely" hides them from lists too, as in WooCommerce
	 * Memberships, so a list never links to a product page that answers 404.
	 *
	 * @return bool
	 */
	public static function hides_from_product_lists(): bool {
		$settings = self::get_settings();
		return $settings['hide_from_product_lists'] || self::PRODUCT_PAGE_HIDE === $settings['product_page'];
	}

	/**
	 * Update the feature's settings.
	 *
	 * @param array $settings The settings.
	 *
	 * @return array The saved settings.
	 */
	public static function update_settings( array $settings ): array {
		$sanitized = self::sanitize_settings( $settings );
		update_option( self::SETTINGS_OPTION_NAME, $sanitized );
		// The hiding pass is memoized per request, so a settings change has to drop
		// it for the same reason a rule change does.
		Product_Purchase_Restriction::flush_cache();
		return $sanitized;
	}
}
