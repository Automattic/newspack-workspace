<?php
/**
 * Newspack Group Subscriptions.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Group_Subscription {
	/**
	 * User meta key for group subscription associations.
	 */
	const GROUP_SUBSCRIPTION_USER_META_KEY = '_newspack_group_subscription';

	/**
	 * Per-request cache of [sub_id => decoded_name] maps, keyed by user_id + product filter.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $names_cache = [];

	/**
	 * Reset the per-request names cache.
	 *
	 * Tests, CLI workers, and invalidation hooks call this to bust the static
	 * memoization in `get_group_names_for_user()` / `get_group_ids_for_user()`.
	 * No-op if nothing is cached.
	 */
	public static function reset_cache() {
		self::$names_cache = [];
	}

	/**
	 * Register cache invalidation hooks. Called once at plugin load.
	 */
	public static function init() {
		// Subscription status changes (WCS hook fires for any active <-> non-active transition).
		\add_action( 'woocommerce_subscription_status_updated', [ __CLASS__, 'reset_cache' ], 10, 0 );
		// Group member meta add / remove.
		\add_action( 'added_user_meta', [ __CLASS__, 'maybe_reset_cache_on_user_meta' ], 10, 3 );
		\add_action( 'updated_user_meta', [ __CLASS__, 'maybe_reset_cache_on_user_meta' ], 10, 3 );
		\add_action( 'deleted_user_meta', [ __CLASS__, 'maybe_reset_cache_on_user_meta' ], 10, 3 );
	}

	/**
	 * Reset the names cache only when a user-meta change touches our group key.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s).
	 * @param int       $object_id Object ID.
	 * @param string    $meta_key  Meta key.
	 */
	public static function maybe_reset_cache_on_user_meta( $meta_ids, $object_id, $meta_key ) {
		if ( self::GROUP_SUBSCRIPTION_USER_META_KEY === $meta_key ) {
			self::reset_cache();
		}
	}

	/**
	 * Check if a subscription is a group subscription.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return bool Whether the subscription is a group subscription.
	 */
	public static function is_group_subscription( $subscription ) {
		// Don't show Group Subscription features in My Account if Woo Memberships is active. TODO: Remove this once Access Control is fully released.
		if ( Memberships::is_active() && function_exists( 'is_account_page' ) && is_account_page() ) {
			return false;
		}
		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
		return $settings['enabled'];
	}

	/**
	 * Get the managers of a group subscription.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int[] The group manager user IDs.
	 */
	public static function get_managers( $subscription ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );

		/**
		 * Filter the managers of a group subscription.
		 * Currently this is only the subscription owner.
		 *
		 * @param int[] $member_ids The group manager user IDs.
		 * @param WC_Subscription $subscription The subscription object.
		 */
		return apply_filters( 'newspack_group_subscription_managers', [ $subscription ? $subscription->get_user_id() : 0 ], $subscription );
	}

	/**
	 * Get the members of a group subscription.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int[] Array of user IDs for the group subscription members.
	 */
	public static function get_members( $subscription ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return [];
		}
		$subscription_id = $subscription->get_id();
		$members         = array_map(
			function( $user ) {
				return $user->ID;
			},
			\get_users(
				[
					'fields'     => [ 'ID' ],
					'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'   => self::GROUP_SUBSCRIPTION_USER_META_KEY,
							'value' => $subscription_id,
						],
					],
				]
			)
		);

		/**
		 * Filter the members of a group subscription.
		 *
		 * @param int[] $members Array of user IDs for group subscription members.
		 * @param \WC_Subscription $subscription The subscription object.
		 */
		return apply_filters( 'newspack_group_subscription_members', $members, $subscription );
	}

	/**
	 * Update the member IDs for a group subscription.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param int[]                $members_to_add Group member user IDs to add the subscription.
	 * @param int[]                $members_to_remove Group member user IDs to remove from the subscription.
	 *
	 * @return array|\WP_Error Added/removed results.
	 */
	public static function update_members( $subscription, $members_to_add, $members_to_remove = [] ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return new \WP_Error( 'newspack_group_subscription_update_members', __( 'Subscription not found.', 'newspack-plugin' ) );
		}
		$subscription_settings = Group_Subscription_Settings::get_subscription_settings( $subscription );

		// If the subscription is not enabled, enable it.
		if ( ! $subscription_settings['enabled'] ) {
			Group_Subscription_Settings::update_subscription_settings( $subscription, [ 'enabled' => true ] );
		}
		$members_to_add    = array_values( array_unique( array_map( 'absint', (array) $members_to_add ) ) );
		$members_to_remove = array_values( array_unique( array_map( 'absint', (array) $members_to_remove ) ) );
		$members_added     = [];
		$members_removed   = [];

		// Remove members.
		foreach ( $members_to_remove as $member_id ) {
			if ( ! Reader_Activation::is_user_reader( $member_id ) ) {
				continue;
			}
			if ( \delete_user_meta( $member_id, self::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() ) ) {
				$members_removed[ $member_id ] = [
					'email' => \get_userdata( $member_id )->user_email,
					'url'   => \get_edit_user_link( $member_id ),
				];
			}
		}

		$existing_members = self::get_members( $subscription );
		if ( $subscription_settings['limit'] > 0 && count( $existing_members ) + count( $members_to_add ) > $subscription_settings['limit'] ) {
			return new \WP_Error( 'newspack_group_subscription_update_members', __( 'Member limit reached. Please remove some members or increase the limit.', 'newspack-plugin' ) );
		}

		// Add new members.
		foreach ( $members_to_add as $member_id ) {
			if ( ! Reader_Activation::is_user_reader( $member_id ) ) {
				continue;
			}

			// Avoid adding duplicate meta entries.
			$existing_group_subscription_ids = self::get_group_subscriptions_for_user( $member_id, true );
			if ( in_array( $subscription->get_id(), $existing_group_subscription_ids, true ) ) {
				continue;
			}
			if ( \add_user_meta( $member_id, self::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() ) ) {
				$members_added[ $member_id ] = [
					'email' => \get_userdata( $member_id )->user_email,
					'url'   => \get_edit_user_link( $member_id ),
				];
			}
		}
		return [
			'members_added'   => $members_added,
			'members_removed' => $members_removed,
		];
	}

	/**
	 * Check if a user is a member (not manager) of a group subscription.
	 *
	 * @param int                  $user_id The user ID.
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return bool|null Whether the user is a member of the group subscription, or null if not a group subscription.
	 */
	public static function user_is_member( $user_id, $subscription ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! self::is_group_subscription( $subscription ) ) {
			return null;
		}
		$is_member = in_array( $subscription->get_id(), self::get_group_subscriptions_for_user( $user_id, true ), true );

		/**
		 * Filter whether a user is a member (not manager) of a group subscription.
		 *
		 * @param bool $is_member Whether the user is a member of the group subscription.
		 * @param int $user_id The user ID.
		 * @param \WC_Subscription|int $subscription The subscription object or ID.
		 */
		return apply_filters( 'newspack_group_subscription_user_is_member', $is_member, $user_id, $subscription );
	}

	/**
	 * Check if a user is a manager of a group subscription.
	 *
	 * @param int                  $user_id The user ID.
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return bool|null Whether the user is a manager of the group subscription, or null if not a group subscription.
	 */
	public static function user_is_manager( $user_id, $subscription ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! self::is_group_subscription( $subscription ) ) {
			return null;
		}
		$is_manager = in_array( $user_id, self::get_managers( $subscription ), true );

		/**
		 * Filter whether a user is a manager of a group subscription.
		 *
		 * @param bool $is_manager Whether the user is a manager of the group subscription.
		 * @param int $user_id The user ID.
		 * @param \WC_Subscription|int $subscription The subscription object or ID.
		 */
		return apply_filters( 'newspack_group_subscription_user_is_manager', $is_manager, $user_id, $subscription );
	}

	/**
	 * Get the group subscriptions a user is a member of.
	 * Group membership is represented as a repeatable user meta key with the subscription IDs the value.
	 *
	 * @param int  $user_id The user ID.
	 * @param bool $ids_only If true, return only the subscription IDs instead of the subscription objects.
	 *
	 * @return \WC_Subscription[]|int[] The group subscriptions or subscription IDs the user is a member of.
	 */
	public static function get_group_subscriptions_for_user( $user_id, $ids_only = false ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return [];
		}
		if ( ! Reader_Activation::is_user_reader( \get_user_by( 'id', $user_id ) ) ) {
			return [];
		}
		$subscription_ids = array_map( 'absint', \get_user_meta( $user_id, self::GROUP_SUBSCRIPTION_USER_META_KEY, false ) );
		$subscriptions    = [];
		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = \wcs_get_subscription( $subscription_id );
			if ( ! $subscription ) {
				continue;
			}
			// Check the group-enabled meta directly rather than calling self::is_group_subscription(),
			// which has a context-dependent side effect on the My Account page when WC Memberships
			// is active. Data-layer callers must always see the canonical state.
			$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
			if ( empty( $settings['enabled'] ) ) {
				continue;
			}
			$subscriptions[] = $ids_only ? $subscription_id : $subscription;
		}

		/**
		 * Filter the group subscriptions a user is a member of.
		 *
		 * @param \WC_Subscription[]|int[] $subscriptions The group subscriptions or subscription IDs the user is a member of.
		 * @param int $user_id The user ID.
		 */
		return apply_filters( 'newspack_group_subscriptions_for_user', $subscriptions, $user_id );
	}

	/**
	 * Get the sorted, deduplicated names of active group subscriptions a user owns or is a member of.
	 *
	 * Memoized per request via {@see self::get_settings_map_for_user()} — see that helper for
	 * cache scope, invalidation hooks, and `reset_cache()`.
	 *
	 * @param int        $user_id        User ID.
	 * @param array|null $product_filter Optional list of product IDs. If non-empty, only subscriptions
	 *                                   containing at least one of these products contribute a name.
	 *                                   Pass null or an empty array to include every active group sub.
	 *
	 * @return string[] Sorted, deduplicated group names.
	 */
	public static function get_group_names_for_user( $user_id, $product_filter = null ) {
		$map   = self::get_settings_map_for_user( $user_id, $product_filter );
		$names = array_values( array_unique( array_values( $map ) ) );
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		return $names;
	}

	/**
	 * Get the IDs of active group subscriptions a user owns or is a member of.
	 *
	 * Returns subscription post IDs (not product IDs). Shares the per-request cache with
	 * {@see self::get_group_names_for_user()}, so calling both for the same user is cheap.
	 * Suitable for downstream consumers that need an anonymous identifier (e.g., GA4) and
	 * want to avoid serializing publisher-facing group names.
	 *
	 * @param int        $user_id        User ID.
	 * @param array|null $product_filter Optional list of product IDs. Same semantics as
	 *                                   {@see self::get_group_names_for_user()}.
	 *
	 * @return int[] Sorted subscription IDs.
	 */
	public static function get_group_ids_for_user( $user_id, $product_filter = null ) {
		$ids = array_keys( self::get_settings_map_for_user( $user_id, $product_filter ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Build the [sub_id => decoded_name] map for the user, memoized per request.
	 *
	 * Cache scope: function-local static, keyed by user ID + normalized product filter.
	 * The cache lives for the duration of the PHP request. Hooks registered in {@see self::init()}
	 * call {@see self::reset_cache()} when subscriptions or group-member meta change so a
	 * long-running CLI worker doesn't serve stale data across jobs. Tests can call
	 * `reset_cache()` directly between cases.
	 *
	 * Gifting note: `WooCommerce_Connection::get_active_subscriptions_for_user()` excludes
	 * gifted subscriptions where the user isn't the recipient. The member branch
	 * (`get_group_subscriptions_for_user()`) doesn't apply that filter — so a gifted group
	 * subscription could be present via membership even when ownership would exclude it.
	 * This mirrors the existing asymmetry in `Access_Rules::has_active_subscription()`.
	 *
	 * @param int        $user_id        User ID.
	 * @param array|null $product_filter Optional list of product IDs (same semantics as the public APIs).
	 *
	 * @return array<int,string> Map of subscription post ID to decoded group name.
	 */
	private static function get_settings_map_for_user( $user_id, $product_filter = null ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! function_exists( 'wcs_get_subscription' ) ) {
			return [];
		}
		if ( ! Reader_Activation::is_user_reader( \get_user_by( 'id', $user_id ) ) ) {
			return [];
		}

		// Normalize the filter so [], null, and unsorted/duplicate inputs share a cache key.
		$normalized_filter = is_array( $product_filter ) && ! empty( $product_filter )
			? array_values( array_unique( array_map( 'absint', $product_filter ) ) )
			: null;
		if ( null !== $normalized_filter ) {
			sort( $normalized_filter, SORT_NUMERIC );
		}
		$cache_key = $user_id . '|' . ( null === $normalized_filter ? '' : implode( ',', $normalized_filter ) );
		if ( isset( self::$names_cache[ $cache_key ] ) ) {
			return self::$names_cache[ $cache_key ];
		}

		$candidates = [];

		// Owned active subscriptions, already filtered by status (and product, if provided) and gifting.
		$owned_ids = WooCommerce_Connection::get_active_subscriptions_for_user(
			$user_id,
			null === $normalized_filter ? [] : $normalized_filter
		);
		foreach ( $owned_ids as $sub_id ) {
			$sub = \wcs_get_subscription( $sub_id );
			if ( $sub ) {
				$candidates[ $sub->get_id() ] = $sub;
			}
		}

		// Member subscriptions (via user meta). Apply status + product filters manually.
		foreach ( self::get_group_subscriptions_for_user( $user_id ) as $sub ) {
			$sub_id = $sub->get_id();
			if ( isset( $candidates[ $sub_id ] ) ) {
				continue;
			}
			if ( ! $sub->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
				continue;
			}
			if ( null !== $normalized_filter ) {
				$matches = false;
				foreach ( $normalized_filter as $product_id ) {
					if ( $sub->has_product( $product_id ) ) {
						$matches = true;
						break;
					}
				}
				if ( ! $matches ) {
					continue;
				}
			}
			$candidates[ $sub_id ] = $sub;
		}

		$map = [];
		foreach ( $candidates as $sub_id => $sub ) {
			// Read settings once: it's the authoritative source for `enabled` and `name`,
			// and is_group_subscription() would call this internally anyway.
			$settings = Group_Subscription_Settings::get_subscription_settings( $sub );
			if ( empty( $settings['enabled'] ) ) {
				continue;
			}
			$map[ $sub_id ] = html_entity_decode( (string) $settings['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		self::$names_cache[ $cache_key ] = $map;
		return $map;
	}
}
Group_Subscription::init();
