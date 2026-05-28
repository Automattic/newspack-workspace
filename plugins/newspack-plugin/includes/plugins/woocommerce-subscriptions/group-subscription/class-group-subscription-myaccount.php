<?php
/**
 * Newspack Group Subscriptions - My Account integration.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * My Account integration class.
 */
class Group_Subscription_MyAccount {
	/**
	 * Manage members endpoint slug.
	 */
	const MANAGE_MEMBERS_ENDPOINT = 'manage-members';

	/**
	 * Nonce action for the invite member form.
	 */
	const INVITE_NONCE_ACTION = 'newspack_group_subscription_invite';

	/**
	 * Nonce action for the cancel invite form.
	 */
	const CANCEL_INVITE_NONCE_ACTION = 'newspack_group_subscription_cancel_invite';

	/**
	 * Nonce action for the remove member form.
	 */
	const REMOVE_MEMBER_NONCE_ACTION = 'newspack_group_subscription_remove_member';

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// Ensure My Account UI v1 is active before registering endpoints/actions.
		if ( ! class_exists( 'Newspack\\My_Account_UI_V1' ) ) {
			return;
		}
		add_action( 'init', [ __CLASS__, 'flush_rewrite_rules' ] );
		add_filter( 'woocommerce_get_query_vars', [ __CLASS__, 'add_manage_members_endpoint' ] );
		add_action( 'woocommerce_account_' . self::MANAGE_MEMBERS_ENDPOINT . '_endpoint', [ __CLASS__, 'render_group_subscription_members_template' ] );
		add_filter( 'wcs_get_users_subscriptions', [ __CLASS__, 'inject_member_group_subscriptions' ], 15, 2 );
		add_filter( 'map_meta_cap', [ __CLASS__, 'grant_group_member_view_order_cap' ], 15, 4 );
		add_filter( 'wcs_view_subscription_actions', [ __CLASS__, 'view_subscription_actions' ], 13, 3 );
		add_action( 'admin_post_' . self::INVITE_NONCE_ACTION, [ __CLASS__, 'handle_invite_member' ] );
		add_action( 'admin_post_' . self::CANCEL_INVITE_NONCE_ACTION, [ __CLASS__, 'handle_cancel_invite' ] );
		add_action( 'admin_post_' . self::REMOVE_MEMBER_NONCE_ACTION, [ __CLASS__, 'handle_remove_member' ] );
	}

	/**
	 * Flush rewrite rules for My Account endpoints for group subscriptions.
	 */
	public static function flush_rewrite_rules() {
		$rewrite_rules_updated_option_name = 'newspack_group_subscription_rewrite_rules_updated';
		if ( false === get_option( $rewrite_rules_updated_option_name ) ) {
			flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
			update_option( $rewrite_rules_updated_option_name, true );
		}
	}

	/**
	 * Get the URL to manage members of a group subscription.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 *
	 * @return string The URL.
	 */
	private static function get_manage_members_url( $subscription ) {
		return wc_get_endpoint_url(
			self::MANAGE_MEMBERS_ENDPOINT,
			$subscription->get_id(),
			wc_get_page_permalink( 'myaccount' )
		);
	}

	/**
	 * Add manage members query var.
	 *
	 * @param array $query_vars Query vars.
	 *
	 * @return array
	 */
	public static function add_manage_members_endpoint( $query_vars ) {
		$query_vars[ self::MANAGE_MEMBERS_ENDPOINT ] = self::MANAGE_MEMBERS_ENDPOINT;
		return $query_vars;
	}

	/**
	 * Render the group subscription members template.
	 */
	public static function render_group_subscription_members_template() {
		$subscription_id = absint( get_query_var( self::MANAGE_MEMBERS_ENDPOINT ) );
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
		if ( ! $subscription ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'message'  => __( 'Subscription not found.', 'newspack-plugin' ),
						'is_error' => true,
					],
					wc_get_account_endpoint_url( 'edit-account' )
				)
			);
			exit;
		}
		$user_id = \get_current_user_id();
		if ( ! Group_Subscription::user_is_manager( $user_id, $subscription ) ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'message'  => __( 'You do not have permission to manage members of this subscription.', 'newspack-plugin' ),
						'is_error' => true,
					],
					wc_get_account_endpoint_url( 'edit-account' )
				)
			);
			exit;
		}
		$args = [
			'actions'      => \wcs_get_all_user_actions_for_subscription( $subscription, $user_id ),
			'subscription' => $subscription,
			'view'         => 'manage-members',
		];
		\wc_get_template( 'myaccount/group-subscription-members.php', $args );
	}

	/**
	 * Filter the actions a group manager or member can take on a subscription.
	 *
	 * Non-manager group members receive an empty actions array (view-only experience).
	 * Managers (subscription owners) receive an additional "Manage members" action.
	 * Non-group subscriptions and off-account-page requests pass through unchanged.
	 *
	 * @param array            $actions      Actions.
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $user_id      The user ID.
	 *
	 * @return array
	 */
	public static function view_subscription_actions( $actions, $subscription, $user_id ) {
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() || ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return $actions;
		}

		// Non-manager group members get a view-only experience: no actions.
		if ( Group_Subscription::user_is_member( $user_id, $subscription ) ) {
			return [];
		}

		// Managers (subscription owners) get a "Manage members" action.
		if ( Group_Subscription::user_is_manager( $user_id, $subscription ) ) {
			$actions['manage_members'] = [
				'url'  => self::get_manage_members_url( $subscription ),
				'name' => __( 'Manage members', 'newspack-plugin' ),
			];
		}

		return $actions;
	}

	/**
	 * Get subscription ID and redirect URL from POST data.
	 *
	 * @return array{ 0: int, 1: string }
	 */
	private static function get_subscription_context(): array {
		$subscription_id = filter_input( INPUT_POST, 'subscription_id', FILTER_VALIDATE_INT ) ?? 0;
		$redirect_url    = wc_get_endpoint_url( self::MANAGE_MEMBERS_ENDPOINT, $subscription_id, wc_get_page_permalink( 'myaccount' ) );
		return [ $subscription_id, $redirect_url ];
	}

	/**
	 * Verify the current user has permission to manage the subscription, redirecting on failure.
	 *
	 * @param int         $subscription_id Subscription ID.
	 * @param string      $redirect_url    URL to redirect to on failure.
	 * @param string      $active_tab      Active tab slug for the redirect.
	 * @param string|null $error_message   Error message to display.
	 */
	private static function verify_permission( $subscription_id, $redirect_url, $active_tab, $error_message = null ): void {
		if ( ! $error_message ) {
			$error_message = __( 'You do not have permission to manage members for this group subscription.', 'newspack-plugin' );
		}
		$request = new \WP_REST_Request();
		$request->set_param( 'subscription_id', $subscription_id );
		if ( ! Group_Subscription_API::permission_callback( $request ) ) {
			self::redirect(
				new \WP_Error( 'newspack_group_subscription_permission_denied', $error_message ),
				$redirect_url,
				$active_tab,
				$error_message
			);
		}
	}

	/**
	 * Redirect with a success or error message depending on the action result.
	 *
	 * @param \WP_Error|mixed $result          Result of the action.
	 * @param string          $redirect_url    URL to redirect to.
	 * @param string          $active_tab      Active tab slug for the redirect.
	 * @param string          $success_message Success message to display.
	 */
	private static function redirect( $result, $redirect_url, $active_tab, $success_message ): never {
		$query_args = [
			'activeTab' => $active_tab,
			'message'   => $success_message,
		];
		if ( is_wp_error( $result ) ) {
			$query_args['is_error'] = true;
			$query_args['message'] = $result->get_error_message();
		} else {
			$query_args['is_success'] = true;
		}
		wp_safe_redirect(
			add_query_arg( $query_args, $redirect_url )
		);
		exit;
	}

	/**
	 * Handle the invite member form submission.
	 */
	public static function handle_invite_member() {
		check_admin_referer( self::INVITE_NONCE_ACTION );
		[ $subscription_id, $redirect_url ] = self::get_subscription_context();
		self::verify_permission( $subscription_id, $redirect_url, 'invites' );

		$email  = filter_input( INPUT_POST, 'newspack-group-subscription-invite-email', FILTER_SANITIZE_EMAIL ) ?? '';
		$invite = Group_Subscription_Invite::generate_invite( $subscription_id, $email );

		self::redirect(
			$invite,
			$redirect_url,
			'invites',
			sprintf(
				// translators: %s: The invited email address.
				__( '%s has been invited to become a member of this group subscription.', 'newspack-plugin' ),
				$email
			)
		);
	}

	/**
	 * Handle the cancel invite form submission.
	 */
	public static function handle_cancel_invite() {
		check_admin_referer( self::CANCEL_INVITE_NONCE_ACTION );
		[ $subscription_id, $redirect_url ] = self::get_subscription_context();
		self::verify_permission( $subscription_id, $redirect_url, 'invites' );

		$email  = filter_input( INPUT_POST, 'email', FILTER_SANITIZE_EMAIL ) ?? '';
		$result = Group_Subscription_Invite::cancel_invite( $subscription_id, $email );

		self::redirect(
			$result,
			$redirect_url,
			'invites',
			sprintf(
				// translators: %s: The cancelled invitation's email address.
				__( 'The invitation for %s has been cancelled.', 'newspack-plugin' ),
				$email
			)
		);
	}

	/**
	 * Inject group subscriptions the current user is a member of into the subscriptions list.
	 *
	 * Only runs on My Account pages to avoid side effects (e.g. trial limit checks)
	 * in non-account contexts.
	 *
	 * @param array $subscriptions Existing subscriptions keyed by subscription ID.
	 * @param int   $user_id       The user ID.
	 *
	 * @return array
	 */
	public static function inject_member_group_subscriptions( $subscriptions, $user_id ) {
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			return $subscriptions;
		}
		// Don't add Group Subscription features to My Account when Woo Memberships
		// is active. TODO: Remove this once Access Control is fully released.
		// Mirrors the suppression that used to live in Group_Subscription::is_group_subscription(),
		// preserved here at the UI layer now that data-layer callers always see the canonical state.
		if ( Memberships::is_active() ) {
			return $subscriptions;
		}
		$existing_ids        = array_keys( $subscriptions );
		$group_subscriptions = Group_Subscription::get_group_subscriptions_for_user( $user_id );
		foreach ( $group_subscriptions as $group_subscription ) {
			if ( ! ( $group_subscription instanceof \WC_Subscription ) ) {
				continue;
			}
			if ( $group_subscription->has_status( 'trash' ) ) {
				continue;
			}
			if ( in_array( $group_subscription->get_id(), $existing_ids, true ) ) {
				continue;
			}
			$subscriptions[ $group_subscription->get_id() ] = $group_subscription;
		}
		return $subscriptions;
	}

	/**
	 * Grant the `view_order` capability to group subscription members on My Account pages.
	 *
	 * WCS checks current_user_can( 'view_order', $subscription->get_id() ) before rendering
	 * the view-subscription template. WC maps view_order → manage_woocommerce for non-owners.
	 * We override this to 'read' (a primitive cap all logged-in users have) for group members.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     The meta capability being checked.
	 * @param int      $user_id The user ID.
	 * @param array    $args    Additional arguments; $args[0] is the post/order ID.
	 *
	 * @return string[]
	 */
	public static function grant_group_member_view_order_cap( $caps, $cap, $user_id, $args ) {
		if ( 'view_order' !== $cap || ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			return $caps;
		}
		$order_id     = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $order_id );
		if ( ! $subscription || $subscription->has_status( 'trash' ) ) {
			return $caps;
		}
		if ( Group_Subscription::user_is_member( $user_id, $subscription ) ) {
			return [ 'read' ];
		}
		return $caps;
	}

	/**
	 * Handle the remove member form submission.
	 */
	public static function handle_remove_member() {
		check_admin_referer( self::REMOVE_MEMBER_NONCE_ACTION );
		[ $subscription_id, $redirect_url ] = self::get_subscription_context();
		self::verify_permission( $subscription_id, $redirect_url, 'members' );

		$member_id   = filter_input( INPUT_POST, 'member_id', FILTER_VALIDATE_INT ) ?? 0;
		$member_data = get_userdata( $member_id );
		$result      = Group_Subscription::update_members( $subscription_id, [], [ $member_id ] );

		self::redirect(
			$result,
			$redirect_url,
			'members',
			sprintf(
				// translators: %s: The removed member's email address.
				__( '%s has been removed from this group subscription.', 'newspack-plugin' ),
				$member_data ? $member_data->user_email : $member_id
			)
		);
	}
}
Group_Subscription_MyAccount::init();
