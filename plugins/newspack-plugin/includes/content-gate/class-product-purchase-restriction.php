<?php
/**
 * Newspack Content Gate - product purchase restriction.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Restricts *purchasing* of WooCommerce products to readers who pass a gate's
 * access rules.
 *
 * Only the purchase is blocked: the product page, its price and the shop
 * catalog stay visible to everyone, and a notice on the product page tells the
 * reader how to unlock it. This mirrors WooCommerce Memberships' product
 * "purchase" restriction, which Access Control replaces.
 *
 * A gate restricts purchasing when its custom access is active, it has access
 * rules (the WHO), and it lists restricted products and/or product categories
 * (the WHAT). Content rules are not required — a purchase-only gate has none,
 * which also keeps it out of content restriction entirely, since
 * Content_Restriction_Control::get_post_gates() skips gates without content rules.
 */
class Product_Purchase_Restriction {

	/**
	 * The WooCommerce product category taxonomy.
	 */
	const PRODUCT_CATEGORY_TAXONOMY = 'product_cat';

	/**
	 * The gate blocking a purchase, keyed by "{product_id}_{user_id}", or false
	 * when the product is purchasable. WooCommerce evaluates purchasability
	 * several times per product per request (catalog loop, single product
	 * template, cart validation), so the decision is memoized.
	 *
	 * @var array<string, array|false>
	 */
	private static array $blocking_gates = [];

	/**
	 * Published gates that restrict purchasing. Null until first looked up.
	 *
	 * @var array[]|null
	 */
	private static ?array $restricting_gates = null;

	/**
	 * Restricted product categories (including child categories), keyed by gate ID.
	 *
	 * @var array<int, int[]>
	 */
	private static array $restricted_categories = [];

	/**
	 * Whether a user passes a gate, keyed by "{gate_id}_{user_id}". A gate's verdict
	 * depends on the reader, not the product, so it is reused across every product a
	 * gate restricts — otherwise a catalog page of N restricted products would re-run
	 * the (uncached) subscription lookups behind an access rule N times.
	 *
	 * @var array<string, bool>
	 */
	private static array $gate_access = [];

	/**
	 * Products the notice has already been rendered for this request, so the classic
	 * and block templates can't both emit it.
	 *
	 * @var array<int, bool>
	 */
	private static array $rendered_notices = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Priority 999, as WooCommerce Memberships does: a restriction must have the
		// final say, or a later callback (e.g. WooCommerce Subscriptions' renewal-cart
		// limiter, which runs at 12 and returns true) can hand the purchase back.
		add_filter( 'woocommerce_is_purchasable', [ __CLASS__, 'filter_is_purchasable' ], 999, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', [ __CLASS__, 'filter_is_purchasable' ], 999, 2 );
		// Priority 31: right after the add-to-cart form (30), where WooCommerce Memberships puts its own notice.
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_restricted_message' ], 31 );
		// Block themes never fire the action above; the notice rides the add-to-cart block instead.
		add_filter( 'render_block', [ __CLASS__, 'filter_add_to_cart_block' ], 10, 2 );
	}

	/**
	 * Whether purchase restriction is enforced at all.
	 *
	 * While WooCommerce Memberships is active it owns purchase restriction, and
	 * enforcing ours on top would double-gate a site mid-migration. Mirrors
	 * Content_Restriction_Control::is_post_restricted().
	 *
	 * @return bool
	 */
	private static function is_enforced(): bool {
		return Content_Gate::is_newspack_feature_enabled() && ! Memberships::is_active();
	}

	/**
	 * Block purchasing of a restricted product for readers who fail its gate.
	 *
	 * @param bool        $purchasable Whether the product is purchasable.
	 * @param \WC_Product $product     The product (or variation).
	 *
	 * @return bool Whether the product is purchasable.
	 */
	public static function filter_is_purchasable( $purchasable, $product ) {
		// Never make a product purchasable that WooCommerce already ruled out.
		if ( ! $purchasable ) {
			return $purchasable;
		}
		return false === self::get_blocking_gate( $product ) ? $purchasable : false;
	}

	/**
	 * Get the gate blocking the current reader from purchasing a product.
	 *
	 * @param \WC_Product $product The product (or variation).
	 * @param int|null    $user_id Optional user ID. Defaults to the current user.
	 *
	 * @return array|false The blocking gate, or false if the product can be purchased.
	 */
	public static function get_blocking_gate( $product, $user_id = null ) {
		if ( ! self::is_enforced() || ! $product instanceof \WC_Product ) {
			return false;
		}
		$product_id = (int) $product->get_id();
		if ( ! $product_id ) {
			return false;
		}
		$user_id   = null === $user_id ? get_current_user_id() : (int) $user_id;
		$cache_key = $product_id . '_' . $user_id;
		if ( ! isset( self::$blocking_gates[ $cache_key ] ) ) {
			self::$blocking_gates[ $cache_key ] = self::find_blocking_gate( $product, $user_id );
		}
		return self::$blocking_gates[ $cache_key ];
	}

	/**
	 * Find the first gate that restricts the product and that the user fails.
	 *
	 * A reader must pass *every* gate restricting the product — "any of these
	 * rules" is expressed within a single gate, by its rule groups.
	 *
	 * @param \WC_Product $product The product (or variation).
	 * @param int         $user_id The user ID (0 for anonymous readers).
	 *
	 * @return array|false The blocking gate, or false if the product can be purchased.
	 */
	private static function find_blocking_gate( $product, $user_id ) {
		$blocking_gate = false;

		// Shop managers can always purchase, so a restriction can't lock a publisher out of their own store (WCM parity).
		if ( ! user_can( $user_id, 'manage_woocommerce' ) ) {
			foreach ( self::get_restricting_gates() as $gate ) {
				if ( ! self::gate_restricts_product( $gate, $product ) ) {
					continue;
				}
				if ( ! self::user_passes_gate( $gate, $user_id ) ) {
					$blocking_gate = $gate;
					break;
				}
			}
		}

		/**
		 * Filters the gate blocking a reader from purchasing a product.
		 *
		 * @param array|false $blocking_gate The blocking gate, or false if the product can be purchased.
		 * @param \WC_Product $product       The product (or variation).
		 * @param int         $user_id       The user ID (0 for anonymous readers).
		 */
		return apply_filters( 'newspack_product_purchase_blocking_gate', $blocking_gate, $product, $user_id );
	}

	/**
	 * Whether a user satisfies a gate's access rules.
	 *
	 * @param array $gate    The gate.
	 * @param int   $user_id The user ID (0 for anonymous readers).
	 *
	 * @return bool
	 */
	private static function user_passes_gate( array $gate, int $user_id ): bool {
		$cache_key = $gate['id'] . '_' . $user_id;
		if ( ! isset( self::$gate_access[ $cache_key ] ) ) {
			$access = User_Gate_Access::evaluate_gate_for_user( $gate, $user_id );
			self::$gate_access[ $cache_key ] = ! empty( $access['can_bypass'] );
		}
		return self::$gate_access[ $cache_key ];
	}

	/**
	 * Get the published gates that restrict product purchasing.
	 *
	 * @return array[] The gates.
	 */
	private static function get_restricting_gates(): array {
		if ( null !== self::$restricting_gates ) {
			return self::$restricting_gates;
		}
		$gates = Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' );

		self::$restricting_gates = array_values(
			array_filter(
				$gates,
				function( $gate ) {
					if ( is_wp_error( $gate ) ) {
						return false;
					}
					$custom_access = $gate['custom_access'];
					// A gate with no access rules grants access to everyone (see
					// User_Gate_Access::evaluate_gate_for_user), so it can't block a purchase.
					// A rule with an empty value passes everyone too, and is left to the same
					// evaluation below rather than second-guessed here — so a half-configured
					// gate fails open, exactly as it does for content.
					return ! empty( $custom_access['active'] )
						&& ! empty( $custom_access['access_rules'] )
						&& ( ! empty( $custom_access['restricted_products'] ) || ! empty( $custom_access['restricted_product_categories'] ) );
				}
			)
		);
		return self::$restricting_gates;
	}

	/**
	 * Whether a gate restricts purchasing of a given product.
	 *
	 * A variation is restricted through its parent, which is what the publisher
	 * selects in the gate.
	 *
	 * @param array       $gate    The gate.
	 * @param \WC_Product $product The product (or variation).
	 *
	 * @return bool
	 */
	private static function gate_restricts_product( array $gate, $product ): bool {
		$custom_access = $gate['custom_access'];
		$product_id    = (int) $product->get_id();
		$parent_id     = (int) $product->get_parent_id();

		// A variation is restricted when it is listed itself or when its parent is.
		foreach ( array_filter( [ $product_id, $parent_id ] ) as $id ) {
			if ( in_array( $id, $custom_access['restricted_products'], true ) ) {
				return true;
			}
		}

		if ( empty( $custom_access['restricted_product_categories'] ) ) {
			return false;
		}

		// Product categories live on the parent product; a variation never carries them.
		$categorized_id = $parent_id ? $parent_id : $product_id;

		return (bool) has_term( self::get_restricted_category_ids( $gate ), self::PRODUCT_CATEGORY_TAXONOMY, $categorized_id );
	}

	/**
	 * Get a gate's restricted product categories, expanded to include child
	 * categories.
	 *
	 * `product_cat` is hierarchical: restricting a parent category restricts
	 * everything filed under it, matching both WooCommerce Memberships and the
	 * gate's own content rules. Without this, a gate restricting "Premium" would
	 * leave every product in "Premium > Merch" purchasable by anyone.
	 *
	 * @param array $gate The gate.
	 *
	 * @return int[] The category IDs, including descendants.
	 */
	private static function get_restricted_category_ids( array $gate ): array {
		$gate_id = (int) $gate['id'];
		if ( isset( self::$restricted_categories[ $gate_id ] ) ) {
			return self::$restricted_categories[ $gate_id ];
		}

		$category_ids = array_map( 'intval', $gate['custom_access']['restricted_product_categories'] );
		$taxonomy     = get_taxonomy( self::PRODUCT_CATEGORY_TAXONOMY );
		if ( $taxonomy ) {
			$category_ids = Content_Restriction_Control::expand_hierarchical_terms( $category_ids, $taxonomy );
		}

		self::$restricted_categories[ $gate_id ] = $category_ids;
		return self::$restricted_categories[ $gate_id ];
	}

	/**
	 * Render the notice on a classic product template.
	 */
	public static function render_restricted_message() {
		global $product;

		echo wp_kses_post( self::get_restricted_message_html( $product ) );
	}

	/**
	 * Render the notice on a block-theme product template, where
	 * `woocommerce_single_product_summary` never fires.
	 *
	 * The add-to-cart block renders nothing for a product the reader can't buy, so
	 * without this the purchase is blocked with no explanation — the reader just
	 * finds the button missing.
	 *
	 * @param string $block_content The block's rendered content.
	 * @param array  $block         The parsed block.
	 *
	 * @return string The block content, with the notice appended when the reader can't purchase.
	 */
	public static function filter_add_to_cart_block( $block_content, $block ) {
		// Only the single-product add-to-cart blocks. The catalog's product button is left
		// alone: the shop loop stays as WooCommerce renders it, exactly as for a product
		// that's out of stock.
		$add_to_cart_blocks = [ 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' ];
		if ( ! in_array( $block['blockName'] ?? '', $add_to_cart_blocks, true ) ) {
			return $block_content;
		}

		$product = self::get_block_product( $block );
		if ( ! $product ) {
			return $block_content;
		}

		return $block_content . self::get_restricted_message_html( $product );
	}

	/**
	 * Resolve the product a block is rendering for.
	 *
	 * @param array $block The parsed block.
	 *
	 * @return \WC_Product|null The product, or null if it can't be resolved.
	 */
	private static function get_block_product( $block ) {
		$product_id = (int) ( $block['attrs']['productId'] ?? $block['context']['postId'] ?? get_the_ID() );
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Build the notice markup for a product the reader can't purchase.
	 *
	 * Returns an empty string when the product is purchasable, so both the classic
	 * and block templates can call it unconditionally. The notice is emitted once
	 * per product per request, so a template that runs both paths can't double it up.
	 *
	 * @param \WC_Product|null $product The product.
	 *
	 * @return string The notice HTML, escaped, or an empty string.
	 */
	private static function get_restricted_message_html( $product ): string {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$gate = self::get_blocking_gate( $product );
		if ( ! $gate ) {
			return '';
		}

		$product_id = (int) $product->get_id();
		if ( isset( self::$rendered_notices[ $product_id ] ) ) {
			return '';
		}
		self::$rendered_notices[ $product_id ] = true;

		$message = self::get_restricted_message( $product, $gate );
		if ( ! $message ) {
			return '';
		}

		return sprintf(
			'<div class="woocommerce-info newspack-product-purchase-restricted">%s</div>',
			wp_kses_post( $message )
		);
	}

	/**
	 * Build the message shown to a reader who can't purchase a product.
	 *
	 * @param \WC_Product $product The product.
	 * @param array       $gate    The gate blocking the purchase.
	 *
	 * @return string The message. May contain links, so it is escaped with wp_kses_post() on output.
	 */
	public static function get_restricted_message( $product, $gate ) {
		$subscription_links = self::get_subscription_product_links( $gate );

		if ( empty( $subscription_links ) ) {
			$message = __( 'This product can only be purchased by members.', 'newspack-plugin' );
		} else {
			$message = sprintf(
				/* translators: %s: list of linked subscription product names. */
				__( 'This product can only be purchased by members. To purchase this product, subscribe to %s.', 'newspack-plugin' ),
				// wp_sprintf( '%l' ) builds the list with the locale's separators ("A, B and C").
				wp_sprintf( '%l', $subscription_links )
			);
		}

		/**
		 * Filters the notice shown to a reader who can't purchase a restricted product.
		 *
		 * @param string      $message The message. Rendered through wp_kses_post().
		 * @param \WC_Product $product The product.
		 * @param array       $gate    The gate blocking the purchase.
		 */
		return apply_filters( 'newspack_product_purchase_restricted_message', $message, $product, $gate );
	}

	/**
	 * Get links to the subscription products that unlock a gate, so the reader
	 * knows what to buy. Empty when the gate grants access by other means
	 * (email domain, institution, reader data), which have no product to link to.
	 *
	 * A subscription the reader can't buy either — because a gate restricts it too —
	 * is left out rather than pointed at, so the notice never sends someone to a
	 * product they've just been barred from purchasing.
	 *
	 * @param array $gate The gate.
	 *
	 * @return string[] The product links, keyed by product ID.
	 */
	private static function get_subscription_product_links( array $gate ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$links = [];
		foreach ( $gate['custom_access']['access_rules'] as $rule_group ) {
			foreach ( $rule_group as $rule ) {
				if ( 'subscription' !== ( $rule['slug'] ?? '' ) || empty( $rule['value'] ) ) {
					continue;
				}
				foreach ( (array) $rule['value'] as $product_id ) {
					$product_id           = (int) $product_id;
					$subscription_product = wc_get_product( $product_id );
					if ( ! $subscription_product || false !== self::get_blocking_gate( $subscription_product ) ) {
						continue;
					}
					$links[ $product_id ] = sprintf(
						'<a href="%s">%s</a>',
						esc_url( (string) get_permalink( $product_id ) ),
						esc_html( $subscription_product->get_name() )
					);
				}
			}
		}
		return $links;
	}
}
Product_Purchase_Restriction::init();
