<?php
/**
 * Subscriber discounts — the rule store.
 *
 * A subscriber discount says "subscribers of subscription X get $/% off store
 * products Y". This class owns the rules and the settings that govern how they
 * combine, plus the discount arithmetic every other surface shares (the price
 * filters, the admin preview, the migration report).
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
	 * Targeting modes a rule may use.
	 */
	const TARGETING_MODES = [ 'products', 'category', 'all' ];

	/**
	 * Discount types a rule may use.
	 */
	const DISCOUNT_TYPES = [ 'fixed', 'percent' ];

	/**
	 * Default global settings.
	 *
	 * `overlap` is 'best' rather than WooCommerce Memberships' cumulative
	 * behaviour: over-discounting is the costlier mistake for a publisher, and
	 * migrated sites that relied on stacking are switched to 'combine'
	 * deliberately during migration.
	 *
	 * @var array
	 */
	const DEFAULT_SETTINGS = [
		'overlap'       => 'best',
		'apply_on_sale' => false,
	];

	/**
	 * Every stored rule, newest first.
	 *
	 * @return array[]
	 */
	public static function get_rules() {
		$stored_rules = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $stored_rules ) ) {
			return [];
		}
		$rules = array_values( array_map( [ __CLASS__, 'fill_defaults' ], array_filter( $stored_rules, 'is_array' ) ) );
		usort(
			$rules,
			function ( $a, $b ) {
				return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			}
		);
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

		return $sanitized_rule;
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
	 * @return array
	 */
	public static function get_settings() {
		$stored_settings = get_option( self::SETTINGS_OPTION_NAME, [] );
		if ( ! is_array( $stored_settings ) ) {
			$stored_settings = [];
		}
		$settings = array_merge( self::DEFAULT_SETTINGS, $stored_settings );

		return [
			'overlap'       => in_array( $settings['overlap'], [ 'best', 'combine' ], true ) ? $settings['overlap'] : 'best',
			'apply_on_sale' => (bool) $settings['apply_on_sale'],
		];
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
		// with more precision than the store's currency format.
		$decimals         = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$discounted_price = round( $discounted_price, $decimals, PHP_ROUND_HALF_DOWN );

		if ( $discounted_price >= $base_price ) {
			return null;
		}

		return (float) $discounted_price;
	}

	/**
	 * The price a subscriber pays once every rule that covers a product has been
	 * taken into account.
	 *
	 * With the default 'best' overlap the single largest reduction wins. With
	 * 'combine' the reductions are applied in a canonical order — every
	 * percentage first, then every fixed amount — rather than in whatever order
	 * the rules happen to be stored. Mixing the two types is otherwise
	 * order-dependent (£200 less 20% less £10 is £150, but less £10 less 20% is
	 * £152), which would make a reader's price depend on the order the publisher
	 * happened to create their rules in.
	 *
	 * @param float $base_price Price before any discount.
	 * @param array $rules      Rules that cover the product.
	 * @param array $settings   Discount settings; only `overlap` is read.
	 * @return float|null Null when no rule lowers the price.
	 */
	public static function combined_price( $base_price, $rules, $settings = [] ) {
		$base_price = (float) $base_price;
		if ( empty( $rules ) || $base_price <= 0 ) {
			return null;
		}

		if ( 'combine' !== ( $settings['overlap'] ?? 'best' ) ) {
			$best_price = null;
			foreach ( $rules as $rule ) {
				$candidate_price = self::discounted_price( $base_price, $rule );
				if ( null !== $candidate_price && ( null === $best_price || $candidate_price < $best_price ) ) {
					$best_price = $candidate_price;
				}
			}
			return $best_price;
		}

		$percentage_rules = array_filter(
			$rules,
			function ( $rule ) {
				return 'percent' === ( $rule['discount_type'] ?? '' );
			}
		);
		$fixed_rules      = array_filter(
			$rules,
			function ( $rule ) {
				return 'percent' !== ( $rule['discount_type'] ?? '' );
			}
		);

		$running_price = $base_price;
		foreach ( array_merge( $percentage_rules, $fixed_rules ) as $rule ) {
			// A rule that cannot lower the running price is skipped rather than
			// aborting the combination — a later rule may still reduce it.
			$reduced_price = self::discounted_price( $running_price, $rule );
			if ( null !== $reduced_price ) {
				$running_price = $reduced_price;
			}
		}

		return $running_price < $base_price ? $running_price : null;
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

		$targeting = $rule['targeting'] ?? '';
		if ( ! in_array( $targeting, self::TARGETING_MODES, true ) ) {
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

		// Only the fields belonging to the selected targeting mode are kept, so a
		// rule the publisher re-pointed in the editor cannot keep matching through
		// selections they can no longer see.
		$product_ids          = 'products' === $targeting ? self::sanitize_ids( $rule['product_ids'] ?? [] ) : [];
		$category_ids         = 'category' === $targeting ? self::sanitize_ids( $rule['category_ids'] ?? [] ) : [];
		$excluded_product_ids = 'products' === $targeting ? [] : self::sanitize_ids( $rule['excluded_product_ids'] ?? [] );

		if ( 'products' === $targeting && empty( $product_ids ) ) {
			return new \WP_Error(
				'newspack_subscriber_discount_no_products',
				__( 'Select at least one product for this discount.', 'newspack-plugin' )
			);
		}
		if ( 'category' === $targeting && empty( $category_ids ) ) {
			return new \WP_Error(
				'newspack_subscriber_discount_no_categories',
				__( 'Select at least one category for this discount.', 'newspack-plugin' )
			);
		}

		return [
			'id'                       => ! empty( $rule['id'] ) ? sanitize_key( $rule['id'] ) : self::generate_id(),
			'subscription_product_ids' => $subscription_product_ids,
			'targeting'                => $targeting,
			'product_ids'              => $product_ids,
			'category_ids'             => $category_ids,
			'excluded_product_ids'     => $excluded_product_ids,
			'discount_type'            => $discount_type,
			'amount'                   => $amount,
			'active'                   => isset( $rule['active'] ) ? (bool) $rule['active'] : true,
			'created_at'               => ! empty( $rule['created_at'] )
				? sanitize_text_field( $rule['created_at'] )
				: gmdate( 'Y-m-d' ),
		];
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

	/**
	 * A unique rule id.
	 *
	 * @return string
	 */
	private static function generate_id() {
		return uniqid( 'discount_' );
	}
}
