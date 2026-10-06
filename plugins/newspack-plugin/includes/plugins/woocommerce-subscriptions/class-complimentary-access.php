<?php
/**
 * Complimentary access products.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Marks the subscription products that grant complimentary ("comp") access.
 *
 * Access Control grants comp access as a $0 subscription on a product an admin assigns
 * by hand. Nothing else tells that subscription apart from a paid one, so reports count
 * comp readers as paying subscribers. A product flag names the comp products; every
 * subscription and order holding one carries the `_newspack_complimentary` stamp, which
 * reports, exporters and the REST API key on. Comp readers stay subscribers for access,
 * ESP sync and analytics: only reporting and display look at the stamp, and checkout
 * looks at the product flag.
 *
 * One flagged line item stamps the whole subscription or order. Checkout can't mix a comp
 * product with a paid one, so a mixed order comes only from an admin adding comp access
 * to a reader's subscription, and that access is what the stamp records.
 *
 * The stamp is derived from the flag, never set on its own. Each save of a subscription or
 * order recomputes it from the order's line items, and changing a product's flag restamps
 * the subscriptions already on it. Renewal orders copy their subscription's meta, so they
 * inherit the stamp as well as recompute it.
 */
class Complimentary_Access {
	/**
	 * Meta key of the product flag and of the subscription and order stamp.
	 */
	const META_KEY = '_newspack_complimentary';

	/**
	 * Key of the product flag in the custom product options.
	 */
	const OPTION_NAME = 'newspack_complimentary';

	/**
	 * Product types the flag applies to: whatever a subscription line item holds.
	 */
	const PRODUCT_TYPES = [ 'subscription', 'subscription_variation' ];

	/**
	 * `created_via` values of subscriptions an admin granted: by hand, or through the
	 * Access Control migrators. Any other value counts as a reader's purchase, including
	 * ones this list doesn't know about, so an unrecognised source never flags a product.
	 */
	const GRANT_SOURCES = [ 'admin', 'migration', 'manual migration' ];

	/**
	 * Action Scheduler hook that restamps a batch of subscriptions.
	 */
	const SYNC_ACTION = 'newspack_complimentary_access_sync';

	/**
	 * Subscriptions restamped per scheduled action. Each brings every renewal order it
	 * has, so a long-running monthly comp is dozens of orders.
	 */
	const SYNC_BATCH_SIZE = 20;

	/**
	 * Products being deleted, whose flag removal must not restamp anything.
	 *
	 * @var int[]
	 */
	private static $deleting_product_ids = [];

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		if ( ! WooCommerce_Subscriptions::is_active() ) {
			return;
		}

		add_filter( 'newspack_custom_product_options', [ __CLASS__, 'add_custom_product_option' ] );

		// After Product_Purchase_Restriction (999), which can otherwise re-allow a purchase.
		foreach ( [ 'woocommerce_is_purchasable', 'woocommerce_variation_is_purchasable', 'woocommerce_subscription_is_purchasable', 'woocommerce_subscription_variation_is_purchasable' ] as $filter ) {
			add_filter( $filter, [ __CLASS__, 'filter_is_purchasable' ], 1000, 2 );
		}
		add_filter( 'woocommerce_variation_is_visible', [ __CLASS__, 'filter_variation_is_visible' ], 10, 4 );

		add_action( 'woocommerce_before_subscription_object_save', [ __CLASS__, 'stamp' ] );
		add_action( 'woocommerce_before_order_object_save', [ __CLASS__, 'stamp' ] );

		add_action( 'before_delete_post', [ __CLASS__, 'handle_product_deletion' ] );
		add_action( 'added_post_meta', [ __CLASS__, 'handle_flag_added' ], 10, 4 );
		add_action( 'updated_post_meta', [ __CLASS__, 'handle_flag_changed' ], 10, 3 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'handle_flag_changed' ], 10, 3 );
		add_action( self::SYNC_ACTION, [ __CLASS__, 'sync_subscriptions' ] );

		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_fields' ] );
		add_filter( 'rest_shop_subscription_collection_params', [ __CLASS__, 'add_rest_collection_param' ] );
		add_filter( 'rest_shop_order_collection_params', [ __CLASS__, 'add_rest_collection_param' ] );
		// The orders controller's last query filter, which the subscriptions controller inherits.
		// Earlier filters lose their meta query on non-HPOS sites when `customer` is passed.
		add_filter( 'woocommerce_rest_orders_prepare_object_query', [ __CLASS__, 'filter_rest_query' ], 10, 2 );
	}

	/**
	 * Add the "Complimentary access" checkbox to subscription products and variations.
	 *
	 * @param array $custom_options Keyed array of custom product options.
	 *
	 * @return array
	 */
	public static function add_custom_product_option( $custom_options ) {
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return $custom_options;
		}
		$custom_options[ self::OPTION_NAME ] = [
			'id'            => self::META_KEY,
			'label'         => __( 'Complimentary access', 'newspack-plugin' ),
			'description'   => __( 'Grants complimentary access assigned by an admin. Readers can\'t buy it, and its subscriptions and orders are marked complimentary so reports can leave them out.', 'newspack-plugin' ),
			'default'       => 'no',
			'product_types' => self::PRODUCT_TYPES,
			'type'          => 'boolean',
			'wrapper_class' => 'show_if_subscription',
		];
		return $custom_options;
	}

	/**
	 * Whether a product or variation is flagged as complimentary access.
	 *
	 * Read straight from meta rather than through the custom option, which is only
	 * registered while content gates are on: a product flagged then still counts. A flag
	 * left behind on a product converted to another type is ignored, since the editor no
	 * longer shows the checkbox that would clear it.
	 *
	 * @param \WC_Product|int $product Product, variation, or ID.
	 *
	 * @return bool
	 */
	public static function is_complimentary_product( $product ): bool {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( $product );
		if ( ! $product || ! $product->is_type( self::PRODUCT_TYPES ) ) {
			return false;
		}
		return wc_string_to_bool( $product->get_meta( self::META_KEY ) );
	}

	/**
	 * Whether a subscription or order is stamped as complimentary.
	 *
	 * @param \WC_Abstract_Order $order Subscription or order.
	 *
	 * @return bool
	 */
	public static function is_complimentary_order( $order ): bool {
		return wc_string_to_bool( $order->get_meta( self::META_KEY ) );
	}

	/**
	 * Whether a subscription or order holds a flagged product, judged from its line items.
	 *
	 * A variation is judged on its own flag, not its parent's: a variable product can
	 * sell a paid variation beside a comp one. A line item whose product no longer exists
	 * can't be judged, so the answer is null unless another item is flagged. That
	 * includes a deleted variation, whose line item WooCommerce then reads back as the
	 * variable parent.
	 *
	 * @param \WC_Abstract_Order $order Subscription or order.
	 *
	 * @return bool|null Null when it can't be told.
	 */
	public static function has_complimentary_item( $order ): ?bool {
		$has_unknown_item = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_callable( [ $item, 'get_product_id' ] ) ) {
				continue;
			}
			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$product    = $product_id ? wc_get_product( $product_id ) : false;
			if ( ! $product || $product->is_type( [ 'variable', 'variable-subscription' ] ) ) {
				$has_unknown_item = true;
				continue;
			}
			if ( self::is_complimentary_product( $product ) ) {
				return true;
			}
		}
		return $has_unknown_item ? null : false;
	}

	/**
	 * Set or clear the stamp on a subscription or order from its line items.
	 *
	 * Runs before every save, so it only stages the meta change. A stamp is kept while a
	 * line item's product can't be loaded: deleting a comp product must not turn its
	 * readers into paying subscribers in reports.
	 *
	 * @param \WC_Abstract_Order $order Subscription or order about to be saved.
	 */
	public static function stamp( $order ): void {
		$has_complimentary_item = self::has_complimentary_item( $order );
		if ( true === $has_complimentary_item ) {
			if ( ! self::is_complimentary_order( $order ) ) {
				$order->update_meta_data( self::META_KEY, 'yes' );
			}
		} elseif ( false === $has_complimentary_item && $order->meta_exists( self::META_KEY ) ) {
			$order->delete_meta_data( self::META_KEY );
		}
	}

	/**
	 * Refuse a flagged product at every checkout, renewal carts included.
	 *
	 * Admins still assign it: adding a product to a subscription or order in the
	 * dashboard or the migrators doesn't check purchasability. A $0 renewal completes
	 * without a cart, so comp subscriptions keep renewing; a flagged product with a price
	 * can't be renewed through the cart, which is one more reason to flag only $0 products.
	 *
	 * @param bool        $is_purchasable Whether the product is purchasable.
	 * @param \WC_Product $product        Product.
	 *
	 * @return bool
	 */
	public static function filter_is_purchasable( $is_purchasable, $product ) {
		if ( $is_purchasable && $product instanceof \WC_Product && self::is_complimentary_product( $product ) ) {
			return false;
		}
		return $is_purchasable;
	}

	/**
	 * Leave a flagged variation off its product page.
	 *
	 * A variation that can't be bought would otherwise still be offered, and Subscriptions
	 * explains any unpurchasable variation as "You have added a variation of this product
	 * to the cart already", even to a reader with an empty cart.
	 *
	 * @param bool                  $visible      Whether the variation is visible.
	 * @param int                   $variation_id Variation ID.
	 * @param int                   $parent_id    Parent product ID.
	 * @param \WC_Product_Variation $variation    Variation.
	 *
	 * @return bool
	 */
	public static function filter_variation_is_visible( $visible, $variation_id, $parent_id, $variation ) {
		return $visible && ! self::is_complimentary_product( $variation );
	}

	/**
	 * Note a product being deleted, before WordPress deletes its meta.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function handle_product_deletion( $post_id ) {
		self::$deleting_product_ids[] = (int) $post_id;
	}

	/**
	 * Restamp a product's subscriptions when its flag is first set.
	 *
	 * An option first saved as "no" changes nothing, so it schedules nothing. Saving
	 * every subscription product writes the option, and most are never flagged.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function handle_flag_added( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( self::META_KEY !== $meta_key || ! wc_string_to_bool( $meta_value ) ) {
			return;
		}
		self::schedule_product_sync( $object_id );
	}

	/**
	 * Restamp a product's subscriptions when its flag changes or is removed.
	 *
	 * Deleting the product removes the flag too, but leaves the stamps: they are what
	 * keeps that product's comp readers out of reports.
	 *
	 * @param int|int[] $meta_id   Meta ID or IDs.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public static function handle_flag_changed( $meta_id, $object_id, $meta_key ) {
		if ( self::META_KEY !== $meta_key || in_array( (int) $object_id, self::$deleting_product_ids, true ) ) {
			return;
		}
		self::schedule_product_sync( $object_id );
	}

	/**
	 * Queue the restamping of every subscription on a product, in batches.
	 *
	 * Orders share the posts table with products on a non-HPOS site, so the stamp's own
	 * meta writes land here too; only products are synced.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	public static function schedule_product_sync( $product_id ): void {
		if ( ! in_array( get_post_type( $product_id ), [ 'product', 'product_variation' ], true ) ) {
			return;
		}
		if ( ! function_exists( 'wcs_get_subscriptions_for_product' ) ) {
			return;
		}
		$subscription_ids = array_keys( wcs_get_subscriptions_for_product( $product_id, 'ids', [ 'subscription_status' => 'any' ] ) );
		foreach ( array_chunk( $subscription_ids, self::SYNC_BATCH_SIZE ) as $batch ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::SYNC_ACTION, [ $batch ], 'newspack' );
			} else {
				self::sync_subscriptions( $batch );
			}
		}
	}

	/**
	 * Restamp subscriptions and their related orders.
	 *
	 * Writes the meta alone rather than saving the whole object, so a restamp doesn't fire
	 * the save hooks other integrations sync on. HPOS saves an order after a meta write by
	 * default; that save is switched off here, as WooCommerce does for its own meta-only
	 * writes.
	 *
	 * @param int[] $subscription_ids Subscription IDs.
	 */
	public static function sync_subscriptions( $subscription_ids ): void {
		// A callback of its own, so removing it can't remove another plugin's `__return_false`.
		$skip_save = static function () {
			return false;
		};
		add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', $skip_save );
		try {
			foreach ( (array) $subscription_ids as $subscription_id ) {
				$subscription = wcs_get_subscription( $subscription_id );
				if ( ! $subscription ) {
					continue;
				}
				self::restamp( $subscription );
				foreach ( $subscription->get_related_orders( 'ids', 'any' ) as $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						self::restamp( $order );
					}
				}
			}
		} finally {
			// Action Scheduler runs other actions in the same request.
			remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', $skip_save );
		}
	}

	/**
	 * Recompute and persist one subscription's or order's stamp.
	 *
	 * @param \WC_Abstract_Order $order Subscription or order.
	 */
	private static function restamp( $order ): void {
		self::stamp( $order );
		$order->save_meta_data();
	}

	/**
	 * Whether a product qualifies to be flagged as complimentary access.
	 *
	 * Only a $0 product whose subscriptions were all granted by an admin: sites sell some
	 * $0 products on purpose, such as student and trial plans. Pre-marking runs across many
	 * sites unattended, so it also requires at least one grant; a $0 product nobody holds
	 * yet may be a plan about to go on sale. A migrator is told which product is the comp
	 * target, so it accepts one with no subscriptions.
	 *
	 * @param \WC_Product $product        Simple subscription or subscription variation.
	 * @param bool        $require_grants Whether the product must already have granted subscriptions.
	 *
	 * @return bool
	 */
	public static function qualifies_for_flag( $product, bool $require_grants = true ): bool {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( self::PRODUCT_TYPES ) ) {
			return false;
		}
		// The regular price too: a paid product on a $0 sale would stay flagged after the sale.
		if ( ! WooCommerce_Store_API_Free_Products::is_free( $product ) || 0 < (float) $product->get_regular_price( 'edit' ) ) {
			return false;
		}
		$holders = self::get_subscription_holders( $product->get_id() );
		return 'purchased' !== $holders && ( ! $require_grants || 'granted' === $holders );
	}

	/**
	 * How a product's subscriptions came about.
	 *
	 * Loads each subscription until it finds a purchase. On a product that qualifies that
	 * is every subscription, which only the CLI asks about.
	 *
	 * @param int $product_id Product or variation ID.
	 *
	 * @return string `none` with no subscriptions, `purchased` when any was not granted by an admin, `granted` otherwise.
	 */
	public static function get_subscription_holders( $product_id ): string {
		if ( ! function_exists( 'wcs_get_subscriptions_for_product' ) ) {
			return 'none';
		}
		$holders = 'none';
		foreach ( array_values( array_keys( wcs_get_subscriptions_for_product( $product_id, 'ids', [ 'subscription_status' => 'any' ] ) ) ) as $index => $subscription_id ) {
			// A comp product can hold thousands of subscriptions; don't keep them all in memory.
			if ( $index && 0 === $index % 100 && defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI\Utils\wp_clear_object_cache();
			}
			$subscription = wcs_get_subscription( $subscription_id );
			if ( ! $subscription ) {
				continue;
			}
			if ( ! in_array( $subscription->get_created_via(), self::GRANT_SOURCES, true ) ) {
				return 'purchased';
			}
			$holders = 'granted';
		}
		return $holders;
	}

	/**
	 * Flag a product as complimentary access.
	 *
	 * Saving the flag restamps the product's existing subscriptions.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function flag_product( $product ): void {
		$product->update_meta_data( self::META_KEY, 'yes' );
		$product->save();
	}

	/**
	 * Expose the stamp on subscriptions and orders in the WooCommerce REST API.
	 */
	public static function register_rest_fields() {
		foreach ( [ 'shop_subscription', 'shop_order' ] as $object_type ) {
			register_rest_field(
				$object_type,
				'is_complimentary',
				[
					'get_callback' => function ( $data ) {
						$order = wc_get_order( $data['id'] ?? 0 );
						return $order ? self::is_complimentary_order( $order ) : false;
					},
					'schema'       => [
						'description' => __( 'Whether the subscription or order grants complimentary access.', 'newspack-plugin' ),
						'type'        => 'boolean',
						'context'     => [ 'view', 'edit' ],
						'readonly'    => true,
					],
				]
			);
		}
	}

	/**
	 * Add the `complimentary` filter to the subscription and order list endpoints.
	 *
	 * @param array $params Collection parameters.
	 *
	 * @return array
	 */
	public static function add_rest_collection_param( $params ) {
		$params['complimentary'] = [
			'description'       => __( 'Include complimentary subscriptions or orders, exclude them, or return only them.', 'newspack-plugin' ),
			'type'              => 'string',
			'enum'              => [ 'include', 'exclude', 'only' ],
			'default'           => 'include',
			'validate_callback' => 'rest_validate_request_arg',
		];
		return $params;
	}

	/**
	 * Apply the `complimentary` filter to a list query.
	 *
	 * `exclude` matches a missing stamp, which holds because a cleared stamp is deleted
	 * rather than stored as "no".
	 *
	 * @param array            $args    Query arguments.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array
	 */
	public static function filter_rest_query( $args, $request ) {
		$mode = $request->get_param( 'complimentary' );
		if ( 'only' === $mode ) {
			$clause = [
				'key'   => self::META_KEY,
				'value' => 'yes',
			];
		} elseif ( 'exclude' === $mode ) {
			$clause = [
				'key'     => self::META_KEY,
				'compare' => 'NOT EXISTS',
			];
		} else {
			return $args;
		}
		$args['meta_query']   = $args['meta_query'] ?? []; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$args['meta_query'][] = $clause;
		return $args;
	}
}
