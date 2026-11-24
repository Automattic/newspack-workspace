<?php
/**
 * Tests for the reader data flags sent as GA4 custom parameters.
 *
 * @package Newspack\Tests
 */

use Newspack\Active_Subscriptions_Reader_Data;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\GoogleSiteKit;
use Newspack\Reader_Activation;
use Newspack\Reader_Data;

/**
 * The `is_donor`, `is_newsletter_subscriber`, and `is_subscriber` parameters
 * follow the reader's current state, so a reader who stops donating, leaves
 * every newsletter, or loses their last subscription is reported as "no" from
 * then on.
 *
 * @group GoogleSiteKit_Reader_Data
 */
class Newspack_Test_GoogleSiteKit_Reader_Data extends WP_UnitTestCase {

	/**
	 * The signed-in reader.
	 *
	 * @var int
	 */
	private $reader_id;

	/**
	 * Load the WooCommerce Subscriptions mocks, which the subscriber tests build
	 * their subscriptions from.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wcs-gifting-mock.php';
	}

	/**
	 * Sign in a reader for each test.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database   = [];
		\WCS_Gifting::$recipients = [];
		Group_Subscription::reset_cache();
		$this->reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->reader_id );
	}

	/**
	 * Sign the reader out and empty the mock subscription store and the gifting
	 * stub's recipient map, which is static and shared across test classes.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database   = [];
		\WCS_Gifting::$recipients = [];
		Group_Subscription::reset_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a verified reader, which is who a group seats.
	 *
	 * @return int User ID.
	 */
	private function create_reader(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Reader_Activation::set_reader_verified( $user_id );
		return $user_id;
	}

	/**
	 * Create a subscription with one line item, because the reader data item reads
	 * its products from the line items.
	 *
	 * @param int $customer_id Who pays for it.
	 *
	 * @return \WC_Subscription
	 */
	private function create_subscription( int $customer_id ) {
		return wcs_create_subscription(
			[
				'customer_id' => $customer_id,
				'status'      => 'active',
				'products'    => [ 123 ],
				'items'       => [ new \WC_Order_Item_Product( [ 'product_id' => 123 ] ) ],
			]
		);
	}

	/**
	 * Give a subscription to a recipient, the way WooCommerce Subscriptions Gifting
	 * lists a gift under its recipient and not its buyer.
	 *
	 * @param \WC_Subscription $subscription Subscription to gift.
	 * @param int              $recipient_id Recipient.
	 */
	private function gift_to( $subscription, int $recipient_id ) {
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;
		add_filter(
			'wcs_get_users_subscriptions',
			function ( $subscriptions, $user_id ) use ( $subscription, $recipient_id ) {
				if ( (int) $user_id === $recipient_id ) {
					$subscriptions[ $subscription->get_id() ] = $subscription;
				}
				return $subscriptions;
			},
			10,
			2
		);
	}

	/**
	 * Sign in as a reader and recompute their `active_subscriptions` item with
	 * `refresh()`, as a stale reader's page view would.
	 *
	 * @param int $user_id User ID.
	 */
	private function view_page_as( int $user_id ) {
		Group_Subscription::reset_cache();
		wp_set_current_user( $user_id );
		Active_Subscriptions_Reader_Data::refresh( $user_id );
	}

	/**
	 * Cancelling a recurring donation makes the reader a former donor, who is
	 * no longer reported as a donor.
	 */
	public function test_former_donor_is_not_reported_as_donor() {
		Reader_Data::set_is_donor( time(), [ 'user_id' => $this->reader_id ] );
		$this->assertSame( 'yes', GoogleSiteKit::get_custom_event_parameters()['is_donor'] );

		Reader_Data::set_is_former_donor( time(), [ 'user_id' => $this->reader_id ] );
		$this->assertSame( 'no', GoogleSiteKit::get_custom_event_parameters()['is_donor'] );
	}

	/**
	 * A reader who leaves their last newsletter list is no longer reported as a
	 * newsletter subscriber.
	 */
	public function test_reader_who_left_every_newsletter_is_not_reported_as_subscriber() {
		Reader_Data::update_newsletter_subscribed_lists(
			time(),
			[
				'user_id' => $this->reader_id,
				'lists'   => [ 'list-1' ],
			]
		);
		$this->assertSame( 'yes', GoogleSiteKit::get_custom_event_parameters()['is_newsletter_subscriber'] );

		Reader_Data::update_newsletter_subscribed_lists(
			time(),
			[
				'user_id'       => $this->reader_id,
				'lists_removed' => [ 'list-1' ],
			]
		);
		$this->assertSame( 'no', GoogleSiteKit::get_custom_event_parameters()['is_newsletter_subscriber'] );
	}

	/**
	 * A reader whose only subscription is cancelled is no longer reported as a
	 * subscriber.
	 */
	public function test_former_subscriber_is_not_reported_as_subscriber() {
		$this->assertSame( 'no', GoogleSiteKit::get_custom_event_parameters()['is_subscriber'], 'A reader who never subscribed should not be reported as a subscriber.' );

		// The event recomputes the list from the reader's subscriptions, so the
		// reader needs a real one. A mock subscription fires no hooks on
		// `update_status()`, so the test sends the event itself.
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $this->reader_id,
				'status'      => 'active',
				'products'    => [ 123 ],
				'items'       => [ new \WC_Order_Item_Product( [ 'product_id' => 123 ] ) ],
			]
		);
		$event        = [
			'user_id'         => $this->reader_id,
			'subscription_id' => $subscription->get_id(),
			'product_ids'     => [ 123 ],
			'status_after'    => 'active',
		];
		Reader_Data::update_active_subscriptions( time(), $event );
		$this->assertSame( 'yes', GoogleSiteKit::get_custom_event_parameters()['is_subscriber'] );

		$subscription->update_status( 'cancelled' );
		$event['status_after'] = 'cancelled';
		Reader_Data::update_active_subscriptions( time(), $event );
		$this->assertSame( 'no', GoogleSiteKit::get_custom_event_parameters()['is_subscriber'] );
	}

	/**
	 * A gift's recipient holds its product, so they are reported as a subscriber
	 * even though they are not the subscription's customer.
	 */
	public function test_gift_recipient_is_reported_as_subscriber() {
		$buyer_id     = $this->create_reader();
		$recipient_id = $this->create_reader();
		$this->gift_to( $this->create_subscription( $buyer_id ), $recipient_id );

		$this->view_page_as( $recipient_id );

		$this->assertSame( 'yes', GoogleSiteKit::get_custom_event_parameters()['is_subscriber'] );
	}

	/**
	 * A seat in an enabled group subscription is held through the group, so a member
	 * is reported as a subscriber too.
	 */
	public function test_group_member_is_reported_as_subscriber() {
		$owner_id     = $this->create_reader();
		$member_id    = $this->create_reader();
		$subscription = $this->create_subscription( $owner_id );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );

		$this->view_page_as( $member_id );

		$this->assertSame( 'yes', GoogleSiteKit::get_custom_event_parameters()['is_subscriber'] );
	}

	/**
	 * A reader whose only subscription is a gift they bought pays for it but holds
	 * nothing, so they are not reported as a subscriber.
	 */
	public function test_gift_only_buyer_is_not_reported_as_subscriber() {
		$buyer_id     = $this->create_reader();
		$recipient_id = $this->create_reader();
		$this->gift_to( $this->create_subscription( $buyer_id ), $recipient_id );

		$this->view_page_as( $buyer_id );

		$this->assertSame( 'no', GoogleSiteKit::get_custom_event_parameters()['is_subscriber'] );
	}
}
