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
		// The group management REST routes back the reader-facing My Account UX
		// and the admin meta box, both gated behind the Access Control feature
		// flag. Don't register the routes on un-migrated sites.
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return;
		}
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
			'/name',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_update_name' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'subscription_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'name'            => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
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
		// Promote/demote a manager. There is no My Account REST equivalent — the
		// reader-facing surface posts a form to admin_post_ — but the rule and the
		// state gate are the same, so the route belongs here beside the member and
		// invite routes rather than in the wizard, keeping one capability model.
		\register_rest_route(
			self::NAMESPACE,
			'/manager',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_set_manager_role' ],
				'permission_callback' => [ __CLASS__, 'role_permission_callback' ],
				'args'                => [
					'subscription_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'user_id'         => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'role'            => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'manager', 'member' ],
					],
				],
			]
		);
		// Adjust the group's seat limit. Admin-only: capacity is sold to the owner,
		// so changing it is a publisher decision with no My Account equivalent.
		\register_rest_route(
			self::NAMESPACE,
			'/seat-limit',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_update_seat_limit' ],
				'permission_callback' => [ __CLASS__, 'admin_permission_callback' ],
				'args'                => [
					'subscription_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'limit'           => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 0,
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
	 * Permission callback for changing who manages a group.
	 *
	 * Stricter than permission_callback(): promoting and demoting managers is the
	 * owner's call alone, with store admins acting on their behalf. Deferring to
	 * Group_Subscription::user_can_manage_roles() keeps this identical to the rule
	 * the My Account form handler applies.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return bool Whether the user may change roles in this group.
	 */
	public static function role_permission_callback( $request ): bool {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $request->get_param( 'subscription_id' ) );
		if ( ! $subscription ) {
			return false;
		}
		return Group_Subscription::user_can_manage_roles( get_current_user_id(), $subscription );
	}

	/**
	 * Permission callback for the admin-only group routes.
	 *
	 * Neither the owner nor a manager may reach these: they have no My Account
	 * equivalent because they are publisher decisions about what the group was
	 * sold, not maintenance of who is in it.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return bool Whether the user is a store admin acting on a real group.
	 */
	public static function admin_permission_callback( $request ): bool {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $request->get_param( 'subscription_id' ) );
		if ( ! $subscription ) {
			return false;
		}
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Resolve the manager an invite link should be minted for or read from.
	 *
	 * Invite links are stored per manager and re-validated against that manager's
	 * status when an invitee clicks (see Group_Subscription_Invite::validate_link_invite()).
	 * An admin is not a manager of the groups they administer, so minting under
	 * their own ID would produce a link that is dead on arrival. They act on the
	 * owner's link instead — the same link the owner sees in My Account.
	 *
	 * For an owner or a manager this returns their own ID, so the reader-facing
	 * path is unchanged. Related: NPPD-2120, the shipped bug where a link outlives
	 * its minter's manager status; this resolver keeps the admin surface from
	 * creating new instances of it, it does not fix it.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int The manager user ID to act as, or 0 when there is none.
	 */
	public static function resolve_link_manager_id( $subscription ): int {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return 0;
		}
		$current_user_id = get_current_user_id();
		if ( $current_user_id && Group_Subscription::user_is_manager( $current_user_id, $subscription ) ) {
			return $current_user_id;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return (int) $subscription->get_user_id();
		}
		return 0;
	}

	/**
	 * Promote a member to manager, or demote a manager back to a member.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error The response object.
	 */
	public static function api_set_manager_role( $request ): \WP_REST_Response|\WP_Error {
		$subscription_id = $request->get_param( 'subscription_id' );
		// Match the member/invite endpoints: terminal-state subscriptions accept no
		// changes. 409 Conflict, the shared "can't write in this state" status.
		if ( ! Group_Subscription_MyAccount::is_subscription_manageable( $subscription_id ) ) {
			return new \WP_Error(
				'newspack_group_subscription_not_manageable',
				sprintf(
					/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
					__( 'This %s is no longer active, so its managers can\'t be changed.', 'newspack-plugin' ),
					Group_Subscription::get_label_lower( 'singular' )
				),
				[ 'status' => 409 ]
			);
		}
		$user_id = $request->get_param( 'user_id' );
		$result  = 'manager' === $request->get_param( 'role' )
			? Group_Subscription::add_manager( $subscription_id, $user_id )
			: Group_Subscription::remove_manager( $subscription_id, $user_id );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}
		// Echo the resulting manager list so the client can re-render roles without
		// inferring what the server decided.
		return \rest_ensure_response(
			[ 'managers' => array_values( array_map( 'intval', Group_Subscription::get_managers( $subscription_id ) ) ) ]
		);
	}

	/**
	 * Update a group's seat limit.
	 *
	 * A maintenance action, not a billing one: it moves capacity only and never
	 * charges, refunds or changes the subscription's status. Selling the extra
	 * seats is a separate, deliberate step.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error The response object.
	 */
	public static function api_update_seat_limit( $request ): \WP_REST_Response|\WP_Error {
		$subscription_id = $request->get_param( 'subscription_id' );
		$subscription    = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
		// Only a real group has a seat limit to move. Mirror api_get_group() and the
		// /manager route (via add_manager()/remove_manager()): a non-group subscription
		// is a 404, not a silent write of limit meta onto something that is not a group.
		if ( ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return new \WP_Error(
				'newspack_group_subscription_not_found',
				sprintf(
					/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
					__( 'That %s could not be found.', 'newspack-plugin' ),
					Group_Subscription::get_label_lower( 'singular' )
				),
				[ 'status' => 404 ]
			);
		}
		if ( ! Group_Subscription_MyAccount::is_subscription_manageable( $subscription_id ) ) {
			return new \WP_Error(
				'newspack_group_subscription_not_manageable',
				sprintf(
					/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
					__( 'This %s is no longer active, so its seat limit can\'t be changed.', 'newspack-plugin' ),
					Group_Subscription::get_label_lower( 'singular' )
				),
				[ 'status' => 409 ]
			);
		}
		$limit = (int) $request->get_param( 'limit' );
		$reserved     = self::reserved_seats( $subscription );

		// 0 is the unlimited sentinel, not "no seats", so it is always acceptable
		// however many seats are committed. Any other value must cover what the
		// group has already promised, so a reduction can't strand a member or a
		// pending invitation.
		if ( $limit > 0 && $limit < $reserved ) {
			return new \WP_Error(
				'newspack_group_subscription_seat_limit_too_low',
				sprintf(
					/* translators: %d: the number of seats already committed to members and pending invitations. */
					__( 'The seat limit cannot be lower than the %d seats already committed to members and pending invitations.', 'newspack-plugin' ),
					$reserved
				),
				[ 'status' => 400 ]
			);
		}

		Group_Subscription_Settings::update_subscription_settings( $subscription, [ 'limit' => $limit ] );
		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
		// Echo the stored value: normalize_limit() raises any non-zero limit to a
		// floor of 2, so what was asked for and what was saved can differ.
		return \rest_ensure_response( [ 'seatLimit' => (int) $settings['limit'] ] );
	}

	/**
	 * The number of seats a group has already committed: everyone holding one plus
	 * every outstanding invitation.
	 *
	 * Expired invitations are excluded — they hold nothing, so they must not pin the
	 * seat limit up.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int The committed seat count.
	 */
	public static function reserved_seats( $subscription ): int {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return 0;
		}
		$pending_invites = Group_Subscription_Invite::get_invites( $subscription, false );
		return Group_Subscription::get_member_count( $subscription ) + count( $pending_invites );
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
		$subscription_id = $request->get_param( 'subscription_id' );
		// Match the admin-post handlers: terminal-state subscriptions don't accept member changes.
		// 409 Conflict (not 403) so every "can't write in this state" rejection across this
		// endpoint shares one status with update_members()'s member-limit response.
		if ( ! Group_Subscription_MyAccount::is_subscription_manageable( $subscription_id ) ) {
			return \rest_ensure_response(
				new \WP_Error(
					'newspack_group_subscription_not_manageable',
					sprintf(
						/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
						__( 'This %s is no longer active, so its members can\'t be changed.', 'newspack-plugin' ),
						Group_Subscription::get_label_lower( 'singular' )
					),
					[ 'status' => 409 ]
				)
			);
		}
		$members_to_add    = $request->get_param( 'members_to_add' );
		$members_to_remove = $request->get_param( 'members_to_remove' );
		// The shared permission_callback only proves the actor may manage the group;
		// it doesn't stop a manager from removing a peer manager. Enforce the
		// per-target peer-manager rule here, matching the My Account handler, so a
		// forged request can't do what the UI won't offer.
		foreach ( (array) $members_to_remove as $member_to_remove ) {
			if ( ! Group_Subscription::can_actor_remove_member( get_current_user_id(), $member_to_remove, $subscription_id ) ) {
				return \rest_ensure_response(
					new \WP_Error(
						'newspack_group_subscription_remove_not_allowed',
						sprintf(
							/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
							__( 'You do not have permission to remove this member from the %s.', 'newspack-plugin' ),
							Group_Subscription::get_label_lower( 'singular' )
						),
						[ 'status' => 403 ]
					)
				);
			}
		}
		$results = Group_Subscription::update_members( $subscription_id, $members_to_add ?? [], $members_to_remove ?? [] );
		return \rest_ensure_response( $results );
	}

	/**
	 * Rename a group subscription.
	 *
	 * Renaming is metadata-only, so unlike member/invite changes it is NOT gated on the
	 * subscription's state: an owner can still rename a cancelled or expired group to tell
	 * their groups apart in the picker. An empty name clears the override, so the group
	 * name falls back to the product name and then the default group label.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response The response object, carrying the resolved group name.
	 */
	public static function api_update_name( $request ) {
		$subscription_id = $request->get_param( 'subscription_id' );
		$subscription    = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
		if ( ! $subscription ) {
			return \rest_ensure_response(
				new \WP_Error(
					'newspack_group_subscription_not_found',
					__( 'Subscription not found.', 'newspack-plugin' ),
					[ 'status' => 404 ]
				)
			);
		}
		// Cap the length to match the input's maxlength, so a client bypassing the field can't
		// store an oversized name that breaks the header/picker layout. mb_substr() needs no
		// mbstring guard: WP core polyfills it in wp-includes/compat.php. Unlike mb_strtolower(),
		// which core does not polyfill, hence the guard in Group_Subscription::get_label_lower().
		$name = mb_substr( trim( (string) $request->get_param( 'name' ) ), 0, Group_Subscription_Settings::GROUP_NAME_MAX_LENGTH );
		Group_Subscription_Settings::update_subscription_name( $subscription, $name );
		// Return the resolved name so the client can reflect the fallback when the name was cleared.
		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
		return \rest_ensure_response( [ 'name' => $settings['name'] ] );
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
		// Email invitations are new invitations, so gate on active state for parity with
		// api_generate_invite_link() and the admin-post handler (verify_active).
		// 409 Conflict: a state-based rejection, matching the other member/invite gates.
		if ( ! Group_Subscription_MyAccount::is_subscription_active( $subscription_id ) ) {
			return \rest_ensure_response(
				new \WP_Error(
					'newspack_group_subscription_not_active',
					sprintf(
						/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
						__( 'This %s is not active, so new invitations can\'t be issued.', 'newspack-plugin' ),
						Group_Subscription::get_label_lower( 'singular' )
					),
					[ 'status' => 409 ]
				)
			);
		}
		$email  = $request->get_param( 'email' );
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
		// Only active subscriptions can mint new invitations; otherwise a stale token could be
		// left behind on an inactive sub for later reactivation. Deletion stays allowed for cleanup.
		// 409 Conflict: a state-based rejection, matching the other member/invite gates.
		if ( ! Group_Subscription_MyAccount::is_subscription_active( $subscription_id ) ) {
			return \rest_ensure_response(
				new \WP_Error(
					'newspack_group_subscription_not_active',
					sprintf(
						/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
						__( 'This %s is not active, so new invitations can\'t be issued.', 'newspack-plugin' ),
						Group_Subscription::get_label_lower( 'singular' )
					),
					[ 'status' => 409 ]
				)
			);
		}
		$result = Group_Subscription_Invite::generate_link_invite( $subscription_id, self::resolve_link_manager_id( $subscription_id ) );
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
		$result = Group_Subscription_Invite::delete_link_invite( $subscription_id, self::resolve_link_manager_id( $subscription_id ) );
		return \rest_ensure_response( $result );
	}
}
Group_Subscription_API::init();
