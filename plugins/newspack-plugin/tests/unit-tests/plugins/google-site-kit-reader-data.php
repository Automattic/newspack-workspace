<?php
/**
 * Tests for the reader data flags sent as GA4 custom parameters.
 *
 * @package Newspack\Tests
 */

use Newspack\GoogleSiteKit;
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
	 * Load the WooCommerce Subscriptions mocks, which the subscriber test builds a
	 * subscription from.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Sign in a reader for each test.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		$this->reader_id        = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->reader_id );
	}

	/**
	 * Sign the reader out and empty the mock subscription store.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		wp_set_current_user( 0 );
		parent::tear_down();
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
}
