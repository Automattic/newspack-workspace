<?php
/**
 * Newspack Listings - Sets up WooCommerce products for self-serve listings.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use Newspack_Listings\Settings;
use Newspack_Listings\Core;
use Newspack_Listings\Featured;
use Newspack_Listings\Utils;

defined( 'ABSPATH' ) || exit;

require_once NEWSPACK_LISTINGS_PLUGIN_FILE . 'includes/products/class-products-purchase.php';
require_once NEWSPACK_LISTINGS_PLUGIN_FILE . 'includes/products/class-products-ui.php';
require_once NEWSPACK_LISTINGS_PLUGIN_FILE . 'includes/products/class-products-user.php';
require_once NEWSPACK_LISTINGS_PLUGIN_FILE . 'includes/products/class-products-cron.php';

/**
 * Products class.
 * Sets up WooCommerce products for listings.
 */
class Products {
	/**
	 * The option name for the product ID.
	 */
	const PRODUCT_OPTION = 'newspack_listings_product_id';

	/**
	 * Actions that can be triggered by the newspack-listings-products query param.
	 */
	const ACTIONS = [
		'create' => 'create-products',
		'delete' => 'delete-products',
	];

	const ACTION_NONCE = 'newspack_listings_nonce';

	/**
	 * Meta keys for self-serve listing products.
	 */
	const PRODUCT_META_KEYS = [
		'parent'       => 'newspack_listings_parent_product',
		'single'       => 'newspack_listings_single_price',
		'featured'     => 'newspack_listings_featured_add_on',
		'subscription' => 'newspack_listings_subscription_price',
		'premium'      => 'newspack_listings_premium_subscription_add_on',
	];

	/**
	 * Meta keys for self-serve listing orders.
	 */
	const ORDER_META_KEYS = [
		'listing_title'          => 'newspack_listings_order_title',
		'listing_type'           => 'newspack_listings_order_type',
		'listing_original_order' => 'newspack_listings_original_order',
		'listing_renewed'        => 'newspack_listings_renewed_id',
		'purchase_type'          => 'newspack_listings_order_type',
	];

	/**
	 * Meta keys for self-serve listing subscriptions.
	 */
	const SUBSCRIPTION_META_KEYS = [
		'listing_subscription' => 'newspack_listings_is_subscription',
		'is_premium'           => 'newspack_listings_is_premium_subscription',
	];

	/**
	 * Meta keys for purchased listing posts.
	 */
	const POST_META_KEYS = [
		'listing_order'        => 'newspack_listings_order_id',
		'listing_subscription' => 'newspack_listings_subscription_id',
		'listing_has_expired'  => 'newspack_listings_has_expired',
	];

	/**
	 * User meta keys for self-serve listing customers.
	 */
	const CUSTOMER_META_KEYS = [
		'is_listings_customer' => 'newspack_listings_self_serve_customer',
	];

	/**
	 * Initialize self-serve listings features.
	 */
	public static function init() {
		// WP actions to create the necessary products, and to handle submission of the Self-Serve Listings block form.
		add_action( 'woocommerce_after_register_post_type', [ __CLASS__, 'setup' ] );
		add_action( 'wp_loaded', [ __CLASS__, 'create_or_delete_listing_products' ], 99 );

		// When product settings are updated, make sure to update the corresponding WooCommerce products as well.
		add_action( 'update_option', [ __CLASS__, 'update_products' ], 10, 3 );
		add_action( 'updated_post_meta', [ __CLASS__, 'update_product_option' ], 10, 4 );
	}

	/**
	 * Check whether self-serve listings should be active on this site.
	 * Self-serve listings require WooCommerce, WooCommerce Subscriptions,
	 * and the `NEWSPACK_LISTINGS_SELF_SERVE_ENABLED` environment constant.
	 */
	public static function is_active() {
		/**
		 * Enables self-serve listings functionality, allowing readers to purchase
		 * and create their own listings. Requires WooCommerce and WooCommerce
		 * Subscriptions plugins to be active.
		 *
		 * @constant NEWSPACK_LISTINGS_SELF_SERVE_ENABLED
		 * @type     bool
		 * @default  Self-serve listings disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_LISTINGS_SELF_SERVE_ENABLED', true );
		 */
		return class_exists( 'WooCommerce' ) && class_exists( 'WC_Subscriptions_Product' ) && defined( 'NEWSPACK_LISTINGS_SELF_SERVE_ENABLED' ) && NEWSPACK_LISTINGS_SELF_SERVE_ENABLED;
	}

	/**
	 * Verify that the products associated with this feature set are valid and published.
	 *
	 * @return boolean True if valid.
	 */
	public static function validate_products() {
		$parent = \get_option( self::PRODUCT_OPTION, false );

		// If missing a product option.
		if ( ! $parent ) {
			return false;
		}

		$children = \get_post_meta( $parent, '_children', true );

		// If missing children.
		if ( ! $children || ! is_array( $children ) ) {
			return false;
		}

		// Ensure that the products are published and valid.
		foreach ( array_merge( [ $parent ], $children ) as $product_id ) {
			// If not a product, or not published.
			if ( 'product' !== \get_post_type( $product_id ) || 'publish' !== \get_post_status( $product_id ) ) {
				return false;
			}

			if ( $product_id !== $parent && ! \metadata_exists( 'post', $product_id, '_newspack_listings_product_slug' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Init subclasses and create base products.
	 */
	public static function setup() {
		if ( self::is_active() && self::validate_products() ) {
			new Products_Purchase();
			new Products_Ui();
			new Products_User();
			new Products_Cron();
		}
	}

	/**
	 * Create or delete self-serve listing products.
	 */
	public static function create_or_delete_listing_products() {
		$action = filter_input( INPUT_GET, 'newspack-listings-products', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$nonce  = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( wp_verify_nonce( $nonce, self::ACTION_NONCE ) && in_array( $action, self::ACTIONS, true ) ) {
			if ( self::ACTIONS['create'] === $action ) {
				self::create_products();
			} elseif ( self::ACTIONS['delete'] === $action ) {
				self::delete_products();
			}

			wp_safe_redirect( get_admin_url( null, '/admin.php?page=' . Settings::PAGE_SLUG ) );
			exit;
		}
	}

	/**
	 * Create the WooCommerce products for self-serve listings.
	 */
	public static function create_products() {
		// First, clean up any potential leftover dupes.
		self::delete_products();

		$settings     = Settings::get_settings();
		$product_name = __( 'Self-Serve Listings', 'newspack-listings' );

		// Parent product.
		$parent_product = new \WC_Product_Grouped();
		$parent_product->set_name( $product_name );
		$parent_product->set_catalog_visibility( 'hidden' );
		$parent_product->set_virtual( true );
		$parent_product->set_downloadable( true );
		$parent_product->set_sold_individually( true );
		$parent_product->update_meta_data( '_newspack_listings_product_slug', self::PRODUCT_META_KEYS['parent'] );

		// Single listing product.
		$single_product = new \WC_Product_Simple();
		/* translators: %s: Product name */
		$single_product->set_name( __( 'Single Listing', 'newspack-listings' ) );
		$single_product->set_regular_price( $settings[ self::PRODUCT_META_KEYS['single'] ] );
		$single_product->update_meta_data( '_newspack_listings_product_slug', self::PRODUCT_META_KEYS['single'] );
		$single_product->set_virtual( true );
		$single_product->set_downloadable( true );
		$single_product->set_catalog_visibility( 'hidden' );
		$single_product->set_sold_individually( true );
		$single_product->save();

		// Single "featured" listing upgrade.
		$featured_upgrade_single = new \WC_Product_Simple();
		/* translators: %s: Product name */
		$featured_upgrade_single->set_name( __( '“Featured” Listing Upgrade', 'newspack-listings' ) );
		$featured_upgrade_single->set_regular_price( $settings[ self::PRODUCT_META_KEYS['featured'] ] );
		$featured_upgrade_single->update_meta_data( '_newspack_listings_product_slug', self::PRODUCT_META_KEYS['featured'] );
		$featured_upgrade_single->set_virtual( true );
		$featured_upgrade_single->set_downloadable( true );
		$featured_upgrade_single->set_catalog_visibility( 'hidden' );
		$featured_upgrade_single->set_sold_individually( true );
		$featured_upgrade_single->save();

		// Monthly subscription product.
		$monthly_product = new \WC_Product_Subscription();
		/* translators: %s: Product name */
		$monthly_product->set_name( __( 'Listing Subscription', 'newspack-listings' ) );
		$monthly_product->set_regular_price( $settings[ self::PRODUCT_META_KEYS['subscription'] ] );
		$monthly_product->update_meta_data( '_newspack_listings_product_slug', self::PRODUCT_META_KEYS['subscription'] );
		$monthly_product->update_meta_data( '_subscription_price', wc_format_decimal( $settings[ self::PRODUCT_META_KEYS['subscription'] ] ) );
		$monthly_product->update_meta_data( '_subscription_period', 'month' );
		$monthly_product->update_meta_data( '_subscription_period_interval', 1 );
		$monthly_product->set_virtual( true );
		$monthly_product->set_downloadable( true );
		$monthly_product->set_catalog_visibility( 'hidden' );
		$monthly_product->set_sold_individually( true );
		$monthly_product->save();

		// Monthly "premium subscription" upgrade.
		$premium_upgrade_monthly = new \WC_Product_Subscription();
		/* translators: %s: Product name */
		$premium_upgrade_monthly->set_name( __( 'Premium Subscription Upgrade', 'newspack-listings' ) );
		$premium_upgrade_monthly->set_regular_price( $settings[ self::PRODUCT_META_KEYS['premium'] ] );
		$premium_upgrade_monthly->update_meta_data( '_newspack_listings_product_slug', self::PRODUCT_META_KEYS['premium'] );
		$premium_upgrade_monthly->update_meta_data( '_subscription_price', wc_format_decimal( $settings[ self::PRODUCT_META_KEYS['premium'] ] ) );
		$premium_upgrade_monthly->update_meta_data( '_subscription_period', 'month' );
		$premium_upgrade_monthly->update_meta_data( '_subscription_period_interval', 1 );
		$premium_upgrade_monthly->set_virtual( true );
		$premium_upgrade_monthly->set_downloadable( true );
		$premium_upgrade_monthly->set_catalog_visibility( 'hidden' );
		$premium_upgrade_monthly->set_sold_individually( true );
		$premium_upgrade_monthly->save();

		$parent_product->set_children(
			[
				$single_product->get_id(),
				$featured_upgrade_single->get_id(),
				$monthly_product->get_id(),
				$premium_upgrade_monthly->get_id(),
			]
		);
		$parent_product->save();
		update_option( self::PRODUCT_OPTION, $parent_product->get_id() );
	}

	/**
	 * Delete self-serve listing products.
	 */
	public static function delete_products() {
		// Find all products with a _newspack_listings_product_slug meta value.
		$product_ids = get_posts(
			[
				'fields'         => 'ids',
				'posts_per_page' => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'post_status'    => 'any',
				'post_type'      => 'product',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_newspack_listings_product_slug',
						'compare' => 'EXISTS',
					],
				],
			]
		);

		if ( $product_ids ) {
			foreach ( $product_ids as $product_id ) {
				if ( false !== get_post_status( $product_id ) ) {
					wp_delete_post( $product_id, true );
				}
			}
		}

		delete_option( self::PRODUCT_OPTION );
	}

	/**
	 * When Newspack Listing settings are updated, update the corresopnding WC products as well.
	 *
	 * @param string $option Name of the option to update.
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $new_value The new option value.
	 */
	public static function update_products( $option, $old_value, $new_value ) {
		if ( ! self::is_active() ) {
			return;
		}

		// Only if the updated option is a Newspack Listing setting.
		$settings = Settings::get_settings();
		if ( ! in_array( $option, array_keys( $settings ) ) ) {
			return;
		}

		$product_id = get_option( self::PRODUCT_OPTION, false );
		if ( $product_id ) {
			$parent_product = \wc_get_product( $product_id );
			$parent_product->set_status( 'publish' );
			$parent_product->save();

			foreach ( $parent_product->get_children() as $child_id ) {
				$child_product = \wc_get_product( $child_id );

				if ( ! $child_product ) {
					continue;
				}

				$settings_slug = $child_product->get_meta( '_newspack_listings_product_slug' );
				if ( $option === $settings_slug ) {
					// Set base price.
					$child_product->set_regular_price( $new_value );

					// Set subscription price, if applicable.
					if ( 'subscription' === $child_product->get_type() ) {
						$child_product->update_meta_data( '_subscription_price', \wc_format_decimal( $new_value ) );
					}

					$child_product->save();
				}
			}
		}
	}

	/**
	 * When a listing product's price is updated in the WooCommerce UI, also update the corresponding plugin setting.
	 *
	 * @param int    $meta_id Meta field ID.
	 * @param int    $post_id Post ID of the product being updated via WooCommerce.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value Value of $meta_key.
	 */
	public static function update_product_option( $meta_id, $post_id, $meta_key, $meta_value ) {
		// Only run if self-serve listings are enabled.
		if ( ! self::is_active() ) {
			return;
		}

		// Only run if post being updated is a product and meta field being updated is '_price'.
		if ( 'product' !== get_post_type( $post_id ) || '_price' !== $meta_key ) {
			return;
		}

		// Only run if we've created listing products.
		$products = self::get_products();
		if ( ! $products || ! is_array( $products ) ) {
			return;
		}

		// If the product being updated is a Listings product, update the corresponding plugin setting.
		$product_key = array_search( $post_id, $products, true );
		if ( $product_key && in_array( $product_key, self::PRODUCT_META_KEYS ) ) {
			update_option( $product_key, (float) $meta_value );
		}
	}

	/**
	 * Get the WooCommerce product for the parent listings product.
	 *
	 * @return boolean|array Array of product IDs, keyed by meta key.
	 *                       False if none created or if WC is inactive.
	 */
	public static function get_products() {
		$product_id = get_option( self::PRODUCT_OPTION, false );

		// If missing a product option, the products need to be created.
		if ( ! $product_id ) {
			return false;
		}

		// If missing a parent product, the products need to be created.
		$parent_product = \wc_get_product( $product_id );
		if ( ! $parent_product || ! $parent_product->is_type( 'grouped' ) || 'publish' !== get_post_status( $product_id ) ) {
			return new \WP_Error(
				'newspack_listings_invalid_parent_product',
				__(
					'Missing or invalid self-serve listing parent product.',
					'newspack-listings'
				)
			);
		}

		$products = [];

		$products[ self::PRODUCT_META_KEYS['parent'] ] = $product_id;

		foreach ( $parent_product->get_children() as $child_id ) {
			$child_product = \wc_get_product( $child_id );

			// If missing a child product, the products need to be created.
			if ( ! $child_product ) {
				return new \WP_Error(
					'newspack_listings_invalid_parent_product',
					__(
						'Missing or invalid self-serve listing child products.',
						'newspack-listings'
					)
				);
			}

			$settings_slug              = $child_product->get_meta( '_newspack_listings_product_slug' );
			$products[ $settings_slug ] = $child_id;
		}

		return $products;
	}

	/**
	 * Valid single listing types that can be purchased.
	 *
	 * @return array Array of listing types.
	 */
	public static function get_listing_types() {
		return [
			[
				'slug' => 'blank',
				'name' => __( 'Blank listing (start from scratch)', 'newspack-listings' ),
			],
			[
				'slug' => 'event',
				'name' => __( 'Event', 'newspack-listings' ),
			],
			[
				'slug' => 'classified',
				'name' => __( 'Classified Ad', 'newspack-listings' ),
			],
			[
				'slug' => 'job',
				'name' => __( 'Job Listing', 'newspack-listings' ),
			],
			[
				'slug' => 'real-estate',
				'name' => __( 'Real Estate Listing', 'newspack-listings' ),
			],
		];
	}

	/**
	 * Given a WC order ID, find the listing associated with that order.
	 *
	 * @param int $order_id ID of the WooCommerce order.
	 *
	 * @return WP_Post|boolean The associated listing, or false if none.
	 */
	public static function get_listing_by_order_id( $order_id ) {
		$listing          = false;
		$associated_posts = get_posts(
			[
				'meta_key'    => self::POST_META_KEYS['listing_order'],
				'meta_value'  => $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post_status' => 'any',
				'post_type'   => array_values( Core::NEWSPACK_LISTINGS_POST_TYPES ),
			]
		);

		if ( 0 < count( $associated_posts ) ) {
			$listing = reset( $associated_posts );
		}

		return $listing;
	}

	/**
	 * Check whether the given or currently logged-in user is a listings customer.
	 *
	 * @param int|null $user_id ID of the user to check. If not given, will check the current user.
	 *
	 * @return boolean True if the user is a customer, false if not.
	 */
	public static function is_listing_customer( $user_id = null ) {
		global $user_ID;

		if ( ! $user_id ) {
			$user_id = $user_ID;
		}

		$user_meta      = get_userdata( $user_id );
		$user_roles     = $user_meta->roles;
		$customer_roles = [ 'subscriber', 'customer' ]; // Avoid limiting capabilities for contributor/author/editor/admin roles.

		$is_listing_customer = 0 < count( array_intersect( $user_roles, $customer_roles ) ) && get_user_meta( $user_id, self::CUSTOMER_META_KEYS['is_listings_customer'], true );

		return $is_listing_customer;
	}

	/**
	 * Given a user ID and post ID, check whether the user owns (is the author of) the post.
	 *
	 * @param int|null $user_id ID of the user to check. If not given, will check the current user.
	 * @param int      $post_id ID of the post to check. Must be a listing post type.
	 *
	 * @return boolean True if the user owns the post, false if not.
	 */
	public static function does_user_own_listing( $user_id = null, $post_id = null ) {
		global $user_ID;

		if ( ! $user_id ) {
			$user_id = $user_ID;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! Core::is_listing( $post->post_type ) ) {
			return false;
		}

		return (int) $post->post_author === (int) $user_id;
	}
}

Products::init();
