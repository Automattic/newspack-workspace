<?php
/**
 * Newspack Subscriber Commerce - product purchase restriction.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces subscriber-only product restrictions, mirroring WooCommerce
 * Memberships' product "purchase" and "view" restrictions, which Access
 * Control replaces.
 *
 * A "Purchase" restriction blocks only the purchase: the product page, its
 * price and the product lists stay visible, and a notice on the product page
 * tells the reader which subscriptions unlock it. A "Purchase and visibility"
 * restriction also hides the product from readers outside its audience: the
 * product page shows only the notice, answers 404 or redirects, per the
 * product-page setting, and the product can be left out of product lists.
 *
 * A reader may buy a restricted product if they subscribe to any subscription
 * named by any restriction covering it — each restriction is an offer, not an
 * additional hurdle. The one exception, as in WooCommerce Memberships: once a "Purchase"
 * restriction covers a product, a "Purchase and visibility" audience may see it
 * but only the "Purchase" audience may buy it.
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
	 * Whether the reader may see a product, keyed like $purchase_verdicts.
	 *
	 * @var array<string, bool>
	 */
	private static $view_verdicts = [];

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
		// Optional, off by default: keep hidden products out of product lists.
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_product_query' ] );
		// The product page of a hidden product answers 404 or redirects.
		add_action( 'template_redirect', [ __CLASS__, 'restrict_product_page' ], 10, 0 );
		// Or it keeps the page and shows only the notice: both WooCommerce
		// single-product templates, classic and block, swap the product for the
		// password form when the post asks for a password.
		add_filter( 'post_password_required', [ __CLASS__, 'filter_post_password_required' ], 10, 2 );
		add_filter( 'the_password_form', [ __CLASS__, 'filter_password_form' ], 10, 2 );
		// Where a hidden product is still listed, its price and image are not shown.
		add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'filter_listed_price' ], 999, 2 );
		add_filter( 'woocommerce_product_get_image', [ __CLASS__, 'filter_listed_image' ], 999, 4 );
		// Related products, upsells and cross-sells skip WP_Query, so list hiding
		// reaches them through product visibility.
		add_filter( 'woocommerce_product_is_visible', [ __CLASS__, 'filter_product_is_visible' ], 999, 2 );
		// A hidden variation drops out of its product's options.
		add_filter( 'woocommerce_variation_is_visible', [ __CLASS__, 'filter_variation_is_visible' ], 999, 4 );
		// Listings outside WooCommerce's templates, such as site search.
		add_filter( 'the_excerpt', [ __CLASS__, 'filter_listed_excerpt' ], 999 );
		add_filter( 'post_thumbnail_html', [ __CLASS__, 'filter_listed_thumbnail' ], 999, 2 );
		// The Store API serves product data to blocks and to anyone who asks.
		// Priority 9: before rest_filter_response_fields() trims the response to
		// `_fields`, which could drop the keys redaction recognises a product by.
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'filter_store_api_response' ], 9, 3 );
		// The core REST API withholds a hidden product's content and excerpt as
		// password-protected; its featured image goes here.
		add_filter( 'rest_prepare_product', [ __CLASS__, 'filter_rest_product' ], 10, 2 );
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

		$user_id   = null === $user_id ? get_current_user_id() : (int) $user_id;
		$cache_key = self::verdict_cache_key( $product_id, $user_id );
		if ( ! isset( self::$purchase_verdicts[ $cache_key ] ) ) {
			self::$purchase_verdicts[ $cache_key ] = self::evaluate_purchase( $product, $user_id );
		}
		return self::$purchase_verdicts[ $cache_key ];
	}

	/**
	 * Whether the reader may see a product.
	 *
	 * Only a "Purchase and visibility" restriction hides a product. Its readers
	 * are the subscribers of any restriction covering the product, either type:
	 * a reader who may buy a product may also see it.
	 *
	 * @param \WC_Product $product The product (or variation).
	 * @param int|null    $user_id Optional user ID. Defaults to the current user.
	 *
	 * @return bool
	 */
	public static function can_view( $product, $user_id = null ) {
		if ( ! Subscriber_Commerce::is_enforcement_active() || ! $product instanceof \WC_Product ) {
			return true;
		}
		$product_id = (int) $product->get_id();
		if ( ! $product_id ) {
			return true;
		}

		$user_id   = null === $user_id ? get_current_user_id() : (int) $user_id;
		$cache_key = self::verdict_cache_key( $product_id, $user_id );
		if ( ! isset( self::$view_verdicts[ $cache_key ] ) ) {
			self::$view_verdicts[ $cache_key ] = self::evaluate_view( $product, $user_id );
		}
		return self::$view_verdicts[ $cache_key ];
	}

	/**
	 * The memo key for a verdict on a product for a reader.
	 *
	 * Verdicts rest on Subscriber_Eligibility::user_matches_rule(), which reads
	 * `payment_recovery_grace` from the ambient evaluation context that
	 * with_evaluation_context() swaps around each gate. Keying on it, for the
	 * same reason that layer does, keeps a verdict reached inside one gate's
	 * context from being served to a caller outside it.
	 *
	 * @param int $product_id The product ID.
	 * @param int $user_id    The user ID.
	 *
	 * @return string
	 */
	private static function verdict_cache_key( int $product_id, int $user_id ): string {
		return implode(
			'_',
			[
				$product_id,
				$user_id,
				Access_Rules::get_evaluation_context( 'payment_recovery_grace', true ) ? 'grace' : 'strict',
			]
		);
	}

	/**
	 * Work out whether a reader may see a product.
	 *
	 * @param \WC_Product $product The product (or variation).
	 * @param int         $user_id The user ID (0 for anonymous readers).
	 *
	 * @return bool
	 */
	private static function evaluate_view( $product, $user_id ): bool {
		$rules    = self::get_restricting_rules( $product );
		$can_view = empty( self::filter_rules_by_type( $rules, Subscriber_Only_Products::RESTRICTION_TYPE_VIEW ) )
			|| user_can( $user_id, 'manage_woocommerce' )
			|| self::user_matches_any( $user_id, $rules );

		/**
		 * Filters whether a reader may see a product hidden by a "Purchase and
		 * visibility" restriction. A reader who can't see it can't buy it either.
		 *
		 * @param bool        $can_view Whether the reader may see it.
		 * @param \WC_Product $product  The product (or variation).
		 * @param int         $user_id  The user ID (0 for anonymous readers).
		 * @param array[]     $rules    The restrictions covering the product.
		 */
		return (bool) apply_filters( 'newspack_subscriber_only_product_can_view', $can_view, $product, $user_id, $rules );
	}

	/**
	 * Whether a reader satisfies any of the given restrictions. Each one names
	 * a way in, so more rules can only widen access.
	 *
	 * @param int     $user_id The user ID.
	 * @param array[] $rules   The restrictions.
	 *
	 * @return bool
	 */
	private static function user_matches_any( $user_id, array $rules ): bool {
		foreach ( $rules as $rule ) {
			if ( Subscriber_Eligibility::user_matches_rule( $user_id, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The restrictions of one type.
	 *
	 * @param array[] $rules            The restrictions.
	 * @param string  $restriction_type One of the Subscriber_Only_Products::RESTRICTION_TYPE_* values.
	 *
	 * @return array[]
	 */
	private static function filter_rules_by_type( array $rules, string $restriction_type ): array {
		return array_values( array_filter( $rules, fn( $rule ) => $restriction_type === $rule['restriction_type'] ) );
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
		$rules = self::get_restricting_rules( $product );

		if ( empty( $rules ) || user_can( $user_id, 'manage_woocommerce' ) ) {
			// Shop managers always keep purchasing rights, so a restriction can't
			// lock a publisher out of their own products (Memberships parity).
			$can_purchase = true;
		} elseif ( ! self::can_view( $product, $user_id ) ) {
			// A reader who can't see a product can't buy it.
			$can_purchase = false;
		} else {
			// Once a "Purchase" restriction covers the product, only its
			// subscribers may buy it, and a "Purchase and visibility" audience may
			// look but not buy (Memberships parity). Without one, the subscribers
			// of the "Purchase and visibility" restrictions may buy it. Purchase
			// always rests on a rule the reader matches, never on visibility
			// alone, so a filter that shows a product as a teaser can't open its
			// sale.
			$purchase_rules = self::filter_rules_by_type( $rules, Subscriber_Only_Products::RESTRICTION_TYPE_PURCHASE );
			$can_purchase   = self::user_matches_any( $user_id, empty( $purchase_rules ) ? $rules : $purchase_rules );
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
		$matching_rules = Product_Targeting::get_matching_rules( self::$rules, $product );

		// A rule open to every subscriber leaves the subscriptions themselves on
		// sale, whatever its targeting reaches. Otherwise "all subscribers" plus
		// "all products" is a store nobody can enter: the only way to satisfy the
		// rule is to hold a subscription, and the rule refuses the sale of one.
		// A rule that names its subscriptions is not exempted — naming a
		// subscription and restricting it is two deliberate choices, where this is
		// the incidental sweep of a mode that names nothing.
		if ( WooCommerce_Subscriptions::is_subscription_product( $product ) ) {
			$matching_rules = array_values(
				array_filter(
					$matching_rules,
					function ( $rule ) {
						return ! Subscriber_Commerce::covers_all_subscriptions( $rule );
					}
				)
			);
		}

		return $matching_rules;
	}

	/**
	 * Keep "Purchase and visibility" products out of the product lists of
	 * readers who can't see them, when the publisher asked for it. A
	 * "Purchase" product is meant to be seen, so it stays listed.
	 *
	 * Opt-in. Direct links are left to the product-page setting
	 * (restrict_product_page()): this only affects listings.
	 *
	 * Applies to every front-end product listing, not only the main query: a
	 * "product list" here means any of them — the shop, a category archive, a
	 * products block in a post — and hiding a product from one but not the next
	 * would be incoherent. Singular queries are left alone so a direct link
	 * still resolves.
	 *
	 * Secondary listings are in scope by the same reasoning. Those built on
	 * WP_Query are covered here; related products, upsells and cross-sells,
	 * which WooCommerce builds without it, are covered by
	 * filter_product_is_visible(). `newspack_subscriber_only_hide_from_query` is
	 * the escape hatch for a publisher who wants hiding confined to the primary
	 * catalog's queries.
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
		if ( ! Subscriber_Only_Products::hides_from_product_lists() ) {
			return;
		}

		/**
		 * Filters whether hidden products are left out of a given product query.
		 *
		 * Every front-end product listing is covered by default, secondary ones
		 * included. Return false to leave a listing alone — scoping hiding to the
		 * primary catalog and leaving related products, cross-sells or cart
		 * upsells untouched, for instance.
		 *
		 * Purchase restriction is unaffected either way: a product left listed
		 * by this filter still cannot be bought.
		 *
		 * @param bool      $hide  Whether to hide products from this query.
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
		$post_types = array_filter( (array) $query->get( 'post_type' ) );
		if ( in_array( 'product', $post_types, true ) ) {
			return true;
		}
		// A site search lists products among everything else unless it names
		// other post types only.
		if ( $query->is_search() && ( empty( $post_types ) || in_array( 'any', $post_types, true ) ) ) {
			return true;
		}
		// The shop and product taxonomy archives don't set post_type explicitly.
		return function_exists( 'is_shop' ) && ( $query->is_post_type_archive( 'product' ) || $query->is_tax( get_object_taxonomies( 'product' ) ) );
	}

	/**
	 * Get the products the current reader may not see, for hiding.
	 *
	 * Only products covered by a "Purchase and visibility" restriction are
	 * considered, so the cost is bounded by what the publisher actually hid
	 * rather than by the catalog size.
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
			if ( $product instanceof \WC_Product && ! self::can_view( $product ) ) {
				$hidden[] = (int) $product_id;
			}
		}
		self::$hidden_product_ids = $hidden;
		return self::$hidden_product_ids;
	}

	/**
	 * Get the IDs of every product covered by an active "Purchase and
	 * visibility" restriction.
	 *
	 * @return int[] The product IDs.
	 */
	private static function covered_product_ids() {
		$rules = self::filter_rules_by_type( Subscriber_Only_Products::get_active_rules(), Subscriber_Only_Products::RESTRICTION_TYPE_VIEW );
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
	 * The product whose page is being viewed, when the reader may not see it.
	 *
	 * Only the product's own page: the product-page setting is about what a URL
	 * answers, and listings are the hiding filter's business.
	 *
	 * @return \WC_Product|null
	 */
	private static function get_hidden_queried_product() {
		if ( ! is_singular( 'product' ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof \WC_Product || self::can_view( $product ) ) {
			return null;
		}
		return $product;
	}

	/**
	 * Answer 404 or redirect on the page of a product the reader may not see,
	 * per the product-page setting.
	 *
	 * "Hide content only" is left to the password filters below, and so is a
	 * redirect whose page is gone: the product stays hidden either way.
	 */
	public static function restrict_product_page(): void {
		$product = self::get_hidden_queried_product();
		if ( ! $product ) {
			return;
		}

		$settings = Subscriber_Only_Products::get_settings();
		if ( Subscriber_Only_Products::PRODUCT_PAGE_HIDE === $settings['product_page'] ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		if ( Subscriber_Only_Products::PRODUCT_PAGE_REDIRECT === $settings['product_page'] ) {
			$page_id = $settings['redirect_page_id'];
			// A page, so a stray ID can't send readers to another hidden product.
			if ( $page_id && 'page' === get_post_type( $page_id ) && 'publish' === get_post_status( $page_id ) ) {
				wp_safe_redirect( get_permalink( $page_id ) );
				exit;
			}
		}
	}

	/**
	 * Ask for a "password" on a product the reader may not see.
	 *
	 * On the product's page, both WooCommerce single-product templates render
	 * the password form in place of the product when one is required, so this
	 * hides the details, price and add-to-cart in classic and block themes
	 * alike. Elsewhere, WordPress withholds a password-protected post's content
	 * and excerpt itself: in the REST API, feeds and any template that asks. No
	 * password is set on the post, so the title gets no "Protected" prefix, and
	 * filter_password_form() shows the notice in place of the form.
	 *
	 * @param bool          $required Whether the post requires a password.
	 * @param \WP_Post|null $post     The post.
	 *
	 * @return bool
	 */
	public static function filter_post_password_required( $required, $post ) {
		// Cheapest test first: this runs for every post a page touches.
		if ( $required || ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return $required;
		}
		return null !== self::get_hidden_product( $post );
	}

	/**
	 * Show the notice instead of a password form for a product the reader may
	 * not see.
	 *
	 * @param string        $output The password form markup.
	 * @param \WP_Post|null $post   The post.
	 *
	 * @return string
	 */
	public static function filter_password_form( $output, $post = null ) {
		$product = self::get_hidden_product( $post );
		if ( ! $product ) {
			return $output;
		}
		return sprintf(
			'<div class="woocommerce-info newspack-subscriber-only-notice">%s</div>',
			wp_kses_post( self::get_restricted_message( $product ) )
		);
	}

	/**
	 * The product behind a post, when the reader may not see it. Front end only:
	 * wp-admin edits products, it doesn't read them as a reader.
	 *
	 * @param \WP_Post|int|null $post The post or its ID.
	 *
	 * @return \WC_Product|null
	 */
	private static function get_hidden_product( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'product' !== $post->post_type || is_admin() || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post->ID );
		return $product instanceof \WC_Product && ! self::can_view( $product ) ? $product : null;
	}

	/**
	 * Whether a product listed for this reader should show as hidden: the
	 * reader may not see it. Whatever the product page does, a listing that
	 * still shows the product withholds its price, image and excerpt.
	 *
	 * @param \WC_Product $product The product (or variation).
	 *
	 * @return bool
	 */
	private static function is_listed_as_hidden( $product ): bool {
		return $product instanceof \WC_Product && ! is_admin() && ! self::can_view( $product );
	}

	/**
	 * Leave out the price of a product the reader may not see.
	 *
	 * @param string           $price_html The price markup.
	 * @param \WC_Product|null $product    The product.
	 *
	 * @return string
	 */
	public static function filter_listed_price( $price_html, $product = null ) {
		return self::is_listed_as_hidden( $product ) ? '' : $price_html;
	}

	/**
	 * Show the placeholder image in place of the image of a product the reader
	 * may not see.
	 *
	 * @param string           $image   The image markup.
	 * @param \WC_Product|null $product The product.
	 * @param string|int[]     $size    The image size.
	 * @param array            $attr    Image attributes, such as the block's alt and style.
	 *
	 * @return string
	 */
	public static function filter_listed_image( $image, $product = null, $size = 'woocommerce_thumbnail', $attr = [] ) {
		if ( ! self::is_listed_as_hidden( $product ) ) {
			return $image;
		}
		return function_exists( 'wc_placeholder_img' ) ? wc_placeholder_img( $size, (array) $attr ) : '';
	}

	/**
	 * Leave a product the reader may not see out of the lists WooCommerce builds
	 * without WP_Query, when list hiding is on.
	 *
	 * @param bool $visible    Whether the product is visible.
	 * @param int  $product_id The product ID.
	 *
	 * @return bool
	 */
	public static function filter_product_is_visible( $visible, $product_id = 0 ) {
		if ( ! $visible || is_admin() || ! Subscriber_Commerce::is_enforcement_active() || ! Subscriber_Only_Products::hides_from_product_lists() ) {
			return $visible;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		return ! $product instanceof \WC_Product || self::can_view( $product );
	}

	/**
	 * The product behind a post, when the post is a product the reader may not
	 * see and the product page keeps its content hidden.
	 *
	 * @param \WP_Post|int|null $post The post or its ID.
	 *
	 * @return \WC_Product|null
	 */
	private static function get_listed_hidden_product( $post ) {
		$product = self::get_hidden_product( $post );
		return $product && self::is_listed_as_hidden( $product ) ? $product : null;
	}

	/**
	 * Show the subscriber-only notice in place of the excerpt of a product the
	 * reader may not see, wherever a listing shows excerpts.
	 *
	 * The excerpt itself is already withheld, since the product counts as
	 * password-protected; this swaps WordPress' "protected post" line for the
	 * notice, which says how to get access.
	 *
	 * @param string $excerpt The excerpt markup.
	 *
	 * @return string
	 */
	public static function filter_listed_excerpt( $excerpt ) {
		$product = self::get_listed_hidden_product( get_post() );
		return $product ? wpautop( wp_kses_post( self::get_restricted_message( $product ) ) ) : $excerpt;
	}

	/**
	 * Leave out the featured image of a product the reader may not see, in
	 * listings outside WooCommerce's templates.
	 *
	 * @param string $html    The image markup.
	 * @param int    $post_id The post ID.
	 *
	 * @return string
	 */
	public static function filter_listed_thumbnail( $html, $post_id = 0 ) {
		return self::get_listed_hidden_product( $post_id ) ? '' : $html;
	}

	/**
	 * Strip what a reader may not see from the Store API's product responses.
	 *
	 * The product's own page hides its details whatever the product-page
	 * setting, so the API that blocks and scripts read does too: no prices,
	 * descriptions, images or variations. The name and link stay, as they do
	 * on the page.
	 *
	 * @param \WP_HTTP_Response|mixed $response The response.
	 * @param \WP_REST_Server         $server   The server.
	 * @param \WP_REST_Request        $request  The request.
	 *
	 * @return \WP_HTTP_Response|mixed
	 */
	public static function filter_store_api_response( $response, $server = null, $request = null ) {
		if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		// The product endpoints only: their siblings under /products/ (categories,
		// tags, reviews…) return other shapes, which the `prices` check skips.
		if ( ! preg_match( '#^/wc/store(?:/v\d+)?/products(?:/[^/]+)?$#', $request->get_route() ) ) {
			return $response;
		}
		if ( ! Subscriber_Commerce::is_enforcement_active() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		$is_single = isset( $data['id'] );
		$items     = $is_single ? [ $data ] : $data;
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'], $item['prices'] ) ) {
				continue;
			}
			$product = wc_get_product( (int) $item['id'] );
			if ( ! $product instanceof \WC_Product || self::can_view( $product ) ) {
				continue;
			}
			$items[ $index ] = self::redact_store_api_product( $item );
		}
		$response->set_data( $is_single ? $items[0] : $items );
		return $response;
	}

	/**
	 * Leave a hidden product's featured image out of the core REST API.
	 *
	 * @param \WP_REST_Response|mixed $response The response.
	 * @param \WP_Post                $post     The product post.
	 *
	 * @return \WP_REST_Response|mixed
	 */
	public static function filter_rest_product( $response, $post ) {
		if ( ! $response instanceof \WP_REST_Response || ! self::get_hidden_product( $post ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['featured_media'] ) ) {
			$data['featured_media'] = 0;
			$response->set_data( $data );
		}
		$response->remove_link( 'https://api.w.org/featuredmedia' );
		$response->remove_link( 'https://api.w.org/attachment' );
		return $response;
	}

	/**
	 * A Store API product item with the details a hidden product's page
	 * withholds left empty.
	 *
	 * @param array $item The product item.
	 *
	 * @return array
	 */
	private static function redact_store_api_product( array $item ): array {
		$prices = (array) $item['prices'];
		foreach ( [ 'price', 'regular_price', 'sale_price' ] as $price_key ) {
			if ( array_key_exists( $price_key, $prices ) ) {
				$prices[ $price_key ] = '';
			}
		}
		if ( array_key_exists( 'price_range', $prices ) ) {
			$prices['price_range'] = null;
		}
		return array_merge(
			$item,
			[
				'short_description' => '',
				'description'       => '',
				'prices'            => (object) $prices,
				'price_html'        => '',
				'images'            => [],
				'variations'        => [],
			]
		);
	}

	/**
	 * Drop a variation the reader may not see from its product's options.
	 *
	 * @param bool             $visible      Whether the variation is visible.
	 * @param int              $variation_id The variation ID.
	 * @param int              $parent_id    The parent product ID.
	 * @param \WC_Product|null $variation    The variation.
	 *
	 * @return bool
	 */
	public static function filter_variation_is_visible( $visible, $variation_id = 0, $parent_id = 0, $variation = null ) {
		if ( ! $visible || is_admin() ) {
			return $visible;
		}
		$variation = $variation instanceof \WC_Product ? $variation : ( function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null );
		return ! $variation instanceof \WC_Product || self::can_view( $variation );
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
	 * A rule open to every subscriber names none, and naming only what a narrower
	 * rule beside it lists would tell the reader to buy one particular subscription
	 * when any of them unlocks the product. So one such rule suppresses the whole
	 * list, and the notice falls back to saying the product is for subscribers.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return string[] The links, keyed by subscription product ID.
	 */
	private static function get_subscription_links( $product ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		// The rules that would let the reader buy: the "Purchase" ones when any
		// cover the product, since then nothing else does, otherwise the rest.
		$rules          = self::get_restricting_rules( $product );
		$purchase_rules = self::filter_rules_by_type( $rules, Subscriber_Only_Products::RESTRICTION_TYPE_PURCHASE );

		$links = [];
		foreach ( empty( $purchase_rules ) ? $rules : $purchase_rules as $rule ) {
			if ( Subscriber_Commerce::covers_all_subscriptions( $rule ) ) {
				return [];
			}
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
		self::$view_verdicts      = [];
		self::$rules              = null;
		self::$rendered_notices   = [];
		self::$hidden_product_ids = null;
	}
}
Product_Purchase_Restriction::init();
