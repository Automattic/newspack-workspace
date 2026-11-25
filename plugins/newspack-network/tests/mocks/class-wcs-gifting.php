<?php
/**
 * Stand-in for WooCommerce Subscriptions' WCS_Gifting, which the suite doesn't load.
 *
 * Tests set a gift's recipient account in `WCS_Gifting::$recipients` and a gift still
 * waiting for its recipient to make an account in `WCS_Gifting::$recipient_emails`,
 * both keyed by subscription ID, and call `WCS_Gifting::reset()` between tests. Once
 * loaded the class exists for the rest of the run, so a subscription with neither
 * entry reads as no gift, as it does with the real class.
 *
 * @package Newspack_Network
 */

if ( class_exists( 'WCS_Gifting' ) ) {
	return;
}

/**
 * Stand-in for WCS_Gifting.
 */
class WCS_Gifting {
	/**
	 * Recipient user IDs: subscription ID => user ID.
	 *
	 * @var array
	 */
	public static $recipients = [];

	/**
	 * Recipient emails of gifts with no recipient account yet: subscription ID => email.
	 *
	 * @var array
	 */
	public static $recipient_emails = [];

	/**
	 * Forget every gift.
	 */
	public static function reset() {
		self::$recipients       = [];
		self::$recipient_emails = [];
	}

	/**
	 * The recipient user ID, as a string like the meta the real method returns.
	 *
	 * @param object $subscription Subscription.
	 * @return string Empty when the subscription has no recipient account.
	 */
	public static function get_recipient_user( $subscription ) {
		$id = is_object( $subscription ) ? (int) $subscription->get_id() : 0;
		return isset( self::$recipients[ $id ] ) ? (string) self::$recipients[ $id ] : '';
	}

	/**
	 * Whether the subscription is a gift: it has a recipient account or a recipient email.
	 *
	 * @param object|int $subscription Subscription or its ID.
	 * @return bool
	 */
	public static function is_gifted_subscription( $subscription ) {
		$id = is_object( $subscription ) ? (int) $subscription->get_id() : (int) $subscription;
		return ! empty( self::$recipients[ $id ] ) || ! empty( self::$recipient_emails[ $id ] );
	}
}
