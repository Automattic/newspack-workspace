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
	 * Per-membership join timestamp meta key prefix.
	 * Full key is `{$prefix}{$subscription_id}` storing a Unix timestamp.
	 */
	const GROUP_SUBSCRIPTION_JOINED_META_KEY_PREFIX = '_newspack_group_subscription_joined_';

	/**
	 * Per-manager user meta key. Repeatable, with the subscription ID as the
	 * value — mirroring the membership storage above. The owner is never
	 * stored: ownership implies management.
	 */
	const GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY = '_newspack_group_subscription_manager';

	/**
	 * Build the per-subscription joined-at user_meta key.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return string Meta key.
	 */
	public static function get_member_joined_meta_key( $subscription_id ) {
		return self::GROUP_SUBSCRIPTION_JOINED_META_KEY_PREFIX . absint( $subscription_id );
	}

	/**
	 * Get the Unix timestamp at which a user joined a group subscription.
	 *
	 * @param int                  $user_id      The user ID.
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int|null Unix timestamp, or null if no record exists.
	 */
	public static function get_member_joined_at( $user_id, $subscription ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription || ! $user_id ) {
			return null;
		}
		$stored = \get_user_meta( $user_id, self::get_member_joined_meta_key( $subscription->get_id() ), true );
		return $stored ? (int) $stored : null;
	}

	/**
	 * Per-request cache of [sub_id => decoded_name] maps, keyed by user_id + product filter.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $names_cache = [];

	/**
	 * Per-request cache of the subscriptions a user is a member of, keyed by `user_id|ids_only`.
	 *
	 * @var array<string,array>
	 */
	private static $member_subscriptions_cache = [];

	/**
	 * Per-request cache of the subscriptions a user manages, keyed by `user_id|ids_only`.
	 *
	 * @var array<string,array>
	 */
	private static $managed_subscriptions_cache = [];

	/**
	 * Per-request cache of a group's manager user IDs, keyed by subscription ID.
	 *
	 * A single group render calls get_managers() several times (member table, seat
	 * count, per-row role); each call otherwise runs a get_users() meta query. The
	 * cache is busted by the same user-meta hooks that reset the others.
	 *
	 * @var array<int,int[]>
	 */
	private static $managers_cache = [];

	/**
	 * Per-request cache of a group's member user IDs, keyed by subscription ID.
	 *
	 * @var array<int,int[]>
	 */
	private static $members_cache = [];

	/**
	 * Reset the per-request caches.
	 *
	 * Tests, CLI workers, and invalidation hooks call this to bust the static
	 * memoization in `get_group_names_for_user()` / `get_group_ids_for_user()`,
	 * `get_group_subscriptions_for_user()`, and `get_managed_subscriptions_for_user()`.
	 * No-op if nothing is cached.
	 */
	public static function reset_cache() {
		self::$names_cache                 = [];
		self::$member_subscriptions_cache  = [];
		self::$managed_subscriptions_cache = [];
		self::$managers_cache              = [];
		self::$members_cache               = [];
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
		if ( in_array( $meta_key, [ self::GROUP_SUBSCRIPTION_USER_META_KEY, self::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY ], true ) ) {
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
	 * Get the publisher-configurable container label.
	 *
	 * @param string $variant Either 'singular' or 'plural'. Unknown variants fall back to singular.
	 *
	 * @return string The override if the publisher has set a non-blank one, otherwise the translated default.
	 */
	public static function get_label( $variant = 'singular' ) {
		$variant    = 'plural' === $variant ? 'plural' : 'singular';
		$option_key = 'newspack_group_subscription_label_' . $variant;
		$override   = trim( (string) \get_option( $option_key, '' ) );
		if ( '' !== $override ) {
			return $override;
		}
		return 'plural' === $variant
			? __( 'Groups', 'newspack-plugin' )
			: __( 'Group', 'newspack-plugin' );
	}

	/**
	 * Get the lowercased group label for inline use in sentences.
	 *
	 * @param string $variant Either 'singular' or 'plural'.
	 *
	 * @return string The lowercased label.
	 */
	public static function get_label_lower( $variant = 'singular' ) {
		$label = self::get_label( $variant );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $label ) : strtolower( $label );
	}

	/**
	 * Get the managers of a group subscription.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int[] The group manager user IDs.
	 */
	public static function get_managers( $subscription ) {
		$subscription    = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		$subscription_id = $subscription ? $subscription->get_id() : 0;

		if ( isset( self::$managers_cache[ $subscription_id ] ) ) {
			$managers = self::$managers_cache[ $subscription_id ];
		} else {
			// The owner is always a manager: ownership implies management.
			$managers = [ $subscription ? $subscription->get_user_id() : 0 ];
			if ( $subscription ) {
				$stored = \get_users(
					[
						'fields'      => [ 'ID' ],
						'count_total' => false,
						'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							[
								'key'   => self::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY,
								'value' => $subscription->get_id(),
							],
						],
					]
				);
				foreach ( $stored as $user ) {
					$managers[] = (int) $user->ID;
				}
				$managers = array_values( array_unique( $managers ) );
			}
			self::$managers_cache[ $subscription_id ] = $managers;
		}

		/**
		 * Filter the managers of a group subscription.
		 *
		 * @param int[]             $managers     The group manager user IDs (owner plus any promoted managers).
		 * @param \WC_Subscription  $subscription The subscription object.
		 */
		return apply_filters( 'newspack_group_subscription_managers', $managers, $subscription );
	}

	/**
	 * Promote a group member to manager.
	 *
	 * Only an existing member qualifies, and the owner is never stored —
	 * ownership implies management. Storage mirrors membership: a repeatable
	 * user meta with the subscription ID as the value.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param int                  $user_id      The member user ID.
	 *
	 * @return true|\WP_Error True on success.
	 */
	public static function add_manager( $subscription, $user_id ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		$user_id      = absint( $user_id );
		if ( ! self::is_group_subscription( $subscription ) ) {
			return new \WP_Error( 'newspack_group_subscription_add_manager', __( 'Subscription not found.', 'newspack-plugin' ), [ 'status' => 404 ] );
		}
		if ( $user_id === (int) $subscription->get_user_id() ) {
			return new \WP_Error( 'newspack_group_subscription_add_manager', __( 'The owner already manages this subscription.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		// Read membership from the data layer rather than via user_is_member(), which
		// routes through is_group_subscription() and its My Account side effect. Same
		// reasoning as can_actor_remove_member(): a role decision must not depend on
		// the context it is made from.
		if ( ! in_array( $user_id, array_map( 'intval', self::get_members( $subscription ) ), true ) ) {
			return new \WP_Error( 'newspack_group_subscription_add_manager', __( 'Only an existing member can be made a manager.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $user_id, self::get_managers( $subscription ), true ) ) {
			\add_user_meta( $user_id, self::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY, $subscription->get_id() );
		}
		return true;
	}

	/**
	 * Demote a manager back to a regular member.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param int                  $user_id      The manager user ID.
	 *
	 * @return true|\WP_Error True on success.
	 */
	public static function remove_manager( $subscription, $user_id ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		$user_id      = absint( $user_id );
		if ( ! self::is_group_subscription( $subscription ) ) {
			return new \WP_Error( 'newspack_group_subscription_remove_manager', __( 'Subscription not found.', 'newspack-plugin' ), [ 'status' => 404 ] );
		}
		if ( $user_id === (int) $subscription->get_user_id() ) {
			return new \WP_Error( 'newspack_group_subscription_remove_manager', __( 'The owner cannot be demoted.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $user_id, self::get_managers( $subscription ), true ) ) {
			return new \WP_Error( 'newspack_group_subscription_remove_manager', __( 'This member is not a manager.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		\delete_user_meta( $user_id, self::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY, $subscription->get_id() );
		return true;
	}

	/**
	 * Whether an actor is allowed to remove a target member from a group.
	 *
	 * The single server-side authority for member removal — the My Account
	 * admin-post handler and the REST endpoint both defer to it, so the
	 * peer-manager rule can't be bypassed by forging a request the UI wouldn't
	 * offer. Role model: exactly one owner (the subscription customer, who holds
	 * billing and can never be removed), any number of managers (maintenance, no
	 * billing), and plain members.
	 *
	 * - The owner and store admins (`manage_woocommerce`) may remove anyone but the owner.
	 * - A manager may remove plain members only — never a peer manager of the same group.
	 * - Plain members and outsiders may remove no one.
	 *
	 * @param int                  $actor_id     The user attempting the removal.
	 * @param int                  $target_id    The member being removed.
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return bool Whether the removal is permitted.
	 */
	public static function can_actor_remove_member( $actor_id, $target_id, $subscription ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return false;
		}
		$actor_id  = (int) $actor_id;
		$target_id = (int) $target_id;
		$owner_id  = (int) $subscription->get_user_id();

		// A logged-out / unresolved actor can remove no one — guard before the
		// owner comparison so an actor of 0 never matches an ownerless (owner 0) group.
		if ( ! $actor_id ) {
			return false;
		}

		// The owner holds billing and is never removable from their own group.
		if ( $target_id === $owner_id ) {
			return false;
		}

		// The owner and store admins may remove any non-owner member.
		if ( $actor_id === $owner_id || \user_can( $actor_id, 'manage_woocommerce' ) ) {
			return true;
		}

		// A manager may remove plain members only — never a peer manager. Read the
		// manager list directly (not user_is_manager(), whose is_group_subscription()
		// call has a My Account side effect) so the rule is context-independent.
		$managers = array_map( 'intval', self::get_managers( $subscription ) );
		if ( in_array( $actor_id, $managers, true ) ) {
			return ! in_array( $target_id, $managers, true );
		}

		// Plain members and outsiders cannot remove anyone.
		return false;
	}

	/**
	 * Get the group subscriptions a user manages.
	 *
	 * The reverse of `get_managers()`: a user manages a group either by owning
	 * its subscription or by having been promoted to manager of a group they're
	 * a member of. Owned subscriptions are filtered to group-enabled subs the
	 * user actually owns (gifted subs where they're only the recipient are
	 * excluded); manager-of subscriptions are read from the user's own manager
	 * meta and filtered to group-enabled subs.
	 *
	 * @param int  $user_id  The user ID.
	 * @param bool $ids_only If true, return only subscription IDs instead of objects.
	 *
	 * @return \WC_Subscription[]|int[] The group subscriptions the user manages.
	 */
	public static function get_managed_subscriptions_for_user( $user_id, $ids_only = false ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return [];
		}
		$cache_key = $user_id . '|' . ( $ids_only ? '1' : '0' );
		if ( isset( self::$managed_subscriptions_cache[ $cache_key ] ) ) {
			return self::$managed_subscriptions_cache[ $cache_key ];
		}
		$owned   = \wcs_get_users_subscriptions( $user_id );
		$managed = [];
		foreach ( $owned as $sub ) {
			if ( ! $sub instanceof \WC_Subscription ) {
				continue;
			}
			// wcs_get_users_subscriptions() is filtered to inject subs the user
			// is only a *member* of on account pages. Manager detection must
			// only accept subs the user actually owns.
			if ( (int) $sub->get_customer_id() !== $user_id ) {
				continue;
			}
			$settings = Group_Subscription_Settings::get_subscription_settings( $sub );
			if ( empty( $settings['enabled'] ) ) {
				continue;
			}
			$managed[] = $ids_only ? $sub->get_id() : $sub;
		}

		// Subscriptions the user manages without owning (a promoted manager),
		// read from their own manager meta — the reverse of get_managers().
		$have_ids   = array_map(
			function ( $sub ) {
				return $sub instanceof \WC_Subscription ? $sub->get_id() : (int) $sub;
			},
			$managed
		);
		$manager_of = array_map( 'absint', (array) \get_user_meta( $user_id, self::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY, false ) );
		foreach ( $manager_of as $managed_id ) {
			if ( in_array( $managed_id, $have_ids, true ) ) {
				continue;
			}
			$sub = WooCommerce_Subscriptions::sanitize_subscription( $managed_id );
			if ( ! $sub instanceof \WC_Subscription ) {
				continue;
			}
			$settings = Group_Subscription_Settings::get_subscription_settings( $sub );
			if ( empty( $settings['enabled'] ) ) {
				continue;
			}
			$managed[]  = $ids_only ? $sub->get_id() : $sub;
			$have_ids[] = $managed_id;
		}

		/**
		 * Filter the group subscriptions a user manages.
		 *
		 * @param \WC_Subscription[]|int[] $managed Managed group subscriptions or IDs.
		 * @param int                      $user_id The user ID.
		 */
		$managed = apply_filters( 'newspack_group_subscriptions_managed_for_user', $managed, $user_id );

		self::$managed_subscriptions_cache[ $cache_key ] = $managed;
		return $managed;
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
		if ( isset( self::$members_cache[ $subscription_id ] ) ) {
			$members = self::$members_cache[ $subscription_id ];
		} else {
			$members = array_map(
				function( $user ) {
					return $user->ID;
				},
				\get_users(
					[
						'fields'      => [ 'ID' ],
						'count_total' => false,
						'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							[
								'key'   => self::GROUP_SUBSCRIPTION_USER_META_KEY,
								'value' => $subscription_id,
							],
						],
					]
				)
			);
			self::$members_cache[ $subscription_id ] = $members;
		}

		/**
		 * Filter the members of a group subscription.
		 *
		 * @param int[] $members Array of user IDs for group subscription members.
		 * @param \WC_Subscription $subscription The subscription object.
		 */
		return apply_filters( 'newspack_group_subscription_members', $members, $subscription );
	}

	/**
	 * Get the combined, de-duplicated list of a group's people: the manager(s)/owner
	 * and the members. The owner is part of the group, so they count as a member for
	 * display purposes (see get_member_count()).
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int[] De-duplicated user IDs of managers and members.
	 */
	public static function get_all_members( $subscription ) {
		$members  = array_map( 'intval', self::get_members( $subscription ) );
		$managers = array_map( 'intval', self::get_managers( $subscription ) );
		// array_filter drops empty IDs (e.g. a subscription with no owner); array_unique
		// guards against a user who is both a manager and a member being counted twice.
		return array_values( array_unique( array_filter( array_merge( $managers, $members ) ) ) );
	}

	/**
	 * Get the total member count for a group, including the manager(s)/owner.
	 *
	 * A group is never empty as long as it has an owner, so the owner is always
	 * included in this count.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int The number of people in the group (managers + members).
	 */
	public static function get_member_count( $subscription ) {
		return count( self::get_all_members( $subscription ) );
	}

	/**
	 * Get the total member capacity for a group, or null when there is no limit.
	 *
	 * The configured limit is the group's total capacity *including* the owner: the
	 * owner occupies one of the limited seats rather than sitting free on top of it.
	 * So the capacity is simply the limit, which keeps the denominator in step with
	 * the owner-inclusive get_member_count() numerator. The member-meta seats left
	 * for everyone but the owner are given by get_member_seat_limit().
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int|null The total capacity, or null when unlimited.
	 */
	public static function get_member_capacity( $subscription ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return null;
		}
		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
		$limit    = isset( $settings['limit'] ) ? (int) $settings['limit'] : 0;
		return $limit > 0 ? $limit : null;
	}

	/**
	 * Get the number of member-meta seats a group can hold, or null when unlimited.
	 *
	 * The configured limit is the total capacity including the owner, so the owner's
	 * seat — the one manager who holds no member meta — is reserved out of it, leaving
	 * `limit - 1` member seats in the common owned case (and all of `limit` in the
	 * ownerless edge case). This is the threshold the add and invite gates count
	 * member-meta holders against, and it stays in step with get_member_capacity():
	 * capacity = seats + owner.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return int|null The member-meta seat limit, or null when unlimited.
	 */
	public static function get_member_seat_limit( $subscription ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return null;
		}
		$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
		$limit    = isset( $settings['limit'] ) ? (int) $settings['limit'] : 0;
		if ( $limit <= 0 ) {
			return null;
		}
		// Managers who hold no member seat (in practice just the owner) occupy a seat
		// within the limit; promoted managers already count via their member meta.
		$managers_without_member_seat = array_diff(
			array_filter( array_map( 'intval', self::get_managers( $subscription ) ) ),
			array_map( 'intval', self::get_members( $subscription ) )
		);
		return max( 0, $limit - count( $managers_without_member_seat ) );
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
			return new \WP_Error( 'newspack_group_subscription_update_members', __( 'Subscription not found.', 'newspack-plugin' ), [ 'status' => 404 ] );
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
				\delete_user_meta( $member_id, self::get_member_joined_meta_key( $subscription->get_id() ) );
				// Leaving the group also ends any manager role — no orphaned managers.
				\delete_user_meta( $member_id, self::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY, $subscription->get_id() );
				$members_removed[ $member_id ] = [
					'email' => \get_userdata( $member_id )->user_email,
					'url'   => \get_edit_user_link( $member_id ),
				];
			}
		}

		// Removals above are persisted before this limit check, so a single call that both removes
		// and adds past the limit would keep the removals while returning 409. No shipped caller
		// batches add + remove in one call (the admin JS and admin-post handlers split them into
		// separate requests), so this can't happen today. If a caller ever combines both arrays,
		// move this check ahead of the removal loop and compute the projected count there.
		$existing_members = self::get_members( $subscription );
		$seat_limit       = self::get_member_seat_limit( $subscription );
		if ( null !== $seat_limit && count( $existing_members ) + count( $members_to_add ) > $seat_limit ) {
			return new \WP_Error( 'newspack_group_subscription_update_members', __( 'Member limit reached. Please remove some members or increase the limit.', 'newspack-plugin' ), [ 'status' => 409 ] );
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
				\update_user_meta( $member_id, self::get_member_joined_meta_key( $subscription->get_id() ), time() );
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
	 * Check if a user holds group membership (the member meta) for a subscription.
	 *
	 * A promoted manager keeps their membership, so this returns true for managers
	 * too — it reflects the membership record, not an exclusive "member, not manager"
	 * role. Use {@see self::user_is_manager()} to distinguish the manager role.
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
		$user_id = (int) $user_id;
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return [];
		}
		if ( ! Reader_Activation::is_user_reader( \get_user_by( 'id', $user_id ) ) ) {
			return [];
		}
		$cache_key = $user_id . '|' . ( $ids_only ? '1' : '0' );
		if ( isset( self::$member_subscriptions_cache[ $cache_key ] ) ) {
			return self::$member_subscriptions_cache[ $cache_key ];
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
		$subscriptions = apply_filters( 'newspack_group_subscriptions_for_user', $subscriptions, $user_id );

		self::$member_subscriptions_cache[ $cache_key ] = $subscriptions;
		return $subscriptions;
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
