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
	 * Hook the opt-in form callback the way the plugin does.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'woocommerce_edit_account_form', [ MailChimp_WooCommerce_Public::instance(), 'user_my_account_opt_in' ], 100 );
	}

	/**
	 * Clean up the hook and the version override.
	 */
	public function tear_down() {
		remove_action( 'woocommerce_edit_account_form', [ MailChimp_WooCommerce_Public::instance(), 'user_my_account_opt_in' ], 100 );
		remove_all_filters( 'newspack_my_account_version' );
		parent::tear_down();
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
}
