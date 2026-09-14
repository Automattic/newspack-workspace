<?php
/**
 * How a subscriber discount is presented to the reader.
 *
 * The price itself is already rendered by WooCommerce as a sale (original
 * struck through, subscriber price beside it), so this adds only what a sale
 * does not say on its own: that the lower price is a subscriber benefit.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Reader-facing subscriber discount messaging.
 */
class Subscriber_Discounts_Display {

	/**
	 * Class applied to every element this feature renders, so a theme can style
	 * subscriber discounts with one selector.
	 */
	const CSS_CLASS_PREFIX = 'newspack-subscriber-discount';

	/**
	 * Hidden order-line meta recording that the line was discounted.
	 *
	 * Underscore-prefixed so WooCommerce keeps it out of the customer-visible
	 * meta list — the note is rendered deliberately, not as raw meta.
	 */
	const ORDER_ITEM_META_KEY = '_newspack_subscriber_discount';

	/**
	 * Register the display hooks.
	 */
	public static function init() {
		add_action( 'wp_loaded', [ __CLASS__, 'register_display_hooks' ], 15 );
	}

	/**
	 * Attach the reader-facing hooks.
	 */
	public static function register_display_hooks() {
		if ( ! Subscriber_Commerce::is_enforcement_active() ) {
			return;
		}
		add_filter( 'woocommerce_sale_flash', [ __CLASS__, 'filter_sale_flash' ], 10, 3 );
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_product_summary_note' ], 11 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'filter_cart_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'stamp_order_line_item' ], 10, 3 );
		add_action( 'woocommerce_order_item_meta_end', [ __CLASS__, 'render_order_item_note' ], 10, 4 );
	}

	/**
	 * Record on the order line that its price was a subscriber discount.
	 *
	 * The order keeps the price it was placed at, so afterwards there is nothing
	 * left to recompute from — whether the reader's subscription later lapses or
	 * the rule changes, the order should still explain itself.
	 *
	 * @param \WC_Order_Item_Product $item          Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item.
	 */
	public static function stamp_order_line_item( $item, $cart_item_key, $values ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( self::product_is_discounted( $values['data'] ?? null ) ) {
			$item->add_meta_data( self::ORDER_ITEM_META_KEY, 'yes', true );
		}
	}

	/**
	 * Label the sale badge as a subscriber discount.
	 *
	 * WooCommerce already renders a badge for what it believes is a sale; when
	 * the reduction is a subscriber discount, this relabels it and adds a class
	 * of ours alongside the native `onsale` one so it inherits the theme's badge
	 * styling.
	 *
	 * @param string      $html    Badge markup.
	 * @param \WP_Post    $post    Product post.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function filter_sale_flash( $html, $post, $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! self::product_is_discounted( $product ) ) {
			return $html;
		}
		return sprintf(
			'<span class="onsale %s">%s</span>',
			esc_attr( self::CSS_CLASS_PREFIX . '-badge' ),
			esc_html__( 'Subscriber discount', 'newspack-plugin' )
		);
	}

	/**
	 * Explain the lower price under it on the product page.
	 */
	public static function render_product_summary_note() {
		global $product;
		if ( ! self::product_is_discounted( $product ) ) {
			return;
		}
		$saving = self::saving_for( $product );
		if ( null === $saving ) {
			return;
		}
		printf(
			'<p class="%s">%s</p>',
			esc_attr( self::CSS_CLASS_PREFIX . '-note' ),
			sprintf(
				/* translators: %s: the amount the subscriber saves, e.g. "£51.00". */
				esc_html__( 'Subscriber discount applied — you save %s.', 'newspack-plugin' ),
				wp_kses_post( wc_price( $saving ) )
			)
		);
	}

	/**
	 * Say why a cart or checkout line is cheaper than the list price.
	 *
	 * @param array $item_data Existing line item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function filter_cart_item_data( $item_data, $cart_item ) {
		$product = $cart_item['data'] ?? null;
		if ( self::product_is_discounted( $product ) ) {
			// Both `key` and `name` are set: the classic cart labels the row from
			// `key`, the Cart and Checkout blocks read `name`, and leaving either
			// empty renders a bare colon with nothing before it. WooCommerce
			// escapes both on output, so they are passed through unescaped here.
			$item_data[] = [
				'key'     => __( 'Discount', 'newspack-plugin' ),
				'name'    => __( 'Discount', 'newspack-plugin' ),
				'value'   => __( 'Subscriber discount applied', 'newspack-plugin' ),
				'display' => __( 'Subscriber discount applied', 'newspack-plugin' ),
			];
		}
		return $item_data;
	}

	/**
	 * Repeat the explanation on the order confirmation and order emails, where
	 * the reader no longer has the list price to compare against.
	 *
	 * @param int                    $item_id    Order item ID.
	 * @param \WC_Order_Item_Product $item       Order item.
	 * @param \WC_Order              $order      Order.
	 * @param bool                   $plain_text Whether this is the plain-text email template.
	 */
	public static function render_order_item_note( $item_id, $item, $order, $plain_text = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! is_callable( [ $item, 'get_meta' ] ) || ! $item->get_meta( self::ORDER_ITEM_META_KEY ) ) {
			return;
		}
		$note = __( 'Subscriber discount applied', 'newspack-plugin' );

		// The same hook renders the plain-text order emails, where both markup
		// and HTML entities arrive literally in the reader's inbox.
		if ( $plain_text ) {
			echo "\n" . $note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email body; escaping would render entities literally.
			return;
		}
		printf( '<p class="%s">%s</p>', esc_attr( self::CSS_CLASS_PREFIX . '-note' ), esc_html( $note ) );
	}

	/**
	 * Whether the reader's price for a product is a subscriber discount rather
	 * than the price everyone pays.
	 *
	 * @param mixed $product Product, if any.
	 * @return bool
	 */
	private static function product_is_discounted( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}
		return null !== self::subscriber_price_for( $product );
	}

	/**
	 * What the reader saves on a product, or null when nothing is discounted.
	 *
	 * @param \WC_Product $product Product.
	 * @return float|null
	 */
	private static function saving_for( $product ) {
		$subscriber_price = self::subscriber_price_for( $product );
		if ( null === $subscriber_price ) {
			return null;
		}
		return self::undiscounted_price( $product ) - $subscriber_price;
	}

	/**
	 * The subscriber price for a product, computed from its undiscounted price.
	 *
	 * @param \WC_Product $product Product.
	 * @return float|null
	 */
	private static function subscriber_price_for( $product ) {
		return Subscriber_Discounts_Pricing::get_subscriber_price( self::undiscounted_price( $product ), $product );
	}

	/**
	 * A product's price with subscriber discounts stood down.
	 *
	 * @param \WC_Product $product Product.
	 * @return float
	 */
	private static function undiscounted_price( $product ) {
		return (float) Subscriber_Discounts_Pricing::undiscounted_price( $product );
	}
}

Subscriber_Discounts_Display::init();
