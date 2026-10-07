<?php
/**
 * Class TestGroupSeatBackfiller
 *
 * @package Newspack_Network
 */

use Newspack_Network\Backfillers\Group_Seat_Changed;
use Newspack_Network\Woocommerce_Subscriptions\Group_Seats;

require_once dirname( __DIR__ ) . '/mocks/class-group-subscription.php';
require_once dirname( __DIR__ ) . '/mocks/class-group-subscription-settings.php';
require_once dirname( __DIR__ ) . '/mocks/wcs-functions.php';

/**
 * What the seat backfill sends for seats whose group is gone.
 */
class TestGroupSeatBackfiller extends WP_UnitTestCase {

	/**
	 * Start with no stand-in subscriptions.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['newspack_network_test_group'] = [];
	}

	/**
	 * The events a verbose, live backfill builds.
	 *
	 * @return \Newspack_Network\Incoming_Events\Group_Seat_Changed[]
	 */
	private function events() {
		return iterator_to_array( ( new Group_Seat_Changed( null, null, true, true ) )->get_events(), false );
	}

	/**
	 * A seat on a subscription that no longer loads is still found, through the member's
	 * meta, and sent as cancelled with the join time as its stamp: that is the revocation a
	 * lost "owner deleted" event leaves behind.
	 */
	public function test_seat_on_a_deleted_group_is_sent_as_cancelled() {
		$member = self::factory()->user->create( [ 'user_email' => 'member@example.test' ] );
		add_user_meta( $member, Group_Seats::MEMBER_META_KEY, 90 );
		update_user_meta( $member, '_newspack_group_subscription_joined_90', 1700000000 );

		$events = $this->events();

		$this->assertCount( 1, $events );
		$this->assertSame( 90, $events[0]->get_id() );
		$this->assertSame( 'cancelled', $events[0]->get_status_after() );
		$this->assertSame( 'member@example.test', $events[0]->get_email() );
		$this->assertSame( 1700000000, $events[0]->get_timestamp() );
	}

	/**
	 * A member who joined before join times were recorded, on a group that is gone, is
	 * stamped with their registration date rather than skipped.
	 */
	public function test_seat_with_no_join_time_on_a_deleted_group_uses_the_registration_date() {
		$member = self::factory()->user->create( [ 'user_registered' => '2024-01-02 03:04:05' ] );
		add_user_meta( $member, Group_Seats::MEMBER_META_KEY, 90 );

		$events = $this->events();

		$this->assertCount( 1, $events );
		$this->assertSame( 'cancelled', $events[0]->get_status_after() );
		$this->assertSame( strtotime( '2024-01-02 03:04:05' ), $events[0]->get_timestamp() );
	}

	/**
	 * A zero registration date, as an imported user table can carry, parses to a time
	 * before 1901 that the event log would clamp and mis-date; the seat is skipped instead.
	 */
	public function test_seat_with_no_usable_date_is_skipped() {
		$member = self::factory()->user->create( [ 'user_registered' => '0000-00-00 00:00:00' ] );
		add_user_meta( $member, Group_Seats::MEMBER_META_KEY, 90 );

		$this->assertSame( [], $this->events() );
	}
}
