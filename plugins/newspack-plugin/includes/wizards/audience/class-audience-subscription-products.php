<?php
/**
 * Audience Subscription Products Wizard.
 *
 * DataViews management page that lists Woo Subscriptions products with a
 * productized, consolidated model (price + period, active subscriber counts,
 * category, status).
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Audience Subscription Products Wizard.
 */
class Audience_Subscription_Products extends Wizard {
	/**
	 * Admin page slug. Must match the React page map key in src/wizards/index.tsx
	 * and the container div id rendered by Wizard::render_wizard().
	 *
	 * @var string
	 */
	protected $slug = 'newspack-audience-subscription-products';

	/**
	 * Parent slug.
	 *
	 * @var string
	 */
	protected $parent_slug = 'newspack-audience';

	/**
	 * Capability required to manage plans.
	 *
	 * Overrides the base wizard's `manage_options`: this page is product management, so it
	 * mirrors the sibling group-subscription API and core product-CRUD by gating on
	 * `manage_woocommerce`, letting WooCommerce shop managers (not only full admins) manage
	 * plans. Applies to the menu page and all three REST routes, which share
	 * {@see Wizard::api_permissions_check()}.
	 *
	 * @var string
	 */
	protected $capability = 'manage_woocommerce';

	/**
	 * Subscription product types we surface. `grouped` products are included only when they
	 * bundle subscription children (they're the plan-switching "Plan Options" containers).
	 */
	const PRODUCT_TYPES = [ 'subscription', 'variable-subscription', 'grouped' ];

	/**
	 * Group-subscription product meta keys (from the content-gate group-subscription feature).
	 */
	const GROUP_ENABLED_META = '_newspack_group_subscription_enabled';
	const GROUP_LIMIT_META   = '_newspack_group_subscription_limit';

	/**
	 * Transient caching the product → content-gates reverse map across requests.
	 */
	const GATE_MAP_TRANSIENT = 'newspack_plans_product_gate_map';

	/**
	 * Subscription statuses counted as "active" subscribers.
	 *
	 * Mirrors the active statuses used by the WooCommerce connection
	 * ({@see Newspack\WooCommerce_Connection}).
	 */
	const ACTIVE_SUBSCRIPTION_STATUSES = [ 'active', 'pending-cancel' ];

	/**
	 * Subscription statuses that pin a product/variation against deletion — anything that can
	 * still renew or resume, so deleting the product would orphan a live subscription. Broader
	 * than {@see self::ACTIVE_SUBSCRIPTION_STATUSES} (the informational subscriber count), which
	 * counts only currently-active subscribers.
	 */
	const BLOCKING_SUBSCRIPTION_STATUSES = [ 'active', 'pending', 'on-hold', 'pending-cancel' ];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );

		// Guard against orphaning live subscriptions: block trashing/deleting a subscription
		// product (or variation) that still has renewable subscriptions, from anywhere in
		// wp-admin (the core Products list, a bulk action, or a programmatic delete).
		add_filter( 'pre_trash_post', [ $this, 'block_subscription_product_deletion' ], 10, 2 );
		add_filter( 'pre_delete_post', [ $this, 'block_subscription_product_deletion' ], 10, 2 );

		// Keep the "Unlocks" column fresh: invalidate the cached product → content-gates map
		// whenever a gate is saved, trashed, or deleted, or its custom_access rules change.
		add_action( 'save_post', [ $this, 'maybe_invalidate_gate_map' ], 10, 2 );
		add_action( 'before_delete_post', [ $this, 'maybe_invalidate_gate_map' ], 10, 2 );
		add_action( 'updated_post_meta', [ $this, 'maybe_invalidate_gate_map_on_meta' ], 10, 3 );
		add_action( 'added_post_meta', [ $this, 'maybe_invalidate_gate_map_on_meta' ], 10, 3 );
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Audience Management / Plans', 'newspack-plugin' );
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/products',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'api_get_products' ],
					'permission_callback' => [ $this, 'api_permissions_check' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'api_create_product' ],
					'permission_callback' => [ $this, 'api_permissions_check' ],
				],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/products/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_product' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'id' => [
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Get all product categories for the create/edit pickers, excluding the
	 * private/free convention categories (those are managed by the availability picker).
	 *
	 * @return array List of { id, name }.
	 */
	private static function get_all_product_categories() {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		$excluded = [ 'private-subscriptions', 'free-subscriptions' ];
		$result   = [];
		foreach ( $terms as $term ) {
			if ( in_array( $term->slug, $excluded, true ) ) {
				continue;
			}
			$result[] = [
				'id'   => $term->term_id,
				'name' => $term->name,
			];
		}
		return $result;
	}

	/**
	 * GET the list of subscription products in the consolidated model.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public function api_get_products() {
		$response = [
			'products'                    => [],
			'currency'                    => self::get_currency(),
			'available_categories'        => self::get_all_product_categories(),
			'group_subscriptions_enabled' => self::group_subscription_available(),
		];

		if ( ! function_exists( 'wc_get_products' ) ) {
			return rest_ensure_response( $response );
		}

		$products = \wc_get_products(
			[
				'type'   => self::PRODUCT_TYPES,
				'status' => [ 'publish', 'private', 'draft', 'pending' ],
				'limit'  => -1,
			]
		);

		// Keep only grouped products that actually bundle subscriptions (plan-switching sets).
		$products = array_filter(
			$products,
			function( $product ) {
				return ! $product->is_type( 'grouped' ) || self::group_has_subscription_children( $product );
			}
		);

		// Pull in one-time (simple) donation products, even though they aren't subscriptions.
		$products = array_merge( array_values( $products ), self::get_simple_donation_products() );

		// Dedupe by product ID.
		$seen     = [];
		$products = array_filter(
			$products,
			function( $product ) use ( &$seen ) {
				if ( isset( $seen[ $product->get_id() ] ) ) {
					return false;
				}
				$seen[ $product->get_id() ] = true;
				return true;
			}
		);

		$response['products'] = array_map( [ $this, 'prepare_product' ], array_values( $products ) );
		return rest_ensure_response( $response );
	}

	/**
	 * Get one-time (simple) donation products.
	 *
	 * Donation simples may be flagged via the _newspack_is_donation meta or detected through
	 * the legacy parent/child donation structure, so we union both sources.
	 *
	 * @return \WC_Product[] Simple donation products.
	 */
	private static function get_simple_donation_products() {
		if ( ! class_exists( 'Newspack\Donations' ) ) {
			return [];
		}
		$ids = Donations::get_flagged_donation_product_ids();
		if ( method_exists( 'Newspack\Donations', 'get_donation_product_child_products_ids' ) ) {
			$ids = array_merge( $ids, array_values( Donations::get_donation_product_child_products_ids() ) );
		}

		$products = [];
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product && $product->is_type( 'simple' ) ) {
				$products[] = $product;
			}
		}
		return $products;
	}

	/**
	 * Whether a product should be surfaced/editable on this page.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return bool
	 */
	private static function is_surfaced_product( $product ) {
		if ( in_array( $product->get_type(), self::PRODUCT_TYPES, true ) ) {
			return true;
		}
		return $product->is_type( 'simple' ) && class_exists( 'Newspack\Donations' ) && Donations::is_donation_product( $product->get_id() );
	}

	/**
	 * Whether a grouped product bundles at least one subscription child.
	 *
	 * @param \WC_Product $product The grouped product.
	 *
	 * @return bool
	 */
	private static function group_has_subscription_children( $product ) {
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child && $child->is_type( [ 'subscription', 'variable-subscription' ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Billing periods accepted when creating a product.
	 */
	const VALID_PERIODS = [ 'day', 'week', 'month', 'year' ];

	/**
	 * Display name of the plan-selection attribute on variable subscriptions. The variation
	 * attribute key is always `sanitize_title()` of this name (see
	 * {@see self::billing_period_attribute_key()}) — never hardcode the derived slug.
	 */
	const BILLING_PERIOD_ATTRIBUTE_NAME = 'Billing period';

	/**
	 * Whether the group-subscription (multi-seat) feature is available.
	 *
	 * The canonical product editor only exposes the group-subscription fields when this
	 * content-gate feature is enabled ({@see Group_Subscription_Settings}), while the
	 * enforcement layer honors the meta regardless — so this wizard must not write the
	 * group meta unless the feature is on.
	 *
	 * @return bool
	 */
	private static function group_subscription_available() {
		return class_exists( 'Newspack\Content_Gate' ) && Content_Gate::is_newspack_feature_enabled();
	}

	/**
	 * The variation attribute key for the plan-selection ("Billing period") attribute.
	 *
	 * WooCommerce derives a variation attribute's key from `sanitize_title()` of its name, so the
	 * key is computed rather than hardcoded — a localized or renamed attribute name would
	 * otherwise silently detach every variation (its attribute reading back as "Any").
	 *
	 * @return string
	 */
	private static function billing_period_attribute_key() {
		return sanitize_title( self::BILLING_PERIOD_ATTRIBUTE_NAME );
	}

	/**
	 * Whether a product or variation ID still has a subscription that could renew or resume
	 * (see {@see self::BLOCKING_SUBSCRIPTION_STATUSES}). Used to refuse deletions that would
	 * orphan a live subscription.
	 *
	 * @param int $product_id The product or variation ID.
	 *
	 * @return bool
	 */
	private static function product_has_blocking_subscriptions( $product_id ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_product' ) ) {
			return false;
		}
		// 'ids' + limit 1: only existence matters, so avoid hydrating WC_Subscription objects.
		$subscription_ids = \wcs_get_subscriptions_for_product(
			$product_id,
			'ids',
			[
				'subscription_status' => self::BLOCKING_SUBSCRIPTION_STATUSES,
				'limit'               => 1,
			]
		);
		return ! empty( $subscription_ids );
	}

	/**
	 * Block trashing or deleting a subscription product (or variation) that still has a
	 * renewable subscription, from anywhere in wp-admin. Renewals resolve the subscription's
	 * product by ID, so removing it would orphan the subscription.
	 *
	 * Hooked on the `pre_trash_post` / `pre_delete_post` short-circuit filters: returning a
	 * non-null value aborts the operation, and WordPress surfaces its standard failure notice.
	 *
	 * @param \WP_Post|false|null $check Short-circuit value (null lets the operation proceed).
	 * @param \WP_Post            $post  The post being trashed or deleted.
	 *
	 * @return \WP_Post|false|null False to block the deletion; the unchanged $check otherwise.
	 */
	public function block_subscription_product_deletion( $check, $post ) {
		if ( null !== $check ) {
			return $check; // Another handler already decided the outcome.
		}
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			return $check;
		}
		return self::post_has_blocking_subscriptions( $post ) ? false : $check;
	}

	/**
	 * Whether a product/variation post — or, for a variable subscription, any of its variations —
	 * still has a renewable subscription.
	 *
	 * @param \WP_Post $post The product or product_variation post.
	 *
	 * @return bool
	 */
	private static function post_has_blocking_subscriptions( $post ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return false;
		}
		$product_ids = [ $product->get_id() ];
		if ( $product->is_type( 'variable-subscription' ) ) {
			$product_ids = array_merge( $product_ids, $product->get_children() );
		}
		foreach ( $product_ids as $product_id ) {
			if ( self::product_has_blocking_subscriptions( (int) $product_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * POST: create a subscription product from the consolidated form.
	 *
	 * Accepts a productized payload (no WooCommerce knowledge required from the caller) and
	 * builds a well-formed simple or variable subscription, setting the WooCommerce +
	 * Subscriptions meta so the product behaves correctly at checkout. Returns the created
	 * row so the UI can insert it without a full refetch.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error The created row, or an error.
	 */
	public function api_create_product( $request ) {
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Subscription' ) ) {
			return new \WP_Error( 'woocommerce_subscriptions_inactive', __( 'WooCommerce Subscriptions is not active.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		$name = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		if ( '' === trim( $name ) ) {
			return new \WP_Error( 'missing_name', __( 'Product name is required.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$type = isset( $params['type'] ) ? sanitize_text_field( $params['type'] ) : 'subscription';
		if ( ! in_array( $type, self::PRODUCT_TYPES, true ) ) {
			return new \WP_Error( 'invalid_type', __( 'Invalid product type.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$status       = ( isset( $params['status'] ) && 'draft' === $params['status'] ) ? 'draft' : 'publish';
		$category_ids = isset( $params['category_ids'] ) && is_array( $params['category_ids'] ) ? array_map( 'absint', $params['category_ids'] ) : [];
		$availability = ( isset( $params['availability'] ) && in_array( $params['availability'], [ 'public', 'private', 'free' ], true ) ) ? $params['availability'] : 'public';
		$category_ids = self::apply_availability_to_categories( $category_ids, $availability );
		$is_donation  = ! empty( $params['is_donation'] );

		if ( 'grouped' === $type ) {
			$result = self::create_grouped_product( $name, $status, $category_ids, $is_donation, isset( $params['bundled_product_ids'] ) ? $params['bundled_product_ids'] : [] );
		} elseif ( 'variable-subscription' === $type ) {
			$result = self::create_variable_subscription( $name, $status, $category_ids, $is_donation, isset( $params['variations'] ) ? $params['variations'] : [] );
		} else {
			$result = self::create_simple_subscription( $name, $status, $category_ids, $is_donation, $params );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Bust WC's caches for the new product. NOTE: with an external object cache
		// (memcached) WC's versioned product cache can't be reliably re-read in the SAME
		// request, so we return just the id — the client refetches the list to render the
		// new (correctly persisted) row.
		clean_post_cache( $result );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $result );
		}

		return rest_ensure_response(
			[
				'id'   => (int) $result,
				'name' => $name,
			]
		);
	}

	/**
	 * Create a simple subscription product.
	 *
	 * @param string $name         Product name.
	 * @param string $status       Post status.
	 * @param int[]  $category_ids  Category term IDs.
	 * @param bool   $is_donation  Whether to flag as a donation.
	 * @param array  $params       The request params (price/period/interval).
	 *
	 * @return int|\WP_Error The new product ID, or an error.
	 */
	private static function create_simple_subscription( $name, $status, $category_ids, $is_donation, $params ) {
		$price = isset( $params['price'] ) ? (float) $params['price'] : -1;
		if ( $price < 0 ) {
			return new \WP_Error( 'invalid_price', __( 'A valid price is required.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		$period = isset( $params['period'] ) ? sanitize_text_field( $params['period'] ) : 'month';
		if ( ! in_array( $period, self::VALID_PERIODS, true ) ) {
			return new \WP_Error( 'invalid_period', __( 'Invalid billing period.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		$interval = isset( $params['interval'] ) ? max( 1, min( 6, (int) $params['interval'] ) ) : 1;

		$product = new \WC_Product_Subscription();
		$product->set_name( $name );
		$product->set_status( $status );
		$product->set_virtual( true );
		$product->set_catalog_visibility( 'visible' );
		$product->set_regular_price( (string) $price );
		$product->set_price( (string) $price );
		if ( $category_ids ) {
			$product->set_category_ids( $category_ids );
		}
		$product->update_meta_data( '_subscription_price', wc_format_decimal( $price ) );
		$product->update_meta_data( '_subscription_period', $period );
		$product->update_meta_data( '_subscription_period_interval', $interval );
		$product->update_meta_data( '_subscription_length', 0 );
		self::set_donation_flag( $product, $is_donation );
		if ( self::group_subscription_available() && ! empty( $params['is_group_subscription'] ) ) {
			$product->update_meta_data( self::GROUP_ENABLED_META, wc_bool_to_string( true ) );
			$product->update_meta_data( self::GROUP_LIMIT_META, isset( $params['group_member_limit'] ) ? max( 0, (int) $params['group_member_limit'] ) : 0 );
		}
		$product_id = $product->save();
		if ( ! $product_id ) {
			return new \WP_Error( 'create_failed', __( 'Failed to create the product.', 'newspack-plugin' ), [ 'status' => 500 ] );
		}

		// WC's save doesn't reliably set the product_type term to 'subscription' in this
		// environment — set it explicitly so the product reads back as a subscription.
		wp_set_object_terms( $product_id, 'subscription', 'product_type' );

		return $product_id;
	}

	/**
	 * Create a variable subscription product with one variation per plan.
	 *
	 * @param string $name         Product name.
	 * @param string $status       Post status.
	 * @param int[]  $category_ids  Category term IDs.
	 * @param bool   $is_donation  Whether to flag as a donation.
	 * @param array  $variations   List of { label, price, period, interval }.
	 *
	 * @return int|\WP_Error The new parent product ID, or an error.
	 */
	private static function create_variable_subscription( $name, $status, $category_ids, $is_donation, $variations ) {
		if ( empty( $variations ) || ! is_array( $variations ) ) {
			return new \WP_Error( 'missing_variations', __( 'Add at least one plan.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$clean = [];
		foreach ( $variations as $variation ) {
			$label    = isset( $variation['label'] ) ? sanitize_text_field( $variation['label'] ) : '';
			$price    = isset( $variation['price'] ) ? (float) $variation['price'] : -1;
			$period   = isset( $variation['period'] ) ? sanitize_text_field( $variation['period'] ) : 'month';
			$interval = isset( $variation['interval'] ) ? max( 1, min( 6, (int) $variation['interval'] ) ) : 1;
			if ( '' === trim( $label ) || $price < 0 || ! in_array( $period, self::VALID_PERIODS, true ) ) {
				return new \WP_Error( 'invalid_variation', __( 'Each plan needs a label, a valid price, and a billing period.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}
			$group_enabled = ! empty( $variation['is_group_subscription'] );
			$group_limit   = isset( $variation['group_member_limit'] ) ? max( 0, (int) $variation['group_member_limit'] ) : 0;
			$clean[]       = compact( 'label', 'price', 'period', 'interval', 'group_enabled', 'group_limit' );
		}

		$labels = wp_list_pluck( $clean, 'label' );
		if ( count( $labels ) !== count( array_unique( $labels ) ) ) {
			return new \WP_Error( 'duplicate_labels', __( 'Plan labels must be unique.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( $name );
		$product->set_status( $status );
		$product->set_virtual( true );
		$product->set_catalog_visibility( 'visible' );
		if ( $category_ids ) {
			$product->set_category_ids( $category_ids );
		}
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( self::BILLING_PERIOD_ATTRIBUTE_NAME );
		$attribute->set_options( $labels );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( [ $attribute ] );
		self::set_donation_flag( $product, $is_donation );
		$parent_id = $product->save();
		if ( ! $parent_id ) {
			return new \WP_Error( 'create_failed', __( 'Failed to create the product.', 'newspack-plugin' ), [ 'status' => 500 ] );
		}

		// Set the product_type term explicitly (WC's save doesn't reliably do it here);
		// without this the parent reads back as 'simple' and has no variations.
		wp_set_object_terms( $parent_id, 'variable-subscription', 'product_type' );

		$variation_ids = [];
		foreach ( $clean as $plan ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( [ self::billing_period_attribute_key() => $plan['label'] ] );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( (string) $plan['price'] );
			$variation->update_meta_data( '_subscription_price', wc_format_decimal( $plan['price'] ) );
			$variation->update_meta_data( '_subscription_period', $plan['period'] );
			$variation->update_meta_data( '_subscription_period_interval', $plan['interval'] );
			$variation->update_meta_data( '_subscription_length', 0 );
			if ( self::group_subscription_available() && $plan['group_enabled'] ) {
				$variation->update_meta_data( self::GROUP_ENABLED_META, wc_bool_to_string( true ) );
				$variation->update_meta_data( self::GROUP_LIMIT_META, $plan['group_limit'] );
			}
			$variation_id = $variation->save();
			if ( ! $variation_id ) {
				// A plan failed to persist — roll back so we never leave a parent missing a plan.
				foreach ( $variation_ids as $saved_id ) {
					wp_delete_post( $saved_id, true );
				}
				wp_delete_post( $parent_id, true );
				return new \WP_Error( 'create_failed', __( 'Failed to create one of the plans.', 'newspack-plugin' ), [ 'status' => 500 ] );
			}
			$variation_ids[] = $variation_id;
		}

		if ( method_exists( '\WC_Product_Variable_Subscription', 'sync' ) ) {
			\WC_Product_Variable_Subscription::sync( $parent_id );
		}

		return $parent_id;
	}

	/**
	 * Create a grouped (plan-switching) product bundling subscription products.
	 *
	 * @param string $name         Product name.
	 * @param string $status       Post status.
	 * @param int[]  $category_ids  Category term IDs.
	 * @param bool   $is_donation  Whether to flag as a donation.
	 * @param array  $bundled_ids  Subscription product IDs to bundle.
	 *
	 * @return int|\WP_Error The new product ID, or an error.
	 */
	private static function create_grouped_product( $name, $status, $category_ids, $is_donation, $bundled_ids ) {
		$bundled_ids = is_array( $bundled_ids ) ? array_values( array_filter( array_map( 'absint', $bundled_ids ) ) ) : [];

		$valid = [];
		foreach ( $bundled_ids as $bundled_id ) {
			$child = wc_get_product( $bundled_id );
			if ( $child && $child->is_type( [ 'subscription', 'variable-subscription' ] ) ) {
				$valid[] = $bundled_id;
			}
		}
		if ( empty( $valid ) ) {
			return new \WP_Error( 'invalid_bundle', __( 'Select at least one subscription product to bundle.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$product = new \WC_Product_Grouped();
		$product->set_name( $name );
		$product->set_status( $status );
		$product->set_catalog_visibility( 'visible' );
		if ( $category_ids ) {
			$product->set_category_ids( $category_ids );
		}
		$product->set_children( $valid );
		self::set_donation_flag( $product, $is_donation );
		$product_id = $product->save();
		if ( ! $product_id ) {
			return new \WP_Error( 'create_failed', __( 'Failed to create the product.', 'newspack-plugin' ), [ 'status' => 500 ] );
		}

		wp_set_object_terms( $product_id, 'grouped', 'product_type' );

		return $product_id;
	}

	/**
	 * PUT: update a subscription product in place.
	 *
	 * The product type is locked (changing a live product's type in WooCommerce is unsafe).
	 * Common fields (name, status, category, availability→category, donation, group
	 * subscription) update for every type; pricing/plans/bundle update per type.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error The updated id, or an error.
	 */
	public function api_update_product( $request ) {
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Subscription' ) ) {
			return new \WP_Error( 'woocommerce_subscriptions_inactive', __( 'WooCommerce Subscriptions is not active.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$product_id = (int) $request['id'];
		$product    = wc_get_product( $product_id );
		if ( ! $product || ! self::is_surfaced_product( $product ) ) {
			return new \WP_Error( 'product_not_found', __( 'Product not found.', 'newspack-plugin' ), [ 'status' => 404 ] );
		}

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		$name = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		if ( '' === trim( $name ) ) {
			return new \WP_Error( 'missing_name', __( 'Product name is required.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$status       = ( isset( $params['status'] ) && 'draft' === $params['status'] ) ? 'draft' : 'publish';
		$category_ids = isset( $params['category_ids'] ) && is_array( $params['category_ids'] ) ? array_map( 'absint', $params['category_ids'] ) : [];
		$availability = ( isset( $params['availability'] ) && in_array( $params['availability'], [ 'public', 'private', 'free' ], true ) ) ? $params['availability'] : 'public';
		$category_ids = self::apply_availability_to_categories( $category_ids, $availability );
		$is_donation  = ! empty( $params['is_donation'] );
		$type         = $product->get_type();

		// Common fields.
		$product->set_name( $name );
		$product->set_status( $status );
		$product->set_category_ids( $category_ids );
		self::set_donation_flag( $product, $is_donation );

		if ( 'grouped' === $type ) {
			$bundled_ids = isset( $params['bundled_product_ids'] ) && is_array( $params['bundled_product_ids'] ) ? array_values( array_filter( array_map( 'absint', $params['bundled_product_ids'] ) ) ) : [];
			$valid       = [];
			foreach ( $bundled_ids as $bundled_id ) {
				$child = wc_get_product( $bundled_id );
				if ( $child && $child->is_type( [ 'subscription', 'variable-subscription' ] ) ) {
					$valid[] = $bundled_id;
				}
			}
			if ( empty( $valid ) ) {
				return new \WP_Error( 'invalid_bundle', __( 'Select at least one subscription product to bundle.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}
			$product->set_children( $valid );
			$product->save();
		} elseif ( 'variable-subscription' === $type ) {
			$product->save();
			$result = self::sync_variable_variations( $product, isset( $params['variations'] ) ? $params['variations'] : [] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} elseif ( 'simple' === $type ) {
			// One-time product: price only, no subscription meta.
			$price = isset( $params['price'] ) ? (float) $params['price'] : -1;
			if ( $price < 0 ) {
				return new \WP_Error( 'invalid_price', __( 'A valid price is required.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}
			$product->set_regular_price( (string) $price );
			$product->set_price( (string) $price );
			$product->save();
		} else {
			$price = isset( $params['price'] ) ? (float) $params['price'] : -1;
			if ( $price < 0 ) {
				return new \WP_Error( 'invalid_price', __( 'A valid price is required.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}
			$period = isset( $params['period'] ) ? sanitize_text_field( $params['period'] ) : 'month';
			if ( ! in_array( $period, self::VALID_PERIODS, true ) ) {
				return new \WP_Error( 'invalid_period', __( 'Invalid billing period.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}
			$interval = isset( $params['interval'] ) ? max( 1, min( 6, (int) $params['interval'] ) ) : 1;
			$product->set_regular_price( (string) $price );
			$product->set_price( (string) $price );
			$product->update_meta_data( '_subscription_price', wc_format_decimal( $price ) );
			$product->update_meta_data( '_subscription_period', $period );
			$product->update_meta_data( '_subscription_period_interval', $interval );
			if ( self::group_subscription_available() ) {
				$product->update_meta_data( self::GROUP_ENABLED_META, wc_bool_to_string( ! empty( $params['is_group_subscription'] ) ) );
				if ( ! empty( $params['is_group_subscription'] ) ) {
					$product->update_meta_data( self::GROUP_LIMIT_META, isset( $params['group_member_limit'] ) ? max( 0, (int) $params['group_member_limit'] ) : 0 );
				}
			}
			$product->save();
		}

		// Keep the product_type term pinned (see create note) and bust caches.
		wp_set_object_terms( $product_id, $type, 'product_type' );
		clean_post_cache( $product_id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		return rest_ensure_response(
			[
				'id'   => $product_id,
				'name' => $name,
			]
		);
	}

	/**
	 * Reconcile a variable subscription's variations with the desired plan list.
	 *
	 * Updates existing plans (matched by id), creates new plans, and deletes removed ones.
	 * Existing plan labels are read from the variation (renaming is not supported here).
	 *
	 * @param \WC_Product $product    The variable subscription product.
	 * @param array       $variations Desired plans: each { id?, label?, price, period, interval, is_group_subscription?, group_member_limit? }.
	 *
	 * @return true|\WP_Error
	 */
	private static function sync_variable_variations( $product, $variations ) {
		if ( empty( $variations ) || ! is_array( $variations ) ) {
			return new \WP_Error( 'missing_variations', __( 'Add at least one plan.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$parent_id    = $product->get_id();
		$existing_ids = array_map( 'intval', $product->get_children() );
		$plans        = [];

		foreach ( $variations as $variation ) {
			$variation_id = isset( $variation['id'] ) ? (int) $variation['id'] : 0;
			$price        = isset( $variation['price'] ) ? (float) $variation['price'] : -1;
			$period       = isset( $variation['period'] ) ? sanitize_text_field( $variation['period'] ) : 'month';
			$interval     = isset( $variation['interval'] ) ? max( 1, min( 6, (int) $variation['interval'] ) ) : 1;
			if ( $price < 0 || ! in_array( $period, self::VALID_PERIODS, true ) ) {
				return new \WP_Error( 'invalid_variation', __( 'Each plan needs a valid price and billing period.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}

			if ( $variation_id && in_array( $variation_id, $existing_ids, true ) ) {
				$existing = wc_get_product( $variation_id );
				$label    = $existing ? $existing->get_attribute( self::billing_period_attribute_key() ) : '';
			} else {
				$variation_id = 0;
				$label        = isset( $variation['label'] ) ? sanitize_text_field( $variation['label'] ) : '';
			}
			if ( '' === trim( $label ) ) {
				return new \WP_Error( 'invalid_variation', __( 'Each plan needs a label.', 'newspack-plugin' ), [ 'status' => 400 ] );
			}

			$plans[] = [
				'id'            => $variation_id,
				'label'         => $label,
				'price'         => $price,
				'period'        => $period,
				'interval'      => $interval,
				'group_enabled' => ! empty( $variation['is_group_subscription'] ),
				'group_limit'   => isset( $variation['group_member_limit'] ) ? max( 0, (int) $variation['group_member_limit'] ) : 0,
			];
		}

		$labels = wp_list_pluck( $plans, 'label' );
		if ( count( $labels ) !== count( array_unique( $labels ) ) ) {
			return new \WP_Error( 'duplicate_labels', __( 'Plan labels must be unique.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		// Refuse to drop a variation that still has subscribers: deleting it would orphan live
		// subscriptions, and silently keeping it (while stripping its label from the parent's
		// options) leaves the product inconsistent. Check up-front, before any mutation, so a
		// blocked removal changes nothing and the UI can surface why.
		$kept_ids = array_filter( array_map( 'intval', wp_list_pluck( $plans, 'id' ) ) );
		$blocked  = [];
		foreach ( array_diff( $existing_ids, $kept_ids ) as $removed_id ) {
			if ( ! self::product_has_blocking_subscriptions( (int) $removed_id ) ) {
				continue;
			}
			$removed   = wc_get_product( (int) $removed_id );
			$label     = $removed ? $removed->get_attribute( self::billing_period_attribute_key() ) : '';
			$blocked[] = '' !== $label ? $label : (string) $removed_id;
		}
		if ( ! empty( $blocked ) ) {
			return new \WP_Error(
				'plan_has_subscribers',
				sprintf(
					/* translators: %s: comma-separated plan labels. */
					__( 'Can’t remove a plan that still has active subscribers: %s. Cancel or move those subscriptions first.', 'newspack-plugin' ),
					implode( ', ', $blocked )
				),
				[ 'status' => 409 ]
			);
		}

		// Rebuild the parent's billing-period attribute to the desired set of plan labels.
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( self::BILLING_PERIOD_ATTRIBUTE_NAME );
		$attribute->set_options( $labels );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( [ $attribute ] );
		$product->save();

		// The Plans UI collapses group-subscription settings into a single control for the whole
		// product, so it can't represent divergent per-variation values. When the existing
		// variations already diverge (e.g. set via the core product editor), writing the collapsed
		// value to each would silently clobber them — so leave existing variations' group meta
		// untouched in that case (new variations still take the submitted value).
		$group_settings_diverge = self::existing_group_settings_diverge( $existing_ids );

		// Upsert variations.
		$keep_ids = [];
		foreach ( $plans as $plan ) {
			$variation = $plan['id'] ? new \WC_Product_Variation( $plan['id'] ) : new \WC_Product_Variation();
			if ( ! $plan['id'] ) {
				$variation->set_parent_id( $parent_id );
			}
			$variation->set_attributes( [ self::billing_period_attribute_key() => $plan['label'] ] );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( (string) $plan['price'] );
			$variation->update_meta_data( '_subscription_price', wc_format_decimal( $plan['price'] ) );
			$variation->update_meta_data( '_subscription_period', $plan['period'] );
			$variation->update_meta_data( '_subscription_period_interval', $plan['interval'] );
			$variation->update_meta_data( '_subscription_length', 0 );
			// Preserve divergent group settings on existing variations (see above); new variations
			// (no id yet) always take the submitted value.
			$preserve_group_settings = $plan['id'] && $group_settings_diverge;
			if ( self::group_subscription_available() && ! $preserve_group_settings ) {
				$variation->update_meta_data( self::GROUP_ENABLED_META, wc_bool_to_string( $plan['group_enabled'] ) );
				if ( $plan['group_enabled'] ) {
					$variation->update_meta_data( self::GROUP_LIMIT_META, $plan['group_limit'] );
				}
			}
			$keep_ids[] = (int) $variation->save();
		}

		// Remove variations dropped from the plan set. Never destroy a variation that still has
		// active subscribers — renewals resolve the subscription's product by this ID, so deleting
		// it would orphan live subscriptions. For the rest, trash (not force-delete) so a removed
		// plan can be recovered.
		foreach ( array_diff( $existing_ids, $keep_ids ) as $removed_id ) {
			if ( self::product_has_blocking_subscriptions( (int) $removed_id ) ) {
				continue;
			}
			wp_delete_post( $removed_id, false );
		}

		if ( method_exists( '\WC_Product_Variable_Subscription', 'sync' ) ) {
			\WC_Product_Variable_Subscription::sync( $parent_id );
		}

		return true;
	}

	/**
	 * Build the consolidated, productized row for a single subscription product.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return array The row.
	 */
	public function prepare_product( $product ) {
		$type       = $product->get_type();
		$categories = self::get_categories( $product->get_id() );
		$pricing    = self::get_pricing( $product );

		$is_grouped = $product->is_type( 'grouped' );

		$availability = self::derive_availability( $pricing['base_price'], $categories );
		$gate_map     = self::get_product_gate_map();
		$unlocks      = isset( $gate_map[ $product->get_id() ] ) ? $gate_map[ $product->get_id() ] : [];
		$group        = self::get_group_subscription_summary( $product, $pricing['variations'] );

		return [
			'id'                    => $product->get_id(),
			'name'                  => $product->get_name(),
			'type'                  => $type,
			'type_label'            => self::get_type_label( $type ),
			// Canonical donation flag (the "designate as donation" product checkbox →
			// _newspack_is_donation meta, plus variation inheritance and legacy products).
			'is_donation'           => class_exists( 'Newspack\Donations' ) ? Donations::is_donation_product( $product->get_id() ) : false,
			// How the plan is offered/distributed (NOT content "access control" — see below).
			'availability'          => $availability,
			'availability_label'    => self::get_availability_label( $availability ),
			// Reverse lookup: the content-access gates this product unlocks (Access control feature).
			'unlocks'               => $unlocks,
			'unlocks_label'         => implode( ', ', wp_list_pluck( $unlocks, 'title' ) ),
			// Group subscription (multi-seat) settings from the content-gate feature.
			'is_group_subscription' => $group['enabled'],
			'group_member_limit'    => $group['limit'],
			'group_member_label'    => $group['label'],
			// Plan-switching set: the subscription products bundled by a grouped product.
			'bundled_products'      => $is_grouped ? self::get_bundled_products( $product ) : [],
			'status'                => $product->get_status(),
			'status_label'          => self::get_status_label( $product->get_status() ),
			'base_price'            => $pricing['base_price'],
			'price_label'           => $pricing['price_label'],
			'price_range_label'     => $pricing['price_range_label'],
			'period'                => $pricing['period'],
			'interval'              => $pricing['interval'],
			'variations'            => $pricing['variations'],
			'categories'            => $categories,
			'category_ids'          => wp_list_pluck( $categories, 'id' ),
			'category_label'        => implode( ', ', wp_list_pluck( $categories, 'name' ) ),
			'active_subscriptions'  => self::get_active_subscription_count( $product ),
			'edit_url'              => html_entity_decode( (string) get_edit_post_link( $product->get_id(), 'raw' ) ),
		];
	}

	/**
	 * Read a product's (or variation's) group-subscription settings.
	 *
	 * @param \WC_Product|false $product The product/variation.
	 *
	 * @return array { enabled: bool, limit: int }.
	 */
	private static function read_group_settings( $product ) {
		if ( ! $product ) {
			return [
				'enabled' => false,
				'limit'   => 0,
			];
		}
		return [
			'enabled' => wc_string_to_bool( $product->get_meta( self::GROUP_ENABLED_META ) ),
			'limit'   => (int) $product->get_meta( self::GROUP_LIMIT_META ),
		];
	}

	/**
	 * Whether a variable subscription's existing variations carry divergent group-subscription
	 * settings — i.e. they aren't all disabled, nor all enabled with the same member limit. The
	 * Plans UI collapses these to one control, so on divergence it must not overwrite the
	 * per-variation values (which can only be set via the core product editor).
	 *
	 * @param int[] $variation_ids Existing variation IDs.
	 *
	 * @return bool
	 */
	private static function existing_group_settings_diverge( $variation_ids ) {
		$signature = null;
		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );
			if ( ! $variation ) {
				continue;
			}
			$settings = self::read_group_settings( $variation );
			// Comparable key: 'off' when disabled, otherwise the (int) member limit.
			$current = $settings['enabled'] ? (int) $settings['limit'] : 'off';
			if ( null === $signature ) {
				$signature = $current;
			} elseif ( $signature !== $current ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build a row-level group-subscription summary.
	 *
	 * For variable subscriptions the setting lives on each variation, so the row is "enabled"
	 * if any plan is, and the limit collapses to a shared value or -1 ("varies").
	 *
	 * @param \WC_Product $product    The product.
	 * @param array       $variations Prepared variations (each may carry a 'group' entry).
	 *
	 * @return array { enabled: bool, limit: int, label: string }.
	 */
	private static function get_group_subscription_summary( $product, $variations ) {
		if ( $product->is_type( 'variable-subscription' ) ) {
			$enabled_limits = [];
			foreach ( $variations as $variation ) {
				if ( ! empty( $variation['group']['enabled'] ) ) {
					$enabled_limits[] = (int) $variation['group']['limit'];
				}
			}
			if ( empty( $enabled_limits ) ) {
				return [
					'enabled' => false,
					'limit'   => 0,
					'label'   => '',
				];
			}
			$unique = array_values( array_unique( $enabled_limits ) );
			$limit  = count( $unique ) === 1 ? $unique[0] : -1;
			return [
				'enabled' => true,
				'limit'   => $limit,
				'label'   => -1 === $limit ? __( 'Varies', 'newspack-plugin' ) : self::group_limit_label( $limit ),
			];
		}

		$settings = self::read_group_settings( $product );
		return [
			'enabled' => $settings['enabled'],
			'limit'   => $settings['limit'],
			'label'   => $settings['enabled'] ? self::group_limit_label( $settings['limit'] ) : '',
		];
	}

	/**
	 * Human label for a group member limit.
	 *
	 * @param int $limit The limit (0 = unlimited).
	 *
	 * @return string The label.
	 */
	private static function group_limit_label( $limit ) {
		if ( $limit <= 0 ) {
			return __( 'Unlimited', 'newspack-plugin' );
		}
		/* translators: %d is the maximum number of group members. */
		return sprintf( _n( 'Up to %d member', 'Up to %d members', $limit, 'newspack-plugin' ), $limit );
	}

	/**
	 * Get the subscription products bundled by a grouped (plan-switching) product.
	 *
	 * @param \WC_Product $product The grouped product.
	 *
	 * @return array List of { id, name, type, type_label, price_label }.
	 */
	private static function get_bundled_products( $product ) {
		$bundled = [];
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}
			$child_pricing = self::get_pricing( $child );
			$bundled[]     = [
				'id'          => $child_id,
				'name'        => $child->get_name(),
				'type'        => $child->get_type(),
				'type_label'  => self::get_type_label( $child->get_type() ),
				'price_label' => $child->is_type( 'variable-subscription' ) && $child_pricing['price_range_label']
					? $child_pricing['price_range_label']
					: $child_pricing['price_label'],
			];
		}
		return $bundled;
	}

	/**
	 * Get pricing details for a product, normalizing simple vs. variable subscriptions.
	 *
	 * For variable subscriptions, base_price is the lowest variation price (representative
	 * for sorting) and price_range_label spans the variation range.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return array Pricing details.
	 */
	private static function get_pricing( $product ) {
		$pricing = [
			'base_price'        => null,
			'price_label'       => '',
			'price_range_label' => '',
			'period'            => '',
			'interval'          => 1,
			'variations'        => [],
		];

		if ( $product->is_type( 'variable-subscription' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$v_price    = self::read_subscription_price( $variation );
				$v_period   = $variation->get_meta( '_subscription_period' );
				$v_interval = (int) $variation->get_meta( '_subscription_period_interval' );

				$pricing['variations'][] = [
					'id'                   => $variation_id,
					'name'                 => $variation->get_name(),
					'plan_label'           => $variation->get_attribute( self::billing_period_attribute_key() ),
					'base_price'           => $v_price,
					'period'               => $v_period,
					'interval'             => $v_interval,
					'price_label'          => self::format_price_label( $v_price, $v_period, $v_interval ),
					// Active subscribers on this specific plan — drives the UI's guard against
					// removing a plan that still has subscribers. Free: the id is memoized for
					// the row's total count.
					'active_subscriptions' => count( self::active_subscription_ids_for( (int) $variation_id ) ),
					// Group-subscription (multi-seat) settings live per variation.
					'group'                => self::read_group_settings( $variation ),
				];
			}

			// Build the range from the actual lowest- and highest-priced variations, each
			// labeled with its OWN billing period (a tier can span $12/month – $100/year).
			$priced = array_values(
				array_filter(
					$pricing['variations'],
					function( $variation ) {
						return null !== $variation['base_price'];
					}
				)
			);
			if ( ! empty( $priced ) ) {
				usort(
					$priced,
					function( $a, $b ) {
						return $a['base_price'] <=> $b['base_price'];
					}
				);
				$low  = $priced[0];
				$high = $priced[ count( $priced ) - 1 ];

				$pricing['base_price']  = $low['base_price'];
				$pricing['period']      = $low['period'];
				$pricing['interval']    = $low['interval'];
				$pricing['price_label'] = $low['price_label'];
				$pricing['price_range_label'] = $low['base_price'] === $high['base_price']
					? $low['price_label']
					: sprintf(
						/* translators: 1: lowest plan price label, 2: highest plan price label. */
						__( '%1$s – %2$s', 'newspack-plugin' ),
						$low['price_label'],
						$high['price_label']
					);
			}

			return $pricing;
		}

		// Simple subscription.
		$price    = self::read_subscription_price( $product );
		$period   = $product->get_meta( '_subscription_period' );
		$interval = (int) $product->get_meta( '_subscription_period_interval' );

		// Non-subscription (one-time) simple product: use the product price, no billing period.
		if ( null === $price && ! $product->is_type( 'subscription' ) ) {
			$raw      = $product->get_price();
			$price    = ( '' === $raw || null === $raw ) ? null : (float) $raw;
			$period   = '';
			$interval = 1;
		}

		$pricing['base_price']  = $price;
		$pricing['period']      = $period;
		$pricing['interval']    = $interval ? $interval : 1;
		$pricing['price_label'] = self::format_price_label( $price, $period, $interval );

		return $pricing;
	}

	/**
	 * Read a product's base subscription price.
	 *
	 * Distinguishes "not set" (null) from an explicit 0 so the UI can render the
	 * difference faithfully.
	 *
	 * @param \WC_Product $product The product or variation.
	 *
	 * @return float|null The price, or null when not set.
	 */
	private static function read_subscription_price( $product ) {
		$raw = $product->get_meta( '_subscription_price' );
		// get_meta() always returns a set value (defaults to ''), so '' === $raw is the real test.
		if ( '' === $raw ) {
			return null;
		}
		return (float) $raw;
	}

	/**
	 * Per-request cache of active-subscription IDs, keyed by product/variation ID.
	 *
	 * @var array<int, array>
	 */
	private static $active_subscription_ids = [];

	/**
	 * Active (see self::ACTIVE_SUBSCRIPTION_STATUSES) subscription IDs for one product or
	 * variation ID, memoized for the request. The same id recurs across rows — a variation feeds
	 * both its own count and its parent's, and grouped products aggregate children also listed
	 * individually — so each id resolves to at most one lightweight query.
	 *
	 * @param int $product_id The product or variation ID.
	 *
	 * @return array Subscription IDs (keyed id => id).
	 */
	private static function active_subscription_ids_for( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! isset( self::$active_subscription_ids[ $product_id ] ) ) {
			self::$active_subscription_ids[ $product_id ] = function_exists( 'wcs_get_subscriptions_for_product' )
				? \wcs_get_subscriptions_for_product( $product_id, 'ids', [ 'subscription_status' => self::ACTIVE_SUBSCRIPTION_STATUSES ] )
				: [];
		}
		return self::$active_subscription_ids[ $product_id ];
	}

	/**
	 * Count active subscriptions for a product.
	 *
	 * Returns null (not zero) when WooCommerce Subscriptions is unavailable, so the UI
	 * can distinguish "unknown" from a genuine zero. For variable subscriptions, counts
	 * distinct subscriptions across the parent and all variation IDs.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return int|null The active subscription count, or null when unavailable.
	 */
	private static function get_active_subscription_count( $product ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_product' ) ) {
			return null;
		}
		// One-time (simple) products have no subscriptions — distinguish from a genuine zero.
		if ( $product->is_type( 'simple' ) ) {
			return null;
		}

		$product_ids = [ $product->get_id() ];
		if ( $product->is_type( 'variable-subscription' ) ) {
			$product_ids = array_merge( $product_ids, $product->get_children() );
		} elseif ( $product->is_type( 'grouped' ) ) {
			// Aggregate across the bundled subscription products (and their variations).
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child ) {
					continue;
				}
				$product_ids[] = $child_id;
				if ( $child->is_type( 'variable-subscription' ) ) {
					$product_ids = array_merge( $product_ids, $child->get_children() );
				}
			}
		}

		// Dedupe subscription IDs across the parent, variations, and grouped children (the same
		// id recurs across rows) via the shared per-request memo.
		$subscription_ids = [];
		foreach ( $product_ids as $product_id ) {
			foreach ( self::active_subscription_ids_for( (int) $product_id ) as $subscription_id ) {
				$subscription_ids[ $subscription_id ] = true;
			}
		}

		return count( $subscription_ids );
	}

	/**
	 * Get product categories.
	 *
	 * @param int $product_id The product ID.
	 *
	 * @return array List of { id, name, slug }.
	 */
	private static function get_categories( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return [];
		}
		return array_map(
			function( $term ) {
				return [
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				];
			},
			$terms
		);
	}

	/**
	 * Build a human price label, e.g. "$10 / month" or "$20 / 2 months".
	 *
	 * @param float|null $price    The price.
	 * @param string     $period   The billing period slug.
	 * @param int        $interval The billing interval.
	 *
	 * @return string The label, or '' when price is not set.
	 */
	private static function format_price_label( $price, $period, $interval ) {
		if ( null === $price ) {
			return '';
		}

		$amount = self::format_amount( $price );

		if ( '' === $period ) {
			return $amount;
		}

		$interval     = $interval ? (int) $interval : 1;
		$period_label = function_exists( 'wcs_get_subscription_period_strings' )
			? wcs_get_subscription_period_strings( $interval, $period )
			: ( $interval > 1 ? $interval . ' ' . $period . 's' : $period );

		return sprintf(
			/* translators: 1: price amount, 2: billing period, e.g. "$10 / month". */
			__( '%1$s / %2$s', 'newspack-plugin' ),
			$amount,
			$period_label
		);
	}

	/**
	 * Format a bare currency amount using the store's currency symbol and decimals.
	 *
	 * @param float $price The price.
	 *
	 * @return string The formatted amount.
	 */
	private static function format_amount( $price ) {
		$currency = self::get_currency();
		$amount   = number_format_i18n( (float) $price, $currency['decimals'] );
		return $currency['symbol'] . $amount;
	}

	/**
	 * Get store currency details for the front end.
	 *
	 * @return array { code, symbol, decimals }.
	 */
	private static function get_currency() {
		return [
			'code'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'symbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '$',
			'decimals' => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
		];
	}

	/**
	 * Derive an availability tier (public / private / free) for a product.
	 *
	 * NOTE ON NAMING: this is "availability" — how the plan is offered/distributed — and is
	 * deliberately NOT called "access". "Access control" is the separate Newspack
	 * content-gating feature (the sibling Audience page); the gates a product unlocks are
	 * surfaced separately as the "unlocks" field. Keeping the words distinct avoids
	 * conflating "how the plan is sold" with "what content it grants".
	 *
	 * DERIVATION (placeholder for a first-class entitlement attribute). Publishers
	 * encode this via product structure today — e.g. Lookout and Richland Source both use a
	 * "Private subscriptions" / "Free subscriptions" product_cat. This normalizes those
	 * conventions plus zero-price into one facet:
	 *   - free    : base price is 0, OR the product carries the "free-subscriptions" convention category.
	 *   - private : the product carries the "private-subscriptions" convention category.
	 *   - public  : everything else (a normally purchasable paid subscription).
	 *
	 * NOTE: we deliberately do NOT infer "private" from catalog_visibility=hidden —
	 * Newspack hides donation/RAS products from the catalog for unrelated reasons, so that
	 * signal is too noisy. This is the signal publishers explicitly reach for; a
	 * first-class entitlement field should own it as a typed value rather than inferring it.
	 *
	 * @param float|null $base_price The representative base price.
	 * @param array      $categories Category terms ({ id, name, slug }).
	 *
	 * @return string One of 'public', 'private', 'free'.
	 */
	private static function derive_availability( $base_price, $categories ) {
		// Match the exact convention slugs (set by apply_availability_to_categories()) rather than a
		// substring of display names, so a category like "Freelance" or "Private Beta" isn't misread.
		$slugs = wp_list_pluck( $categories, 'slug' );

		if ( ( null !== $base_price && 0.0 === (float) $base_price ) || in_array( 'free-subscriptions', $slugs, true ) ) {
			return 'free';
		}

		if ( in_array( 'private-subscriptions', $slugs, true ) ) {
			return 'private';
		}

		return 'public';
	}

	/**
	 * Human label for an availability tier.
	 *
	 * @param string $availability The availability tier.
	 *
	 * @return string The label.
	 */
	private static function get_availability_label( $availability ) {
		$labels = [
			'public'  => __( 'Public', 'newspack-plugin' ),
			'private' => __( 'Private', 'newspack-plugin' ),
			'free'    => __( 'Free', 'newspack-plugin' ),
		];
		return isset( $labels[ $availability ] ) ? $labels[ $availability ] : ucfirst( $availability );
	}

	/**
	 * Cached product → content-gates reverse map.
	 *
	 * @var array<int, array>|null
	 */
	private static $product_gate_map = null;

	/**
	 * Build a reverse map of product ID → content gates that require it.
	 *
	 * Content gates (the "Access control" feature) store their rules in the gate's
	 * `custom_access` meta as a grouped `access_rules` structure. The `subscription` rule's
	 * value is a list of (parent) product IDs the reader must be subscribed to. This walks
	 * every published gate and inverts that relationship so each product row can show what
	 * it unlocks. Built once per request and cached.
	 *
	 * @return array<int, array> Map of product ID → list of { id, title } gate entries.
	 */
	private static function get_product_gate_map() {
		if ( null !== self::$product_gate_map ) {
			return self::$product_gate_map;
		}

		$map = [];
		if ( ! class_exists( 'Newspack\Content_Gate' ) ) {
			self::$product_gate_map = $map;
			return $map;
		}

		// Cache the gate scan across requests: it walks every published gate, and this only feeds an
		// informational "unlocks" column, so short-lived staleness is acceptable.
		$cached = get_transient( self::GATE_MAP_TRANSIENT );
		if ( is_array( $cached ) ) {
			self::$product_gate_map = $cached;
			return $cached;
		}

		$gates = get_posts(
			[
				'post_type'      => Content_Gate::get_gate_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			]
		);

		foreach ( $gates as $gate ) {
			$settings = Content_Gate::get_custom_access_settings( $gate->ID );
			if ( empty( $settings['access_rules'] ) || ! is_array( $settings['access_rules'] ) ) {
				continue;
			}
			foreach ( $settings['access_rules'] as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				foreach ( $group as $rule ) {
					if ( ! isset( $rule['slug'] ) || 'subscription' !== $rule['slug'] || empty( $rule['value'] ) ) {
						continue;
					}
					$product_ids = is_array( $rule['value'] ) ? $rule['value'] : [ $rule['value'] ];
					foreach ( $product_ids as $product_id ) {
						$product_id = (int) $product_id;
						if ( ! isset( $map[ $product_id ] ) ) {
							$map[ $product_id ] = [];
						}
						// Keyed by gate ID to dedupe across groups/rules.
						$map[ $product_id ][ $gate->ID ] = [
							'id'    => $gate->ID,
							'title' => get_the_title( $gate->ID ),
						];
					}
				}
			}
		}

		// Reindex inner maps to plain lists.
		foreach ( $map as $product_id => $product_gates ) {
			$map[ $product_id ] = array_values( $product_gates );
		}

		set_transient( self::GATE_MAP_TRANSIENT, $map, 5 * MINUTE_IN_SECONDS );
		self::$product_gate_map = $map;
		return $map;
	}

	/**
	 * Invalidate the cached product → content-gates map when a content-gate post is saved,
	 * trashed, or deleted — its status or existence changes which gates the map includes.
	 *
	 * @param int      $post_id The post ID (unused; the post object carries the type).
	 * @param \WP_Post $post    The post being saved or deleted.
	 *
	 * @return void
	 */
	public function maybe_invalidate_gate_map( $post_id, $post ) {
		if ( ! class_exists( 'Newspack\Content_Gate' ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( in_array( $post->post_type, Content_Gate::get_gate_post_types(), true ) ) {
			self::invalidate_gate_map_cache();
		}
	}

	/**
	 * Invalidate the cached product → content-gates map when a gate's `custom_access` rules are
	 * written directly. The subscription rule that feeds the map lives in that post meta, and its
	 * update bypasses `save_post`.
	 *
	 * @param int    $meta_id   The meta row ID (unused).
	 * @param int    $object_id The post ID (unused).
	 * @param string $meta_key  The meta key that changed.
	 *
	 * @return void
	 */
	public function maybe_invalidate_gate_map_on_meta( $meta_id, $object_id, $meta_key ) {
		if ( 'custom_access' === $meta_key ) {
			self::invalidate_gate_map_cache();
		}
	}

	/**
	 * Clear the cached product → content-gates map (cross-request transient + per-request memo).
	 *
	 * @return void
	 */
	public static function invalidate_gate_map_cache() {
		delete_transient( self::GATE_MAP_TRANSIENT );
		self::$product_gate_map = null;
	}

	/**
	 * Find a product_cat term ID by slug.
	 *
	 * @param string $slug The category slug.
	 *
	 * @return int The term ID, or 0.
	 */
	private static function find_product_cat_id( $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		return $term ? (int) $term->term_id : 0;
	}

	/**
	 * Ensure the convention category for an availability tier exists, returning its ID.
	 *
	 * @param string $availability 'private' or 'free'.
	 *
	 * @return int The term ID, or 0.
	 */
	private static function ensure_availability_category( $availability ) {
		// Term names are stored in the DB as content — use locale-independent defaults so a
		// later language switch can't fork the data. UI labels are translated separately.
		$map = [
			'private' => [ 'private-subscriptions', 'Private Subscriptions' ],
			'free'    => [ 'free-subscriptions', 'Free Subscriptions' ],
		];
		if ( ! isset( $map[ $availability ] ) ) {
			return 0;
		}
		list( $slug, $name ) = $map[ $availability ];
		$existing            = self::find_product_cat_id( $slug );
		if ( $existing ) {
			return $existing;
		}
		$result = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
		return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
	}

	/**
	 * Apply an availability choice to a category list (availability maps to a category).
	 *
	 * Strips the private/free convention categories, then re-adds the chosen one. "Public"
	 * leaves the product in neither.
	 *
	 * @param int[]  $category_ids The picked category IDs.
	 * @param string $availability 'public', 'private', or 'free'.
	 *
	 * @return int[] The resolved category IDs.
	 */
	private static function apply_availability_to_categories( $category_ids, $availability ) {
		$convention = array_filter(
			[
				self::find_product_cat_id( 'private-subscriptions' ),
				self::find_product_cat_id( 'free-subscriptions' ),
			]
		);
		$category_ids = array_values( array_diff( array_map( 'absint', $category_ids ), $convention ) );
		if ( in_array( $availability, [ 'private', 'free' ], true ) ) {
			$category_id = self::ensure_availability_category( $availability );
			if ( $category_id ) {
				$category_ids[] = $category_id;
			}
		}
		return array_values( array_unique( $category_ids ) );
	}

	/**
	 * Set or clear a product's donation flag.
	 *
	 * @param \WC_Product $product     The product.
	 * @param bool        $is_donation Whether the product is a donation.
	 *
	 * @return void
	 */
	private static function set_donation_flag( $product, $is_donation ) {
		$product->update_meta_data( WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( (bool) $is_donation ) );
	}

	/**
	 * Human label for a subscription product type.
	 *
	 * @param string $type The product type.
	 *
	 * @return string The label.
	 */
	private static function get_type_label( $type ) {
		$labels = [
			'subscription'          => __( 'Simple subscription', 'newspack-plugin' ),
			'variable-subscription' => __( 'Variable subscription', 'newspack-plugin' ),
			'grouped'               => __( 'Plan bundle', 'newspack-plugin' ),
			'simple'                => __( 'One-time', 'newspack-plugin' ),
		];
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Human label for a product status.
	 *
	 * @param string $status The post status.
	 *
	 * @return string The label.
	 */
	private static function get_status_label( $status ) {
		$object = get_post_status_object( $status );
		return $object ? $object->label : ucfirst( $status );
	}

	/**
	 * Add the Subscription Products page.
	 */
	public function add_page() {
		add_submenu_page(
			$this->parent_slug,
			$this->get_name(),
			esc_html__( 'Plans', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() ) {
			return;
		}

		parent::enqueue_scripts_and_styles();
		wp_enqueue_script( 'newspack-wizards' );
		wp_localize_script(
			'newspack-wizards',
			'newspackAudienceSubscriptionProducts',
			[
				'new_product_url'                  => admin_url( 'post-new.php?post_type=product' ),
				'manage_products_url'              => admin_url( 'edit.php?post_type=product' ),
				'woocommerce_subscriptions_active' => function_exists( 'wcs_get_subscriptions' ),
			]
		);
	}
}
