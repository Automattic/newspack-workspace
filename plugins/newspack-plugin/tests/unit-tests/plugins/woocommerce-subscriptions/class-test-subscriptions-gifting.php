<?php
/**
 * Tests the gift-holder helpers on the WooCommerce Subscriptions Gifting integration.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Connection;
use Newspack\WooCommerce_Subscriptions_Gifting;

require_once NEWSPACK_ABSPATH . 'tests/mocks/wc-mocks.php';
require_once NEWSPACK_ABSPATH . 'tests/mocks/wcs-gifting-mock.php';
require_once NEWSPACK_ABSPATH . 'tests/mocks/wcsg-recipient-addresses-mock.php';

/**
 * Test who holds a gifted subscription: the recipient, not the purchaser.
 *
 * The WCS_Gifting stub is loaded for the whole suite, so the helpers' absent-class
 * path (class_exists guard) is guarded in code rather than exercised here.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscriptions_Gifting extends WP_UnitTestCase {

	/**
	 * Each newspack_subscription_gift_recipient_cleared call, as
	 * [ subscription ID, previous recipient ID ].
	 *
	 * @var array[]
	 */
	private $cleared_recipients = [];

	/**
	 * Reset the gift and subscription stores so one test's data never leaks into the next.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database   = [];
		WCS_Gifting::$recipients  = [];
		$this->cleared_recipients = [];
		// The test case restores hooks after each test, so this never outlives it.
		add_action( 'newspack_subscription_gift_recipient_cleared', [ $this, 'record_cleared_recipient' ], 10, 2 );
	}

	/**
	 * Reset the stores after the last test too.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database  = [];
		WCS_Gifting::$recipients = [];
		parent::tear_down();
	}

	/**
	 * List each gift under its recipient, as Gifting does.
	 *
	 * @param array $subscriptions Subscriptions keyed by ID.
	 * @param int   $user_id       User ID.
	 *
	 * @return array
	 */
	public function list_gifts_for_recipient( $subscriptions, $user_id ): array {
		foreach ( WCS_Gifting::$recipients as $subscription_id => $recipient_id ) {
			if ( (int) $recipient_id === (int) $user_id ) {
				$subscriptions[ $subscription_id ] = wcs_get_subscription( $subscription_id );
			}
		}
		return $subscriptions;
	}

	/**
	 * Create an active subscription the purchaser gave to the recipient.
	 *
	 * @param int $purchaser_id Purchaser user ID.
	 * @param int $recipient_id Recipient user ID.
	 *
	 * @return \WC_Subscription
	 */
	private function create_gift( int $purchaser_id, int $recipient_id ): \WC_Subscription {
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $purchaser_id,
				'status'      => 'active',
			]
		);
		WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;
		add_filter( 'wcs_get_users_subscriptions', [ $this, 'list_gifts_for_recipient' ], 10, 2 );
		return $subscription;
	}

	/**
	 * Load the billing edit-address endpoint, where Gifting filters the
	 * subscription list.
	 */
	private function view_billing_address_page(): void {
		add_filter( 'wcs_get_users_subscriptions', 'WCSG_Recipient_Addresses::get_users_subscriptions', 100, 2 );
		set_query_var( 'edit-address', 'billing' );
	}

	/**
	 * Gifting hides a recipient's gifts from the billing address form so the
	 * form can't change the purchaser's billing details. The recipient still
	 * holds the gift on that page, so reader data refreshed there keeps it.
	 */
	public function test_recipient_holds_gift_on_billing_address_page() {
		$purchaser_id = $this->factory()->user->create();
		$recipient_id = $this->factory()->user->create();
		$subscription = $this->create_gift( $purchaser_id, $recipient_id );

		$this->view_billing_address_page();

		$this->assertSame( [ $subscription->get_id() ], WooCommerce_Connection::get_active_subscriptions_for_user( $recipient_id ) );
	}

	/**
	 * Looking up what the recipient holds leaves Gifting's own billing-page
	 * list as it was, so the address form still can't reach the gift.
	 */
	public function test_billing_address_form_still_hides_the_gift_after_a_lookup() {
		$purchaser_id = $this->factory()->user->create();
		$recipient_id = $this->factory()->user->create();
		$this->create_gift( $purchaser_id, $recipient_id );
		$this->view_billing_address_page();

		WooCommerce_Connection::get_active_subscriptions_for_user( $recipient_id );

		$this->assertSame( [], wcs_get_users_subscriptions( $recipient_id ) );
	}

	/**
	 * A gifted subscription is held by its recipient, even though the purchaser
	 * is still the subscription's customer.
	 */
	public function test_get_recipient_id_returns_recipient_for_gift() {
		$purchaser_id  = $this->factory()->user->create();
		$recipient_id  = $this->factory()->user->create();
		$subscription  = wcs_create_subscription( [ 'customer_id' => $purchaser_id ] );
		WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;

		$this->assertSame( $recipient_id, WooCommerce_Subscriptions_Gifting::get_recipient_id( $subscription ), 'A gift names its recipient.' );
		$this->assertSame( $recipient_id, WooCommerce_Subscriptions_Gifting::get_holder_id( $subscription ), 'The recipient holds a gift.' );
	}

	/**
	 * A subscription that was not a gift has no recipient, so its purchaser holds it.
	 */
	public function test_get_recipient_id_returns_zero_for_non_gift() {
		$purchaser_id = $this->factory()->user->create();
		$subscription = wcs_create_subscription( [ 'customer_id' => $purchaser_id ] );

		$this->assertSame( 0, WooCommerce_Subscriptions_Gifting::get_recipient_id( $subscription ), 'A plain subscription has no recipient.' );
		$this->assertSame( $purchaser_id, WooCommerce_Subscriptions_Gifting::get_holder_id( $subscription ), 'The purchaser holds a plain subscription.' );
	}

	/**
	 * A gift whose recipient account no longer exists falls back to the purchaser,
	 * so the subscription never ends up with no holder.
	 */
	public function test_get_holder_id_falls_back_to_customer_when_recipient_missing() {
		$purchaser_id = $this->factory()->user->create();
		$subscription = wcs_create_subscription( [ 'customer_id' => $purchaser_id ] );
		WCS_Gifting::$recipients[ $subscription->get_id() ] = 999999;

		$this->assertSame( 0, WooCommerce_Subscriptions_Gifting::get_recipient_id( $subscription ), 'A missing recipient user is not a recipient.' );
		$this->assertSame( $purchaser_id, WooCommerce_Subscriptions_Gifting::get_holder_id( $subscription ), 'The purchaser holds the subscription when the recipient is gone.' );
	}

	/**
	 * Record one announcement that a gift lost its recipient.
	 *
	 * @param \WC_Subscription $subscription          The gift.
	 * @param int              $previous_recipient_id Its recipient before the change.
	 */
	public function record_cleared_recipient( $subscription, $previous_recipient_id ): void {
		$this->assertInstanceOf( \WC_Subscription::class, $subscription, 'The action passes the subscription.' );
		$this->cleared_recipients[] = [ $subscription->get_id(), $previous_recipient_id ];
	}

	/**
	 * Save a subscription's edit screen with WCS's own priority-50 save in place.
	 *
	 * @param \WC_Subscription $subscription The subscription being saved.
	 * @param callable         $wcs_save     Stand-in for WCS's save of the recipient field.
	 */
	private function save_edit_screen( $subscription, callable $wcs_save ): void {
		add_action( 'woocommerce_process_shop_order_meta', $wcs_save, 50 );
		do_action( 'woocommerce_process_shop_order_meta', $subscription->get_id(), $subscription );
		remove_action( 'woocommerce_process_shop_order_meta', $wcs_save, 50 );
	}

	/**
	 * Clearing a gift's recipient on the edit screen fires no hook in WCS, so the
	 * plugin announces it, naming the reader who lost the gift.
	 */
	public function test_admin_clear_announces_the_cleared_recipient() {
		$purchaser_id = $this->factory()->user->create();
		$recipient_id = $this->factory()->user->create();
		$subscription = $this->create_gift( $purchaser_id, $recipient_id );

		$this->save_edit_screen(
			$subscription,
			function () use ( $subscription ) {
				unset( WCS_Gifting::$recipients[ $subscription->get_id() ] );
			}
		);

		$this->assertSame( [ [ $subscription->get_id(), $recipient_id ] ], $this->cleared_recipients );
	}

	/**
	 * Ways an edit-screen save leaves a gift with a recipient account.
	 *
	 * @return array[]
	 */
	public function data_saves_that_keep_a_recipient() {
		return [
			'recipient unchanged'  => [ false ],
			'recipient reassigned' => [ true ],
		];
	}

	/**
	 * A save that keeps or reassigns the recipient announces nothing: WCS fires its
	 * own recipient-changed hook for a reassignment.
	 *
	 * @dataProvider data_saves_that_keep_a_recipient
	 *
	 * @param bool $reassign Whether the save hands the gift to another reader.
	 */
	public function test_admin_save_that_keeps_a_recipient_announces_nothing( $reassign ) {
		$purchaser_id     = $this->factory()->user->create();
		$recipient_id     = $this->factory()->user->create();
		$new_recipient_id = $this->factory()->user->create();
		$subscription     = $this->create_gift( $purchaser_id, $recipient_id );

		$this->save_edit_screen(
			$subscription,
			function () use ( $subscription, $reassign, $new_recipient_id ) {
				if ( $reassign ) {
					WCS_Gifting::$recipients[ $subscription->get_id() ] = $new_recipient_id;
				}
			}
		);

		$this->assertSame( [], $this->cleared_recipients );
	}

	/**
	 * Stand in for WC_Subscriptions_Manager::trash_users_subscriptions(), which runs
	 * on delete_user at priority 10 and removes the recipient from every gift WCS
	 * lists for the user, without firing a recipient-changed hook.
	 *
	 * @param int $user_id ID of the user being deleted.
	 */
	public function clear_recipients_like_wcs( $user_id ): void {
		foreach ( wcs_get_users_subscriptions( (int) $user_id ) as $subscription ) {
			if ( WCS_Gifting::is_gifted_subscription( $subscription ) ) {
				unset( WCS_Gifting::$recipients[ $subscription->get_id() ] );
			}
		}
	}

	/**
	 * Delete a user with WCS's priority-10 clean-up in place.
	 *
	 * @param int $user_id User ID.
	 */
	private function delete_user_like_wcs( int $user_id ): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		// The ESP snapshot taken before a deletion reads a recipient's gift as an
		// order, which the subscription mock can't stand in for. It plays no part
		// in what these tests check.
		remove_action( 'delete_user', [ \Newspack\Data_Events\Connectors\Contact_Sync_Connector::class, 'store_user_data_before_deletion' ], 5 );
		add_action( 'delete_user', [ $this, 'clear_recipients_like_wcs' ], 10 );
		wp_delete_user( $user_id );
	}

	/**
	 * Which reader of a gift is deleted.
	 *
	 * @return array[]
	 */
	public function data_deleted_gift_readers() {
		return [
			'recipient deleted' => [ 'recipient' ],
			'buyer deleted'     => [ 'buyer' ],
		];
	}

	/**
	 * Deleting either reader of a gift makes WCS remove its recipient with no hook,
	 * so the plugin announces the gift's cleared recipient.
	 *
	 * @dataProvider data_deleted_gift_readers
	 *
	 * @param string $deleted Which reader is deleted.
	 */
	public function test_deleting_a_gift_reader_announces_the_cleared_recipient( $deleted ) {
		$purchaser_id = $this->factory()->user->create();
		$recipient_id = $this->factory()->user->create();
		$subscription = $this->create_gift( $purchaser_id, $recipient_id );

		$this->delete_user_like_wcs( 'buyer' === $deleted ? $purchaser_id : $recipient_id );

		$this->assertSame( [ [ $subscription->get_id(), $recipient_id ] ], $this->cleared_recipients );
	}

	/**
	 * Deleting a user announces only the gifts that lost a recipient account: not a
	 * gift with no recipient account, and not another reader's gift.
	 */
	public function test_deleting_a_user_skips_gifts_without_a_recipient_account() {
		$purchaser_id       = $this->factory()->user->create();
		$other_purchaser_id = $this->factory()->user->create();
		$other_recipient_id = $this->factory()->user->create();
		$this->create_gift( $purchaser_id, 0 );
		$this->create_gift( $other_purchaser_id, $other_recipient_id );

		$this->delete_user_like_wcs( $purchaser_id );

		$this->assertSame( [], $this->cleared_recipients );
	}
}
