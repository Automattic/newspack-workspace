<?php
/**
 * Applies subscriber discounts to WooCommerce prices.
 *
 * The discount is surfaced as an apparent sale price: WooCommerce (and the
 * theme) then render the struck-through original next to the subscriber price
 * with no bespoke markup, which is what readers already recognise.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriber discount price filters.
 */
class Subscriber_Discounts_Pricing {

	/**
	 * How deep the current suspension of price adjustments is.
	 *
	 * Reading an undiscounted price re-enters these same filters, so callers
	 * bracket such reads with suspend()/resume() and the filters stand down
	 * while the counter is above zero.
	 *
	 * @var int
	 */
	private static $suspend_depth = 0;

	/**
	 * Memoized per-product rule lookups, keyed by "user_id:product_id".
	 *
	 * @var array
	 */
	private static $rules_for_product = [];

	/**
	 * Register the price filters once plugins and the cart session are loaded.
	 */
	public static function init() {
		add_action( 'wp_loaded', [ __CLASS__, 'register_price_filters' ], 15 );
	}

	/**
	 * Attach the WooCommerce price filters.
	 */
	public static function register_price_filters() {
		if ( ! Subscriber_Commerce::is_enforcement_active() ) {
			return;
		}

		/**
		 * Filters the priority of the subscriber-discount price filters.
		 *
		 * The default runs late so the discount composes over whatever price
		 * other pricing extensions have already settled on, rather than
		 * competing with them for the base price.
		 *
		 * @param int $priority Filter priority.
		 */
		$priority = apply_filters( 'newspack_subscriber_discounts_price_filter_priority', 999 );

		foreach ( [ 'woocommerce_product_get_price', 'woocommerce_product_variation_get_price' ] as $hook ) {
			add_filter( $hook, [ __CLASS__, 'filter_price' ], $priority, 2 );
		}
		foreach ( [ 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price' ] as $hook ) {
			add_filter( $hook, [ __CLASS__, 'filter_sale_price' ], $priority, 2 );
		}
		foreach ( [ 'woocommerce_variation_prices_price', 'woocommerce_variation_prices_sale_price' ] as $hook ) {
			add_filter( $hook, [ __CLASS__, 'filter_variation_prices' ], $priority, 3 );
		}
		add_filter( 'woocommerce_get_variation_prices_hash', [ __CLASS__, 'filter_variation_prices_hash' ], $priority, 2 );
		add_filter( 'woocommerce_product_is_on_sale', [ __CLASS__, 'filter_is_on_sale' ], $priority, 2 );
	}

	/**
	 * Stop adjusting prices until the matching resume() call.
	 */
	public static function suspend() {
		++self::$suspend_depth;
	}

	/**
	 * Resume adjusting prices.
	 */
	public static function resume() {
		self::$suspend_depth = max( 0, self::$suspend_depth - 1 );
	}

	/**
	 * Discard the memoized per-product lookups.
	 */
	public static function flush_cache() {
		self::$rules_for_product = [];
	}

	/**
	 * The current price, discounted when the reader qualifies.
	 *
	 * @param string|float $price   Price WooCommerce is reporting.
	 * @param \WC_Product  $product Product being priced.
	 * @return string|float
	 */
	public static function filter_price( $price, $product ) {
		$subscriber_price = self::get_subscriber_price( $price, $product );
		return null === $subscriber_price ? $price : $subscriber_price;
	}

	/**
	 * The sale price, which is how the discount is presented.
	 *
	 * Reporting the subscriber price here is what makes WooCommerce treat the
	 * product as on sale and render the original struck through beside it.
	 *
	 * @param string|float $sale_price Sale price WooCommerce is reporting.
	 * @param \WC_Product  $product    Product being priced.
	 * @return string|float
	 */
	public static function filter_sale_price( $sale_price, $product ) {
		$subscriber_price = self::get_subscriber_price( $product->get_price(), $product );
		return null === $subscriber_price ? $sale_price : $subscriber_price;
	}

	/**
	 * Variation prices, used for a variable product's price range.
	 *
	 * @param string|float $price     Price WooCommerce is reporting.
	 * @param \WC_Product  $variation Variation being priced.
	 * @param \WC_Product  $product   Parent product.
	 * @return string|float
	 */
	public static function filter_variation_prices( $price, $variation, $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$subscriber_price = self::get_subscriber_price( $price, $variation );
		return null === $subscriber_price ? $price : $subscriber_price;
	}

	/**
	 * Whether the product should be presented as on sale.
	 *
	 * @param bool        $on_sale Whether WooCommerce considers it on sale.
	 * @param \WC_Product $product Product being priced.
	 * @return bool
	 */
	public static function filter_is_on_sale( $on_sale, $product ) {
		if ( $on_sale ) {
			return true;
		}
		return null !== self::get_subscriber_price( $product->get_price(), $product );
	}

	/**
	 * Vary the variation-price cache key by reader and rule set.
	 *
	 * WooCommerce caches a variable product's price range under this hash. Two
	 * readers with different entitlements must not share an entry, or the first
	 * reader to warm the cache would fix everyone else's prices.
	 *
	 * @param array       $hash    Hash parts.
	 * @param \WC_Product $product Product being priced.
	 * @return array
	 */
	public static function filter_variation_prices_hash( $hash, $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::is_suspended() ) {
			return $hash;
		}
		$hash['newspack_subscriber_discounts'] = [
			'user'  => get_current_user_id(),
			'rules' => self::active_rules_signature(),
		];
		return $hash;
	}

	/**
	 * What a product costs for the current reader, or null when no discount
	 * applies.
	 *
	 * @param string|float $base_price Price before the subscriber discount.
	 * @param \WC_Product  $product    Product being priced.
	 * @param int|null     $user_id    Reader; defaults to the current user.
	 * @return float|null
	 */
	public static function get_subscriber_price( $base_price, $product, $user_id = null ) {
		if ( self::is_suspended() || ! $product instanceof \WC_Product ) {
			return null;
		}
		if ( '' === $base_price || null === $base_price ) {
			return null;
		}

		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $user_id <= 0 ) {
			return null;
		}

		$rules = self::get_rules_for( $product, $user_id );
		if ( empty( $rules ) ) {
			return null;
		}

		$settings = Subscriber_Discounts::get_settings();
		if ( empty( $settings['apply_on_sale'] ) && self::is_on_sale_before_discount( $product ) ) {
			return null;
		}

		return Subscriber_Discounts::combined_price( (float) $base_price, $rules, $settings );
	}

	/**
	 * The active rules covering a product that this reader qualifies for.
	 *
	 * @param \WC_Product $product Product being priced.
	 * @param int         $user_id Reader.
	 * @return array[]
	 */
	private static function get_rules_for( $product, $user_id ) {
		$cache_key = $user_id . ':' . $product->get_id();
		if ( isset( self::$rules_for_product[ $cache_key ] ) ) {
			return self::$rules_for_product[ $cache_key ];
		}

		$covering_rules = Product_Targeting::get_matching_rules( Subscriber_Discounts::get_active_rules(), $product );

		$qualifying_rules = array_values(
			array_filter(
				$covering_rules,
				function ( $rule ) use ( $user_id ) {
					return Subscriber_Eligibility::user_has( $user_id, $rule['subscription_product_ids'] );
				}
			)
		);

		self::$rules_for_product[ $cache_key ] = $qualifying_rules;

		return $qualifying_rules;
	}

	/**
	 * Whether the product was already discounted before subscriber discounts.
	 *
	 * @param \WC_Product $product Product being priced.
	 * @return bool
	 */
	private static function is_on_sale_before_discount( $product ) {
		self::suspend();
		try {
			$on_sale = $product->is_on_sale();
		} finally {
			// Resume even if reading the price throws: leaving the filters
			// suspended would silently drop every discount for the rest of the
			// request.
			self::resume();
		}
		return (bool) $on_sale;
	}

	/**
	 * Whether price adjustments are currently suspended.
	 *
	 * @return bool
	 */
	private static function is_suspended() {
		return self::$suspend_depth > 0;
	}

	/**
	 * A short signature of the active rules, for cache keys.
	 *
	 * @return string
	 */
	private static function active_rules_signature() {
		$rules = Subscriber_Discounts::get_active_rules();
		return md5( (string) wp_json_encode( wp_list_pluck( $rules, 'id' ) ) );
	}
}

Subscriber_Discounts_Pricing::init();
