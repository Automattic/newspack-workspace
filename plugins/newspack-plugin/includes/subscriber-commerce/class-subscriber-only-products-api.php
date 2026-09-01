<?php
/**
 * Newspack Subscriber Commerce - subscriber-only products REST API.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes and wizard-tab registration for subscriber-only products.
 *
 * Reachable whenever the admin is available, not only when enforcement is
 * live: a site migrating off WooCommerce Memberships authors its restrictions
 * first and deactivates Memberships afterwards.
 */
class Subscriber_Only_Products_API {

	/**
	 * The wizard tab slug. Matches the front-end registration.
	 */
	const TAB_SLUG = 'subscriber-only';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'init', [ __CLASS__, 'register_tab' ] );
	}

	/**
	 * Register the wizard tab.
	 */
	public static function register_tab() {
		if ( ! Subscriber_Commerce::is_admin_available() ) {
			return;
		}
		Audience_Subscriptions::register_tab(
			self::TAB_SLUG,
			[
				'label' => __( 'Subscriber-only products', 'newspack-plugin' ),
				'path'  => '/' . self::TAB_SLUG,
				// Last of the subscriber-commerce tabs, per the design's tab order.
				'order' => 30,
			]
		);
	}

	/**
	 * Whether the current user may manage restrictions.
	 *
	 * @return bool
	 */
	public static function permissions_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register the REST routes.
	 */
	public static function register_routes() {
		if ( ! Subscriber_Commerce::is_admin_available() ) {
			return;
		}

		$namespace = NEWSPACK_API_NAMESPACE;
		$base      = '/wizard/newspack-audience-subscriptions/restrictions';

		register_rest_route(
			$namespace,
			$base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'api_get_rules' ],
					'permission_callback' => [ __CLASS__, 'permissions_check' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'api_save_rule' ],
					'permission_callback' => [ __CLASS__, 'permissions_check' ],
					'args'                => self::get_rule_args(),
				],
			]
		);

		register_rest_route(
			$namespace,
			$base . '/(?P<id>[a-zA-Z0-9\-]+)',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'api_save_rule' ],
					'permission_callback' => [ __CLASS__, 'permissions_check' ],
					'args'                => self::get_rule_args(),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ __CLASS__, 'api_delete_rule' ],
					'permission_callback' => [ __CLASS__, 'permissions_check' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/wizard/newspack-audience-subscriptions/restriction-settings',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_update_settings' ],
				'permission_callback' => [ __CLASS__, 'permissions_check' ],
				'args'                => [
					'hide_from_product_lists' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
	}

	/**
	 * REST argument schema for a restriction.
	 *
	 * @return array
	 */
	private static function get_rule_args() {
		$id_list = [
			'type'    => 'array',
			'items'   => [ 'type' => 'integer' ],
			'default' => [],
		];
		return [
			'subscription_product_ids' => $id_list,
			'product_ids'              => $id_list,
			'category_ids'             => $id_list,
			'excluded_product_ids'     => $id_list,
			'targeting'                => [
				'type'    => 'string',
				'enum'    => [ Product_Targeting::TARGETING_PRODUCTS, Product_Targeting::TARGETING_CATEGORY, Product_Targeting::TARGETING_ALL ],
				'default' => Product_Targeting::TARGETING_PRODUCTS,
			],
			// Deliberately undefaulted, so an absent `active` reaches
			// sanitize_base_rule() as absent and lands on its fail-safe: a partial
			// payload leaves the rule paused rather than silently gating purchases.
			'active'                   => [
				'type' => 'boolean',
			],
		];
	}

	/**
	 * Get every restriction plus the feature's settings.
	 *
	 * @return \WP_REST_Response
	 */
	public static function api_get_rules() {
		return rest_ensure_response(
			[
				'restrictions' => Subscriber_Only_Products::get_rules(),
				'settings'     => Subscriber_Only_Products::get_settings(),
			]
		);
	}

	/**
	 * Create or update a restriction.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_save_rule( $request ) {
		$id = $request->get_param( 'id' );
		if ( $id && ! Subscriber_Only_Products::get_rule( $id ) ) {
			return new \WP_Error( 'newspack_restriction_not_found', __( 'Restriction not found.', 'newspack-plugin' ), [ 'status' => 404 ] );
		}

		$rule = [
			'id'                       => $id,
			'subscription_product_ids' => $request->get_param( 'subscription_product_ids' ),
			'targeting'                => $request->get_param( 'targeting' ),
			'product_ids'              => $request->get_param( 'product_ids' ),
			'category_ids'             => $request->get_param( 'category_ids' ),
			'excluded_product_ids'     => $request->get_param( 'excluded_product_ids' ),
			'active'                   => $request->get_param( 'active' ),
		];

		Subscriber_Only_Products::save_rule( $rule );
		return self::api_get_rules();
	}

	/**
	 * Delete a restriction.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_delete_rule( $request ) {
		if ( ! Subscriber_Only_Products::delete_rule( $request->get_param( 'id' ) ) ) {
			return new \WP_Error( 'newspack_restriction_not_found', __( 'Restriction not found.', 'newspack-plugin' ), [ 'status' => 404 ] );
		}
		return self::api_get_rules();
	}

	/**
	 * Update the feature's settings.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function api_update_settings( $request ) {
		Subscriber_Only_Products::update_settings( [ 'hide_from_product_lists' => $request->get_param( 'hide_from_product_lists' ) ] );
		return self::api_get_rules();
	}
}
Subscriber_Only_Products_API::init();
