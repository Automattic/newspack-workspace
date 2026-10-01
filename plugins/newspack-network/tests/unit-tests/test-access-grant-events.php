<?php
/**
 * Class TestAccessGrantEvents
 *
 * @package Newspack_Network
 */

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Incoming_Events\Access_Grant_Changed;
use Newspack_Network\Incoming_Events\Group_Seat_Changed;
use Newspack_Network\Incoming_Events\One_Time_Purchase_Changed;

/**
 * How a site records what a reader holds on other network sites besides their own subscriptions.
 */
class TestAccessGrantEvents extends WP_UnitTestCase {

	/**
	 * A group seat event.
	 *
	 * @param string $site   Site URL.
	 * @param string $email  Member email.
	 * @param string $status Status.
	 * @return Group_Seat_Changed
	 */
	private function seat( $site, $email, $status = 'active' ) {
		return new Group_Seat_Changed(
			$site,
			[
				'email'        => $email,
				'user_id'      => 3,
				'id'           => 90,
				'status_after' => $status,
				'products'     => [
					7 => [
						'id'   => 7,
						'name' => 'Team',
						'slug' => 'team',
					],
				],
			],
			time()
		);
	}

	/**
	 * A one-time purchase event.
	 *
	 * @param string $site  Site URL.
	 * @param string $email Customer email.
	 * @return One_Time_Purchase_Changed
	 */
	private function purchase( $site, $email ) {
		return new One_Time_Purchase_Changed(
			$site,
			[
				'email'        => $email,
				'user_id'      => 3,
				'id'           => 86,
				'status_after' => 'completed',
				'purchased_at' => 1700000000,
				'products'     => [
					30 => [
						'id'   => 30,
						'name' => 'Pass',
						'slug' => 'pass',
					],
				],
			],
			time()
		);
	}

	/**
	 * A seat and a purchase are recorded on the reader, keyed by site and item.
	 */
	public function test_events_record_grants_on_the_reader() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );

		$this->seat( 'https://a.example.test', 'reader@example.test', 'pending-cancel' )->process_in_node();
		$this->purchase( 'https://a.example.test', 'reader@example.test' )->process_in_node();

		$grants = Access_Grant_Changed::get_user_grants( $user_id )['https://a.example.test'];
		$this->assertSame( 'group', $grants['group:90']['type'] );
		$this->assertSame( 'pending-cancel', $grants['group:90']['status'] );
		$this->assertSame( [ 7 ], array_keys( $grants['group:90']['products'] ) );
		$this->assertSame( 'purchase', $grants['order:86']['type'] );
		$this->assertSame( 1700000000, $grants['order:86']['purchased_at'] );
	}

	/**
	 * A later event for the same seat replaces its record.
	 */
	public function test_later_event_replaces_the_record() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );

		$this->seat( 'https://a.example.test', 'reader@example.test', 'active' )->process_in_node();
		$this->seat( 'https://a.example.test', 'reader@example.test', 'cancelled' )->process_in_node();

		$this->assertSame( 'cancelled', Access_Grant_Changed::get_user_grants( $user_id )['https://a.example.test']['group:90']['status'] );
	}

	/**
	 * Every site numbers its own orders and subscriptions, so the same ID on two sites is two records.
	 */
	public function test_grants_are_kept_per_site() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );

		$this->purchase( 'https://a.example.test', 'reader@example.test' )->process_in_node();
		$this->purchase( 'https://b.example.test', 'reader@example.test' )->process_in_node();

		$this->assertSame( [ 'https://a.example.test', 'https://b.example.test' ], array_keys( Access_Grant_Changed::get_user_grants( $user_id ) ) );
	}

	/**
	 * With no account for the email, the event is dropped, as subscription events are.
	 */
	public function test_event_for_unknown_email_writes_nothing() {
		$this->seat( 'https://a.example.test', 'nobody@example.test' )->process_in_node();

		$this->assertSame( [], get_users( [ 'meta_key' => Access_Grant_Changed::USER_GRANTS_META_KEY ] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	/**
	 * Both actions are accepted and pulled by nodes, like subscription events.
	 */
	public function test_actions_are_accepted_and_pulled() {
		$this->assertSame( 'Group_Seat_Changed', Accepted_Actions::ACTIONS['newspack_node_group_seat_changed'] );
		$this->assertSame( 'One_Time_Purchase_Changed', Accepted_Actions::ACTIONS['newspack_node_one_time_purchase_changed'] );
		$this->assertContains( 'newspack_node_group_seat_changed', Accepted_Actions::ACTIONS_THAT_NODES_PULL );
		$this->assertContains( 'newspack_node_one_time_purchase_changed', Accepted_Actions::ACTIONS_THAT_NODES_PULL );
	}
}
