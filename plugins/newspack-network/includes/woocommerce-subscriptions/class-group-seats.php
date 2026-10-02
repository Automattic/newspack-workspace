<?php
/**
 * Newspack Network group subscription seats.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce_Subscriptions;

use Newspack\Data_Events;
use Newspack_Network\Woocommerce\Events as Woo_Events;

/**
 * Reports each change to a seat on one of this site's group subscriptions, so the
 * member can read on the other network sites as the group's owner can.
 *
 * One event per member, shaped like a subscription event: the seat carries the
 * subscription's status, so a reading site applies the same status rules, while
 * keeping seats apart from the subscriptions a reader owns. Membership is user meta on
 * this site and changes through several routes (adding and removing members, leaving
 * a group, deleting a user), so the meta itself is watched. A deleted user is gone by
 * the end of the request, so the member's email is kept when the seat is queued and
 * the seat is reported as cancelled.
 */
class Group_Seats {

	/**
	 * Data Events action sent to the hub and pulled by nodes.
	 */
	const ACTION = 'newspack_node_group_seat_changed';

	/**
	 * Hook fired once per changed seat at the end of the request.
	 */
	const HOOK = 'newspack_network_group_seat_changed';

	/**
	 * User meta newspack-plugin records a member's group subscription IDs in
	 * (its `Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY`), read here directly
	 * rather than through that class: the WooCommerce Subscriptions status hook fires
	 * whether or not newspack-plugin is active, and calling its `get_members()` from
	 * there would be a fatal error without it.
	 */
	const MEMBER_META_KEY = '_newspack_group_subscription';

	/**
	 * Seats that changed this request: user ID => [ subscription ID => true ].
	 *
	 * @var array
	 */
	private static $queue = [];

	/**
	 * Emails of the members in the queue, kept for any whose account is deleted before
	 * the seat is reported: user ID => email.
	 *
	 * @var string[]
	 */
	private static $emails = [];

	/**
	 * Initializer.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_listener' ] );
		add_action( 'added_user_meta', [ __CLASS__, 'queue_from_meta' ], 10, 4 );
		add_action( 'delete_user_meta', [ __CLASS__, 'queue_from_meta_ids' ], 10, 3 );
		add_action( 'woocommerce_subscription_status_changed', [ __CLASS__, 'queue_subscription_members' ] );
		add_action( 'newspack_group_subscription_settings_updated', [ __CLASS__, 'queue_from_settings' ], 10, 2 );
		add_action( 'set_user_role', [ __CLASS__, 'queue_user_seats' ] );
		add_action( 'add_user_role', [ __CLASS__, 'queue_user_seats' ] );
		add_action( 'remove_user_role', [ __CLASS__, 'queue_user_seats' ] );
		add_action( 'shutdown', [ __CLASS__, 'dispatch_queued' ] );
	}

	/**
	 * Register the Data Events listener that sends each seat to the hub.
	 */
	public static function register_listener() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		Data_Events::register_listener( self::HOOK, self::ACTION, [ __CLASS__, 'get_event_data' ] );
	}

	/**
	 * Queue a seat recorded by membership meta.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value A group subscription ID.
	 */
	public static function queue_from_meta( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( self::MEMBER_META_KEY === $meta_key ) {
			self::queue( $user_id, $meta_value );
		}
	}

	/**
	 * Queue the seats recorded by membership meta about to be deleted.
	 *
	 * Read before the rows go, since a deletion without a value removes every
	 * membership at once.
	 *
	 * @param int|int[] $meta_ids Meta IDs.
	 * @param int       $user_id  User ID.
	 * @param string    $meta_key Meta key.
	 */
	public static function queue_from_meta_ids( $meta_ids, $user_id, $meta_key ) {
		if ( self::MEMBER_META_KEY !== $meta_key ) {
			return;
		}
		foreach ( (array) $meta_ids as $meta_id ) {
			$meta = get_metadata_by_mid( 'user', $meta_id );
			if ( $meta ) {
				// The hook's user ID is 0 when the deletion matched rows by value alone.
				self::queue( $meta->user_id, $meta->meta_value );
			}
		}
	}

	/**
	 * Queue every seat a user holds when their roles change.
	 *
	 * Whether a member may hold a seat follows their roles (staff are excluded), so a
	 * promotion can end a seat with no membership change for the meta hooks to see.
	 *
	 * @param int $user_id User ID.
	 */
	public static function queue_user_seats( $user_id ) {
		foreach ( get_user_meta( $user_id, self::MEMBER_META_KEY, false ) as $subscription_id ) {
			self::queue( $user_id, $subscription_id );
		}
	}

	/**
	 * Queue every seat on a group subscription whose status changed.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function queue_subscription_members( $subscription_id ) {
		foreach ( self::get_member_ids( $subscription_id ) as $member_id ) {
			self::queue( $member_id, $subscription_id );
		}
	}

	/**
	 * Queue every seat on a group subscription turned on or off.
	 *
	 * @param \WC_Subscription|int $subscription Subscription.
	 * @param string[]             $changed_keys Settings that changed.
	 */
	public static function queue_from_settings( $subscription, $changed_keys ) {
		if ( ! in_array( 'enabled', (array) $changed_keys, true ) ) {
			return;
		}
		self::queue_subscription_members( is_object( $subscription ) ? $subscription->get_id() : $subscription );
	}

	/**
	 * Queue a seat, keeping the member's email in case the account goes before the seat is reported.
	 *
	 * @param int   $user_id         Member user ID.
	 * @param mixed $subscription_id Subscription ID.
	 */
	public static function queue( $user_id, $subscription_id ) {
		$user_id         = absint( $user_id );
		$subscription_id = absint( $subscription_id );
		if ( ! $user_id || ! $subscription_id ) {
			return;
		}
		self::$queue[ $user_id ][ $subscription_id ] = true;
		if ( ! isset( self::$emails[ $user_id ] ) ) {
			$user = get_userdata( $user_id );
			if ( $user && $user->user_email ) {
				self::$emails[ $user_id ] = $user->user_email;
			}
		}
	}

	/**
	 * The email kept for a queued member.
	 *
	 * @param int $user_id Member user ID.
	 * @return string|null Null when none was kept.
	 */
	public static function get_queued_email( $user_id ) {
		return self::$emails[ absint( $user_id ) ] ?? null;
	}

	/**
	 * Report each queued seat once.
	 */
	public static function dispatch_queued() {
		$queued       = self::$queue;
		$emails       = self::$emails;
		self::$queue  = [];
		self::$emails = [];
		foreach ( $queued as $user_id => $subscription_ids ) {
			// Hand the kept email to the listener for the length of this member's reports.
			if ( isset( $emails[ $user_id ] ) ) {
				self::$emails[ $user_id ] = $emails[ $user_id ];
			}
			foreach ( array_keys( $subscription_ids ) as $subscription_id ) {
				do_action( self::HOOK, $user_id, $subscription_id );
			}
			unset( self::$emails[ $user_id ] );
		}
	}

	/**
	 * The event for a seat, from this site's current state.
	 *
	 * @param int $user_id         Member user ID.
	 * @param int $subscription_id Subscription ID.
	 * @return array|null Null when there's nothing to report.
	 */
	public static function get_event_data( $user_id, $subscription_id ) {
		$user_id         = absint( $user_id );
		$subscription_id = absint( $subscription_id );
		if ( ! function_exists( 'wcs_get_subscription' ) || ! class_exists( 'Newspack\Group_Subscription' ) || ! class_exists( 'Newspack\Group_Subscription_Settings' ) ) {
			return null;
		}
		// A deleted member has no account left, so the email kept at queue time stands in and the seat is gone.
		$user         = get_userdata( $user_id );
		$email        = $user ? $user->user_email : self::get_queued_email( $user_id );
		if ( ! $email ) {
			return null;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			// Deleting an owner's account cancels and then force-deletes their subscriptions in the
			// same request, after the status change queued every seat; by now there is nothing to load,
			// and the members' seats are gone with it.
			return [
				'email'        => $email,
				'user_id'      => $user_id,
				'id'           => $subscription_id,
				'status_after' => 'cancelled',
				'products'     => [],
			];
		}
		$settings    = \Newspack\Group_Subscription_Settings::get_subscription_settings( $subscription );
		$seat_active = $user
			&& ! empty( $settings['enabled'] )
			&& in_array( $subscription_id, array_map( 'absint', get_user_meta( $user_id, self::MEMBER_META_KEY, false ) ), true )
			&& \Newspack\Group_Subscription::is_eligible_member( $user_id );
		return self::build_event_data( $email, $user_id, $subscription, $seat_active );
	}

	/**
	 * The event payload for a seat.
	 *
	 * While the seat is active it carries the subscription's own status, so the
	 * reading site applies the same status rules it applies to owned subscriptions.
	 * A seat that is gone is reported as cancelled.
	 *
	 * @param string $email        Member email.
	 * @param int    $user_id      Member user ID.
	 * @param object $subscription The group subscription.
	 * @param bool   $seat_active  Whether the member still holds an eligible seat on an enabled group.
	 * @return array
	 */
	public static function build_event_data( $email, $user_id, $subscription, $seat_active ) {
		return [
			'email'        => $email,
			'user_id'      => (int) $user_id,
			'id'           => (int) $subscription->get_id(),
			'status_after' => $seat_active ? $subscription->get_status() : 'cancelled',
			'products'     => Woo_Events::get_subscription_products( $subscription ),
		];
	}

	/**
	 * IDs of the users holding a seat on a subscription, from the meta itself
	 * (see MEMBER_META_KEY for why not newspack-plugin's `get_members()`).
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return int[]
	 */
	private static function get_member_ids( $subscription_id ) {
		return array_map(
			'intval',
			get_users(
				[
					'fields'      => 'ID',
					'count_total' => false,
					'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'   => self::MEMBER_META_KEY,
							'value' => absint( $subscription_id ),
						],
					],
				]
			)
		);
	}
}
