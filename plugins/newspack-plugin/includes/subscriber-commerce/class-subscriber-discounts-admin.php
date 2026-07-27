<?php
/**
 * Admin surface for subscriber discounts: the Subscriptions wizard tab and the
 * endpoints behind it.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriber discounts admin and REST.
 */
class Subscriber_Discounts_Admin {

	/**
	 * The wizard tab this feature owns.
	 */
	const TAB_SLUG = 'discounts';

	/**
	 * Hook up the tab and the endpoints.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_tab' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_api_endpoints' ] );
	}

	/**
	 * Add the tab to the Subscriptions wizard.
	 *
	 * Gated on the admin being available rather than on enforcement being
	 * active: a publisher migrating off WooCommerce Memberships configures their
	 * discounts while Memberships still owns the front end, and deactivates it
	 * afterwards.
	 */
	public static function register_tab() {
		if ( ! Subscriber_Commerce::is_admin_available() ) {
			return;
		}
		Audience_Subscriptions::register_tab(
			self::TAB_SLUG,
			[
				'label' => esc_html__( 'Subscriber discounts', 'newspack-plugin' ),
				'path'  => '/discounts',
			]
		);
	}

	/**
	 * Register the discount endpoints.
	 */
	public static function register_api_endpoints() {
		if ( ! Subscriber_Commerce::is_admin_available() ) {
			return;
		}
		$route_base = '/wizard/newspack-audience-subscriptions/discounts';

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			$route_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'api_get_discounts' ],
					'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'api_save_discount' ],
					'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
					'args'                => self::rule_args(),
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			$route_base . '/settings',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_save_settings' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				'args'                => [
					'overlap'       => [
						'type'              => 'string',
						'enum'              => [ 'best', 'combine' ],
						'sanitize_callback' => 'sanitize_key',
					],
					'apply_on_sale' => [
						'type' => 'boolean',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			$route_base . '/(?P<id>[A-Za-z0-9\-]+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'api_delete_discount' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				'args'                => [
					'id' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * Argument schema for saving a rule.
	 *
	 * The rule is validated as a whole by the store; this only shapes the input.
	 *
	 * @return array
	 */
	private static function rule_args() {
		$id_list = [
			'type'  => 'array',
			'items' => [ 'type' => 'integer' ],
		];
		return [
			'id'                       => [ 'type' => 'string' ],
			'subscription_product_ids' => $id_list,
			'targeting'                => [
				'type' => 'string',
				'enum' => [
					Product_Targeting::TARGETING_PRODUCTS,
					Product_Targeting::TARGETING_CATEGORY,
					Product_Targeting::TARGETING_ALL,
				],
			],
			'product_ids'              => $id_list,
			'category_ids'             => $id_list,
			'excluded_product_ids'     => $id_list,
			'discount_type'            => [
				'type' => 'string',
				'enum' => [ 'fixed', 'percent' ],
			],
			'amount'                   => [ 'type' => 'number' ],
			'active'                   => [ 'type' => 'boolean' ],
		];
	}

	/**
	 * Whether the current user may manage discounts.
	 *
	 * @return bool|\WP_Error
	 */
	public static function api_permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * The rules and settings behind the tab.
	 *
	 * @return \WP_REST_Response
	 */
	public static function api_get_discounts() {
		return rest_ensure_response( self::response_payload() );
	}

	/**
	 * Create or update a rule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_save_discount( $request ) {
		$saved_rule = Subscriber_Discounts::save_rule(
			[
				'id'                       => $request->get_param( 'id' ),
				'subscription_product_ids' => $request->get_param( 'subscription_product_ids' ),
				'targeting'                => $request->get_param( 'targeting' ),
				'product_ids'              => $request->get_param( 'product_ids' ),
				'category_ids'             => $request->get_param( 'category_ids' ),
				'excluded_product_ids'     => $request->get_param( 'excluded_product_ids' ),
				'discount_type'            => $request->get_param( 'discount_type' ),
				'amount'                   => $request->get_param( 'amount' ),
				'active'                   => $request->get_param( 'active' ),
			]
		);
		if ( is_wp_error( $saved_rule ) ) {
			$saved_rule->add_data( [ 'status' => 400 ] );
			return $saved_rule;
		}
		return rest_ensure_response( self::response_payload() );
	}

	/**
	 * Delete a rule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_delete_discount( $request ) {
		if ( ! Subscriber_Discounts::delete_rule( $request->get_param( 'id' ) ) ) {
			return new \WP_Error(
				'newspack_subscriber_discount_not_found',
				esc_html__( 'That discount no longer exists.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}
		return rest_ensure_response( self::response_payload() );
	}

	/**
	 * Update the global settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function api_save_settings( $request ) {
		$settings = [];
		foreach ( [ 'overlap', 'apply_on_sale' ] as $setting ) {
			if ( null !== $request->get_param( $setting ) ) {
				$settings[ $setting ] = $request->get_param( $setting );
			}
		}
		Subscriber_Discounts::save_settings( $settings );
		return rest_ensure_response( self::response_payload() );
	}

	/**
	 * Everything the tab renders from, so every write returns the new state and
	 * the client never has to re-fetch.
	 *
	 * @return array
	 */
	private static function response_payload() {
		return [
			'rules'    => Subscriber_Discounts::get_rules(),
			'settings' => Subscriber_Discounts::get_settings(),
		];
	}
}

Subscriber_Discounts_Admin::init();
