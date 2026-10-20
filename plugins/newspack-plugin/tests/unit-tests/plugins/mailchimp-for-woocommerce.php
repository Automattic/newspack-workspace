<?php
/**
 * Tests for the Mailchimp for WooCommerce integration.
 *
 * @package Newspack\Tests
 */

use Newspack\Mailchimp_For_WooCommerce;

require_once __DIR__ . '/../../mocks/mailchimp-for-woocommerce-mocks.php';

/**
 * Test the Mailchimp for WooCommerce integration.
 */
class Newspack_Test_Mailchimp_For_WooCommerce extends WP_UnitTestCase {
	/**
	 * Hook the plugin's callbacks the way the plugin does.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'woocommerce_edit_account_form', [ MailChimp_WooCommerce_Public::instance(), 'user_my_account_opt_in' ], 100 );
		add_action( 'profile_update', [ MailChimp_Service::instance(), 'handleUserUpdated' ], 100 );
		MailChimp_Service::instance()->updated_users      = [];
		$GLOBALS['newspack_test_mailchimp_statuses']      = [];
		$GLOBALS['newspack_test_mailchimp_status_lookups'] = [];
	}

	/**
	 * Clean up the hooks and the version override.
	 */
	public function tear_down() {
		remove_action( 'woocommerce_edit_account_form', [ MailChimp_WooCommerce_Public::instance(), 'user_my_account_opt_in' ], 100 );
		remove_action( 'profile_update', [ MailChimp_Service::instance(), 'handleUserUpdated' ], 100 );
		remove_all_filters( 'newspack_my_account_version' );
		parent::tear_down();
	}

	/**
	 * Create a customer whose contact has the given status in the Mailchimp audience.
	 *
	 * @param string      $email            Email address.
	 * @param string|null $mailchimp_status Status in Mailchimp, or null if not in the audience.
	 * @param string|null $stored_opt_in    The plugin's stored opt-in, or null for none.
	 *
	 * @return WP_User
	 */
	private function create_customer( $email, $mailchimp_status, $stored_opt_in = null ) {
		$user = self::factory()->user->create_and_get( [ 'user_email' => $email ] );
		if ( null !== $stored_opt_in ) {
			update_user_meta( $user->ID, 'mailchimp_woocommerce_is_subscribed', $stored_opt_in );
		}
		$GLOBALS['newspack_test_mailchimp_statuses'][ $email ] = $mailchimp_status;
		return $user;
	}

	/**
	 * Run the order filter the plugin applies before sending an order.
	 *
	 * @param MailChimp_WooCommerce_Customer $customer The order's customer.
	 */
	private function filter_order( $customer ) {
		return apply_filters( 'mailchimp_filter_ecommerce_order', new MailChimp_WooCommerce_Order( $customer ), null );
	}

	/**
	 * The plugin saves its opt-in on every account details save, but skips the save when its
	 * form callback is not hooked. Newspack's My Account never renders that form, so the
	 * callback goes, and with it the save that marked every reader "not subscribed".
	 */
	public function test_opt_in_is_unhooked_on_newspack_my_account() {
		Mailchimp_For_WooCommerce::remove_account_details_opt_in();

		$this->assertFalse( has_action( 'woocommerce_edit_account_form', [ MailChimp_WooCommerce_Public::instance(), 'user_my_account_opt_in' ] ) );
	}

	/**
	 * The core WooCommerce My Account (version 0) renders the plugin's radio, so the opt-in stays.
	 */
	public function test_opt_in_stays_on_core_woocommerce_my_account() {
		add_filter( 'newspack_my_account_version', fn() => '0.0.0' );

		Mailchimp_For_WooCommerce::remove_account_details_opt_in();

		$this->assertSame( 100, has_action( 'woocommerce_edit_account_form', [ MailChimp_WooCommerce_Public::instance(), 'user_my_account_opt_in' ] ) );
	}

	/**
	 * The plugin sends a reader with no stored opt-in, or a stored "0", as "transactional" on any
	 * profile update in wp-admin or admin-ajax, such as Newspack's contact sync after registration.
	 * A reader who subscribed through Newspack has neither, so the update would unsubscribe them.
	 */
	public function test_profile_update_skips_the_plugin_without_a_stored_opt_in() {
		$new_reader        = $this->create_customer( 'new-reader@example.test', 'subscribed' );
		$opted_in_customer = $this->create_customer( 'opted-in@example.test', 'subscribed', '1' );

		$opted_out_reader = $this->create_customer( 'opted-out-reader@example.test', 'subscribed', '0' );

		do_action( 'profile_update', $new_reader->ID, $new_reader );
		do_action( 'profile_update', $opted_out_reader->ID, $opted_out_reader );
		do_action( 'profile_update', $opted_in_customer->ID, $opted_in_customer );

		$this->assertSame( [ $opted_in_customer->ID ], MailChimp_Service::instance()->updated_users );
	}

	/**
	 * An admin who sets "not subscribed" in the plugin's own wp-admin profile field means it, so
	 * that update still reaches Mailchimp.
	 */
	public function test_profile_update_runs_the_plugin_when_its_opt_in_field_is_saved() {
		$reader = $this->create_customer( 'reader@example.test', 'subscribed', '0' );
		$_POST['mailchimp_woocommerce_is_subscribed_radio'] = '0';

		do_action( 'profile_update', $reader->ID, $reader );
		unset( $_POST['mailchimp_woocommerce_is_subscribed_radio'] );

		$this->assertSame( [ $reader->ID ], MailChimp_Service::instance()->updated_users );
	}

	/**
	 * The plugin sends an order's customer as "transactional" unless its stored opt-in is "1", so
	 * the integration checks Mailchimp first and keeps a subscribed customer subscribed.
	 */
	public function test_order_keeps_a_subscribed_customer_subscribed() {
		$subscriber            = $this->create_customer( 'subscriber@example.test', 'subscribed', '' );
		$transactional_contact = $this->create_customer( 'transactional@example.test', 'transactional', '' );

		$subscriber_order    = $this->filter_order( new MailChimp_WooCommerce_Customer( $subscriber, $subscriber->user_email ) );
		$transactional_order = $this->filter_order( new MailChimp_WooCommerce_Customer( $transactional_contact, $transactional_contact->user_email ) );

		$this->assertTrue( $subscriber_order->getCustomer()->getOptInStatus() );
		$this->assertSame( '1', get_user_meta( $subscriber->ID, 'mailchimp_woocommerce_is_subscribed', true ) );
		$this->assertFalse( $transactional_order->getCustomer()->getOptInStatus() );
		$this->assertSame( '', get_user_meta( $transactional_contact->ID, 'mailchimp_woocommerce_is_subscribed', true ) );
	}

	/**
	 * Opted-in customers and guests need no lookup: the plugin already sends opted-in customers
	 * as subscribed, and checks Mailchimp itself for guests.
	 */
	public function test_order_looks_up_only_registered_customers_who_are_not_opted_in() {
		$opted_in_customer = $this->create_customer( 'opted-in@example.test', 'subscribed', '1' );

		$this->filter_order( ( new MailChimp_WooCommerce_Customer( $opted_in_customer, $opted_in_customer->user_email ) )->setOptInStatus( true ) );
		$this->filter_order( new MailChimp_WooCommerce_Customer( null, 'guest@example.test' ) );

		$this->assertSame( [], $GLOBALS['newspack_test_mailchimp_status_lookups'] );
	}
}
