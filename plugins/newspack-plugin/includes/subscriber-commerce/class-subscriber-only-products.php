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
 * of any of these subscriptions". Readers still see the product and its price;
 * only the purchase is blocked. This replaces WooCommerce Memberships' product
 * purchase restriction.
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
		return array_values(
			array_filter(
				$rules,
				function ( $rule ) {
					return is_array( $rule ) && ! empty( $rule['id'] );
				}
			)
		);
	}

	/**
	 * Get the restrictions that are enforced right now.
	 *
	 * A restriction naming no subscription names no way in, which would make
	 * its products unbuyable by everyone. That is far more likely to be a
	 * half-finished rule than an intent to withdraw the products from sale, so
	 * it is skipped — the same fail-open reading the content gate takes.
	 *
	 * @return array[] The active, enforceable restrictions.
	 */
	public static function get_active_rules(): array {
		return array_values(
			array_filter(
				self::get_rules(),
				function ( $rule ) {
					return ! empty( $rule['active'] ) && ! empty( $rule['subscription_product_ids'] );
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
		$sanitized = Subscriber_Commerce::sanitize_base_rule( $rule );
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
		return [
			// Off by default: the parity feature blocks purchasing, and hiding a
			// product goes further than that. A publisher opts in.
			'hide_from_product_lists' => ! empty( $settings['hide_from_product_lists'] ),
		];
	}

	/**
	 * Update the feature's settings.
	 *
	 * @param array $settings The settings.
	 *
	 * @return array The saved settings.
	 */
	public static function update_settings( array $settings ): array {
		$sanitized = [ 'hide_from_product_lists' => ! empty( $settings['hide_from_product_lists'] ) ];
		update_option( self::SETTINGS_OPTION_NAME, $sanitized );
		// The hiding pass is memoized per request, so a settings change has to drop
		// it for the same reason a rule change does.
		Product_Purchase_Restriction::flush_cache();
		return $sanitized;
	}
}
