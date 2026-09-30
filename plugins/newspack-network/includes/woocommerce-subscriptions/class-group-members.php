<?php
/**
 * Newspack Network group subscription members.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce_Subscriptions;

use Newspack\Data_Events;
use Newspack_Network\Woocommerce\Events as Woo_Events;

/**
 * Reports a group subscription's members to the hub whenever they change, so a
 * member can read on the other network sites too.
 *
 * Membership is user meta on the site that owns the group subscription, and it
 * changes through several routes (adding and removing members, leaving a group,
 * deleting a user). Watching the meta itself catches all of them.
 */
class Group_Members {

	/**
	 * Data Events action sent to the hub. Nodes don't pull it: member lists only
	 * need to reach the hub, which answers the other sites' questions.
	 */
	const ACTION = 'newspack_node_group_members_changed';

	/**
	 * Hook fired, once per changed group subscription, at the end of the request.
	 */
	const HOOK = 'newspack_network_group_members_changed';

	/**
	 * User meta newspack-plugin records a member's group subscription IDs in.
	 */
	const MEMBER_META_KEY = '_newspack_group_subscription';

	/**
	 * Post meta on the hub's copy of a subscription holding each member's email.
	 */
	const HUB_MEMBER_META_KEY = 'group_member';

	/**
	 * Product meta newspack-plugin reads a group subscription's on/off setting from
	 * when the subscription doesn't set its own.
	 */
	const PRODUCT_ENABLED_META_KEY = '_newspack_group_subscription_enabled';

	/**
	 * Group subscription IDs whose members changed during this request.
	 *
	 * @var array<int, true>
	 */
	private static $queue = [];

	/**
	 * Initializer.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_listener' ] );
		add_action( 'added_user_meta', [ __CLASS__, 'queue_from_meta' ], 10, 4 );
		add_action( 'delete_user_meta', [ __CLASS__, 'queue_from_meta_ids' ], 10, 3 );
		add_action( 'update_user_meta', [ __CLASS__, 'queue_from_meta_ids' ], 10, 3 );
		add_action( 'updated_user_meta', [ __CLASS__, 'queue_from_meta' ], 10, 4 );
		add_action( 'newspack_group_subscription_settings_updated', [ __CLASS__, 'queue_from_settings' ], 10, 2 );
		add_action( 'set_user_role', [ __CLASS__, 'queue_user_groups' ] );
		add_action( 'add_user_role', [ __CLASS__, 'queue_user_groups' ] );
		add_action( 'remove_user_role', [ __CLASS__, 'queue_user_groups' ] );
		add_action( 'added_post_meta', [ __CLASS__, 'queue_from_product_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ __CLASS__, 'queue_from_product_meta' ], 10, 3 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'queue_from_product_meta' ], 10, 3 );
		add_action( 'shutdown', [ __CLASS__, 'dispatch_queued' ] );
	}

	/**
	 * Register the Data Events listener that sends the members to the hub.
	 */
	public static function register_listener() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		Data_Events::register_listener( self::HOOK, self::ACTION, [ __CLASS__, 'get_event_data' ] );
	}

	/**
	 * Queue the group subscription named by a membership meta value that was added or set.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value: a group subscription ID.
	 */
	public static function queue_from_meta( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( self::MEMBER_META_KEY === $meta_key ) {
			self::queue( $meta_value );
		}
	}

	/**
	 * Queue the group subscriptions named by membership meta about to be deleted or changed.
	 *
	 * The rows are read before the change, since a deletion without a value removes
	 * every membership at once and a change replaces the old value.
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
				self::queue( $meta->meta_value );
			}
		}
	}

	/**
	 * Queue a group subscription whose group was turned on or off.
	 *
	 * @param \WC_Subscription|int $subscription Subscription.
	 * @param string[]             $changed_keys Settings that changed.
	 */
	public static function queue_from_settings( $subscription, $changed_keys ) {
		if ( in_array( 'enabled', (array) $changed_keys, true ) ) {
			self::queue( is_object( $subscription ) ? $subscription->get_id() : $subscription );
		}
	}

	/**
	 * Queue the groups of a user whose role changed.
	 *
	 * Eligibility for a seat depends on the user's role, and the member list sent to
	 * the hub only includes eligible members.
	 *
	 * @param int $user_id User ID.
	 */
	public static function queue_user_groups( $user_id ) {
		foreach ( (array) get_user_meta( $user_id, self::MEMBER_META_KEY, false ) as $subscription_id ) {
			self::queue( $subscription_id );
		}
	}

	/**
	 * Queue every group with members when a product's group setting changes.
	 *
	 * Subscriptions without their own setting inherit the product's, so turning groups
	 * off on a product ends every such group's seats. Finding which subscriptions
	 * inherit it would mean loading each one; groups with members are few, so they
	 * are all reported and each report reads its own current setting.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public static function queue_from_product_meta( $meta_ids, $object_id, $meta_key ) {
		if ( self::PRODUCT_ENABLED_META_KEY !== $meta_key || 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		global $wpdb;
		$subscription_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM $wpdb->usermeta WHERE meta_key = %s", self::MEMBER_META_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $subscription_ids as $subscription_id ) {
			self::queue( $subscription_id );
		}
	}

	/**
	 * Queue a group subscription ID.
	 *
	 * @param mixed $subscription_id Subscription ID.
	 */
	private static function queue( $subscription_id ) {
		$subscription_id = absint( $subscription_id );
		if ( $subscription_id ) {
			self::$queue[ $subscription_id ] = true;
		}
	}

	/**
	 * Report each queued group subscription once.
	 */
	public static function dispatch_queued() {
		$queued      = array_keys( self::$queue );
		self::$queue = [];
		foreach ( $queued as $subscription_id ) {
			do_action( self::HOOK, $subscription_id );
		}
	}

	/**
	 * The event sent to the hub: the subscription, as its status events describe it,
	 * plus whether its group is on and the emails of its members.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array|null Null when there's nothing to report.
	 */
	public static function get_event_data( $subscription_id ) {
		if ( ! function_exists( 'wcs_get_subscription' ) || ! class_exists( 'Newspack\Group_Subscription' ) || ! class_exists( 'Newspack\Group_Subscription_Settings' ) ) {
			return null;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return null;
		}

		$settings = \Newspack\Group_Subscription_Settings::get_subscription_settings( $subscription );
		$enabled  = ! empty( $settings['enabled'] );
		$members  = [];
		if ( $enabled ) {
			foreach ( \Newspack\Group_Subscription::get_members( $subscription ) as $member_id ) {
				// The same test newspack-plugin applies before a seat grants access locally.
				if ( ! \Newspack\Group_Subscription::is_eligible_member( $member_id ) ) {
					continue;
				}
				$member = get_userdata( $member_id );
				if ( $member && $member->user_email ) {
					$members[] = $member->user_email;
				}
			}
		}

		$status                  = $subscription->get_status();
		$data                    = Woo_Events::subscription_changed( $subscription->get_id(), $status, $status, $subscription );
		$data['group_enabled']   = $enabled;
		$data['group_members']   = $members;
		return $data;
	}
}
