<?php
/**
 * Mailchimp for WooCommerce integration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Mailchimp_For_WooCommerce {
	/**
	 * Whether this class removed MC4WC's profile update handler for the current user update.
	 *
	 * @var bool
	 */
	private static bool $removed_profile_update_handler = false;

	/**
	 * Mailchimp statuses read during this request, keyed by email address.
	 *
	 * @var array<string, string|null>
	 */
	private static array $mailchimp_statuses = [];

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// MC4WC fires it's hook at priority 10 so we need to move quicker.
		add_action( 'init', [ __CLASS__, 'remove_campaign_tracking_cookie' ], 9 );
		add_action( 'wp_loaded', [ __CLASS__, 'remove_account_details_opt_in' ] );
		add_action( 'profile_update', [ __CLASS__, 'skip_profile_update_without_opt_in' ], 99 );
		add_action( 'profile_update', [ __CLASS__, 'restore_profile_update_handler' ], 101 );
		add_filter( 'mailchimp_filter_ecommerce_order', [ __CLASS__, 'keep_subscribed_customer_subscribed' ] );
		add_filter( 'mailchimp_filter_ecommerce_order', [ __CLASS__, 'send_only_complete_address' ], 10, 2 );
	}

	/**
	 * Stop MC4WC from unsubscribing a reader whose stored opt-in is not "subscribed".
	 *
	 * On a profile update in wp-admin or admin-ajax, MC4WC sends the reader's stored opt-in to
	 * Mailchimp, and sends "" (none) or "0" as "transactional". That flag misses subscriptions
	 * made outside MC4WC: readers who sign up through Newspack have none, and Newspack's contact
	 * sync updates their profile from admin-ajax after registration, so MC4WC would unsubscribe
	 * them right after they subscribe. A reader MC4WC once stored as "0" who subscribes again
	 * through Newspack would be unsubscribed the same way.
	 *
	 * MC4WC's own opt-in field in wp-admin sets the flag on purpose, so an update that saves
	 * it still goes through.
	 *
	 * @param int $user_id User ID.
	 */
	public static function skip_profile_update_without_opt_in( $user_id ) {
		if ( ! \class_exists( '\MailChimp_Service' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks whether the field was submitted; core verifies the profile form's nonce.
		if ( isset( $_POST['mailchimp_woocommerce_is_subscribed_radio'] ) ) {
			return;
		}
		if ( in_array( get_user_meta( $user_id, 'mailchimp_woocommerce_is_subscribed', true ), [ '', '0' ], true ) ) {
			if ( remove_action( 'profile_update', [ \MailChimp_Service::instance(), 'handleUserUpdated' ], 100 ) ) {
				self::$removed_profile_update_handler = true;
			}
		}
	}

	/**
	 * Re-hook MC4WC's profile update handler for the next user updated in this request.
	 */
	public static function restore_profile_update_handler() {
		if ( ! self::$removed_profile_update_handler ) {
			return;
		}
		self::$removed_profile_update_handler = false;
		add_action( 'profile_update', [ \MailChimp_Service::instance(), 'handleUserUpdated' ], 100, 2 );
	}

	/**
	 * Stop MC4WC from unsubscribing a customer who places an order.
	 *
	 * MC4WC sends an order's customer to Mailchimp as "transactional" unless its stored opt-in
	 * says "subscribed", and the stored opt-in misses subscriptions made outside MC4WC, such as
	 * Newspack's newsletter signup. For a registered customer MC4WC did not mark as subscribed,
	 * this reads the status from Mailchimp, and keeps a subscribed customer subscribed.
	 *
	 * @param \MailChimp_WooCommerce_Order $order The order MC4WC is about to send.
	 *
	 * @return \MailChimp_WooCommerce_Order
	 */
	public static function keep_subscribed_customer_subscribed( $order ) {
		// A plugin release that renames these leaves its orders as they would be without this filter.
		$customer = method_exists( $order, 'getCustomer' ) ? $order->getCustomer() : null;
		foreach ( [ 'getWordpressUser', 'getOptInStatus', 'setOptInStatus', 'getMailchimpStatus', 'getEmailAddress' ] as $customer_method ) {
			if ( ! $customer || ! method_exists( $customer, $customer_method ) ) {
				return $order;
			}
		}
		if ( ! function_exists( 'mailchimp_get_subscriber_status' ) ) {
			return $order;
		}
		// MC4WC sets the Mailchimp status only when it read it, which only a sync of existing contacts does.
		if ( ! $customer->getWordpressUser() || $customer->getOptInStatus() || null !== $customer->getMailchimpStatus() ) {
			return $order;
		}
		// A sync sends many orders in one request, often several from the same customer. The lookup
		// returns null when it fails or the contact is not in the audience yet, and isset() treats a
		// cached null as missing, so the next order looks again.
		$email = $customer->getEmailAddress();
		if ( ! isset( self::$mailchimp_statuses[ $email ] ) ) {
			self::$mailchimp_statuses[ $email ] = \mailchimp_get_subscriber_status( $email );
		}
		if ( 'subscribed' === self::$mailchimp_statuses[ $email ] ) {
			$customer->setOptInStatus( true );
			update_user_meta( $customer->getWordpressUser()->ID, 'mailchimp_woocommerce_is_subscribed', '1' );
		}
		return $order;
	}

	/**
	 * Stop an order's billing address from blocking Newspack's contact updates.
	 *
	 * Mailchimp writes each part of the address MC4WC sends with an order into the contact's
	 * address. When the result lacks a street, city, or postcode, Mailchimp ignores every later
	 * update to the contact, while still reporting success. Checkouts for digital products often
	 * collect only a postcode and country. A missing street and city come from the reader's earlier
	 * order at the same postcode and country, so a returning reader keeps their real address, and
	 * become "-" otherwise, which Mailchimp accepts as complete. An address without a postcode is
	 * not sent.
	 *
	 * @param \MailChimp_WooCommerce_Order $order    The order MC4WC is about to send.
	 * @param \WC_Order|null               $wc_order The WooCommerce order it was built from.
	 *
	 * @return \MailChimp_WooCommerce_Order
	 */
	public static function send_only_complete_address( $order, $wc_order = null ) {
		$customer = method_exists( $order, 'getCustomer' ) ? $order->getCustomer() : null;
		$address  = $customer && method_exists( $customer, 'getAddress' ) ? $customer->getAddress() : null;
		foreach ( [ 'Address1', 'Address2', 'City', 'PostalCode', 'Country' ] as $field ) {
			if ( ! $address || ! method_exists( $address, 'get' . $field ) || ! method_exists( $address, 'set' . $field ) ) {
				return $order;
			}
		}
		if ( ! $address->getPostalCode() ) {
			if ( method_exists( $customer, 'setAddress' ) && class_exists( '\MailChimp_WooCommerce_Address' ) ) {
				// MC4WC leaves an empty address out of what it sends.
				$customer->setAddress( new \MailChimp_WooCommerce_Address() );
			}
			return $order;
		}
		if ( $address->getAddress1() && $address->getCity() ) {
			return $order;
		}
		$earlier_address = self::get_earlier_address( $customer, $wc_order, $address->getPostalCode(), $address->getCountry() );
		if ( ! $address->getAddress1() ) {
			$address->setAddress1( $earlier_address ? $earlier_address['address_1'] : '-' );
			if ( $earlier_address ) {
				$address->setAddress2( $earlier_address['address_2'] );
			}
		}
		if ( ! $address->getCity() ) {
			$address->setCity( $earlier_address ? $earlier_address['city'] : '-' );
		}
		return $order;
	}

	/**
	 * Get the street and city from the reader's most recent earlier order that has them, if that
	 * order was at the given postcode and country.
	 *
	 * Orders are read rather than the reader's saved billing address, because checkout saves the
	 * new postcode while keeping a street the form did not collect.
	 *
	 * @param \MailChimp_WooCommerce_Customer $customer The order's customer.
	 * @param \WC_Order|null                  $wc_order The order being sent.
	 * @param string                          $postcode The postcode on the order.
	 * @param string                          $country  The country on the order.
	 *
	 * @return array{address_1: string, address_2: string, city: string}|null Null when the reader is a guest, has no earlier street, or has moved since.
	 */
	private static function get_earlier_address( $customer, $wc_order, $postcode, $country ) {
		$user = method_exists( $customer, 'getWordpressUser' ) ? $customer->getWordpressUser() : null;
		if ( ! $user instanceof \WP_User || ! $wc_order instanceof \WC_Order || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$query      = [
			'customer_id' => $user->ID,
			'type'        => 'shop_order',
			'orderby'     => 'date',
			'order'       => 'DESC',
			'limit'       => 50,
		];
		$order_date = $wc_order->get_date_created();
		if ( $order_date ) {
			$query['date_created'] = '<' . $order_date->getTimestamp();
		}
		$normalize = fn( $value ) => strtoupper( preg_replace( '/\s+/', '', (string) $value ) );
		foreach ( wc_get_orders( $query ) as $earlier_order ) {
			if ( $earlier_order->get_id() === $wc_order->get_id() ) {
				continue;
			}
			if ( ! $earlier_order->get_billing_address_1() || ! $earlier_order->get_billing_city() ) {
				continue;
			}
			if ( $normalize( $earlier_order->get_billing_postcode() ) !== $normalize( $postcode ) || $normalize( $earlier_order->get_billing_country() ) !== $normalize( $country ) ) {
				return null;
			}
			return [
				'address_1' => $earlier_order->get_billing_address_1(),
				'address_2' => $earlier_order->get_billing_address_2(),
				'city'      => $earlier_order->get_billing_city(),
			];
		}
		return null;
	}

	/**
	 * Stop MC4WC from unsubscribing readers who save their account details.
	 *
	 * Newspack's My Account does not render MC4WC's opt-in field, but WooCommerce still runs
	 * MC4WC's save handler, which reads the missing field as "not subscribed" and sets the
	 * reader's Mailchimp status to transactional. MC4WC skips that handler when its form
	 * callback is not hooked, so removing the callback turns off both. Readers manage their
	 * subscriptions on the My Account Newsletters page instead.
	 *
	 * Newspack's My Account applies only with Reader Activation on. Without it, WooCommerce's own
	 * account details form renders the field, and the opt-in works there.
	 */
	public static function remove_account_details_opt_in() {
		if ( ! \class_exists( '\MailChimp_WooCommerce_Public' ) || ! Reader_Activation::is_enabled() ) {
			return;
		}

		remove_action( 'woocommerce_edit_account_form', [ \MailChimp_WooCommerce_Public::instance(), 'user_my_account_opt_in' ], 100 );
	}

	/**
	 * Disable the cookie added by the Mailchimp for WooCommerce (MC4WC) plugin when on
	 * a Newspack site because the cookie causes performance issues when logged out.
	 */
	public static function remove_campaign_tracking_cookie() {
		// Don't do anything unless we know the MC4WC plugin is active.
		if ( ! \class_exists( '\MailChimp_Service' ) ) {
			return;
		}

		// Remove entirely the hook that sets the cookie.
		$service = \MailChimp_Service::instance();
		remove_action( 'init', [ $service, 'handleCampaignTracking' ] );
	}
}
Mailchimp_For_WooCommerce::init();
