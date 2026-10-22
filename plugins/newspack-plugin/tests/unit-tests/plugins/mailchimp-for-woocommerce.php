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
		( new ReflectionProperty( Mailchimp_For_WooCommerce::class, 'mailchimp_statuses' ) )->setValue( null, [] );
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
	 * form callback is not hooked. Newspack's My Account, which applies with Reader Activation on,
	 * never renders that form, so the callback goes, and with it the save that marked every
	 * reader "not subscribed". Without Reader Activation, WooCommerce's own form renders the
	 * plugin's radio, so the opt-in stays.
	 */
	public function test_opt_in_is_unhooked_only_with_reader_activation() {
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
		Mailchimp_For_WooCommerce::remove_account_details_opt_in();
		$this->assertSame( 100, has_action( 'woocommerce_edit_account_form', [ MailChimp_WooCommerce_Public::instance(), 'user_my_account_opt_in' ] ) );

		remove_filter( 'newspack_reader_activation_enabled', '__return_false' );
		Mailchimp_For_WooCommerce::remove_account_details_opt_in();
		$this->assertFalse( has_action( 'woocommerce_edit_account_form', [ MailChimp_WooCommerce_Public::instance(), 'user_my_account_opt_in' ] ) );
	}

	/**
	 * The plugin sends a reader with no stored opt-in, or a stored "0", as "transactional" on any
	 * profile update in wp-admin or admin-ajax, such as Newspack's contact sync after registration.
	 * A reader who subscribed through Newspack has neither, so the update would unsubscribe them.
	 * The handler is back in place for the next user, so the opted-in customer's update still runs.
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
	 * The integration re-adds the plugin's handler only after removing it, so a site that
	 * unhooked the handler keeps it unhooked.
	 */
	public function test_profile_update_leaves_a_handler_the_site_removed() {
		$reader = $this->create_customer( 'unhooked@example.test', 'subscribed' );
		remove_action( 'profile_update', [ MailChimp_Service::instance(), 'handleUserUpdated' ], 100 );

		do_action( 'profile_update', $reader->ID, $reader );

		$this->assertFalse( has_action( 'profile_update', [ MailChimp_Service::instance(), 'handleUserUpdated' ] ) );
	}

	/**
	 * A profile update can run inside another, for example when a callback updates a second user.
	 * The inner skip finds the handler already removed, and the plugin's handler is still back in
	 * place once both updates finish. The nested update runs at priority 100, the one point where
	 * the outer update has removed the handler and not yet restored it.
	 */
	public function test_profile_update_restores_the_handler_after_a_nested_update() {
		$reader       = $this->create_customer( 'outer@example.test', 'subscribed' );
		$other_reader = $this->create_customer( 'inner@example.test', 'subscribed' );
		$update_other_reader = function () use ( $other_reader, &$update_other_reader ) {
			remove_action( 'profile_update', $update_other_reader, 100 );
			do_action( 'profile_update', $other_reader->ID, $other_reader );
		};
		add_action( 'profile_update', $update_other_reader, 100 );

		do_action( 'profile_update', $reader->ID, $reader );

		$this->assertSame( [], MailChimp_Service::instance()->updated_users );
		$this->assertSame( 100, has_action( 'profile_update', [ MailChimp_Service::instance(), 'handleUserUpdated' ] ) );
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
	 * The lookup runs once per customer in a request, and only for a registered customer the
	 * plugin neither opted in nor looked up: it sends opted-in customers as subscribed, checks
	 * Mailchimp itself for guests, and reads the status first in a sync of existing contacts.
	 * A customer not in the audience yet is looked up again on the next order.
	 */
	public function test_order_looks_up_each_customer_once_and_only_when_needed() {
		$opted_in_customer = $this->create_customer( 'opted-in@example.test', 'subscribed', '1' );
		$synced_customer   = $this->create_customer( 'synced@example.test', 'transactional', '' );
		$repeat_customer   = $this->create_customer( 'repeat@example.test', 'transactional', '' );
		$unlisted_customer = $this->create_customer( 'unlisted@example.test', null, '' );

		$this->filter_order( ( new MailChimp_WooCommerce_Customer( $opted_in_customer, $opted_in_customer->user_email ) )->setOptInStatus( true ) );
		$this->filter_order( new MailChimp_WooCommerce_Customer( null, 'guest@example.test' ) );
		$this->filter_order( ( new MailChimp_WooCommerce_Customer( $synced_customer, $synced_customer->user_email ) )->setMailchimpStatus( 'transactional' ) );
		$this->filter_order( new MailChimp_WooCommerce_Customer( $repeat_customer, $repeat_customer->user_email ) );
		$this->filter_order( new MailChimp_WooCommerce_Customer( $repeat_customer, $repeat_customer->user_email ) );
		$this->filter_order( new MailChimp_WooCommerce_Customer( $unlisted_customer, $unlisted_customer->user_email ) );
		$this->filter_order( new MailChimp_WooCommerce_Customer( $unlisted_customer, $unlisted_customer->user_email ) );

		$this->assertSame( [ 'repeat@example.test', 'unlisted@example.test', 'unlisted@example.test' ], $GLOBALS['newspack_test_mailchimp_status_lookups'] );
	}

	/**
	 * Billing addresses as checkout collects them, the billing address on the reader's earlier
	 * order, and the address the order should carry.
	 */
	public function partial_address_provider() {
		$earlier_order = [
			'billing_address_1' => '1 Main St',
			'billing_address_2' => 'Flat 4',
			'billing_city'      => 'London',
			'billing_postcode'  => 'SW1A1AA',
			'billing_country'   => 'GB',
		];
		return [
			'postcode only, no earlier order'         => [
				[
					'postal_code' => '10036',
					'country'     => 'US',
				],
				null,
				[
					'address1'    => '-',
					'city'        => '-',
					'postal_code' => '10036',
					'country'     => 'US',
				],
			],
			'postcode only, earlier order there'      => [
				[
					'postal_code' => 'sw1a 1aa',
					'country'     => 'GB',
				],
				$earlier_order,
				[
					'address1'    => '1 Main St',
					'address2'    => 'Flat 4',
					'city'        => 'London',
					'postal_code' => 'sw1a 1aa',
					'country'     => 'GB',
				],
			],
			'postcode only, earlier order elsewhere'  => [
				[
					'postal_code' => 'E1 6AN',
					'country'     => 'GB',
				],
				$earlier_order,
				[
					'address1'    => '-',
					'city'        => '-',
					'postal_code' => 'E1 6AN',
					'country'     => 'GB',
				],
			],
			'same postcode, another country'          => [
				[
					'postal_code' => 'SW1A1AA',
					'country'     => 'US',
				],
				$earlier_order,
				[
					'address1'    => '-',
					'city'        => '-',
					'postal_code' => 'SW1A1AA',
					'country'     => 'US',
				],
			],
			'street but no city, earlier order there' => [
				[
					'address1'    => '2 Elm St',
					'postal_code' => 'SW1A1AA',
					'country'     => 'GB',
				],
				$earlier_order,
				[
					'address1'    => '2 Elm St',
					'city'        => 'London',
					'postal_code' => 'SW1A1AA',
					'country'     => 'GB',
				],
			],
			'street and city, no postcode'            => [
				[
					'name'     => 'Ann Reader',
					'phone'    => '5555550100',
					'address1' => '1 Main St',
					'city'     => 'Dublin',
					'country'  => 'IE',
				],
				$earlier_order,
				[],
			],
			'complete'                                => [
				[
					'address1'    => '2 Elm St',
					'city'        => 'London',
					'postal_code' => 'SW1A1AA',
					'country'     => 'GB',
				],
				$earlier_order,
				[
					'address1'    => '2 Elm St',
					'city'        => 'London',
					'postal_code' => 'SW1A1AA',
					'country'     => 'GB',
				],
			],
		];
	}

	/**
	 * Mailchimp writes the address parts sent with an order into the contact's address, and
	 * ignores later updates to a contact whose address lacks a street, city, or postcode.
	 *
	 * @dataProvider partial_address_provider
	 *
	 * @param array      $billing_address The address checkout collected.
	 * @param array|null $earlier_billing The billing address on the reader's earlier order, if any.
	 * @param array      $sent_address    The address the order should carry.
	 */
	public function test_order_carries_only_an_address_mailchimp_accepts( $billing_address, $earlier_billing, $sent_address ) {
		global $orders_database;
		$orders_database = [];
		$reader          = $this->create_customer( 'reader@example.test', 'subscribed' );
		if ( $earlier_billing ) {
			wc_create_order(
				array_merge(
					$earlier_billing,
					[
						'customer_id'  => $reader->ID,
						'status'       => 'processing',
						'date_created' => '2026-01-01 00:00:00',
					]
				) 
			);
		}
		$wc_order      = wc_create_order(
			[
				'customer_id'  => $reader->ID,
				'status'       => 'processing',
				'date_created' => '2026-06-01 00:00:00',
			]
		);
		$empty_address = ( new MailChimp_WooCommerce_Address() )->fields;
		$customer      = new MailChimp_WooCommerce_Customer( $reader, $reader->user_email );
		$customer->getAddress()->fields = array_merge( $empty_address, $billing_address );

		$order = apply_filters( 'mailchimp_filter_ecommerce_order', new MailChimp_WooCommerce_Order( $customer ), $wc_order );

		$this->assertSame( array_merge( $empty_address, $sent_address ), $order->getCustomer()->getAddress()->fields );
	}

	/**
	 * A plugin version without the customer methods the integration reads gets its orders back
	 * unchanged, so its order sync keeps running as it would without Newspack.
	 */
	public function test_order_is_left_alone_when_the_plugin_lacks_the_customer_methods() {
		$customer         = $this->create_customer( 'renamed@example.test', 'subscribed', '' );
		$renamed_customer = new class( $customer ) {
			/**
			 * The customer's WordPress user.
			 *
			 * @var WP_User
			 */
			public $user;

			/**
			 * Constructor.
			 *
			 * @param WP_User $user WordPress user.
			 */
			public function __construct( $user ) {
				$this->user = $user;
			}

			/**
			 * Get the WordPress user.
			 */
			public function getWordpressUser() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The plugin's method name.
				return $this->user;
			}
		};
		$order = new MailChimp_WooCommerce_Order( $renamed_customer );

		$this->assertSame( $order, apply_filters( 'mailchimp_filter_ecommerce_order', $order, null ) );
		$this->assertSame( [], $GLOBALS['newspack_test_mailchimp_status_lookups'] );
	}
}
