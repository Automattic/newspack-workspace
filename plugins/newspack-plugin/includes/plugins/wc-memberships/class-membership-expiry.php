<?php
/**
 * Hanlde WooCommerce Memberships expiry.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\WooCommerce_Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Membership_Expiry {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// Using just this first filter is not enough. Even if the membership cancellation via linked subscription
		// is prevented, the expiry code is also executed.
		add_filter( 'wc_memberships_cancel_subscription_linked_membership', [ __CLASS__, 'prevent_membership_expiration' ], 10, 2 );
		add_filter( 'wc_memberships_expire_user_membership', [ __CLASS__, 'prevent_membership_expiration' ], 10, 2 );
	}

	/**
	 * Prevent membership expiration if there are other active subscriptions.
	 *
	 * @param bool                            $cancel_membership Whether to cancel the membership when the subscription is cancelled (default true).
	 * @param \WC_Memberships_User_Membership $user_membership   The membership. Only subscription-tied memberships (\WC_Memberships_Integration_Subscriptions_User_Membership) expose get_subscription_id()/set_subscription_id().
	 */
	public static function prevent_membership_expiration( $cancel_membership, $user_membership ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return $cancel_membership;
		}
		// `wc_memberships_expire_user_membership` fires for every membership, but only the
		// Subscriptions integration class exposes get_subscription_id()/set_subscription_id().
		if ( ! method_exists( $user_membership, 'get_subscription_id' ) ) {
			return $cancel_membership;
		}
		$membership_product_id = $user_membership->get_product_id();
		$cancelling_subscription_id = $user_membership->get_subscription_id();
		$active_subscription_ids = WooCommerce_Connection::get_active_subscriptions_for_user( $user_membership->get_user_id() );
		foreach ( $active_subscription_ids as $subscription_id ) {
			// Skip the subscription that is being cancelled — it may still appear as "active"
			// (e.g. in `pending-cancel` status) when this filter runs.
			if ( $subscription_id == $cancelling_subscription_id ) {
				continue;
			}
			$subscription = \wcs_get_subscription( $subscription_id );
			foreach ( $subscription->get_items() as $item ) {
				if ( $item->get_product()->get_id() == $membership_product_id ) {
					$user_membership->set_subscription_id( $subscription_id );
					$parent_order_ids = \wcs_get_subscription( $subscription_id )->get_related_orders( 'ids', [ 'parent' ] );
					if ( ! empty( $parent_order_ids ) ) {
						$first_parent_order_id = reset( $parent_order_ids );
						$user_membership->set_order_id( $first_parent_order_id );
					}
					$user_membership->update_status( 'active' );
					$user_membership->add_note(
						sprintf(
							/* translators: %s: Subscription ID */
							__( 'Another active subscription for this product was found (Subscription ID: %s). Expiry prevented.', 'newspack-plugin' ),
							$subscription_id
						)
					);
					$cancel_membership = false;
					break;
				}
			}
		}

		return $cancel_membership;
	}
}
Membership_Expiry::init();
