<?php
/**
 * Newspack Blocks Fast Checkout
 *
 * @package Newspack_Blocks
 */

namespace Newspack_Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Fast Checkout Class.
 */
final class Fast_Checkout {

	/**
	 * Block name.
	 */
	const BLOCK_NAME = 'newspack-blocks/fast-checkout';

	/**
	 * Bindings source identifier.
	 */
	const BINDINGS_SOURCE = 'newspack-blocks/fast-checkout-product';

	/**
	 * Cart item meta key for source post.
	 */
	const CART_ITEM_SOURCE_KEY = '_newspack_fast_checkout_source_post';

	/**
	 * Block context key for the product ID.
	 */
	const CONTEXT_PRODUCT_KEY = 'newspack-blocks/fastCheckoutProductId';

	/**
	 * Block context key for the variation ID.
	 */
	const CONTEXT_VARIATION_KEY = 'newspack-blocks/fastCheckoutVariationId';

	/**
	 * Core blocks that should receive product context.
	 */
	const CORE_CONTEXT_BLOCKS = [ 'core/heading', 'core/image', 'core/paragraph' ];

	/**
	 * Query parameter names.
	 */
	const QP_EMAIL         = 'fc_email';
	const QP_QTY           = 'fc_qty';
	const QP_COUPON        = 'fc_coupon';
	const QP_VARIATION     = 'fc_variation';
	const QP_GROUPED_CHILD = 'fc_grouped_child';
	const QP_PRICE         = 'fc_price';
	const QP_SUCCESS       = 'fc_success';

	/**
	 * Cache of post ID → product ID lookups.
	 *
	 * @var array
	 */
	private static $post_product_cache = [];

	/**
	 * Cache of post ID → parsed block array.
	 *
	 * @var array
	 */
	private static $post_blocks_cache = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_bindings_source' ], 20 );
		add_action( 'wp', [ __CLASS__, 'mark_page_noncacheable' ], 5 );
		add_action( 'wp', [ __CLASS__, 'maybe_replace_cart' ], 10 );
		add_filter( 'render_block_' . self::BLOCK_NAME, [ __CLASS__, 'filter_render' ], 10, 2 );
		add_filter( 'woocommerce_get_return_url', [ __CLASS__, 'maybe_override_return_url' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'attach_line_item_meta' ], 10, 4 );
		add_filter( 'block_type_metadata', [ __CLASS__, 'add_context_to_core_blocks' ] );
		add_filter( 'woocommerce_is_checkout', [ __CLASS__, 'maybe_flag_as_checkout' ] );
		add_filter( 'render_block_data', [ __CLASS__, 'filter_checkout_actions_block' ] );
		add_filter( 'woocommerce_store_api_add_to_cart_data', [ __CLASS__, 'store_api_nyp_bridge_handler' ], 10, 2 );
		add_filter( 'wc_nyp_show_edit_link_in_cart', [ __CLASS__, 'suppress_nyp_edit_link' ], 10, 2 );
	}

	/**
	 * Reset the internal cache. Used by tests.
	 */
	public static function reset_cache() {
		self::$post_product_cache = [];
		self::$post_blocks_cache  = [];
	}

	/**
	 * Parse the post's blocks once, caching the result per post ID.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Parsed block tree.
	 */
	private static function get_parsed_blocks( $post ) {
		if ( ! isset( self::$post_blocks_cache[ $post->ID ] ) ) {
			self::$post_blocks_cache[ $post->ID ] = parse_blocks( $post->post_content );
		}
		return self::$post_blocks_cache[ $post->ID ];
	}

	/**
	 * Resolve the effective product ID from block attributes.
	 *
	 * Returns the grouped child ID when the product is grouped and a child is set,
	 * the variation ID when the product is variable and a variation is set,
	 * otherwise the product ID. Returns null when no product attribute is present.
	 *
	 * @param array $attrs Block attributes.
	 * @return int|null Product, variation, or grouped child ID, or null.
	 */
	public static function resolve_product_id_from_attrs( $attrs ) {
		if ( empty( $attrs['product'] ) ) {
			return null;
		}
		$product_id    = (int) $attrs['product'];
		$is_variable   = ! empty( $attrs['is_variable'] );
		$is_grouped    = ! empty( $attrs['is_grouped'] );
		$variation     = ! empty( $attrs['variation'] ) ? (int) $attrs['variation'] : 0;
		$grouped_child = ! empty( $attrs['grouped_child'] ) ? (int) $attrs['grouped_child'] : 0;

		if ( $is_grouped && $grouped_child ) {
			return $grouped_child;
		}
		if ( $is_variable && $variation ) {
			return $variation;
		}
		return $product_id;
	}

	/**
	 * Walk parsed blocks depth-first to find the first Fast Checkout block
	 * and return its resolved product ID.
	 *
	 * Results are cached per post ID.
	 *
	 * @param \WP_Post|null $post The post to inspect.
	 * @return int|null Product ID or null.
	 */
	public static function get_block_product_id( $post ) {
		if ( ! $post ) {
			return null;
		}
		if ( isset( self::$post_product_cache[ $post->ID ] ) ) {
			return self::$post_product_cache[ $post->ID ];
		}
		$blocks = self::get_parsed_blocks( $post );
		$result = self::find_fast_checkout_block_product( $blocks );
		self::$post_product_cache[ $post->ID ] = $result;
		return $result;
	}

	/**
	 * Recursively search blocks for the first Fast Checkout block.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return int|null Product ID or null.
	 */
	private static function find_fast_checkout_block_product( $blocks ) {
		foreach ( $blocks as $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				return self::resolve_product_id_from_attrs( $block['attrs'] ?? [] );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_fast_checkout_block_product( $block['innerBlocks'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Get the parsed attributes of the first Fast Checkout block in the post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Block attributes.
	 */
	private static function get_block_attributes( $post ) {
		$blocks = self::get_parsed_blocks( $post );
		$block  = self::find_fast_checkout_block( $blocks );
		return $block['attrs'] ?? [];
	}

	/**
	 * Resolve the effective product ID for cart replacement, applying query
	 * param overrides and runtime first-child fallback for grouped products.
	 *
	 * @param array $attrs Block attributes.
	 * @param array $qp    Sanitized query params.
	 * @return int|null Resolved product/variation ID, or null.
	 */
	private static function resolve_effective_product_id( $attrs, $qp ) {
		if ( empty( $attrs['product'] ) ) {
			return null;
		}
		$is_grouped  = ! empty( $attrs['is_grouped'] );
		$is_variable = ! empty( $attrs['is_variable'] );

		// Grouped: query param override → attribute → first child (runtime).
		if ( $is_grouped ) {
			$parent         = (int) $attrs['product'];
			$valid_children = [];
			if ( function_exists( 'wc_get_product' ) ) {
				$parent_product = wc_get_product( $parent );
				if ( $parent_product && method_exists( $parent_product, 'get_children' ) ) {
					$valid_children = array_map( 'intval', $parent_product->get_children() );
				}
			}
			if ( ! empty( $qp['grouped_child'] ) && in_array( (int) $qp['grouped_child'], $valid_children, true ) ) {
				return (int) $qp['grouped_child'];
			}
			if ( ! empty( $attrs['grouped_child'] ) && in_array( (int) $attrs['grouped_child'], $valid_children, true ) ) {
				return (int) $attrs['grouped_child'];
			}
			if ( ! empty( $valid_children ) ) {
				return (int) $valid_children[0];
			}
			return $parent;
		}

		// Variable: query param > attribute > first variation (runtime).
		if ( $is_variable ) {
			if ( ! empty( $qp['variation'] ) ) {
				return (int) $qp['variation'];
			}
			if ( ! empty( $attrs['variation'] ) ) {
				return (int) $attrs['variation'];
			}
			if ( function_exists( 'wc_get_product' ) ) {
				$parent = wc_get_product( (int) $attrs['product'] );
				if ( $parent && method_exists( $parent, 'get_children' ) ) {
					$children = array_map( 'intval', $parent->get_children() );
					if ( ! empty( $children ) ) {
						return $children[0];
					}
				}
			}
		}

		return (int) $attrs['product'];
	}

	/**
	 * Register the block bindings source.
	 */
	public static function register_bindings_source() {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		register_block_bindings_source(
			self::BINDINGS_SOURCE,
			[
				'label'              => __( 'Fast Checkout Product', 'newspack-blocks' ),
				'get_value_callback' => [ __CLASS__, 'bindings_get_value' ],
				'uses_context'       => [ self::CONTEXT_PRODUCT_KEY, self::CONTEXT_VARIATION_KEY ],
			]
		);
	}

	/**
	 * Resolve a product field value for block bindings.
	 *
	 * @param array  $source_args    Source arguments including 'field'.
	 * @param object $block          The block instance with context.
	 * @param string $attribute_name The bound attribute name.
	 * @return string Resolved value or empty string.
	 */
	public static function bindings_get_value( $source_args, $block, $attribute_name ) {
		$field        = $source_args['field'] ?? '';
		$product_id   = $block->context[ self::CONTEXT_PRODUCT_KEY ] ?? 0;
		$variation_id = $block->context[ self::CONTEXT_VARIATION_KEY ] ?? 0;

		// Variation takes precedence.
		$resolved_id = $variation_id ? (int) $variation_id : (int) $product_id;
		if ( ! $resolved_id ) {
			return '';
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $resolved_id );
		if ( ! $product ) {
			return '';
		}

		switch ( $field ) {
			case 'title':
				return $product->get_name();
			case 'short_description':
				return $product->get_short_description();
			case 'price':
				return wc_price( $product->get_price() );
			case 'price_raw':
				return (string) $product->get_price();
			case 'image_url':
				$image_url = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
				return $image_url ? $image_url : '';
			case 'url':
				return get_permalink( $product->get_id() );
			default:
				return '';
		}
	}

	/**
	 * Mark the current page as non-cacheable when a Fast Checkout block is present.
	 */
	public static function mark_page_noncacheable() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_block( self::BLOCK_NAME, $post ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	/**
	 * Flag the current page as a WooCommerce checkout page when it contains
	 * a Fast Checkout block. This ensures payment gateway scripts (Stripe, etc.)
	 * enqueue their initialization data.
	 *
	 * @param bool $is_checkout Current checkout flag.
	 * @return bool
	 */
	public static function maybe_flag_as_checkout( $is_checkout ) {
		if ( $is_checkout || is_admin() || ! is_singular() ) {
			return $is_checkout;
		}
		$post = get_post();
		if ( $post && has_block( self::BLOCK_NAME, $post ) ) {
			return true;
		}
		return $is_checkout;
	}

	/**
	 * Hide the "Return to Cart" link in the checkout actions block when
	 * rendering inside a Fast Checkout page.
	 *
	 * @param array $parsed_block Parsed block data.
	 * @return array
	 */
	public static function filter_checkout_actions_block( $parsed_block ) {
		if ( 'woocommerce/checkout-actions-block' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $parsed_block;
		}
		if ( is_admin() || ! is_singular() ) {
			return $parsed_block;
		}
		$post = get_post();
		if ( ! $post || ! has_block( self::BLOCK_NAME, $post ) ) {
			return $parsed_block;
		}
		$parsed_block['attrs']['showReturnToCart'] = false;
		return $parsed_block;
	}

	/**
	 * Read and sanitize the supported Fast Checkout query parameters.
	 *
	 * @return array Associative array of query parameter values (only non-empty ones).
	 */
	private static function get_query_params() {
		$params = [];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::QP_EMAIL ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$email = sanitize_email( wp_unslash( $_GET[ self::QP_EMAIL ] ) );
			if ( $email && is_email( $email ) ) {
				$params['email'] = $email;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::QP_QTY ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$qty_raw = wp_unslash( $_GET[ self::QP_QTY ] );
			$qty     = (int) filter_var( $qty_raw, FILTER_SANITIZE_NUMBER_INT );
			if ( $qty > 0 ) {
				$params['qty'] = $qty;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::QP_COUPON ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$coupon = sanitize_text_field( wp_unslash( $_GET[ self::QP_COUPON ] ) );
			if ( $coupon ) {
				$params['coupon'] = $coupon;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::QP_VARIATION ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$variation_raw = wp_unslash( $_GET[ self::QP_VARIATION ] );
			$variation     = (int) filter_var( $variation_raw, FILTER_SANITIZE_NUMBER_INT );
			if ( $variation > 0 ) {
				$params['variation'] = $variation;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::QP_GROUPED_CHILD ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$grouped_child_raw = wp_unslash( $_GET[ self::QP_GROUPED_CHILD ] );
			$grouped_child     = (int) filter_var( $grouped_child_raw, FILTER_SANITIZE_NUMBER_INT );
			if ( $grouped_child > 0 ) {
				$params['grouped_child'] = $grouped_child;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::QP_PRICE ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$price_raw = sanitize_text_field( wp_unslash( $_GET[ self::QP_PRICE ] ) );
			if ( is_numeric( $price_raw ) && (float) $price_raw > 0 ) {
				$params['price'] = (float) $price_raw;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::QP_SUCCESS ] ) ) {
			// Restrict to http/https so unsafe schemes (e.g. javascript:) can't
			// slip through to the post-purchase redirect.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$success = esc_url_raw( wp_unslash( $_GET[ self::QP_SUCCESS ] ), [ 'http', 'https' ] );
			if ( $success ) {
				$params['success'] = $success;
			}
		}

		return $params;
	}

	/**
	 * Replace the WooCommerce cart contents with the block's product.
	 *
	 * Supports URL query parameters to override variation, quantity, price,
	 * coupon, billing email, and post-purchase redirect URL.
	 */
	public static function maybe_replace_cart() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_block( self::BLOCK_NAME, $post ) ) {
			return;
		}

		$qp         = self::get_query_params();
		$attrs      = self::get_block_attributes( $post );
		$product_id = self::resolve_effective_product_id( $attrs, $qp );
		$quantity   = $qp['qty'] ?? 1;

		if ( ! $product_id ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			return;
		}
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		// Idempotency: if the cart already has exactly the right item, do nothing.
		$cart_contents = $cart->get_cart();
		if ( 1 === count( $cart_contents ) && empty( $qp ) ) {
			$item        = reset( $cart_contents );
			$cart_pid    = $product->is_type( 'variation' )
				? (int) $item['variation_id']
				: (int) $item['product_id'];
			$matches_id  = $cart_pid === $product_id;
			$matches_qty = (int) $quantity === (int) $item['quantity'];

			// NYP products require a valid nyp value to checkout. If the cart
			// item was added before the suggested-price fallback landed, it
			// will be missing — fail idempotency so we re-populate.
			$is_nyp        = class_exists( '\WC_Name_Your_Price_Helpers' ) && \WC_Name_Your_Price_Helpers::is_nyp( $product_id );
			$has_valid_nyp = ! $is_nyp || ( isset( $item['nyp'] ) && is_numeric( $item['nyp'] ) && (float) $item['nyp'] > 0 );

			if ( $matches_id && $matches_qty && $has_valid_nyp ) {
				return;
			}
		}

		// Build cart item data.
		$cart_item_data = [
			self::CART_ITEM_SOURCE_KEY => $post->ID,
		];

		// Store fc_success override in cart item data so it carries to the order.
		if ( ! empty( $qp['success'] ) ) {
			$cart_item_data['_fc_success_url'] = $qp['success'];
		}

		// Handle Name Your Price: fc_price > nyp_price attr > suggested > minimum.
		if ( class_exists( '\WC_Name_Your_Price_Helpers' ) && \WC_Name_Your_Price_Helpers::is_nyp( $product_id ) ) {
			$price = ! empty( $qp['price'] ) ? (float) $qp['price'] : null;
			if ( null === $price && ! empty( $attrs['nyp_price'] ) && is_numeric( $attrs['nyp_price'] ) ) {
				$price = (float) $attrs['nyp_price'];
			}
			if ( null === $price ) {
				$price = (float) \WC_Name_Your_Price_Helpers::get_suggested_price( $product_id );
			}
			if ( ! $price ) {
				$price = (float) \WC_Name_Your_Price_Helpers::get_minimum_price( $product_id );
			}
			if ( $price > 0 ) {
				$min_price = \WC_Name_Your_Price_Helpers::get_minimum_price( $product_id );
				$max_price = \WC_Name_Your_Price_Helpers::get_maximum_price( $product_id );
				$price     = ! empty( $max_price ) ? min( $price, (float) $max_price ) : $price;
				$price     = ! empty( $min_price ) ? max( $price, (float) $min_price ) : $price;
				$cart_item_data['nyp'] = (float) \WC_Name_Your_Price_Helpers::standardize_number( $price );
			}
		}

		/**
		 * Filter the cart item data added by Fast Checkout.
		 *
		 * @param array    $cart_item_data Cart item data.
		 * @param int      $product_id     Resolved product or variation ID.
		 * @param \WP_Post $post           The source post.
		 * @param array    $qp             Sanitized query parameters.
		 */
		$cart_item_data = apply_filters( 'newspack_blocks_fast_checkout_cart_item_data', $cart_item_data, $product_id, $post, $qp );

		$cart->empty_cart();

		// Clear stale validation notices from the prior cart state — e.g. WC NYP's
		// `check_cart_items` may have added an error notice on `wp_loaded` (before
		// this action) for an item that's about to be replaced.
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices( 'error' );
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			$cart->add_to_cart( $parent_id, $quantity, $product_id, [], $cart_item_data );
		} else {
			$cart->add_to_cart( $product_id, $quantity, 0, [], $cart_item_data );
		}

		// Apply coupon.
		if ( ! empty( $qp['coupon'] ) ) {
			$cart->apply_coupon( $qp['coupon'] );
		}

		// Pre-fill billing email.
		if ( ! empty( $qp['email'] ) ) {
			WC()->customer->set_billing_email( $qp['email'] );
			WC()->customer->save();
		}
	}

	/**
	 * Filter the rendered output of the Fast Checkout block.
	 *
	 * @param string $content Rendered block content.
	 * @param array  $block   Block data including attrs.
	 * @return string Filtered content.
	 */
	public static function filter_render( $content, $block ) {
		$attrs      = $block['attrs'] ?? [];
		$qp         = self::get_query_params();
		$product_id = self::resolve_effective_product_id( $attrs, $qp );

		if ( $product_id && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product && $product->is_purchasable() ) {
				return $content;
			}
		}

		return '<div class="wp-block-newspack-blocks-fast-checkout--unavailable">'
			. esc_html__( 'This product is no longer available.', 'newspack-blocks' )
			. '</div>';
	}

	/**
	 * Override the WooCommerce return URL for orders placed via Fast Checkout.
	 *
	 * @param string    $url   Default return URL.
	 * @param \WC_Order $order The order.
	 * @return string Possibly overridden URL.
	 */
	public static function maybe_override_return_url( $url, $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $url;
		}
		foreach ( $order->get_items() as $item ) {
			$source_post_id = $item->get_meta( self::CART_ITEM_SOURCE_KEY );
			if ( ! $source_post_id ) {
				continue;
			}
			// fc_success query param takes precedence over block attribute.
			$fc_success = $item->get_meta( '_fc_success_url' );
			if ( $fc_success ) {
				return $fc_success;
			}
			$custom_url = self::get_after_success_url( (int) $source_post_id );
			if ( $custom_url ) {
				return $custom_url;
			}
		}
		return $url;
	}

	/**
	 * Get the afterSuccessURL from a Fast Checkout block in a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Custom URL or empty string.
	 */
	private static function get_after_success_url( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$blocks = self::get_parsed_blocks( $post );
		$block  = self::find_fast_checkout_block( $blocks );
		if ( ! $block ) {
			return '';
		}
		$after_success_url = $block['attrs']['afterSuccessURL'] ?? '';
		// Restrict to http/https so unsafe schemes can't be used as a redirect target.
		return $after_success_url ? esc_url_raw( $after_success_url, [ 'http', 'https' ] ) : '';
	}

	/**
	 * Recursively find the first Fast Checkout block.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array|null Block array or null.
	 */
	private static function find_fast_checkout_block( $blocks ) {
		foreach ( $blocks as $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				return $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_fast_checkout_block( $block['innerBlocks'] );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Attach source post meta to order line items.
	 *
	 * @param \WC_Order_Item_Product $item          The line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item values.
	 * @param \WC_Order              $order         The order.
	 */
	public static function attach_line_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! isset( $values[ self::CART_ITEM_SOURCE_KEY ] ) ) {
			return;
		}
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}
		$item->add_meta_data( self::CART_ITEM_SOURCE_KEY, (int) $values[ self::CART_ITEM_SOURCE_KEY ], true );
		if ( ! empty( $values['_fc_success_url'] ) ) {
			$item->add_meta_data( '_fc_success_url', esc_url_raw( $values['_fc_success_url'] ), true );
		}
	}

	/**
	 * Filter handler that adapts the WC Store API add-to-cart payload to inject
	 * NYP cart_item_data when the reader-set price is present in the request.
	 *
	 * @param array            $request_data WC's normalized cart_item_data array.
	 * @param \WP_REST_Request $request      Underlying REST request.
	 * @return array
	 */
	public static function store_api_nyp_bridge_handler( $request_data, $request ) {
		$product_id = isset( $request_data['product_id'] ) ? (int) $request_data['product_id'] : 0;
		if ( ! $product_id && method_exists( $request, 'get_param' ) ) {
			$product_id = (int) $request->get_param( 'id' );
		}
		$cart_item_data                 = $request_data['cart_item_data'] ?? [];
		$cart_item_data                 = self::propagate_source_post( $cart_item_data, $request );
		$cart_item_data                 = self::store_api_nyp_bridge( $cart_item_data, $product_id, $request );
		$request_data['cart_item_data'] = $cart_item_data;
		return $request_data;
	}

	/**
	 * Propagate the Fast Checkout source-post marker from the Store API
	 * request into cart_item_data, so cart items added via selector swaps
	 * remain identifiable as Fast Checkout items (drives edit-link
	 * suppression, post-purchase redirect, and order line item meta).
	 *
	 * @param array            $cart_item_data Existing cart item data.
	 * @param \WP_REST_Request $request        REST request.
	 * @return array
	 */
	public static function propagate_source_post( $cart_item_data, $request ) {
		if ( isset( $cart_item_data[ self::CART_ITEM_SOURCE_KEY ] ) ) {
			return $cart_item_data;
		}
		$body = method_exists( $request, 'get_body_params' ) ? $request->get_body_params() : [];
		$raw  = $body[ self::CART_ITEM_SOURCE_KEY ] ?? null;
		if ( null === $raw && method_exists( $request, 'get_json_params' ) ) {
			$json = $request->get_json_params();
			$raw  = is_array( $json ) ? ( $json[ self::CART_ITEM_SOURCE_KEY ] ?? null ) : null;
		}
		if ( $raw && is_numeric( $raw ) && (int) $raw > 0 ) {
			$cart_item_data[ self::CART_ITEM_SOURCE_KEY ] = (int) $raw;
		}
		return $cart_item_data;
	}

	/**
	 * Pure helper: inject the NYP value into cart_item_data when the request
	 * body carries one and the target product is NYP-eligible. Clamps to min/max.
	 *
	 * @param array            $cart_item_data Existing cart item data.
	 * @param int              $product_id     Target product.
	 * @param \WP_REST_Request $request        REST request.
	 * @return array
	 */
	public static function store_api_nyp_bridge( $cart_item_data, $product_id, $request ) {
		if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
			return $cart_item_data;
		}
		if ( ! \WC_Name_Your_Price_Helpers::is_nyp( $product_id ) ) {
			return $cart_item_data;
		}
		$body = method_exists( $request, 'get_body_params' ) ? $request->get_body_params() : [];
		$raw  = $body['nyp'] ?? null;
		if ( null === $raw && method_exists( $request, 'get_json_params' ) ) {
			$json = $request->get_json_params();
			$raw  = is_array( $json ) ? ( $json['nyp'] ?? null ) : null;
		}
		// No explicit price provided — fall back to the product's suggested
		// price, then minimum. Lets selector swaps (which don't carry an nyp
		// value) succeed for grouped/variable products with NYP children.
		if ( null === $raw || ! is_numeric( $raw ) ) {
			$raw = \WC_Name_Your_Price_Helpers::get_suggested_price( $product_id );
			if ( ! is_numeric( $raw ) || (float) $raw <= 0 ) {
				$raw = \WC_Name_Your_Price_Helpers::get_minimum_price( $product_id );
			}
			if ( ! is_numeric( $raw ) || (float) $raw <= 0 ) {
				return $cart_item_data;
			}
		}
		$price     = (float) $raw;
		$min_price = \WC_Name_Your_Price_Helpers::get_minimum_price( $product_id );
		$max_price = \WC_Name_Your_Price_Helpers::get_maximum_price( $product_id );
		$price     = ! empty( $max_price ) ? min( $price, (float) $max_price ) : $price;
		$price     = ! empty( $min_price ) ? max( $price, (float) $min_price ) : $price;
		$cart_item_data['nyp'] = (float) \WC_Name_Your_Price_Helpers::standardize_number( $price );
		return $cart_item_data;
	}

	/**
	 * Suppress WC Name Your Price's "Edit price" link in the checkout cart
	 * summary for cart items added via Fast Checkout — the reader edits the
	 * amount through our selector blocks instead.
	 *
	 * @param bool  $show      Whether the link should render.
	 * @param array $cart_item The cart item.
	 * @return bool
	 */
	public static function suppress_nyp_edit_link( $show, $cart_item ) {
		if ( is_array( $cart_item ) && isset( $cart_item[ self::CART_ITEM_SOURCE_KEY ] ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Add product context keys to core block metadata.
	 *
	 * @param array $metadata Block type metadata.
	 * @return array Filtered metadata.
	 */
	public static function add_context_to_core_blocks( $metadata ) {
		if ( ! is_array( $metadata ) || empty( $metadata['name'] ) ) {
			return $metadata;
		}
		if ( ! in_array( $metadata['name'], self::CORE_CONTEXT_BLOCKS, true ) ) {
			return $metadata;
		}
		$existing = isset( $metadata['usesContext'] ) && is_array( $metadata['usesContext'] )
			? $metadata['usesContext']
			: [];
		$metadata['usesContext'] = array_values(
			array_unique(
				array_merge( $existing, [ self::CONTEXT_PRODUCT_KEY, self::CONTEXT_VARIATION_KEY ] )
			)
		);
		return $metadata;
	}
}

Fast_Checkout::init();
