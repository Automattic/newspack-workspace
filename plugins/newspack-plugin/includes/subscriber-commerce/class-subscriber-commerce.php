<?php
/**
 * Newspack Subscriber Commerce - shared infrastructure.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Shared infrastructure for subscriber-commerce features: rules that tie
 * WooCommerce store products to subscriptions (subscriber-only products,
 * subscriber discounts).
 *
 * Each feature stores its rules in its own option as an array of rule arrays.
 * Every rule carries the base fields:
 *
 *   id                       Unique rule ID (string).
 *   subscription_product_ids Subscription products whose subscribers the rule applies to.
 *   targeting                'products' | 'category' | 'all' (see Product_Targeting).
 *   product_ids              Targeted product IDs ('products' targeting).
 *   category_ids             Targeted product category IDs ('category' targeting).
 *   excluded_product_ids     Products excluded from 'category'/'all' targeting.
 *   active                   Whether the rule is enforced (an inactive rule is kept but ignored).
 *   created_at               Creation date, Y-m-d.
 *
 * Features extend this shape with their own fields.
 */
class Subscriber_Commerce {

	/**
	 * Whether subscriber-commerce rules can be configured.
	 *
	 * Independent of whether they are enforced yet: a site migrating off
	 * WooCommerce Memberships sets its rules up first and deactivates
	 * Memberships afterwards, so the admin has to be reachable while
	 * Memberships still owns the front end.
	 *
	 * @return bool
	 */
	public static function is_admin_available(): bool {
		return Content_Gate::is_newspack_feature_enabled() && function_exists( 'wc_get_product' );
	}

	/**
	 * Whether subscriber-commerce rules are enforced at all.
	 *
	 * Two things stand enforcement down, and they differ in what the publisher can
	 * still do about it:
	 *
	 * While WooCommerce Memberships is active it owns purchase restriction and
	 * member discounts, and enforcing ours on top would double-apply on a site
	 * mid-migration. The admin stays reachable throughout, because configuring
	 * the rules first and deactivating Memberships afterwards is the migration.
	 *
	 * Without Audience Management there is nowhere to send a reader who is
	 * refused. Registration, sign-in, account emails and My Account all belong
	 * to it, so a blocked purchase would leave the reader at a notice naming a
	 * subscription they have no way to buy. Content gates go inert in the same
	 * state ({@see Content_Gate::is_gating_active()}), and subscriber-commerce
	 * matches them rather than half-working alongside. Here the admin closes too:
	 * the Subscriptions screen shows the Audience Management prerequisite instead
	 * of its tabs, so there is nothing to author until the dependency is met.
	 *
	 * @return bool
	 */
	public static function is_enforcement_active(): bool {
		$active = self::is_admin_available() && Reader_Activation::is_enabled() && ! Memberships::is_active();

		/**
		 * Filters whether subscriber-commerce rules (subscriber-only products,
		 * subscriber discounts) are enforced.
		 *
		 * The filter can only turn enforcement *off*. Features read this as their
		 * licence to call WooCommerce APIs, so letting a filter answer yes on a
		 * site where WooCommerce is not loaded would turn a stand-down into a
		 * fatal — the escape hatch this exists for is the Memberships overlap,
		 * which the clamp leaves reachable.
		 *
		 * @param bool $active Whether enforcement is active.
		 */
		return $active && (bool) apply_filters( 'newspack_subscriber_commerce_enforcement_active', $active );
	}

	/**
	 * Sanitize a base rule. Feature callers sanitize their own extra fields and
	 * merge them over the returned array.
	 *
	 * @param array $rule The raw rule.
	 *
	 * @return array The rule with the base fields sanitized and defaulted.
	 */
	public static function sanitize_base_rule( array $rule ): array {
		$targeting = $rule['targeting'] ?? Product_Targeting::TARGETING_PRODUCTS;
		if ( ! in_array( $targeting, [ Product_Targeting::TARGETING_PRODUCTS, Product_Targeting::TARGETING_CATEGORY, Product_Targeting::TARGETING_ALL ], true ) ) {
			$targeting = Product_Targeting::TARGETING_PRODUCTS;
		}

		$sanitize_ids = function ( $ids ) {
			return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		};

		// A real date or today's — the value is displayed, so garbage like
		// "2026-13-99" must not reach the list.
		$created_at = '';
		if ( isset( $rule['created_at'] ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $rule['created_at'], $date_parts ) ) {
			if ( wp_checkdate( (int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1], $rule['created_at'] ) ) {
				$created_at = $rule['created_at'];
			}
		}

		// A rule with no ID is one nothing can address for edit or delete, so a
		// missing or unusable one is minted here rather than left for each caller
		// to notice. `active` is deliberately NOT defaulted the same way: absent
		// still reads as paused, and callers that mean "live on create" say so.
		// Flipping that would make a partial payload silently start enforcing,
		// which is the worse direction for a rule that gates a purchase or a price.
		$id = sanitize_key( $rule['id'] ?? '' );

		return [
			'id'                       => $id ? $id : self::generate_rule_id(),
			'subscription_product_ids' => $sanitize_ids( $rule['subscription_product_ids'] ?? [] ),
			'targeting'                => $targeting,
			'product_ids'              => $sanitize_ids( $rule['product_ids'] ?? [] ),
			'category_ids'             => $sanitize_ids( $rule['category_ids'] ?? [] ),
			'excluded_product_ids'     => $sanitize_ids( $rule['excluded_product_ids'] ?? [] ),
			'active'                   => ! empty( $rule['active'] ),
			'created_at'               => $created_at ? $created_at : gmdate( 'Y-m-d' ),
		];
	}

	/**
	 * Generate an ID for a new rule.
	 *
	 * @return string
	 */
	public static function generate_rule_id(): string {
		return wp_generate_uuid4();
	}
}
