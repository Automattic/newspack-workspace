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
 * A subscription change recomputes the deals of the readers who hold it.
 *
 * Each test runs in its own process: the engine stand-in can't be undeclared,
 * and would switch the bridge on for every later test.
 *
 * @group Dynamic_Pricing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Newspack_Test_Available_Deals_Bridge extends WP_UnitTestCase {

	/**
	 * Load the engine stand-in and clear its record. The mock subscription store
	 * is set here because an isolated process loads the mocks inside a function,
	 * where their file-scope globals stay local.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		require_once dirname( __DIR__, 2 ) . '/mocks/available-deals-query-mock.php';
		Available_Deals_Query::$customer_ids = [];
		\WCS_Gifting::$recipients            = [];
	}

	/**
	 * A gift's status change names its buyer. The recipient holds the gift, so
	 * their deals are recomputed too.
	 */
	public function test_subscription_event_refreshes_the_gift_recipient() {
		$buyer_id     = self::factory()->user->create();
		$recipient_id = self::factory()->user->create();
		$gift         = wcs_create_subscription(
			[
				'customer_id' => $buyer_id,
				'status'      => 'cancelled',
			]
		);
		\WCS_Gifting::$recipients[ $gift->get_id() ] = $recipient_id;

		Available_Deals_Bridge::refresh_on_event(
			time(),
			[
				'user_id'         => $buyer_id,
				'subscription_id' => $gift->get_id(),
			]
		);

		$this->assertSame( [ $buyer_id, $recipient_id ], Available_Deals_Query::$customer_ids );
	}

	/**
	 * Deals follow the reader's own subscriptions, so a group's members are left
	 * alone: a group can have hundreds, and none of them owns the subscription.
	 */
	public function test_subscription_event_leaves_group_members_alone() {
		$owner_id  = self::factory()->user->create();
		$member_id = self::factory()->user->create();
		$group     = wcs_create_subscription(
			[
				'customer_id' => $owner_id,
				'status'      => 'cancelled',
				'meta'        => [ Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled' => 'yes' ],
			]
		);
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group->get_id() );

		Available_Deals_Bridge::refresh_on_event(
			time(),
			[
				'user_id'         => $owner_id,
				'subscription_id' => $group->get_id(),
			]
		);

		$this->assertSame( [ $owner_id ], Available_Deals_Query::$customer_ids );
	}
}
