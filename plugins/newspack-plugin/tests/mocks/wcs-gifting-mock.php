<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Mock for the WooCommerce Subscriptions Gifting WCS_Gifting class.
 *
 * @package Newspack\Tests
 */

/**
 * Minimal WCS_Gifting stub mirroring the two static methods the plugin's
 * gift-aware code calls: is_gifted_subscription() and get_recipient_user(). A
 * subscription is "gifted" when an entry exists in $recipients. The stub has no
 * reset: each test class that uses it clears WCS_Gifting::$recipients itself in
 * set_up() and tear_down().
 */
if ( ! class_exists( 'WCS_Gifting' ) ) {
	/**
	 * Recording WCS_Gifting stub: gifted iff an entry exists in $recipients.
	 */
	class WCS_Gifting {
		/**
		 * Map of subscription ID => recipient user ID.
		 *
		 * @var array
		 */
		public static $recipients = [];

		/**
		 * Whether the subscription was purchased as a gift.
		 *
		 * @param object $subscription Subscription object.
		 * @return bool
		 */
		public static function is_gifted_subscription( $subscription ) {
			return isset( self::$recipients[ $subscription->get_id() ] );
		}

		/**
		 * The recipient user ID for a gifted subscription.
		 *
		 * @param object $subscription Subscription object.
		 * @return int
		 */
		public static function get_recipient_user( $subscription ) {
			return (int) ( self::$recipients[ $subscription->get_id() ] ?? 0 );
		}
	}
}
