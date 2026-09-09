<?php
/**
 * Newspack Subscriber Commerce - product purchase restriction.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces subscriber-only product restrictions.
 *
 * Only the purchase is blocked: the product page, its price and the product
 * lists stay visible, and a notice on the product page tells the reader which
 * subscriptions unlock it. This mirrors WooCommerce Memberships' product
 * purchase restriction, which Access Control replaces.
 *
 * A reader may buy a restricted product if they subscribe to any subscription
 * named by any restriction covering it — each restriction is an offer, not an
 * additional hurdle. Publishers can also hide restricted products from product
 * lists entirely, which goes beyond purchase restriction and is off by default.
 */
class Product_Purchase_Restriction {

	/**
	 * Whether the reader may purchase a product, keyed by
	 * "{product_id}_{user_id}_{payment-recovery grace}".
	 * WooCommerce asks several times per product per request (list loop, single
	 * product template, cart validation), so the decision is memoized.
	 *
	 * @var array<string, bool>
	 */
	private static $purchase_verdicts = [];

	/**
	 * The active restrictions. Null until first read.
	 *
	 * @var array[]|null
	 */
	private static $rules = null;

	/**
	 * Products the notice has already been rendered for this request, so the
	 * classic and block templates can't both emit it.
	 *
	 * @var array<int, bool>
	 */
	private static $rendered_notices = [];

	/**
	 * Products hidden from the current reader's product lists, or null before
	 * they have been worked out. A page can run many product queries; the set
	 * depends only on the rules and the reader, so it is computed once.
	 *
	 * @var int[]|null
	 */
	private static $hidden_product_ids = null;

	/**
	 * How many covered products the hiding pass will consider.
	 *
	 * Hiding has to name the products to exclude, so it enumerates what the
	 * restrictions cover. That is bounded by what a publisher restricted rather
	 * than by the catalog, and these are newsroom stores — books, tickets,
	 * merchandise — so the ceiling is far above any real configuration. Past it
	 * the excess stays listed (but still unpurchasable): over-listing is the
	 * safe failure for an opt-in convenience, where a slow query is not.
	 */
	const MAX_HIDDEN_PRODUCTS = 500;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Priority 999, as WooCommerce Memberships does: the restriction must have
		// the final say, or a later callback (e.g. WooCommerce Subscriptions'
		// renewal-cart limiter, which runs at 12 and returns true) hands the
		// purchase back.
		add_filter( 'woocommerce_is_purchasable', [ __CLASS__, 'filter_is_purchasable' ], 999, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', [ __CLASS__, 'filter_is_purchasable' ], 999, 2 );
		// Priority 31: right after the add-to-cart form (30), where Memberships puts its own notice.
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_restricted_message' ], 31 );
		// Block themes never fire the action above; the notice rides the add-to-cart block instead.
		add_filter( 'render_block', [ __CLASS__, 'filter_add_to_cart_block' ], 10, 3 );
		// Optional, off by default: keep restricted products out of product lists.
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_product_query' ] );
	}

	/**
	 * Block purchasing of a restricted product for readers who don't subscribe.
	 *
	 * @param bool        $purchasable Whether the product is purchasable.
	 * @param \WC_Product $product     The product (or variation).
	 *
	 * @return bool Whether the product is purchasable.
	 */
	public static function filter_is_purchasable( $purchasable, $product ) {
		// Never make purchasable a product WooCommerce already ruled out.
		if ( ! $purchasable ) {
			return $purchasable;
		}
		return self::can_purchase( $product );
	}

	/**
	 * Whether the reader may purchase a product.
	 *
	 * @param \WC_Product $product The product (or variation).
	 * @param int|null    $user_id Optional user ID. Defaults to the current user.
	 *
	 * @return bool
	 */
	public static function can_purchase( $product, $user_id = null ) {
		if ( ! Subscriber_Commerce::is_enforcement_active() || ! $product instanceof \WC_Product ) {
			return true;
		}
		$product_id = (int) $product->get_id();
		if ( ! $product_id ) {
			return true;
		}

		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		// The verdict rests on Subscriber_Eligibility::user_has(), which reads
		// `payment_recovery_grace` from the ambient evaluation context that
		// with_evaluation_context() swaps around each gate. Key on it for the same
		// reason that layer does, so a verdict reached inside one gate's context
		// is never served to a caller outside it.
		$cache_key = implode(
			'_',
			[
				$product_id,
				$user_id,
				Access_Rules::get_evaluation_context( 'payment_recovery_grace', true ) ? 'grace' : 'strict',
			]
		);
		if ( ! isset( self::$purchase_verdicts[ $cache_key ] ) ) {
			self::$purchase_verdicts[ $cache_key ] = self::evaluate_purchase( $product, $user_id );
		}
		return self::$purchase_verdicts[ $cache_key ];
	}

	/**
	 * Work out whether a reader may purchase a product.
	 *
	 * @param \WC_Product $product The product (or variation).
	 * @param int         $user_id The user ID (0 for anonymous readers).
	 *
	 * @return bool
	 */
	private static function evaluate_purchase( $product, $user_id ) {
		$can_purchase = true;
		$rules        = self::get_restricting_rules( $product );

		if ( ! empty( $rules ) ) {
			// Shop managers always keep purchasing rights, so a restriction can't
			// lock a publisher out of their own products (Memberships parity).
			if ( user_can( $user_id, 'manage_woocommerce' ) ) {
				$can_purchase = true;
			} else {
				// Any restriction the reader satisfies unlocks the product: each
				// rule names a way in, so more rules can only widen access.
				$can_purchase = false;
				foreach ( $rules as $rule ) {
					if ( Subscriber_Eligibility::user_has( $user_id, $rule['subscription_product_ids'] ) ) {
						$can_purchase = true;
						break;
					}
				}
			}
		}

		/**
		 * Filters whether a reader may purchase a subscriber-only product.
		 *
		 * @param bool        $can_purchase Whether the reader may purchase it.
		 * @param \WC_Product $product      The product (or variation).
		 * @param int         $user_id      The user ID (0 for anonymous readers).
		 * @param array[]     $rules        The restrictions covering the product.
		 */
		return (bool) apply_filters( 'newspack_subscriber_only_product_can_purchase', $can_purchase, $product, $user_id, $rules );
	}

	/**
	 * Get the active restrictions covering a product.
	 *
	 * @param \WC_Product $product The product (or variation).
	 *
	 * @return array[] The restrictions.
	 */
	public static function get_restricting_rules( $product ) {
		if ( null === self::$rules ) {
			self::$rules = Subscriber_Only_Products::get_active_rules();
		}
		return Product_Targeting::get_matching_rules( self::$rules, $product );
	}

	/**
	 * Keep restricted products out of product lists, when the publisher asked
	 * for it.
	 *
	 * Goes beyond purchase restriction, so it is opt-in. Direct links still
	 * work and show the product page with its notice — this only affects
	 * listings, so a reader who has the URL is never left wondering where the
	 * product went.
	 *
	 * Applies to every front-end product listing, not only the main query: a
	 * "product list" here means any of them — the shop, a category archive, a
	 * products block in a post — and hiding a product from one but not the next
	 * would be incoherent. Singular queries are left alone so a direct link
	 * still resolves.
	 *
	 * Secondary listings are in scope by the same reasoning: related products,
	 * cross-sells and cart upsells all recommend something the reader cannot
	 * buy. `newspack_subscriber_only_hide_from_query` is the escape hatch for a
	 * publisher who wants hiding confined to the primary catalog.
	 *
	 * @param \WP_Query $query The query.
	 */
	public static function filter_product_query( $query ) {
		if ( is_admin() || ! $query instanceof \WP_Query ) {
			return;
		}
		// Cheapest test first: this fires on every front-end query, and the vast
		// majority aren't about products at all. Only product listings qualify —
		// a single product's own query must still find it.
		if ( $query->is_singular() || ! self::is_product_query( $query ) ) {
			return;
		}
		if ( ! Subscriber_Commerce::is_enforcement_active() ) {
			return;
		}
		$settings = Subscriber_Only_Products::get_settings();
		if ( empty( $settings['hide_from_product_lists'] ) ) {
			return;
		}

		/**
		 * Filters whether restricted products are hidden from a given product query.
		 *
		 * Every front-end product listing is covered by default, secondary ones
		 * included. Return false to leave a listing alone — scoping hiding to the
		 * primary catalog and leaving related products, cross-sells or cart
		 * upsells untouched, for instance.
		 *
		 * Purchase restriction is unaffected either way: a product left visible
		 * by this filter still cannot be bought.
		 *
		 * @param bool      $hide  Whether to hide restricted products from this query.
		 * @param \WP_Query $query The query being filtered.
		 */
		if ( ! apply_filters( 'newspack_subscriber_only_hide_from_query', true, $query ) ) {
			return;
		}

		$hidden = self::get_hidden_product_ids();
		if ( empty( $hidden ) ) {
			return;
		}

		// WordPress drops `post__not_in` entirely once `post__in` is set, so a
		// curated listing — a hand-picked Products block, a Product Collection with
		// chosen products — has to be narrowed instead of excluded from, or it
		// would keep showing what every other listing hides.
		$included = array_filter( array_map( 'absint', (array) $query->get( 'post__in' ) ) );
		if ( ! empty( $included ) ) {
			$remaining = array_values( array_diff( $included, $hidden ) );
			// An empty `post__in` reads as "no constraint" and would list the whole
			// catalog. Post ID 0 exists for no post, so asking for it is how a
			// listing whose every pick is hidden comes back empty.
			// phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts -- Deliberately covers secondary product listings too; see the doc block.
			$query->set( 'post__in', empty( $remaining ) ? [ 0 ] : $remaining );
			return;
		}

		$excluded = array_map( 'absint', (array) $query->get( 'post__not_in' ) );
		// phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts -- Deliberately covers secondary product listings too; see the doc block.
		$query->set( 'post__not_in', array_values( array_unique( array_merge( $excluded, $hidden ) ) ) );
	}

	/**
	 * Whether a query lists products.
	 *
	 * @param \WP_Query $query The query.
	 *
	 * @return bool
	 */
	private static function is_product_query( $query ) {
		$post_types = (array) $query->get( 'post_type' );
		if ( in_array( 'product', $post_types, true ) ) {
			return true;
		}
		// The shop and product taxonomy archives don't set post_type explicitly.
		return function_exists( 'is_shop' ) && ( $query->is_post_type_archive( 'product' ) || $query->is_tax( get_object_taxonomies( 'product' ) ) );
	}

	/**
	 * Get the products the current reader may not purchase, for hiding.
	 *
	 * Only products covered by a restriction are considered, so the cost is
	 * bounded by what the publisher actually restricted rather than by the
	 * catalog size.
	 *
	 * @return int[] The product IDs to hide.
	 */
	private static function get_hidden_product_ids() {
		if ( null !== self::$hidden_product_ids ) {
			return self::$hidden_product_ids;
		}
		// Enumerating the covered products runs a product query, which fires
		// `pre_get_posts` — straight back into the filter that called us. Publish
		// the empty result first so that re-entry hides nothing and returns,
		// instead of recursing until PHP dies.
		self::$hidden_product_ids = [];

		$hidden = [];
		foreach ( self::covered_product_ids() as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product && ! self::can_purchase( $product ) ) {
				$hidden[] = (int) $product_id;
			}
		}
		self::$hidden_product_ids = $hidden;
		return self::$hidden_product_ids;
	}

	/**
	 * Get the IDs of every product covered by an active restriction.
	 *
	 * @return int[] The product IDs.
	 */
	private static function covered_product_ids() {
		$rules = Subscriber_Only_Products::get_active_rules();
		if ( empty( $rules ) ) {
			return [];
		}

		$named       = [];
		$category_ids = [];
		$covers_all  = false;
		foreach ( $rules as $rule ) {
			if ( Product_Targeting::TARGETING_ALL === $rule['targeting'] ) {
				$covers_all = true;
			} elseif ( Product_Targeting::TARGETING_CATEGORY === $rule['targeting'] ) {
				$category_ids = array_merge( $category_ids, Product_Targeting::expand_category_ids( $rule['category_ids'] ) );
			} else {
				$named = array_merge( $named, $rule['product_ids'] );
			}
		}

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded by what the publisher restricted, not by the catalog; see MAX_HIDDEN_PRODUCTS.
			'posts_per_page' => self::MAX_HIDDEN_PRODUCTS,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];
		if ( ! $covers_all ) {
			if ( empty( $category_ids ) ) {
				return array_values( array_unique( array_map( 'absint', $named ) ) );
			}
			$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy'         => Product_Targeting::PRODUCT_CATEGORY_TAXONOMY,
					'terms'            => array_unique( $category_ids ),
					'include_children' => false, // Already expanded.
				],
			];
		}

		$ids = get_posts( $args );
		return array_values( array_unique( array_merge( array_map( 'absint', $named ), array_map( 'absint', $ids ) ) ) );
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
	 * The add-to-cart block renders nothing for a product the reader can't buy,
	 * so without this the purchase is blocked with no explanation — the reader
	 * just finds the button missing.
	 *
	 * @param string    $block_content The block's rendered content.
	 * @param array     $block         The parsed block.
	 * @param \WP_Block $instance      The block instance, which carries the block context.
	 *
	 * @return string The block content, with the notice appended when the reader can't purchase.
	 */
	public static function filter_add_to_cart_block( $block_content, $block, $instance = null ) {
		// Only the single-product add-to-cart blocks. WooCommerce's own lists use a
		// different block (`woocommerce/product-button`), which is left alone so
		// lists render exactly as they do for a product that's out of stock.
		$add_to_cart_blocks = [ 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' ];
		if ( ! in_array( $block['blockName'] ?? '', $add_to_cart_blocks, true ) ) {
			return $block_content;
		}

		// A product's own page, which is the whole surface the classic path covers
		// too. Nothing stops a custom listing from putting one of those blocks on
		// every card, and the notice would then repeat down the page; pinning both
		// paths to the same surface keeps that out without resting on which block
		// WooCommerce happens to use in its own list templates.
		if ( ! is_singular( 'product' ) ) {
			return $block_content;
		}

		$product = self::get_block_product( $block, $instance );
		if ( ! $product ) {
			return $block_content;
		}

		return $block_content . self::get_restricted_message_html( $product );
	}

	/**
	 * Resolve the product a block is rendering for.
	 *
	 * Block context lives on the block instance, not on the parsed block array,
	 * so a block rendering for a product other than the one in the loop is
	 * resolved from its own context rather than from ambient loop state.
	 *
	 * @param array     $block    The parsed block.
	 * @param \WP_Block $instance The block instance, if the filter supplied one.
	 *
	 * @return \WC_Product|null The product, or null if it can't be resolved.
	 */
	private static function get_block_product( $block, $instance = null ) {
		$context_id = $instance instanceof \WP_Block ? ( $instance->context['postId'] ?? null ) : null;
		$product_id = (int) ( $block['attrs']['productId'] ?? $context_id ?? get_the_ID() );
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Build the notice markup for a product the reader can't purchase.
	 *
	 * Returns an empty string when the product is purchasable, so both the
	 * classic and block templates can call it unconditionally. The notice is
	 * emitted once per product per request, so a template running both paths
	 * can't double it up.
	 *
	 * @param \WC_Product|null $product The product.
	 *
	 * @return string The notice HTML, escaped, or an empty string.
	 */
	private static function get_restricted_message_html( $product ): string {
		if ( ! $product instanceof \WC_Product || self::can_purchase( $product ) ) {
			return '';
		}

		$product_id = (int) $product->get_id();
		if ( isset( self::$rendered_notices[ $product_id ] ) ) {
			return '';
		}

		$message = self::get_restricted_message( $product );
		if ( ! $message ) {
			return '';
		}
		// Marked only once something is actually emitted, so a filter that blanks
		// the message on one path doesn't suppress the other path's notice too.
		self::$rendered_notices[ $product_id ] = true;

		return sprintf(
			'<div class="woocommerce-info newspack-subscriber-only-notice">%s</div>',
			wp_kses_post( $message )
		);
	}

	/**
	 * Build the message shown to a reader who can't purchase a product.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return string The message. May contain links, so it is escaped with wp_kses_post() on output.
	 */
	public static function get_restricted_message( $product ) {
		$links = self::get_subscription_links( $product );

		if ( empty( $links ) ) {
			$message = __( 'This product is available to subscribers.', 'newspack-plugin' );
		} else {
			$message = sprintf(
				/* translators: %s: list of linked subscription names. */
				__( 'This product is available to subscribers. To purchase it, subscribe to %s.', 'newspack-plugin' ),
				// wp_sprintf( '%l' ) builds the list with the locale's separators ("A, B and C").
				wp_sprintf( '%l', $links )
			);
		}

		/**
		 * Filters the notice shown to a reader who can't purchase a subscriber-only product.
		 *
		 * @param string      $message The message. Rendered through wp_kses_post().
		 * @param \WC_Product $product The product.
		 */
		return apply_filters( 'newspack_subscriber_only_product_message', $message, $product );
	}

	/**
	 * Get links to the subscriptions that unlock a product, so the reader knows
	 * what to buy.
	 *
	 * A subscription the reader can't buy either — because a restriction covers
	 * it too — is left out rather than pointed at, so the notice never sends
	 * someone to a product they've just been barred from purchasing.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return string[] The links, keyed by subscription product ID.
	 */
	private static function get_subscription_links( $product ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$links = [];
		foreach ( self::get_restricting_rules( $product ) as $rule ) {
			foreach ( $rule['subscription_product_ids'] as $subscription_id ) {
				$subscription_id = (int) $subscription_id;
				if ( isset( $links[ $subscription_id ] ) ) {
					continue;
				}
				$subscription = wc_get_product( $subscription_id );
				if ( ! $subscription || ! self::can_purchase( $subscription ) ) {
					continue;
				}
				// The product's own permalink, not the post's: a subscription
				// variation can be named by a rule, and its post permalink points at
				// a page nobody can buy from, where WC_Product_Variation resolves to
				// the parent URL carrying the variation's attributes.
				$links[ $subscription_id ] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( (string) $subscription->get_permalink() ),
					esc_html( $subscription->get_name() )
				);
			}
		}
		return $links;
	}

	/**
	 * Flush the per-request caches. For tests and for callers that change the
	 * rules mid-request.
	 */
	public static function flush_cache() {
		self::$purchase_verdicts  = [];
		self::$rules              = null;
		self::$rendered_notices   = [];
		self::$hidden_product_ids = null;
	}
}
Product_Purchase_Restriction::init();
