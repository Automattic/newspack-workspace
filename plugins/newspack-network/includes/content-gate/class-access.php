<?php
/**
 * Newspack Network Content Gate Access integration.
 *
 * Hooks into newspack-plugin's access rules to grant access when a reader holds,
 * on another network site, something for a product with a matching Network ID:
 * a subscription they own, a seat on someone else's group subscription, or a
 * paid one-time order.
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
	 * Subscription statuses that grant access.
	 */
	const ACTIVE_STATUSES = [ 'active', 'pending-cancel' ];

	/**
	 * Initializer.
	 */
	public static function init() {
		add_filter( 'newspack_access_rules_has_active_subscription', [ __CLASS__, 'check_network_subscriptions' ], 10, 4 );
		add_filter( 'newspack_access_rules_has_one_time_purchase', [ __CLASS__, 'check_network_one_time_purchases' ], 10, 3 );
	}

	/**
	 * Check if the user has an active subscription on another network site
	 * for a product with a matching Network ID.
	 *
	 * Seats on another site's group subscription count too, unless the check is
	 * strict, mirroring how newspack-plugin counts local group seats.
	 *
	 * @param bool  $has_subscription Whether the user already has an active subscription (from local checks).
	 * @param int   $user_id          User ID.
	 * @param array $product_ids      Required product IDs (local).
	 * @param bool  $strict           Only count subscriptions the user owns.
	 * @return bool
	 */
	public static function check_network_subscriptions( $has_subscription, $user_id, $product_ids, $strict = false ) {
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

		// Subscription events keep these records current, so a match needs no pull.
		if ( self::find_active_network_subscription( $user_id, $network_ids ) ) {
			return true;
		}

		Reader_Access_Sync::maybe_sync( $user_id );

		if ( self::find_active_network_subscription( $user_id, $network_ids ) ) {
			return true;
		}

		if ( ! $strict && self::has_active_group_seat( $user_id, $network_ids ) ) {
			return true;
		}

		return $has_subscription;
	}

	/**
	 * Check if the user has a paid one-time order on another network site for a
	 * product with a matching Network ID, placed within the rule's duration.
	 *
	 * The duration counts from the order's creation date, as the local rule does.
	 *
	 * @param bool  $has_purchase Whether the user already has a qualifying local purchase.
	 * @param int   $user_id      User ID.
	 * @param array $value        Sanitized rule value (product_ids, duration_value, duration_unit).
	 * @return bool
	 */
	public static function check_network_one_time_purchases( $has_purchase, $user_id, $value ) {
		if ( $has_purchase ) {
			return true;
		}

		$product_ids = is_array( $value ) ? (array) ( $value['product_ids'] ?? [] ) : [];
		if ( empty( $product_ids ) ) {
			return $has_purchase;
		}

		$cutoff = self::get_one_time_purchase_cutoff( $value );
		if ( false === $cutoff ) {
			return $has_purchase;
		}

		$network_ids = self::get_network_ids_for_products( $product_ids );
		if ( empty( $network_ids ) ) {
			return $has_purchase;
		}

		Reader_Access_Sync::maybe_sync( $user_id );

		foreach ( Reader_Access_Sync::get_orders( $user_id ) as $order ) {
			if ( null !== $cutoff && (int) ( $order['date_created'] ?? 0 ) <= $cutoff ) {
				continue;
			}
			if ( array_intersect( $network_ids, (array) ( $order['network_ids'] ?? [] ) ) ) {
				return true;
			}
		}

		return $has_purchase;
	}

	/**
	 * The point in time a one-time order must postdate to satisfy a rule.
	 *
	 * Mirrors newspack-plugin's Access_Rules::get_one_time_purchase_cutoff(), which is
	 * private, so a network order is held to exactly the local rule's window. An
	 * unknown unit fails closed, so a unit added there grants nothing here until it
	 * is added here too.
	 *
	 * @param array $value Rule value.
	 * @return int|null|false Unix timestamp; null for lifetime access; false for a misconfigured duration.
	 */
	private static function get_one_time_purchase_cutoff( $value ) {
		$unit           = $value['duration_unit'] ?? '';
		$duration_value = absint( $value['duration_value'] ?? 0 );
		if ( 'forever' === $unit ) {
			return null;
		}
		if ( in_array( $unit, [ 'days', 'months' ], true ) && $duration_value > 0 ) {
			return strtotime( sprintf( '-%d %s', $duration_value, $unit ) );
		}
		return false;
	}

	/**
	 * Whether the user holds a seat on another site's active group subscription
	 * for a product with one of the given Network IDs.
	 *
	 * @param int      $user_id     User ID.
	 * @param string[] $network_ids Network IDs to match.
	 * @return bool
	 */
	private static function has_active_group_seat( $user_id, $network_ids ) {
		foreach ( Reader_Access_Sync::get_group_seats( $user_id ) as $seat ) {
			if ( ! in_array( $seat['status'] ?? '', self::ACTIVE_STATUSES, true ) ) {
				continue;
			}
			if ( array_intersect( $network_ids, (array) ( $seat['network_ids'] ?? [] ) ) ) {
				return true;
			}
		}
		return false;
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
		return self::find_active_network_subscription( $user_id, [ $network_id ] );
	}

	/**
	 * Find an active subscription the user owns on another network site for a
	 * product with one of the given Network IDs.
	 *
	 * @param int      $user_id     The user ID.
	 * @param string[] $network_ids Network IDs to match.
	 * @return array|false Array with 'site' and 'subscription' keys if found, false otherwise.
	 */
	private static function find_active_network_subscription( $user_id, $network_ids ) {
		$network_subscriptions = self::get_user_network_active_subscriptions( $user_id );
		if ( empty( $network_subscriptions ) ) {
			return false;
		}

		// Get synced product data from all network sites.
		$network_products = get_option( Product_Updated::OPTION_NAME, [] );

		foreach ( $network_subscriptions as $site => $subscriptions ) {
			$site_products = $network_products[ $site ] ?? [];
			foreach ( $subscriptions as $subscription ) {
				foreach ( (array) ( $subscription['products'] ?? [] ) as $product ) {
					$remote_network_id = self::get_remote_product_network_id( (array) $product, $site_products );
					if ( ! empty( $remote_network_id ) && in_array( $remote_network_id, $network_ids, true ) ) {
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
	 * The Network ID of a product on another site's subscription.
	 *
	 * The product data synced from that site comes first, since product events keep
	 * it current. A subscription pulled from its site also names each product's
	 * Network ID itself, which covers products whose sync event never arrived here.
	 *
	 * @param array $product       Product entry from the subscription record.
	 * @param array $site_products Synced product data for the subscription's site.
	 * @return string
	 */
	private static function get_remote_product_network_id( $product, $site_products ) {
		// Cast to string to handle int/string key mismatch from JSON round-tripping.
		$product_id = (string) ( $product['id'] ?? '' );
		$synced     = (string) ( $site_products[ $product_id ]['network_id'] ?? '' );
		if ( '' !== $synced ) {
			return $synced;
		}
		return (string) ( $product['network_id'] ?? '' );
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
					return in_array( $sub['status'], self::ACTIVE_STATUSES, true );
				}
			);
			if ( empty( $returned_subs[ $site ] ) ) {
				unset( $returned_subs[ $site ] );
			}
		}

		return $returned_subs;
	}
}
