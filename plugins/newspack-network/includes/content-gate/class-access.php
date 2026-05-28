<?php
/**
 * Newspack Network Content Gate Access integration.
 *
 * Hooks into newspack-plugin's access rules to grant access
 * when a user has an active subscription on another network site
 * for a product with a matching Network ID.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Gate;

use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Incoming_Events\Product_Updated;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Class to handle network-aware content gate access.
 */
class Access {

	/**
	 * Initializer.
	 */
	public static function init() {
		add_filter( 'newspack_access_rules_has_active_subscription', [ __CLASS__, 'check_network_subscriptions' ], 10, 3 );
	}

	/**
	 * Check if the user has an active subscription on another network site
	 * for a product with a matching Network ID.
	 *
	 * @param bool  $has_subscription Whether the user already has an active subscription (from local checks).
	 * @param int   $user_id          User ID.
	 * @param array $product_ids      Required product IDs (local).
	 * @return bool
	 */
	public static function check_network_subscriptions( $has_subscription, $user_id, $product_ids ) {
		// If local check already passed, no need to check network.
		if ( $has_subscription ) {
			return true;
		}

		// If no products specified, we can't match by Network ID.
		if ( empty( $product_ids ) ) {
			return $has_subscription;
		}

		// Get Network IDs for the required local products.
		$network_ids = self::get_network_ids_for_products( $product_ids );
		if ( empty( $network_ids ) ) {
			return $has_subscription;
		}

		// Get user's network subscriptions.
		$network_subscriptions = self::get_user_network_active_subscriptions( $user_id );
		if ( empty( $network_subscriptions ) ) {
			return $has_subscription;
		}

		// Get synced product data from all network sites.
		$network_products = get_option( Product_Updated::OPTION_NAME, [] );

		// Check if any network subscription has a product with a matching Network ID.
		foreach ( $network_subscriptions as $site => $subscriptions ) {
			$site_products = $network_products[ $site ] ?? [];
			foreach ( $subscriptions as $subscription ) {
				foreach ( $subscription['products'] as $product ) {
					// Cast to string to handle int/string key mismatch from JSON round-tripping.
					$product_id = (string) $product['id'];
					// Look up this product's Network ID from synced data.
					$remote_network_id = $site_products[ $product_id ]['network_id'] ?? '';
					if ( ! empty( $remote_network_id ) && in_array( $remote_network_id, $network_ids, true ) ) {
						return true;
					}
				}
			}
		}

		return $has_subscription;
	}

	/**
	 * Get Network IDs for the given local product IDs.
	 *
	 * @param array $product_ids Local product IDs.
	 * @return array Array of Network IDs (non-empty values only).
	 */
	public static function get_network_ids_for_products( $product_ids ) {
		$network_ids = [];
		foreach ( $product_ids as $product_id ) {
			$network_id = Product_Admin::get_network_id( $product_id );
			if ( ! empty( $network_id ) ) {
				$network_ids[] = $network_id;
			}
		}
		return array_unique( $network_ids );
	}

	/**
	 * Check if a user has an active network subscription for a given Network ID.
	 *
	 * @param int    $user_id    The user ID.
	 * @param string $network_id The product Network ID to match.
	 * @return array|false Array with 'site' and 'subscription' keys if found, false otherwise.
	 */
	public static function user_has_active_network_subscription_for_network_id( $user_id, $network_id ) {
		$network_subscriptions = self::get_user_network_active_subscriptions( $user_id );
		if ( empty( $network_subscriptions ) ) {
			return false;
		}

		$network_products = get_option( Product_Updated::OPTION_NAME, [] );

		foreach ( $network_subscriptions as $site => $subscriptions ) {
			$site_products = $network_products[ $site ] ?? [];
			foreach ( $subscriptions as $subscription ) {
				foreach ( $subscription['products'] as $product ) {
					// Cast to string to handle int/string key mismatch from JSON round-tripping.
					$product_id = (string) $product['id'];
					$remote_network_id = $site_products[ $product_id ]['network_id'] ?? '';
					if ( ! empty( $remote_network_id ) && $remote_network_id === $network_id ) {
						return [
							'site'         => $site,
							'subscription' => $subscription,
						];
					}
				}
			}
		}

		return false;
	}

	/**
	 * Gets all active subscriptions for a user across all network sites.
	 *
	 * @param int $user_id The user ID.
	 * @return array An array with the site as key and an array of subscriptions as value.
	 */
	public static function get_user_network_active_subscriptions( $user_id ) {
		$meta = get_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );
		if ( ! $meta ) {
			return [];
		}

		$returned_subs = [];

		foreach ( $meta as $site => $subscriptions ) {
			$returned_subs[ $site ] = array_filter(
				$subscriptions,
				function ( $sub ) {
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
