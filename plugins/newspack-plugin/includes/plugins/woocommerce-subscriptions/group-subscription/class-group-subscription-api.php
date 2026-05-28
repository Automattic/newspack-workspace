<?php
/**
 * REST API class for Newspack Group Subscriptions.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Settings class.
 */
class Group_Subscription_API {
	const NAMESPACE = 'newspack-group-subscription/v1';
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		\register_rest_route(
			self::NAMESPACE,
			'/search-users',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_search_users' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'search'          => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'subscription_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/members',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_update_members' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'subscription_id'   => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'members_to_add'    => [
						'type'     => 'array',
						'items'    => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'required' => false,
					],
					'members_to_remove' => [
						'type'     => 'array',
						'items'    => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'required' => false,
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/invite',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_invite' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'subscription_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'email'           => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/invite',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'api_cancel_invite' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'subscription_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'email'           => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/invite-link',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_generate_invite_link' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'subscription_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/invite-link',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'api_delete_invite_link' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'subscription_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Permission callback for managing group subscriptions.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission to invite to the group subscription.
	 */
	public static function permission_callback( $request ) {
		$subscription_id = $request->get_param( 'subscription_id' );
		$subscription    = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
		if ( ! $subscription ) {
			return false;
		}
		return current_user_can( 'manage_woocommerce' ) || Group_Subscription::user_is_manager( get_current_user_id(), $subscription );
	}

	/**
	 * User search for group subscription.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public static function api_search_users( $request ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return \rest_ensure_response( new \WP_Error( 'newspack_group_subscription_api', __( 'WooCommerce Subscriptions is not available.', 'newspack-plugin' ) ) );
		}
		$search          = $request->get_param( 'search' );
		$subscription_id = $request->get_param( 'subscription_id' );
		$subscription    = wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return \rest_ensure_response( new \WP_Error( 'newspack_group_subscription_api_search_users', __( 'Subscription not found.', 'newspack-plugin' ) ) );
		}
		$exclude   = Group_Subscription::get_members( $subscription );
		$exclude[] = $subscription->get_user_id();
		$query1    = get_users(
			/**
			 * Filter the user query args for searching for group subscription users.
			 *
			 * @param array $query_args Query args.
			 * @param string $query_type Query type: main_query or meta_query.
			 */
			apply_filters(
				'newspack_group_subscription_user_query_args',
				[
					'fields'         => [ 'ID', 'user_email' ],
					'exclude'        => $exclude,
					'search'         => "*$search*",
					'search_columns' => [ 'ID', 'user_login', 'user_url', 'user_email', 'user_nicename', 'display_name' ],
					'role__in'       => Reader_Activation::get_reader_roles(),
				],
				'main_query'
			)
		);
		$exclude = array_values( array_unique( array_merge( $exclude, array_column( $query1, 'ID' ) ) ) );
		$query2  = \get_users(
			/**
			 * Filter the user query args for searching for group subscription users.
			 *
			 * @param array $query_args Query args.
			 * @param string $query_type Query type: main_query or meta_query.
			 */
			\apply_filters(
				'newspack_group_subscription_user_query_args',
				[
					'fields'     => [ 'ID', 'user_email' ],
					'exclude'    => $exclude,
					'role__in'   => Reader_Activation::get_reader_roles(),
					'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						[
							'key'     => 'first_name',
							'value'   => $search,
							'compare' => 'LIKE',
						],
						[
							'key'     => 'last_name',
							'value'   => $search,
							'compare' => 'LIKE',
						],
					],
				],
				'meta_query'
			)
		);
		$users = array_map(
			function( $user ) {
				return [
					'id'   => $user->ID,
					'text' => $user->user_email . ' (#' . $user->ID . ')',
				];
			},
			array_merge( $query1, $query2 )
		);

		// Sort by ID.
		usort(
			$users,
			function( $a, $b ) {
				return $a['id'] <=> $b['id'];
			}
		);
		return \rest_ensure_response( $users );
	}

	/**
	 * Update members for a group subscription.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public static function api_update_members( $request ) {
		$subscription_id   = $request->get_param( 'subscription_id' );
		$members_to_add    = $request->get_param( 'members_to_add' );
		$members_to_remove = $request->get_param( 'members_to_remove' );
		$results           = Group_Subscription::update_members( $subscription_id, $members_to_add ?? [], $members_to_remove ?? [] );
		return \rest_ensure_response( $results );
	}

	/**
	 * Invite a user to a group subscription.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public static function api_invite( $request ) {
		$subscription_id = $request->get_param( 'subscription_id' );
		$email           = $request->get_param( 'email' );
		$invite = Group_Subscription_Invite::generate_invite( $subscription_id, $email );
		return \rest_ensure_response( $invite );
	}

	/**
	 * Cancel an invite for a group subscription.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public static function api_cancel_invite( $request ) {
		$subscription_id = $request->get_param( 'subscription_id' );
		$email           = $request->get_param( 'email' );
		$result = Group_Subscription_Invite::cancel_invite( $subscription_id, $email );
		return \rest_ensure_response( $result );
	}

	/**
	 * Generate an invite-link for a group subscription.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public static function api_generate_invite_link( $request ) {
		$subscription_id = $request->get_param( 'subscription_id' );
		$result = Group_Subscription_Invite::generate_link_invite( $subscription_id, get_current_user_id() );
		return \rest_ensure_response( $result );
	}

	/**
	 * Delete an invite-link for a group subscription.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The response object.
	 */
	public static function api_delete_invite_link( $request ) {
		$subscription_id = $request->get_param( 'subscription_id' );
		$result = Group_Subscription_Invite::delete_link_invite( $subscription_id, get_current_user_id() );
		return \rest_ensure_response( $result );
	}
}
Group_Subscription_API::init();
