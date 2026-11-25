<?php
/**
 * Tests for which readers Available_Deals_Bridge recomputes.
 *
 * @package Newspack\Tests
 */

use Automattic\WooCommerce\DynamicPricing\Subscriptions\Available_Deals_Query;
use Newspack\Available_Deals_Bridge;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
require_once dirname( __DIR__, 2 ) . '/mocks/wcs-gifting-mock.php';

/**
 * Runs in its own process: the engine stand-in can't be undeclared, and would
 * switch the bridge on for every later test.
 *
 * @group Dynamic_Pricing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Newspack_Test_Available_Deals_Bridge extends WP_UnitTestCase {

	/**
	 * A subscription event names its buyer. A gift's recipient holds it, so their
	 * deals are recomputed too; a group's members own nothing, so theirs are not.
	 */
	public function test_subscription_event_refreshes_the_gift_recipient_only() {
		global $subscriptions_database;
		$subscriptions_database = [];
		require_once dirname( __DIR__, 2 ) . '/mocks/available-deals-query-mock.php';
		[ $buyer_id, $recipient_id, $member_id ] = self::factory()->user->create_many( 3 );
		$gift = wcs_create_subscription(
			[
				'customer_id' => $buyer_id,
				'meta'        => [ Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled' => 'yes' ],
			]
		);
		\WCS_Gifting::$recipients = [ $gift->get_id() => $recipient_id ];
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $gift->get_id() );
		$data = [
			'user_id'         => $buyer_id,
			'subscription_id' => $gift->get_id(),
		];

		Available_Deals_Bridge::refresh_on_event( time(), $data );

		$this->assertSame( [ $buyer_id, $recipient_id ], Available_Deals_Query::$customer_ids );
	}

	/**
	 * A gift changing hands fires no subscription event, so the readers it moved
	 * between are recomputed when the holder change is announced. Group members
	 * named alongside them own nothing, so theirs are not.
	 */
	public function test_holder_change_refreshes_the_readers_but_not_group_members() {
		global $subscriptions_database;
		$subscriptions_database = [];
		require_once dirname( __DIR__, 2 ) . '/mocks/available-deals-query-mock.php';
		[ $buyer_id, $old_recipient_id, $new_recipient_id, $member_id ] = self::factory()->user->create_many( 4 );
		$gift = wcs_create_subscription( [ 'customer_id' => $buyer_id ] );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $gift->get_id() );

		do_action( 'newspack_subscription_readers_changed', [ $new_recipient_id, $old_recipient_id, $member_id ], $gift );

		$this->assertSame( [ $new_recipient_id, $old_recipient_id ], Available_Deals_Query::$customer_ids );
	}
}
