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
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// MC4WC fires it's hook at priority 10 so we need to move quicker.
		add_action( 'init', [ __CLASS__, 'remove_campaign_tracking_cookie' ], 9 );
		add_action( 'wp_loaded', [ __CLASS__, 'remove_account_details_opt_in' ] );
	}

	/**
	 * Stop MC4WC from unsubscribing readers who save their account details.
	 *
	 * Newspack's My Account does not render MC4WC's opt-in field, but WooCommerce still runs
	 * MC4WC's save handler, which reads the missing field as "not subscribed" and sets the
	 * reader's Mailchimp status to transactional. MC4WC skips that handler when its form
	 * callback is not hooked, so removing the callback turns off both. Readers manage their
	 * subscriptions on the My Account Newsletters page instead.
	 */
	public static function remove_account_details_opt_in() {
		if ( ! \class_exists( '\MailChimp_WooCommerce_Public' ) || ! \class_exists( __NAMESPACE__ . '\WooCommerce_My_Account' ) ) {
			return;
		}

		// The core WooCommerce My Account renders the field, so the opt-in works there.
		if ( version_compare( WooCommerce_My_Account::get_version(), '1.0.0', '<' ) ) {
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
