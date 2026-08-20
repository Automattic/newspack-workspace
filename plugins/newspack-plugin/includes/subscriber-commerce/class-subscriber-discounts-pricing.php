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
	 * The base price each variation was last discounted from, keyed by
	 * variation id, so the sale-price filter discounts the same figure.
	 *
	 * @var array
	 */
	private static $variation_price_base = [];

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
		if ( ! Subscriber_Commerce::is_enforcement_active() || ! self::should_adjust_prices_in_context() ) {
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
		add_filter( 'woocommerce_variation_prices_price', [ __CLASS__, 'filter_variation_prices' ], $priority, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', [ __CLASS__, 'filter_variation_sale_prices' ], $priority, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', [ __CLASS__, 'filter_variation_prices_hash' ], $priority, 2 );
		add_filter( 'woocommerce_product_is_on_sale', [ __CLASS__, 'filter_is_on_sale' ], $priority, 2 );
	}

	/**
	 * Whether prices should be adjusted in the current request context.
	 *
	 * A subscriber discount belongs to the storefront. In wp-admin the same
	 * price reads are how a shop manager edits the catalogue, and an
	 * administrator who also holds a subscription would otherwise see — and,
	 * through Quick Edit or a manual order, save back — their own discounted
	 * price as the product's price.
	 *
	 * @return bool
	 */
	public static function should_adjust_prices_in_context() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( wp_doing_ajax() ) {
			// Admin-screen AJAX that reads a price in order to write it back, or
			// to populate an admin picker. The storefront's own AJAX (add to
			// cart, cart fragments) is not in this list and stays discounted.
			$admin_ajax_actions = [
				'woocommerce_add_order_item',
				'woocommerce_save_order_items',
				'woocommerce_calc_line_taxes',
				'woocommerce_json_search_products',
				'woocommerce_json_search_products_and_variations',
			];
			// Quick Edit posts `action=inline-save` and marks itself with a
			// request field rather than an action, so it is detected separately.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading request markers only, to decide whether to price for the storefront.
			if ( ! empty( $_REQUEST['woocommerce_quick_edit'] ) ) {
				return false;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the action only, to decide whether to price for the storefront.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( in_array( $action, $admin_ajax_actions, true ) ) {
				return false;
			}
		}
		if ( self::is_management_rest_request() ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the current request is a REST call to a management route.
	 *
	 * A REST request is neither `is_admin()` nor AJAX, so the checks above see
	 * nothing. The storefront's own Store API must keep discounting, but the
	 * routes that read a price in order to edit it — or to preview what a
	 * discount would do to it — must not: a shop manager who also holds a
	 * qualifying subscription would otherwise edit against, and save back,
	 * their own subscriber price.
	 *
	 * The route is read at price time rather than at registration, since
	 * `wp_loaded` runs before WordPress has resolved one.
	 *
	 * @return bool
	 */
	private static function is_management_rest_request() {
		$is_rest_request = function_exists( 'wp_is_rest_endpoint' )
			? wp_is_rest_endpoint()
			: ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( ! $is_rest_request ) {
			return false;
		}

		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		if ( '' === $route ) {
			return false;
		}
		$route = '/' . ltrim( $route, '/' );

		/**
		 * Filters the REST routes that read prices for management rather than
		 * for the storefront. Each entry is matched against the start of the
		 * route, which always begins with a slash.
		 *
		 * `wc/store/*` is deliberately absent: that is the storefront's own
		 * API, and it must report the price the reader is charged.
		 *
		 * @param string[] $management_route_prefixes Route prefixes.
		 */
		$management_route_prefixes = (array) apply_filters(
			'newspack_subscriber_discounts_management_rest_routes',
			[
				// WooCommerce's management API, plus the admin's own analytics.
				'/wc/v1/',
				'/wc/v2/',
				'/wc/v3/',
				'/wc-admin/',
				'/wc-analytics/',
				// Product routes behind the block-based product editor.
				'/wp/v2/product',
				// This feature's own editor, whose preview composes a discount
				// over the price these endpoints report.
				'/' . NEWSPACK_API_NAMESPACE . '/wizard/',
			]
		);

		foreach ( $management_route_prefixes as $management_route_prefix ) {
			if ( 0 === strpos( $route, (string) $management_route_prefix ) ) {
				return true;
			}
		}
		return false;
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
		self::$rules_for_product    = [];
		self::$variation_price_base = [];
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
		// Read the price with adjustments stood down: `get_price()` is itself
		// filtered here, so discounting what it returns would apply the rule a
		// second time and report a sale price below the one being charged.
		$subscriber_price = self::get_subscriber_price( self::undiscounted_price( $product ), $product );
		return null === $subscriber_price ? $sale_price : $subscriber_price;
	}

	/**
	 * A product's price with subscriber discounts stood down.
	 *
	 * @param \WC_Product $product Product being priced.
	 * @return string|float
	 */
	public static function undiscounted_price( $product ) {
		self::suspend();
		try {
			$price = $product->get_price();
		} finally {
			self::resume();
		}
		return $price;
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
		// Remembered for the sale-price filter, which WooCommerce calls for the
		// same variation immediately afterwards. Both must discount the same
		// base or the two arrays disagree and the strike-through is dropped.
		self::$variation_price_base[ (int) $variation->get_id() ] = $price;

		$subscriber_price = self::get_subscriber_price( $price, $variation );
		return null === $subscriber_price ? $price : $subscriber_price;
	}

	/**
	 * Variation sale prices, which drive a variable product's on-sale range.
	 *
	 * Reported as the subscriber price rather than the variation's own stored
	 * sale price, so the prices array stays internally consistent — a consumer
	 * reading it directly (the Store API among them) sees the same discounted
	 * figure the product is sold at.
	 *
	 * @param string|float $sale_price Sale price WooCommerce is reporting.
	 * @param \WC_Product  $variation  Variation being priced.
	 * @param \WC_Product  $product    Parent product.
	 * @return string|float
	 */
	public static function filter_variation_sale_prices( $sale_price, $variation, $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// The base the price filter just discounted, which is the edit-context
		// price plus whatever another pricing extension made of it. Falling
		// back to the raw edit-context price covers the case where the price
		// filter is not registered. WooCommerce drops a variation sale price
		// that doesn't match the variation price exactly, so a base that
		// differs by a cent silently costs the strike-through.
		$variation_id = (int) $variation->get_id();
		$base_price   = self::$variation_price_base[ $variation_id ] ?? $variation->get_price( 'edit' );

		$subscriber_price = self::get_subscriber_price( $base_price, $variation );
		return null === $subscriber_price ? $sale_price : $subscriber_price;
	}

	/**
	 * Whether the product should be presented as on sale.
	 *
	 * @param bool        $on_sale Whether WooCommerce considers it on sale.
	 * @param \WC_Product $product Product being priced.
	 * @return bool
	 */
	public static function filter_is_on_sale( $on_sale, $product ) {
		if ( self::is_suspended() ) {
			return $on_sale;
		}
		if ( $on_sale ) {
			return true;
		}
		return null !== self::get_subscriber_price( self::undiscounted_price( $product ), $product );
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
	public static function filter_variation_prices_hash( $hash, $product ) {
		// Left untouched wherever prices are not adjusted, so a request that
		// reports list prices cannot store them under a subscriber's key and
		// hand that reader undiscounted prices on the storefront afterwards.
		if ( self::is_suspended() || ! self::should_adjust_prices_in_context() ) {
			return $hash;
		}
		// Keyed on the reader's entitlement across the whole active rule set,
		// not on the product: prices here are computed per variation, and a rule
		// can cover a variation without covering its parent, so a parent-derived
		// key could hand two readers with different entitlements the same cache
		// entry. Keying on the reader id instead would be correct but would give
		// every logged-in reader an entry in a transient WooCommerce accumulates
		// rather than evicts; entitlement collapses all non-subscribers onto one.
		// Full rule content, since editing an amount changes prices without
		// changing which rules apply, plus the settings that gate them.
		$hash['newspack_subscriber_discounts'] = md5(
			(string) wp_json_encode( [ self::qualifying_rules_for_reader( get_current_user_id() ), Subscriber_Discounts::get_settings() ] )
		);
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
		// Checked here as well as at registration so every surface agrees:
		// the reader-facing messaging asks this question directly rather than
		// through the price filters, and a context where prices are not adjusted
		// must not still be told a discount applied.
		if ( ! self::should_adjust_prices_in_context() ) {
			return null;
		}
		if ( '' === $base_price || null === $base_price ) {
			return null;
		}

		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $user_id <= 0 && empty( Subscriber_Discounts::get_settings()['apply_at_checkout'] ) ) {
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

		return Subscriber_Discounts::combined_price( (float) $base_price, $rules );
	}

	/**
	 * Whether a reader gets a rule's discount.
	 *
	 * Normally that means holding one of the subscriptions the rule names. With
	 * "apply at checkout" on, having one in the cart is enough — so a reader
	 * buying a subscription and a discounted product together sees the
	 * subscriber price before they have finished checking out.
	 *
	 * A rule never discounts the subscription that grants it, whether the reader
	 * holds that subscription or has it in the cart: discounting the thing that
	 * grants the discount is circular, and it would otherwise quietly cut the
	 * renewal price of every subscription a whole-catalogue rule reaches.
	 *
	 * @param int         $user_id Reader.
	 * @param array       $rule    Rule being considered.
	 * @param \WC_Product $product Product being priced.
	 * @return bool
	 */
	private static function reader_qualifies( $user_id, $rule, $product ) {
		if ( self::product_is_one_of( $product, $rule['subscription_product_ids'] ) ) {
			return false;
		}
		if ( Subscriber_Eligibility::user_has( $user_id, $rule['subscription_product_ids'] ) ) {
			return true;
		}
		if ( empty( Subscriber_Discounts::get_settings()['apply_at_checkout'] ) ) {
			return false;
		}
		return self::cart_contains_any( $rule['subscription_product_ids'] );
	}

	/**
	 * Whether a product (or its parent) is one of the given products.
	 *
	 * @param \WC_Product $product     Product being priced.
	 * @param int[]       $product_ids Products to match against.
	 * @return bool
	 */
	private static function product_is_one_of( $product, $product_ids ) {
		$product_ids = array_map( 'absint', (array) $product_ids );
		return in_array( (int) $product->get_id(), $product_ids, true )
			|| ( $product->get_parent_id() && in_array( (int) $product->get_parent_id(), $product_ids, true ) );
	}

	/**
	 * Whether the cart holds any of the given products.
	 *
	 * @param int[] $product_ids Products to look for.
	 * @return bool
	 */
	private static function cart_contains_any( $product_ids ) {
		$product_ids = array_map( 'absint', (array) $product_ids );
		return (bool) array_intersect( $product_ids, self::get_cart_product_ids() );
	}

	/**
	 * Every product currently in the cart, variations included.
	 *
	 * @return int[]
	 */
	private static function get_cart_product_ids() {
		$cart_product_ids = [];
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$cart_product_ids[] = absint( $cart_item['product_id'] ?? 0 );
				$cart_product_ids[] = absint( $cart_item['variation_id'] ?? 0 );
			}
		}

		/**
		 * Filters the products considered to be in the reader's cart when
		 * deciding whether a subscription there already grants its discount.
		 *
		 * @param int[] $cart_product_ids Product and variation IDs in the cart.
		 */
		$cart_product_ids = (array) apply_filters( 'newspack_subscriber_discounts_cart_product_ids', $cart_product_ids );

		return array_values( array_filter( array_map( 'absint', $cart_product_ids ) ) );
	}

	/**
	 * Every active rule this reader qualifies for, regardless of product.
	 *
	 * @param int $user_id Reader.
	 * @return array[]
	 */
	private static function qualifying_rules_for_reader( $user_id ) {
		$apply_at_checkout = ! empty( Subscriber_Discounts::get_settings()['apply_at_checkout'] );
		if ( $user_id <= 0 && ! $apply_at_checkout ) {
			return [];
		}
		return array_values(
			array_filter(
				Subscriber_Discounts::get_active_rules(),
				function ( $rule ) use ( $user_id, $apply_at_checkout ) {
					if ( Subscriber_Eligibility::user_has( $user_id, $rule['subscription_product_ids'] ) ) {
						return true;
					}
					// Cart contents are part of the entitlement once they can
					// grant it, so the variation-price cache key varies with them.
					return $apply_at_checkout && self::cart_contains_any( $rule['subscription_product_ids'] );
				}
			)
		);
	}

	/**
	 * The active rules covering a product that this reader qualifies for.
	 *
	 * @param \WC_Product $product Product being priced.
	 * @param int         $user_id Reader.
	 * @return array[]
	 */
	private static function get_rules_for( $product, $user_id ) {
		// Rule and settings writes flush this memo, so the key does not need to
		// carry the rule set — and must not, since hashing it on every call
		// would do the work the memo exists to avoid, several times per product
		// on a shop archive.
		$cache_key = $user_id . ':' . $product->get_id();
		if ( isset( self::$rules_for_product[ $cache_key ] ) ) {
			return self::$rules_for_product[ $cache_key ];
		}

		$covering_rules = Product_Targeting::get_matching_rules( Subscriber_Discounts::get_active_rules(), $product );

		$qualifying_rules = array_values(
			array_filter(
				$covering_rules,
				function ( $rule ) use ( $user_id, $product ) {
					return self::reader_qualifies( $user_id, $rule, $product );
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
}

Subscriber_Discounts_Pricing::init();
