<?php
/**
 * Newspack Network gift subscription recipients.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce_Subscriptions;

use Newspack\Data_Events;
use Newspack_Network\Woocommerce\Events as Woo_Events;

/**
 * Reports each change to who holds a gift subscription on this site, so the
 * recipient can read on the other network sites as they can here.
 *
 * A gift's customer is its buyer, so the subscription event names the buyer and
 * marks the subscription as a gift (see `Woo_Events::subscription_changed()`); this
 * sends a separate event for the recipient, shaped like a group seat's: it carries
 * the subscription's status while they hold the gift and is cancelled once they
 * don't. A recipient whose account is deleted is gone by the end of the request, so
 * their email is kept when the gift is queued.
 *
 * A subscription can also start or stop being a gift with no status change, which
 * is the only time its own event is sent; the buyer's subscription event is then
 * sent again, so other sites update whether it grants the buyer access.
 *
 * Reads the recipient through WooCommerce Subscriptions' gifting class only, never
 * through newspack-plugin, which may not be active. Without that class nothing is
 * a gift, which is also how newspack-plugin's own access check treats it.
 */
class Gift_Recipients {

	/**
	 * Data Events action sent to the hub and pulled by nodes.
	 */
	const ACTION = 'newspack_node_gift_changed';

	/**
	 * Hook fired once per changed gift and recipient at the end of the request.
	 */
	const HOOK = 'newspack_network_gift_changed';

	/**
	 * Hook fired once per subscription that started or stopped being a gift this
	 * request, to send its buyer's subscription event again.
	 */
	const SUBSCRIPTION_HOOK = 'newspack_network_gift_subscription_changed';

	/**
	 * Gifts that changed this request: user ID => [ subscription ID => whether the gift was taken from them ].
	 *
	 * @var array
	 */
	private static $queue = [];

	/**
	 * Emails of the recipients in the queue, kept for any whose account is deleted
	 * before the gift is reported: user ID => email.
	 *
	 * @var string[]
	 */
	private static $emails = [];

	/**
	 * Subscriptions that started or stopped being a gift this request: subscription ID => true.
	 *
	 * @var array
	 */
	private static $gift_status_changed = [];

	/**
	 * Initializer.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_listener' ] );
		add_action( 'woocommerce_subscription_status_changed', [ __CLASS__, 'queue_from_status_change' ], 10, 4 );
		add_action( 'woocommerce_subscriptions_gifting_recipient_changed', [ __CLASS__, 'queue_from_recipient_change' ], 10, 3 );
		add_action( 'newspack_subscription_gift_recipient_cleared', [ __CLASS__, 'queue_from_cleared_recipient' ], 10, 2 );
		add_action( 'shutdown', [ __CLASS__, 'dispatch_queued' ] );
	}

	/**
	 * Register the Data Events listeners that send each gift, and each buyer's
	 * subscription that started or stopped being a gift, to the hub.
	 */
	public static function register_listener() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		Data_Events::register_listener( self::HOOK, self::ACTION, [ __CLASS__, 'get_event_data' ] );
		Data_Events::register_listener( self::SUBSCRIPTION_HOOK, 'newspack_node_subscription_changed', [ __CLASS__, 'get_subscription_event_data' ] );
	}

	/**
	 * The recipient account of a gift subscription.
	 *
	 * @param object $subscription Subscription.
	 * @return int 0 when the subscription isn't a gift, or its recipient has no account yet.
	 */
	public static function get_recipient_id( $subscription ) {
		if ( ! $subscription || ! class_exists( 'WCS_Gifting' ) ) {
			return 0;
		}
		$recipient_id = \WCS_Gifting::get_recipient_user( $subscription );
		// The meta can be '' or '0' on a subscription that was never a gift, and 0 is not a user.
		return ( ! empty( $recipient_id ) && is_numeric( $recipient_id ) ) ? (int) $recipient_id : 0;
	}

	/**
	 * Whether a subscription is a gift, by the test WooCommerce Subscriptions uses.
	 *
	 * That includes a gift whose recipient has no account yet, so its buyer is never
	 * read as its owner in the meantime.
	 *
	 * @param object $subscription Subscription.
	 * @return bool
	 */
	public static function is_gift( $subscription ) {
		return $subscription && class_exists( 'WCS_Gifting' ) && (bool) \WCS_Gifting::is_gifted_subscription( $subscription );
	}

	/**
	 * Queue the recipient of a gift whose status changed.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $status_from     Status before the change.
	 * @param string $status_to       Status after the change.
	 * @param object $subscription    Subscription.
	 */
	public static function queue_from_status_change( $subscription_id, $status_from = '', $status_to = '', $subscription = null ) {
		if ( ! is_object( $subscription ) ) {
			$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;
		}
		$recipient_id = self::get_recipient_id( $subscription );
		if ( $recipient_id ) {
			self::queue( $recipient_id, $subscription_id );
		}
	}

	/**
	 * Queue both readers when a gift moves to a new recipient.
	 *
	 * WooCommerce Subscriptions fires this after saving, and also when the recipient
	 * stays the same, which must not take the gift from them.
	 *
	 * @param object $subscription  Subscription.
	 * @param int    $new_recipient New recipient user ID.
	 * @param int    $old_recipient Previous recipient user ID, 0 when there was none.
	 */
	public static function queue_from_recipient_change( $subscription, $new_recipient, $old_recipient ) {
		$subscription_id = is_object( $subscription ) ? $subscription->get_id() : $subscription;
		$new_recipient   = absint( $new_recipient );
		$old_recipient   = absint( $old_recipient );
		self::queue( $new_recipient, $subscription_id );
		if ( $old_recipient !== $new_recipient ) {
			self::queue( $old_recipient, $subscription_id, true );
			// Gained its first recipient or lost its last one. A move from one recipient to
			// another leaves it a gift, so the buyer's event has nothing new to say.
			if ( ! $old_recipient || ! $new_recipient ) {
				self::queue_gift_status_change( $subscription_id );
			}
		}
	}

	/**
	 * Queue a recipient removed from a gift with no new one, which WooCommerce
	 * Subscriptions does without a hook; newspack-plugin fires this action for it.
	 *
	 * @param object $subscription       Subscription.
	 * @param int    $previous_recipient Previous recipient user ID.
	 */
	public static function queue_from_cleared_recipient( $subscription, $previous_recipient ) {
		$subscription_id = is_object( $subscription ) ? $subscription->get_id() : $subscription;
		self::queue( $previous_recipient, $subscription_id, true );
		self::queue_gift_status_change( $subscription_id );
	}

	/**
	 * Queue a subscription that started or stopped being a gift, to send its buyer's
	 * subscription event again at the end of the request.
	 *
	 * @param mixed $subscription_id Subscription ID.
	 */
	public static function queue_gift_status_change( $subscription_id ) {
		$subscription_id = absint( $subscription_id );
		if ( $subscription_id ) {
			self::$gift_status_changed[ $subscription_id ] = true;
		}
	}

	/**
	 * Queue a gift for a reader, keeping their email in case the account goes before the gift is reported.
	 *
	 * The last call for a reader and gift wins, so a gift taken and given back in one
	 * request is reported as held.
	 *
	 * @param int   $user_id         Recipient user ID.
	 * @param mixed $subscription_id Subscription ID.
	 * @param bool  $taken           Whether the gift was taken from this reader.
	 */
	public static function queue( $user_id, $subscription_id, $taken = false ) {
		$user_id         = absint( $user_id );
		$subscription_id = absint( $subscription_id );
		if ( ! $user_id || ! $subscription_id ) {
			return;
		}
		self::$queue[ $user_id ][ $subscription_id ] = (bool) $taken;
		if ( ! isset( self::$emails[ $user_id ] ) ) {
			$user = get_userdata( $user_id );
			if ( $user && $user->user_email ) {
				self::$emails[ $user_id ] = $user->user_email;
			}
		}
	}

	/**
	 * The email kept for a queued recipient.
	 *
	 * @param int $user_id Recipient user ID.
	 * @return string|null Null when none was kept.
	 */
	public static function get_queued_email( $user_id ) {
		return self::$emails[ absint( $user_id ) ] ?? null;
	}

	/**
	 * Report each queued gift once.
	 */
	public static function dispatch_queued() {
		$queued                    = self::$queue;
		$emails                    = self::$emails;
		$gift_status_changed       = self::$gift_status_changed;
		self::$queue               = [];
		self::$emails              = [];
		self::$gift_status_changed = [];
		foreach ( $queued as $user_id => $subscriptions ) {
			// Hand the kept email to the listener for the length of this reader's reports.
			if ( isset( $emails[ $user_id ] ) ) {
				self::$emails[ $user_id ] = $emails[ $user_id ];
			}
			foreach ( $subscriptions as $subscription_id => $taken ) {
				do_action( self::HOOK, $user_id, $subscription_id, $taken );
			}
			unset( self::$emails[ $user_id ] );
		}
		foreach ( array_keys( $gift_status_changed ) as $subscription_id ) {
			do_action( self::SUBSCRIPTION_HOOK, $subscription_id );
		}
	}

	/**
	 * The buyer's subscription event for a subscription that started or stopped being
	 * a gift, built as a status change would build it, with its current status as both
	 * before and after.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array|null Null when the subscription no longer loads.
	 */
	public static function get_subscription_event_data( $subscription_id ) {
		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( absint( $subscription_id ) ) : false;
		if ( ! $subscription ) {
			return null;
		}
		$status = $subscription->get_status();
		return Woo_Events::subscription_changed( $subscription->get_id(), $status, $status, $subscription );
	}

	/**
	 * The event for a gift and reader, from this site's current state.
	 *
	 * The reader holds the gift while they are still its recipient, so a reader
	 * queued for a status change who lost the gift later in the request is reported
	 * as cancelled too.
	 *
	 * @param int  $user_id         Recipient user ID.
	 * @param int  $subscription_id Subscription ID.
	 * @param bool $taken           Whether the gift was taken from this reader.
	 * @return array|null Null when there's nothing to report.
	 */
	public static function get_event_data( $user_id, $subscription_id, $taken = false ) {
		$user_id         = absint( $user_id );
		$subscription_id = absint( $subscription_id );
		if ( ! $user_id || ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}
		// A deleted recipient has no account left, so the email kept at queue time stands in and the gift is gone.
		$user  = get_userdata( $user_id );
		$email = $user ? $user->user_email : self::get_queued_email( $user_id );
		if ( ! $email ) {
			return null;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			// Deleting a buyer's account can delete the gift in the same request, leaving nothing to load.
			return [
				'email'        => $email,
				'user_id'      => $user_id,
				'id'           => $subscription_id,
				'status_after' => 'cancelled',
				'products'     => [],
			];
		}
		$holds_gift = $user && ! $taken && self::get_recipient_id( $subscription ) === $user_id;
		return self::build_event_data( $email, $user_id, $subscription, $holds_gift );
	}

	/**
	 * The event payload for a gift.
	 *
	 * While the reader holds the gift it carries the subscription's own status, so the
	 * reading site applies the same status rules it applies to owned subscriptions. A
	 * gift the reader no longer holds is reported as cancelled.
	 *
	 * @param string $email        Recipient email.
	 * @param int    $user_id      Recipient user ID.
	 * @param object $subscription The gift subscription.
	 * @param bool   $holds_gift   Whether the reader is still the gift's recipient.
	 * @return array
	 */
	public static function build_event_data( $email, $user_id, $subscription, $holds_gift ) {
		return [
			'email'        => $email,
			'user_id'      => (int) $user_id,
			'id'           => (int) $subscription->get_id(),
			'status_after' => $holds_gift ? $subscription->get_status() : 'cancelled',
			'products'     => Woo_Events::get_subscription_products( $subscription ),
		];
	}
}
