<?php
/**
 * WooCommerce Subscriptions Gifting Integration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class WooCommerce_Subscriptions_Gifting {
	/**
	 * Gift recipients read just before WCS saves a subscription's edit screen,
	 * keyed by subscription ID, 0 for a subscription with no recipient account.
	 * Lets handle_cleared_gift_recipient() tell which reader lost a gift once WCS
	 * has removed the recipient.
	 *
	 * @var array<int, int>
	 */
	private static $gift_recipients_before_save = [];

	/**
	 * Gifts with a recipient account that a user being deleted bought or received,
	 * read just before WCS removes their recipients. Keyed by the deleted user's ID,
	 * then by subscription ID, holding the recipient's user ID.
	 *
	 * @var array<int, array<int, int>>
	 */
	private static $gift_recipients_before_user_deletion = [];

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		\add_filter( 'wcsg_new_recipient_account_details_fields', [ __CLASS__, 'new_recipient_fields' ] );
		\add_filter( 'wcsg_require_shipping_address_for_virtual_products', '__return_false' );
		\add_filter( 'default_option_woocommerce_subscriptions_gifting_gifting_checkbox_text', [ __CLASS__, 'default_gifting_checkbox_text' ] );
		\add_filter( 'newpack_reader_activation_reader_is_without_password', [ __CLASS__, 'is_reader_without_password' ], 10, 2 );
		\add_filter( 'wcs_view_subscription_actions', [ __CLASS__, 'view_subscription_actions' ], 12, 3 );

		// WCS removes a gift's recipient without the recipient-changed hook in two
		// places, so the recipient is read on either side of each and the removal
		// announced. Clearing it on the edit screen: WCS saves it at priority 50.
		\add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'remember_gift_recipient_before_save' ], 49 );
		\add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'handle_cleared_gift_recipient' ], 51 );
		// Deleting the recipient's or the buyer's account: WCS's
		// trash_users_subscriptions() runs at priority 10.
		\add_action( 'delete_user', [ __CLASS__, 'remember_gift_recipients_before_user_deletion' ], 9 );
		\add_action( 'delete_user', [ __CLASS__, 'handle_gift_recipients_cleared_by_user_deletion' ], 11 );
	}

	/**
	 * Check if WooCommerce Subscriptions Gifting is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return function_exists( 'WC' ) && class_exists( 'WC_Subscriptions' ) && class_exists( 'WCS_Gifting' );
	}

	/**
	 * Get the gift recipient of a subscription.
	 *
	 * Mirrors the gift check content-gate access uses, so callers agree with it on
	 * who a gifted subscription belongs to.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 *
	 * @return int The recipient's user ID, or 0 if the subscription isn't a gift,
	 *             the gift has no recipient account yet (email-only), or the
	 *             recipient's account no longer exists.
	 */
	public static function get_recipient_id( $subscription ): int {
		if ( ! class_exists( 'WCS_Gifting' ) || ! \WCS_Gifting::is_gifted_subscription( $subscription ) ) {
			return 0;
		}
		$recipient_id = (int) \WCS_Gifting::get_recipient_user( $subscription );
		return $recipient_id && \get_userdata( $recipient_id ) ? $recipient_id : 0;
	}

	/**
	 * Get the user who holds a subscription: the recipient of a gift, otherwise
	 * the customer who purchased it.
	 *
	 * A gift with no recipient account (email-only, or the account is gone) falls
	 * back to the buyer here. Content-gate access differs: it grants such a gift
	 * to nobody.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 *
	 * @return int User ID.
	 */
	public static function get_holder_id( $subscription ): int {
		$recipient_id = self::get_recipient_id( $subscription );
		return $recipient_id ? $recipient_id : (int) $subscription->get_customer_id();
	}

	/**
	 * Announce that a gift lost its recipient account without the hook WCS fires
	 * for a change of recipient.
	 *
	 * @param \WC_Subscription $subscription          The gift.
	 * @param int              $previous_recipient_id The recipient it had before.
	 *
	 * @return void
	 */
	private static function announce_cleared_gift_recipient( $subscription, int $previous_recipient_id ): void {
		/**
		 * Fires when a gift loses its recipient account and WCS fires no
		 * `woocommerce_subscriptions_gifting_recipient_changed`: an admin cleared the
		 * recipient on the edit screen, or the recipient's or the buyer's account was
		 * deleted. The gift may still hold the recipient's email address, in which
		 * case WCS still counts it as a gift.
		 *
		 * @param \WC_Subscription $subscription          The gift.
		 * @param int              $previous_recipient_id The recipient it had before.
		 */
		\do_action( 'newspack_subscription_gift_recipient_cleared', $subscription, $previous_recipient_id );
	}

	/**
	 * Remember a gift's recipient before WCS saves the subscription edit screen.
	 *
	 * @param int $order_id ID of the order or subscription being saved.
	 *
	 * @return void
	 */
	public static function remember_gift_recipient_before_save( $order_id ): void {
		$subscription = self::get_subscription_being_saved( $order_id );
		if ( $subscription ) {
			self::$gift_recipients_before_save[ (int) $order_id ] = self::get_recipient_id( $subscription );
		}
	}

	/**
	 * Announce a gift's cleared recipient when an admin removes it on the edit screen.
	 *
	 * WCS removes the recipient while it saves the edit screen and fires no hook,
	 * so without this the former recipient would keep what the gift gave them and a
	 * buyer who gets the subscription back would never be told. A reassignment fires
	 * the recipient-changed hook from that same save, so a subscription that still
	 * has a recipient account is left alone.
	 *
	 * @param int $order_id ID of the order or subscription being saved.
	 *
	 * @return void
	 */
	public static function handle_cleared_gift_recipient( $order_id ): void {
		$order_id         = (int) $order_id;
		$old_recipient_id = self::$gift_recipients_before_save[ $order_id ] ?? 0;
		unset( self::$gift_recipients_before_save[ $order_id ] );
		if ( ! $old_recipient_id ) {
			return;
		}
		$subscription = self::get_subscription_being_saved( $order_id );
		if ( ! $subscription || self::get_recipient_id( $subscription ) ) {
			return;
		}
		self::announce_cleared_gift_recipient( $subscription, $old_recipient_id );
	}

	/**
	 * The subscription an edit-screen save is for, or null when the save is for
	 * another kind of order.
	 *
	 * @param int $order_id ID of the order or subscription being saved.
	 *
	 * @return \WC_Subscription|null
	 */
	private static function get_subscription_being_saved( $order_id ) {
		if ( ! function_exists( 'wcs_is_subscription' ) || ! \wcs_is_subscription( $order_id ) ) {
			return null;
		}
		$subscription = \wcs_get_subscription( $order_id );
		return $subscription ? $subscription : null;
	}

	/**
	 * Remember the recipient of each gift a user being deleted bought or received.
	 *
	 * WCS's trash_users_subscriptions() removes the recipient from every gift it
	 * lists for the user, which covers both, so this reads the same list.
	 *
	 * @param int $user_id ID of the user being deleted.
	 *
	 * @return void
	 */
	public static function remember_gift_recipients_before_user_deletion( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! class_exists( 'WCS_Gifting' ) || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return;
		}
		$recipients = [];
		foreach ( (array) \wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription ) {
				continue;
			}
			$recipient_id = self::get_recipient_id( $subscription );
			if ( $recipient_id ) {
				$recipients[ $subscription->get_id() ] = $recipient_id;
			}
		}
		if ( $recipients ) {
			self::$gift_recipients_before_user_deletion[ $user_id ] = $recipients;
		}
	}

	/**
	 * Announce each gift that lost its recipient when WCS cleaned up after a deleted user.
	 *
	 * Deleting the recipient hands the gift back to its buyer, and deleting the buyer
	 * leaves the recipient without it. WCS fires no recipient-changed hook either
	 * way, so without this neither reader's access would be rechecked.
	 *
	 * @param int $user_id ID of the user being deleted.
	 *
	 * @return void
	 */
	public static function handle_gift_recipients_cleared_by_user_deletion( $user_id ): void {
		$user_id    = (int) $user_id;
		$recipients = self::$gift_recipients_before_user_deletion[ $user_id ] ?? [];
		unset( self::$gift_recipients_before_user_deletion[ $user_id ] );
		foreach ( $recipients as $subscription_id => $previous_recipient_id ) {
			$subscription = \wcs_get_subscription( $subscription_id );
			if ( $subscription && ! self::get_recipient_id( $subscription ) ) {
				self::announce_cleared_gift_recipient( $subscription, $previous_recipient_id );
			}
		}
	}

	/**
	 * Ensure that only billing address fields enabled in Reader Revenue settings
	 * are required for new gift recipient accounts.
	 *
	 * See: https://github.com/woocommerce/woocommerce-subscriptions-gifting/blob/trunk/includes/class-wcsg-recipient-details.php#L275
	 *
	 * @param array $fields Address fields.
	 * @return array
	 */
	public static function new_recipient_fields( $fields ) {
		// Escape hatch to force required shipping address for virtual products.
		if ( apply_filters( 'wcsg_require_shipping_address_for_virtual_products', false ) ) { // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return $fields;
		}
		$required_fields = Donations::get_billing_fields();
		foreach ( $fields as $field_name => $field_config ) {
			if ( 'shipping_' !== substr( $field_name, 0, 9 ) && ! in_array( 'billing_' . $field_name, $required_fields, true ) ) {
				unset( $fields[ $field_name ] );
			}
		}
		return $fields;
	}

	/**
	 * Filters the default text shown for the gifting checkbox during checkout.
	 *
	 * @return string
	 */
	public static function default_gifting_checkbox_text() {
		return __( 'This purchase is a gift', 'newspack' );
	}

	/**
	 * New gift recipients don't yet have a password.
	 *
	 * @param bool $is_reader_without_password True if the reader has not set a password.
	 * @param int  $user_id The user ID.
	 *
	 * @return bool
	 */
	public static function is_reader_without_password( $is_reader_without_password, $user_id ) {
		// wcsg_update_account meta will force the user to update/set account information on login.
		$user_needs_account_update = get_user_meta( $user_id, 'wcsg_update_account', true );
		if ( ! empty( $user_needs_account_update ) ) {
			return 'true' === $user_needs_account_update;
		}
		return $is_reader_without_password;
	}

	/**
	 * Remove actions from the gifted subscription page.
	 *
	 * Only the purchaser of the subscription should see the actions.
	 *
	 * @param array            $actions      Actions.
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $user_id      The user ID.
	 *
	 * @return array
	 */
	public static function view_subscription_actions( $actions, $subscription, $user_id ) {
		if ( function_exists( 'is_account_page' ) && is_account_page() && $subscription->get_customer_id() !== $user_id ) {
			return [];
		}
		return $actions;
	}
}
WooCommerce_Subscriptions_Gifting::init();
