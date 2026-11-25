<?php
/**
 * Tests the gift-holder helpers on the WooCommerce Subscriptions Gifting integration.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events\Connectors\Contact_Sync_Connector;
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
	 * Each newspack_subscription_readers_changed call, as [ user IDs, subscription ID ].
	 *
	 * @var array[]
	 */
	private $announced = [];

	/**
	 * Reset the gift and subscription stores so one test's data never leaks into the next.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database  = [];
		WCS_Gifting::$recipients = [];
		$this->announced         = [];
		add_action(
			'newspack_subscription_readers_changed',
			function ( $user_ids, $subscription ) {
				$this->announced[] = [ $user_ids, $subscription->get_id() ];
			},
			10,
			2
		);
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
	 * Recipient changes WCS reports, as [ previous recipient, new recipient, readers announced ].
	 *
	 * @return array[]
	 */
	public function data_recipient_changes() {
		return [
			'reassigned'      => [ 'old', 'new', [ 'new', 'old' ] ],
			'first recipient' => [ '', 'new', [ 'new', 'buyer' ] ],
			'unchanged'       => [ 'old', 'old', [] ],
		];
	}

	/**
	 * A change of recipient announces the new holder and the previous one, who is the
	 * buyer when the gift had no recipient. WCS fires its hook on every save of a gift,
	 * so an unchanged recipient announces nothing.
	 *
	 * @dataProvider data_recipient_changes
	 *
	 * @param string   $old      Previous recipient's key, '' for none.
	 * @param string   $new      New recipient's key.
	 * @param string[] $expected Keys of the readers announced.
	 */
	public function test_recipient_change_announces_both_holders( $old, $new, $expected ) {
		$ids          = [
			''      => 0,
			'buyer' => $this->factory()->user->create(),
			'old'   => $this->factory()->user->create(),
			'new'   => $this->factory()->user->create(),
		];
		$subscription = $this->create_gift( $ids['buyer'], $ids[ $new ] );

		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $ids[ $new ], $ids[ $old ] );

		$announced = array_map( fn( $key ) => $ids[ $key ], $expected );
		$this->assertSame( $announced ? [ [ $announced, $subscription->get_id() ] ] : [], $this->announced );
	}

	/**
	 * Ways WCS removes a gift's recipient without its recipient-changed hook, as
	 * [ how, whether the recipient is removed ].
	 *
	 * @return array[]
	 */
	public function data_silent_recipient_clears() {
		return [
			'cleared on the edit screen' => [ 'edit', true ],
			'kept on the edit screen'    => [ 'edit', false ],
			'recipient deleted'          => [ 'recipient', true ],
			'buyer deleted'              => [ 'buyer', true ],
		];
	}

	/**
	 * WCS removes a gift's recipient with no hook when an admin clears it on the edit
	 * screen and when either reader is deleted, so the previous recipient and the buyer
	 * are announced. A gift without a recipient account has nothing to announce.
	 *
	 * @dataProvider data_silent_recipient_clears
	 *
	 * @param string $how     The edit screen, or which reader is deleted.
	 * @param bool   $removed Whether WCS removes the recipient.
	 */
	public function test_silent_recipient_clear_announces_previous_recipient_and_buyer( $how, $removed ) {
		$buyer_id     = $this->factory()->user->create();
		$recipient_id = $this->factory()->user->create();
		$gift         = $this->create_gift( $buyer_id, $recipient_id );
		$this->create_gift( $buyer_id, 0 );
		// Stands in for WCS's own save (priority 50) or trash_users_subscriptions() (priority 10).
		$wcs_clear = function () use ( $gift, $removed ) {
			if ( $removed ) {
				unset( WCS_Gifting::$recipients[ $gift->get_id() ] );
			}
		};

		if ( 'edit' === $how ) {
			add_action( 'woocommerce_process_shop_order_meta', $wcs_clear, 50 );
			do_action( 'woocommerce_process_shop_order_meta', $gift->get_id(), $gift );
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			// The ESP snapshot reads the gift as an order, which the subscription mock can't stand in for.
			remove_action( 'delete_user', [ Contact_Sync_Connector::class, 'store_user_data_before_deletion' ], 5 );
			add_action( 'delete_user', $wcs_clear, 10 );
			wp_delete_user( 'buyer' === $how ? $buyer_id : $recipient_id );
		}

		$this->assertSame( $removed ? [ [ [ $buyer_id, $recipient_id ], $gift->get_id() ] ] : [], $this->announced );
	}
}
