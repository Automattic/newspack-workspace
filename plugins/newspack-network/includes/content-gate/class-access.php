<?php
/**
 * Newspack Network Content Gate Access integration.
 *
 * Hooks into newspack-plugin's access rules to grant access when a reader holds,
 * on another network site, something for a product with a matching Network ID: a
 * subscription they own, a seat on someone else's group subscription, or a paid
 * one-time order.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Gate;

use Newspack_Network\Incoming_Events\Access_Grant_Changed;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Incoming_Events\Product_Updated;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Class to handle network-aware content gate access.
 */
class Access {

	/**
	 * Subscription statuses that grant access, for owned subscriptions and group seats alike.
	 */
	const ACTIVE_STATUSES = [ 'active', 'pending-cancel' ];

	/**
	 * Order statuses that count as paid when WooCommerce isn't loaded to say.
	 */
	const PAID_STATUSES = [ 'processing', 'completed' ];

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
	 * strict: newspack-plugin's access attribution runs the strict check to tell an
	 * owner from a group member, so a seat must fail it.
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

		// Subscriptions the user owns on other network sites.
		foreach ( self::get_user_network_active_subscriptions( $user_id ) as $site => $subscriptions ) {
			foreach ( $subscriptions as $subscription ) {
				if ( self::products_match( $site, $subscription['products'] ?? [], $network_ids ) ) {
					return true;
				}
			}
		}

		// Seats on group subscriptions owned by someone else.
		if ( ! $strict ) {
			foreach ( self::get_user_grants( $user_id, 'group' ) as $site => $seats ) {
				foreach ( $seats as $seat ) {
					if ( in_array( $seat['status'] ?? '', self::ACTIVE_STATUSES, true ) && self::products_match( $site, $seat['products'] ?? [], $network_ids ) ) {
						return true;
					}
				}
			}
		}

		return $has_subscription;
	}

	/**
	 * Check if the user has a paid one-time order on another network site for a
	 * product with a matching Network ID, placed within the rule's duration.
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
		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : self::PAID_STATUSES;

		foreach ( self::get_user_grants( $user_id, 'purchase' ) as $site => $purchases ) {
			foreach ( $purchases as $purchase ) {
				if ( ! in_array( $purchase['status'] ?? '', $paid_statuses, true ) ) {
					continue;
				}
				if ( null !== $cutoff && (int) ( $purchase['purchased_at'] ?? 0 ) <= $cutoff ) {
					continue;
				}
				if ( self::products_match( $site, $purchase['products'] ?? [], $network_ids ) ) {
					return true;
				}
			}
		}

		return $has_purchase;
	}

	/**
	 * The point in time a one-time order must postdate to satisfy a rule.
	 *
	 * Mirrors newspack-plugin's Access_Rules::get_one_time_purchase_cutoff(), which is
	 * private, so an order on another site is held to exactly the local rule's window.
	 * An unknown unit fails closed, so a unit added there grants nothing here until it
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
	 * The reader's grants of one type, keyed by site.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    'group' or 'purchase'.
	 * @return array Site URL => records.
	 */
	private static function get_user_grants( $user_id, $type ) {
		$by_site = [];
		foreach ( Access_Grant_Changed::get_user_grants( $user_id ) as $site => $grants ) {
			foreach ( (array) $grants as $grant ) {
				if ( is_array( $grant ) && ( $grant['type'] ?? '' ) === $type ) {
					$by_site[ $site ][] = $grant;
				}
			}
		}
		return $by_site;
	}

	/**
	 * Whether any of a site's products, as another site synced them, carries one of the Network IDs.
	 *
	 * @param string   $site        The site the products belong to.
	 * @param array    $products    Products from the record, each with an id.
	 * @param string[] $network_ids Network IDs to match.
	 * @return bool
	 */
	private static function products_match( $site, $products, $network_ids ) {
		$site_products = get_option( Product_Updated::OPTION_NAME, [] )[ $site ] ?? [];
		foreach ( (array) $products as $product ) {
			// Cast to string to handle int/string key mismatch from JSON round-tripping.
			$product_id        = (string) ( ( (array) $product )['id'] ?? '' );
			$remote_network_id = $site_products[ $product_id ]['network_id'] ?? '';
			if ( ! empty( $remote_network_id ) && in_array( $remote_network_id, $network_ids, true ) ) {
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
		$network_subscriptions = self::get_user_network_active_subscriptions( $user_id );
		if ( empty( $network_subscriptions ) ) {
			return false;
		}

		foreach ( $network_subscriptions as $site => $subscriptions ) {
			foreach ( $subscriptions as $subscription ) {
				if ( self::products_match( $site, $subscription['products'] ?? [], [ $network_id ] ) ) {
					return [
						'site'         => $site,
						'subscription' => $subscription,
					];
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
