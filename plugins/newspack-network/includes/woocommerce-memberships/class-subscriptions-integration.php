<?php
/**
 * Newspack Network WooCommerce Subscriptions integration for WooCommerce Memberships.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce_Memberships;

use Newspack_Network\Incoming_Events\Membership_Plan_Updated;
use Newspack_Network\Incoming_Events\Subscription_Changed;

/**
 * Handles tweaks for WooCommerce Memberships WooCommerce Subscriptions integration.
 *
 * This class handles a very specific case when a local User Membership is linked to a Subscription in this site, and then the Subscription is cancelled,
 * triggering a cancellation to that membership.
 *
 * This class will short circuit the cancellation process and check if the user has an active subscription in another site that is linked to a Membership plan with the same Network ID.
 */
class Subscriptions_Integration {

	/**
	 * Runs the initialization.
	 *
	 * @return void
	 */
	public static function init() {
		// You'd think this first filter is enough, but it's not. Even if the membership cancellation via linked subscription
		// is prevented, the expiry code is also executed.
		add_filter( 'wc_memberships_cancel_subscription_linked_membership', [ __CLASS__, 'prevent_membership_expiration' ], 10, 2 );
		add_filter( 'wc_memberships_expire_user_membership', [ __CLASS__, 'prevent_membership_expiration' ], 10, 2 );
	}

	/**
	 * Prevent membership expiration if another network site has a synced membership active.
	 *
	 * @param bool                                                      $cancel_or_expire whether to cancel/expire the membership when the subscription is cancelled (default true).
	 * @param \WC_Memberships_Integration_Subscriptions_User_Membership $user_membership the subscription-tied membership.
	 */
	public static function prevent_membership_expiration( $cancel_or_expire, $user_membership ) {
		$plan_network_id = get_post_meta( $user_membership->get_plan()->get_id(), Admin::NETWORK_ID_META_KEY, true );

		if ( ! $plan_network_id ) {
			return $cancel_or_expire;
		}

		$is_managed = get_post_meta( $user_membership->get_id(), Admin::NETWORK_MANAGED_META_KEY, true );

		if ( $is_managed ) {
			return false;
		}

		$user = $user_membership->get_user();
		if ( ! $user ) {
			return $cancel_or_expire;
		}

		$user_id = $user->ID;

		$user_network_subs = self::get_user_network_active_subscriptions( $user_id );

		$network_active_subscription = self::user_has_active_subscription_in_network( $user_id, $plan_network_id );

		if ( ! $network_active_subscription ) {
			return $cancel_or_expire;
		}

		$prevent_cancellation_note_meta_key = 'prevent_cancellation_note_added_timestamp';

		$last_note_added_timestamp = get_post_meta( $user_membership->get_id(), $prevent_cancellation_note_meta_key, true );

		if ( $last_note_added_timestamp && ( time() - (int) $last_note_added_timestamp ) < 60 ) {
			// Prevent adding the same note multiple times.
			return false;
		}

		update_post_meta( $user_membership->get_id(), $prevent_cancellation_note_meta_key, time() );

		$user_membership->add_note(
			sprintf(
				/* translators: %1$s is the site URL where the user has an active subscription. %2$d is that subscription ID. */
				__( 'Membership is not cancelled, because there is an active subscription in %1$s linked to a membership plan with the same network id. (Subscription #%2$d)', 'newspack-plugin' ),
				$network_active_subscription['site'],
				$network_active_subscription['subscription']['id']
			)
		);

		return false;
	}

	/**
	 * Checks if a user has an active subscription for a given Membership plan network id.
	 *
	 * It will check for active subscriptions in all network sites and then look at all Membership plans in all site, to see
	 * if any of the users' active subscriptions includes the plan with the given network id.
	 *
	 * If none a are found, it returns false. If it finds one, it will return an array with two keys:
	 * - 'site' with the site where the subscription was found.
	 * - 'subscription' with the subscription data. The subscription is an array with id, name, slug, status and products.
	 *
	 * @param int    $user_id The user ID to check.
	 * @param string $plan_network_id The "Network ID" of the Membership plan.
	 * @return bool|array False if no active subscription is found, or an array with the site and subscription if one is found.
	 */
	public static function user_has_active_subscription_in_network( $user_id, $plan_network_id ) {
		$subscriptions = self::get_user_network_active_subscriptions( $user_id );

		if ( empty( $subscriptions ) ) {
			return false;
		}


		foreach ( $subscriptions as $site => $subs ) {
			$subscription_with_plan = self::subscriptions_includes_plan( $site, $subs, $plan_network_id );
			if ( $subscription_with_plan ) {
				return [
					'site'         => $site,
					'subscription' => $subscription_with_plan,
				];
			}
		}

		return false;
	}

	/**
	 * Takes a list of user subscriptions and checks if any of them includes a plan with the given network id in a given site.
	 *
	 * @param string $site The site to check.
	 * @param array  $subscriptions The list of subscriptions to check.
	 * @param string $plan_network_id The "Network ID" of the Membership plan.
	 * @return bool|array False if no subscription is found, or an array representing the subscription if one is found.
	 */
	private static function subscriptions_includes_plan( $site, $subscriptions, $plan_network_id ) {
		$network_plans = self::get_network_membership_plans( $plan_network_id );

		if ( empty( $network_plans[ $site ] ) ) {
			return false;
		}

		foreach ( $subscriptions as $subscription ) {
			foreach ( $subscription['products'] as $product ) {
				foreach ( $network_plans[ $site ] as $plan ) {
					if ( array_key_exists( $product['id'], $plan['products'] ) ) {
						return $subscription;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Gets all Membership plans in all sites that have the given network id.
	 *
	 * @param string $plan_network_id The "Network ID" of the Membership plan.
	 * @return array An array with the site as key and an array of plans as value.
	 */
	private static function get_network_membership_plans( $plan_network_id ) {
		$all_plans = get_option( Membership_Plan_Updated::OPTION_NAME, [] );
		$returned_plans = [];
		foreach ( $all_plans as $site => $plans ) {
			$returned_plans[ $site ] = array_filter(
				$plans,
				function( $plan ) use ( $plan_network_id ) {
					return $plan['network_id'] === $plan_network_id;
				}
			);
			if ( empty( $returned_plans[ $site ] ) ) {
				unset( $returned_plans[ $site ] );
			}
		}

		return $returned_plans;
	}

	/**
	 * Gets all active subscriptions for a user in all network sites.
	 *
	 * @param int $user_id The user ID.
	 * @return array An array with the site as key and an array of subscriptions as value.
	 */
	private static function get_user_network_active_subscriptions( $user_id ) {
		$meta = get_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );
		if ( ! $meta ) {
			return [];
		}

		$returned_subs = [];

		foreach ( $meta as $site => $subscriptions ) {
			$returned_subs[ $site ] = array_filter(
				$subscriptions,
				function( $sub ) {
					return in_array( $sub['status'], [ 'active', 'pending-cancel' ], true );
				}
			);
			if ( empty( $returned_subs[ $site ] ) ) {
				unset( $returned_subs[ $site ] );
			}
		}

		return $returned_subs;
	}
}
