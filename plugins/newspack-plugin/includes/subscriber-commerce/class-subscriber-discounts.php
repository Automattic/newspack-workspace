<?php
/**
 * Subscriber discounts — the rule store.
 *
 * A subscriber discount says "subscribers of subscription X get $/% off store
 * products Y". This class owns the rules and the global settings, plus the
 * discount arithmetic every other surface shares (the price filters, the admin
 * preview, the migration report).
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriber discount rules and settings.
 */
class Subscriber_Discounts {

	/**
	 * Option holding the discount rules.
	 */
	const OPTION_NAME = 'newspack_subscriber_discounts';

	/**
	 * Option holding the global discount settings.
	 */
	const SETTINGS_OPTION_NAME = 'newspack_subscriber_discounts_settings';

	/**
	 * Discount types a rule may use.
	 */
	const DISCOUNT_TYPES = [ 'fixed', 'percent' ];

	/**
	 * Default global settings.
	 *
	 * @var array
	 */
	const DEFAULT_SETTINGS = [
		// Matches WooCommerce Memberships, whose own switch is an *exclusion*
		// defaulting to off — so a store that has never touched the setting
		// discounts products that are already on sale. Defaulting the other way
		// would make a new site quietly stingier than the plugin it replaces.
		'apply_on_sale'     => true,
		// Whether a subscription sitting in the cart already counts, so a reader
		// buying a subscription and a discounted product together sees the
		// subscriber price before they have checked out. Off in Memberships too.
		'apply_at_checkout' => false,
	];

	/**
	 * Memoized rules for this request, or null when nothing is memoized.
	 *
	 * @var array[]|null
	 */
	private static $rules_memo = null;

	/**
	 * Memoized settings for this request, or null when nothing is memoized.
	 *
	 * @var array|null
	 */
	private static $settings_memo = null;

	/**
	 * Discard the memos whenever either option is written, including by a
	 * caller that goes straight to the options API.
	 */
	public static function init() {
		foreach ( [ 'added_option', 'updated_option', 'deleted_option' ] as $option_write_hook ) {
			add_action( $option_write_hook, [ __CLASS__, 'flush_cache_for_option' ] );
		}
	}

	/**
	 * Flush the memos when the written option is one of this class's.
	 *
	 * @param string $option Option that was written.
	 */
	public static function flush_cache_for_option( $option ) {
		if ( in_array( $option, [ self::OPTION_NAME, self::SETTINGS_OPTION_NAME ], true ) ) {
			self::flush_dependent_caches();
		}
	}

	/**
	 * Every stored rule, newest first.
	 *
	 * Memoized: the price filters read this once per product on a shop archive,
	 * and the map-and-sort below is otherwise redone every time.
	 *
	 * @return array[]
	 */
	public static function get_rules() {
		if ( null !== self::$rules_memo ) {
			return self::$rules_memo;
		}

		$stored_rules = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $stored_rules ) ) {
			self::$rules_memo = [];
			return [];
		}

		$rules = array_values( array_map( [ __CLASS__, 'fill_defaults' ], array_filter( $stored_rules, 'is_array' ) ) );
		usort(
			$rules,
			function ( $a, $b ) {
				return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			}
		);

		self::$rules_memo = $rules;

		return $rules;
	}

	/**
	 * Rules that are currently in effect.
	 *
	 * @return array[]
	 */
	public static function get_active_rules() {
		return array_values(
			array_filter(
				self::get_rules(),
				function ( $rule ) {
					return ! empty( $rule['active'] );
				}
			)
		);
	}

	/**
	 * A single rule by id.
	 *
	 * @param string $id Rule id.
	 * @return array|null
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
	 * Create or update a rule.
	 *
	 * @param array $rule Rule data.
	 * @return array|\WP_Error The saved rule, or an error when it is not valid.
	 */
	public static function save_rule( $rule ) {
		$sanitized_rule = self::sanitize_rule( $rule );
		if ( is_wp_error( $sanitized_rule ) ) {
			return $sanitized_rule;
		}

		$stored_rules = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $stored_rules ) ) {
			$stored_rules = [];
		}

		$replaced = false;
		foreach ( $stored_rules as $index => $stored_rule ) {
			if ( is_array( $stored_rule ) && isset( $stored_rule['id'] ) && $stored_rule['id'] === $sanitized_rule['id'] ) {
				$stored_rules[ $index ] = $sanitized_rule;
				$replaced               = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$stored_rules[] = $sanitized_rule;
		}

		update_option( self::OPTION_NAME, array_values( $stored_rules ) );
		self::flush_dependent_caches();

		return $sanitized_rule;
	}

	/**
	 * Discard everything memoized from the rules or settings.
	 *
	 * Anything that priced a product earlier in this request did so against the
	 * previous configuration.
	 */
	private static function flush_dependent_caches() {
		self::$rules_memo    = null;
		self::$settings_memo = null;
		Subscriber_Discounts_Pricing::flush_cache();
		Product_Targeting::flush_cache();
	}

	/**
	 * Delete a rule.
	 *
	 * @param string $id Rule id.
	 * @return bool Whether a rule was removed.
	 */
	public static function delete_rule( $id ) {
		$stored_rules = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $stored_rules ) ) {
			return false;
		}
		$remaining_rules = array_values(
			array_filter(
				$stored_rules,
				function ( $rule ) use ( $id ) {
					return ! is_array( $rule ) || ! isset( $rule['id'] ) || $rule['id'] !== $id;
				}
			)
		);
		if ( count( $remaining_rules ) === count( $stored_rules ) ) {
			return false;
		}
		update_option( self::OPTION_NAME, $remaining_rules );
		self::flush_dependent_caches();
		return true;
	}

	/**
	 * Pause or resume a rule without discarding its configuration.
	 *
	 * @param string $id     Rule id.
	 * @param bool   $active Whether the rule should apply.
	 * @return array|\WP_Error|null The updated rule, or null when it does not exist.
	 */
	public static function set_rule_active( $id, $active ) {
		$rule = self::get_rule( $id );
		if ( ! $rule ) {
			return null;
		}
		$rule['active'] = (bool) $active;
		return self::save_rule( $rule );
	}

	/**
	 * Global settings, with defaults filled in.
	 *
	 * Memoized for the same reason as the rules: the price path asks for these
	 * several times per product.
	 *
	 * @return array
	 */
	public static function get_settings() {
		if ( null !== self::$settings_memo ) {
			return self::$settings_memo;
		}

		$stored_settings = get_option( self::SETTINGS_OPTION_NAME, [] );
		if ( ! is_array( $stored_settings ) ) {
			$stored_settings = [];
		}
		$settings = array_merge( self::DEFAULT_SETTINGS, $stored_settings );

		self::$settings_memo = [
			'apply_on_sale'     => (bool) $settings['apply_on_sale'],
			'apply_at_checkout' => (bool) $settings['apply_at_checkout'],
		];

		return self::$settings_memo;
	}

	/**
	 * Update settings, merging with what is already stored so a caller that
	 * knows about one setting cannot clear the others.
	 *
	 * @param array $settings Settings to change.
	 * @return array The full settings after the update.
	 */
	public static function save_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}
		update_option( self::SETTINGS_OPTION_NAME, array_merge( self::get_settings(), $settings ) );
		self::flush_dependent_caches();
		return self::get_settings();
	}

	/**
	 * The price a subscriber pays under a rule.
	 *
	 * Returns null when the rule cannot lower the price — a free product, or an
	 * adjustment that leaves the price unchanged. Callers treat null as "this
	 * rule does not apply", so a rule can never produce a fake sale price at the
	 * original amount.
	 *
	 * @param float $base_price Price before the discount.
	 * @param array $rule       Rule providing `discount_type` and `amount`.
	 * @return float|null
	 */
	public static function discounted_price( $base_price, $rule ) {
		$base_price = (float) $base_price;
		if ( $base_price <= 0 ) {
			return null;
		}

		$amount = (float) ( $rule['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return null;
		}

		$discounted_price = 'percent' === ( $rule['discount_type'] ?? '' )
			? $base_price * ( 1 - min( $amount, 100 ) / 100 )
			: $base_price - $amount;

		$discounted_price = max( 0, $discounted_price );

		// Match WooCommerce's own rounding so a discounted price can never render
		// with more precision than the store's currency format, and so anything
		// recomputing the same percentage through WooCommerce's helpers lands on
		// the same cent. WooCommerce rounds half away from zero.
		$decimals         = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$discounted_price = round( $discounted_price, $decimals, PHP_ROUND_HALF_UP );

		if ( $discounted_price >= $base_price ) {
			return null;
		}

		return (float) $discounted_price;
	}

	/**
	 * The price a subscriber pays once every rule that covers a product has been
	 * taken into account.
	 *
	 * Overlapping rules never accumulate: the single largest reduction wins, and
	 * each rule is measured against the catalog price rather than against
	 * whatever a previous rule left behind. Cumulative stacking is deliberately
	 * absent — it is WooCommerce Memberships' default only because the off
	 * switch is a code filter with no settings screen, it compounds rather than
	 * adds (two 20% rules take 36% off, not 40%), and the Pricing Rules engine
	 * this feature is designed to hand over to cannot express it either.
	 *
	 * @param float $base_price Price before any discount.
	 * @param array $rules      Rules that cover the product.
	 * @return float|null Null when no rule lowers the price.
	 */
	public static function combined_price( $base_price, $rules ) {
		$base_price = (float) $base_price;
		if ( empty( $rules ) || $base_price <= 0 ) {
			return null;
		}

		$best_price = null;
		foreach ( $rules as $rule ) {
			$candidate_price = self::discounted_price( $base_price, $rule );
			if ( null !== $candidate_price && ( null === $best_price || $candidate_price < $best_price ) ) {
				$best_price = $candidate_price;
			}
		}

		return $best_price;
	}

	/**
	 * Validate and normalize a rule.
	 *
	 * @param array $rule Raw rule data.
	 * @return array|\WP_Error
	 */
	public static function sanitize_rule( $rule ) {
		if ( ! is_array( $rule ) ) {
			return new \WP_Error( 'newspack_subscriber_discount_invalid_rule', __( 'Invalid discount rule.', 'newspack-plugin' ) );
		}

		$subscription_product_ids = self::sanitize_ids( $rule['subscription_product_ids'] ?? [] );
		if ( empty( $subscription_product_ids ) ) {
			return new \WP_Error(
				'newspack_subscriber_discount_no_audience',
				__( 'Choose which subscription’s subscribers get this discount.', 'newspack-plugin' )
			);
		}

		$targeting            = $rule['targeting'] ?? '';
		$valid_targeting_modes = [
			Product_Targeting::TARGETING_PRODUCTS,
			Product_Targeting::TARGETING_CATEGORY,
			Product_Targeting::TARGETING_ALL,
		];
		if ( ! in_array( $targeting, $valid_targeting_modes, true ) ) {
			return new \WP_Error(
				'newspack_subscriber_discount_invalid_targeting',
				__( 'Choose whether this discount applies to specific products, a category, or all products.', 'newspack-plugin' )
			);
		}

		$discount_type = $rule['discount_type'] ?? '';
		if ( ! in_array( $discount_type, self::DISCOUNT_TYPES, true ) ) {
			return new \WP_Error(
				'newspack_subscriber_discount_invalid_type',
				__( 'Choose a fixed amount or a percentage.', 'newspack-plugin' )
			);
		}

		$amount = (float) ( $rule['amount'] ?? 0 );
		if ( $amount <= 0 || ( 'percent' === $discount_type && $amount > 100 ) ) {
			return new \WP_Error(
				'newspack_subscriber_discount_invalid_amount',
				'percent' === $discount_type
					? __( 'Enter a percentage between 0 and 100.', 'newspack-plugin' )
					: __( 'Enter an amount greater than zero.', 'newspack-plugin' )
			);
		}

		$base_rule = Subscriber_Commerce::sanitize_base_rule(
			array_merge(
				$rule,
				[
					// A newly created discount is live immediately; the shared
					// sanitizer reads a missing flag as paused, so state it.
					'active' => isset( $rule['active'] ) ? (bool) $rule['active'] : true,
				]
			)
		);

		// Only the fields belonging to the selected targeting mode are kept, so a
		// rule the publisher re-pointed in the editor cannot keep matching through
		// selections they can no longer see.
		$base_rule['product_ids']          = Product_Targeting::TARGETING_PRODUCTS === $targeting ? $base_rule['product_ids'] : [];
		$base_rule['category_ids']         = Product_Targeting::TARGETING_CATEGORY === $targeting ? $base_rule['category_ids'] : [];
		$base_rule['excluded_product_ids'] = Product_Targeting::TARGETING_PRODUCTS === $targeting ? [] : $base_rule['excluded_product_ids'];

		if ( Product_Targeting::TARGETING_PRODUCTS === $targeting && empty( $base_rule['product_ids'] ) ) {
			return new \WP_Error(
				'newspack_subscriber_discount_no_products',
				__( 'Select at least one product for this discount.', 'newspack-plugin' )
			);
		}
		if ( Product_Targeting::TARGETING_CATEGORY === $targeting && empty( $base_rule['category_ids'] ) ) {
			return new \WP_Error(
				'newspack_subscriber_discount_no_categories',
				__( 'Select at least one category for this discount.', 'newspack-plugin' )
			);
		}

		if ( empty( $base_rule['id'] ) ) {
			$base_rule['id'] = Subscriber_Commerce::generate_rule_id();
		}

		return array_merge(
			$base_rule,
			[
				'discount_type' => $discount_type,
				'amount'        => $amount,
			]
		);
	}

	/**
	 * Fill a stored rule with defaults so consumers never null-check the shape.
	 *
	 * @param array $rule Stored rule.
	 * @return array
	 */
	private static function fill_defaults( $rule ) {
		return array_merge(
			[
				'id'                       => '',
				'subscription_product_ids' => [],
				'targeting'                => 'products',
				'product_ids'              => [],
				'category_ids'             => [],
				'excluded_product_ids'     => [],
				'discount_type'            => 'fixed',
				'amount'                   => 0.0,
				'active'                   => true,
				'created_at'               => '',
			],
			$rule
		);
	}

	/**
	 * Normalize a list of post/term ids: positive integers, unique, re-indexed.
	 *
	 * @param mixed $ids Raw ids.
	 * @return int[]
	 */
	private static function sanitize_ids( $ids ) {
		if ( ! is_array( $ids ) ) {
			return [];
		}
		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids ),
					function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
	}
}

Subscriber_Discounts::init();
