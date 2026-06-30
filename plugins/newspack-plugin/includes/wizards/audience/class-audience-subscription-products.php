<?php
/**
 * Audience Subscription Products Wizard.
 *
 * Exploratory DataViews management page that lists Woo Subscriptions products with
 * a productized, consolidated model (price + period, active subscriber counts,
 * category, status) plus the RSM Layer 2 policy stack + effective price.
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
	 * Subscription statuses counted as "active" subscribers.
	 *
	 * Mirrors the active statuses used by the WooCommerce connection
	 * ({@see Newspack\WooCommerce_Connection}).
	 */
	const ACTIVE_SUBSCRIPTION_STATUSES = [ 'active', 'pending-cancel' ];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
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
			'products'              => [],
			'currency'              => self::get_currency(),
			'policy_source_is_mock' => Subscription_Policy_Resolver::IS_MOCK,
			'available_categories'  => self::get_all_product_categories(),
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
				'name' => get_the_title( $result ),
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
	 * @param array  $params       The request params (price/period/interval/sign_up_fee/trial_*).
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
		$interval     = isset( $params['interval'] ) ? max( 1, min( 6, (int) $params['interval'] ) ) : 1;
		$sign_up_fee  = isset( $params['sign_up_fee'] ) ? max( 0, (float) $params['sign_up_fee'] ) : 0;
		$trial_length = isset( $params['trial_length'] ) ? max( 0, (int) $params['trial_length'] ) : 0;
		$trial_period = ( isset( $params['trial_period'] ) && in_array( $params['trial_period'], self::VALID_PERIODS, true ) ) ? $params['trial_period'] : 'month';

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
		$product->update_meta_data( '_subscription_price', $price );
		$product->update_meta_data( '_subscription_period', $period );
		$product->update_meta_data( '_subscription_period_interval', $interval );
		$product->update_meta_data( '_subscription_length', 0 );
		$product->update_meta_data( '_subscription_sign_up_fee', $sign_up_fee );
		$product->update_meta_data( '_subscription_trial_length', $trial_length );
		$product->update_meta_data( '_subscription_trial_period', $trial_period );
		if ( $is_donation ) {
			$product->update_meta_data( WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		}
		if ( ! empty( $params['is_group_subscription'] ) ) {
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
		$attribute->set_name( 'Billing period' );
		$attribute->set_options( $labels );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( [ $attribute ] );
		if ( $is_donation ) {
			$product->update_meta_data( WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		}
		$parent_id = $product->save();
		if ( ! $parent_id ) {
			return new \WP_Error( 'create_failed', __( 'Failed to create the product.', 'newspack-plugin' ), [ 'status' => 500 ] );
		}

		// Set the product_type term explicitly (WC's save doesn't reliably do it here);
		// without this the parent reads back as 'simple' and has no variations.
		wp_set_object_terms( $parent_id, 'variable-subscription', 'product_type' );

		foreach ( $clean as $plan ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( [ 'billing-period' => $plan['label'] ] );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( (string) $plan['price'] );
			$variation->update_meta_data( '_subscription_price', $plan['price'] );
			$variation->update_meta_data( '_subscription_period', $plan['period'] );
			$variation->update_meta_data( '_subscription_period_interval', $plan['interval'] );
			$variation->update_meta_data( '_subscription_length', 0 );
			if ( $plan['group_enabled'] ) {
				$variation->update_meta_data( self::GROUP_ENABLED_META, wc_bool_to_string( true ) );
				$variation->update_meta_data( self::GROUP_LIMIT_META, $plan['group_limit'] );
			}
			$variation->save();
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
		if ( $is_donation ) {
			$product->update_meta_data( WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		}
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
			$product->update_meta_data( '_subscription_price', $price );
			$product->update_meta_data( '_subscription_period', $period );
			$product->update_meta_data( '_subscription_period_interval', $interval );
			$product->update_meta_data( self::GROUP_ENABLED_META, wc_bool_to_string( ! empty( $params['is_group_subscription'] ) ) );
			if ( ! empty( $params['is_group_subscription'] ) ) {
				$product->update_meta_data( self::GROUP_LIMIT_META, isset( $params['group_member_limit'] ) ? max( 0, (int) $params['group_member_limit'] ) : 0 );
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
				'name' => get_the_title( $product_id ),
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
				$label    = $existing ? $existing->get_attribute( 'billing-period' ) : '';
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

		// Rebuild the parent's billing-period attribute to the desired set of plan labels.
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Billing period' );
		$attribute->set_options( $labels );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( [ $attribute ] );
		$product->save();

		// Upsert variations.
		$keep_ids = [];
		foreach ( $plans as $plan ) {
			$variation = $plan['id'] ? new \WC_Product_Variation( $plan['id'] ) : new \WC_Product_Variation();
			if ( ! $plan['id'] ) {
				$variation->set_parent_id( $parent_id );
			}
			$variation->set_attributes( [ 'billing-period' => $plan['label'] ] );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( (string) $plan['price'] );
			$variation->update_meta_data( '_subscription_price', $plan['price'] );
			$variation->update_meta_data( '_subscription_period', $plan['period'] );
			$variation->update_meta_data( '_subscription_period_interval', $plan['interval'] );
			$variation->update_meta_data( '_subscription_length', 0 );
			$variation->update_meta_data( self::GROUP_ENABLED_META, wc_bool_to_string( $plan['group_enabled'] ) );
			if ( $plan['group_enabled'] ) {
				$variation->update_meta_data( self::GROUP_LIMIT_META, $plan['group_limit'] );
			}
			$keep_ids[] = (int) $variation->save();
		}

		// Delete variations that were removed.
		foreach ( array_diff( $existing_ids, $keep_ids ) as $removed_id ) {
			wp_delete_post( $removed_id, true );
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
		$type          = $product->get_type();
		$categories    = self::get_categories( $product->get_id() );
		$pricing       = self::get_pricing( $product );
		$currency_code = self::get_currency()['code'];

		$is_grouped = $product->is_type( 'grouped' );

		// Layer 2: resolve the policy stack + effective price through the integration seam.
		// Variable subscriptions resolve PER VARIATION — each plan (monthly/annual/…) can
		// carry a different policy and effective price. Grouped products and one-time (simple)
		// products aren't recurring, so they get an empty (no-policy) resolution.
		if ( $is_grouped || $product->is_type( 'simple' ) ) {
			$policy = self::empty_policy( $currency_code );
		} elseif ( $product->is_type( 'variable-subscription' ) ) {
			foreach ( $pricing['variations'] as $index => $variation ) {
				$pricing['variations'][ $index ]['policy'] = self::resolve_policy(
					$variation['id'],
					$variation['base_price'],
					$variation['period'],
					$currency_code
				);
			}
			$policy = self::representative_variation_policy( $pricing['variations'], $pricing['base_price'], $currency_code );
		} else {
			$policy = self::resolve_policy(
				$product->get_id(),
				$pricing['base_price'],
				$pricing['period'],
				$currency_code
			);
		}

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
			'policy'                => $policy,
		];
	}

	/**
	 * An empty (no-policy) resolution payload, for products that aren't directly priced.
	 *
	 * @param string $currency_code The store currency code.
	 *
	 * @return array A resolution payload with no policies and null pricing.
	 */
	private static function empty_policy( $currency_code ) {
		return [
			'is_mock'         => Subscription_Policy_Resolver::IS_MOCK,
			'base_price'      => null,
			'effective_price' => null,
			'currency'        => $currency_code,
			'cycle'           => '',
			'policies'        => [],
			'schedule'        => [],
		];
	}

	/**
	 * Resolve a product's policy stack, guarding an unset base price.
	 *
	 * A null base price means "not priced" — resolving it would coerce to 0.0 and
	 * fabricate a free price (priming the engine against it), so return an empty
	 * resolution instead and let the UI render an em dash.
	 *
	 * @param int        $id            The product/variation ID (0 when none).
	 * @param float|null $base_price    The base recurring price, or null when unset.
	 * @param string     $cycle         Billing period slug.
	 * @param string     $currency_code Store currency code.
	 *
	 * @return array A policy resolution payload.
	 */
	private static function resolve_policy( $id, $base_price, $cycle, $currency_code ) {
		if ( null === $base_price ) {
			return self::empty_policy( $currency_code );
		}
		return Subscription_Policy_Resolver::resolve(
			$id,
			[
				'base_price' => $base_price,
				'cycle'      => $cycle,
				'currency'   => $currency_code,
			]
		);
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
					'id'          => $variation_id,
					'name'        => $variation->get_name(),
					'plan_label'  => $variation->get_attribute( 'billing-period' ),
					'base_price'  => $v_price,
					'period'      => $v_period,
					'interval'    => $v_interval,
					'price_label' => self::format_price_label( $v_price, $v_period, $v_interval ),
					// Group-subscription (multi-seat) settings live per variation.
					'group'       => self::read_group_settings( $variation ),
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
	 * Pick the representative policy for a variable subscription row.
	 *
	 * The row in the table shows the entry (lowest-price) plan, so the row-level policy
	 * mirrors that variation. Falls back to the first variation, then to an empty
	 * resolution, so a variable product with no priced variations still renders cleanly.
	 *
	 * @param array      $variations    Variations, each already carrying a resolved 'policy'.
	 * @param float|null $base_price    The representative (lowest) base price.
	 * @param string     $currency_code The store currency code.
	 *
	 * @return array A policy resolution payload.
	 */
	private static function representative_variation_policy( $variations, $base_price, $currency_code ) {
		foreach ( $variations as $variation ) {
			if ( isset( $variation['policy'], $variation['base_price'] ) && $variation['base_price'] === $base_price ) {
				return $variation['policy'];
			}
		}
		if ( isset( $variations[0]['policy'] ) ) {
			return $variations[0]['policy'];
		}
		// No priced variations — resolve_policy() returns an empty resolution when the base
		// price is unset (null), so the UI renders an em dash rather than a fabricated $0.
		return self::resolve_policy( 0, $base_price, '', $currency_code );
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
		if ( ! isset( $raw ) || '' === $raw ) {
			return null;
		}
		return (float) $raw;
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
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
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

		$subscription_ids = [];
		foreach ( $product_ids as $product_id ) {
			$subscriptions = \wcs_get_subscriptions(
				[
					'product_id'             => $product_id,
					'subscription_status'    => self::ACTIVE_SUBSCRIPTION_STATUSES,
					'subscriptions_per_page' => -1,
				]
			);
			// wcs_get_subscriptions() is keyed by subscription id — dedupe across variations.
			foreach ( array_keys( $subscriptions ) as $subscription_id ) {
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
		global $wp_locale;
		return [
			'code'               => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'symbol'             => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '$',
			'decimals'           => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
			// Separators number_format_i18n() uses, so the JS-formatted effective price matches
			// the PHP-formatted base price_label.
			'decimal_separator'  => isset( $wp_locale ) ? $wp_locale->number_format['decimal_point'] : '.',
			'thousand_separator' => isset( $wp_locale ) ? $wp_locale->number_format['thousands_sep'] : ',',
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
	 *   - free    : base price is 0, OR a category name contains "free".
	 *   - private : a category name contains "private" (the explicit publisher convention).
	 *   - public  : everything else (a normally purchasable paid subscription).
	 *
	 * NOTE: we deliberately do NOT infer "private" from catalog_visibility=hidden —
	 * Newspack hides donation/RAS products from the catalog for unrelated reasons, so that
	 * signal is too noisy. This is the signal publishers explicitly reach for; the real
	 * RSM/entitlement layer should own it as a typed field rather than inferring it.
	 *
	 * @param float|null $base_price The representative base price.
	 * @param array      $categories Category terms ({ id, name, slug }).
	 *
	 * @return string One of 'public', 'private', 'free'.
	 */
	private static function derive_availability( $base_price, $categories ) {
		$category_names = strtolower( implode( ' ', wp_list_pluck( $categories, 'name' ) ) );

		if ( ( null !== $base_price && 0.0 === (float) $base_price ) || false !== strpos( $category_names, 'free' ) ) {
			return 'free';
		}

		if ( false !== strpos( $category_names, 'private' ) ) {
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

		self::$product_gate_map = $map;
		return $map;
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
		$map = [
			'private' => [ 'private-subscriptions', __( 'Private Subscriptions', 'newspack-plugin' ) ],
			'free'    => [ 'free-subscriptions', __( 'Free Subscriptions', 'newspack-plugin' ) ],
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
				'policy_source_is_mock'            => Subscription_Policy_Resolver::IS_MOCK,
				'woocommerce_subscriptions_active' => function_exists( 'wcs_get_subscriptions' ),
			]
		);
	}
}
