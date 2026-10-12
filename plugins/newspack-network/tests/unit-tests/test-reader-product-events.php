<?php
/**
 * Class TestReaderProductEvents
 *
 * @package Newspack_Network
 */

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Incoming_Events\Reader_Product_Changed;
use Newspack_Network\Utils\Users;
use Newspack_Network\Incoming_Events\Group_Seat_Changed;
use Newspack_Network\Incoming_Events\One_Time_Purchase_Changed;

/**
 * How a site records the products a reader holds on other network sites besides their own subscriptions.
 */
class TestReaderProductEvents extends WP_UnitTestCase {

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
	 * @param string $site    Site URL.
	 * @param string $email   Customer email.
	 * @param string $status  Order status after the change.
	 * @param int    $user_id Customer ID on the origin site; 0 for a guest order.
	 * @return One_Time_Purchase_Changed
	 */
	private function purchase( $site, $email, $status = 'completed', $user_id = 3 ) {
		return new One_Time_Purchase_Changed(
			$site,
			[
				'email'        => $email,
				'user_id'      => $user_id,
				'id'           => 86,
				'status_after' => $status,
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
	public function test_events_record_products_on_the_reader() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );

		$this->seat( 'https://a.example.test', 'reader@example.test', 'pending-cancel' )->process_in_node();
		$this->purchase( 'https://a.example.test', 'reader@example.test' )->process_in_node();

		$records = Reader_Product_Changed::get_user_products( $user_id )['https://a.example.test'];
		$this->assertSame( 'group', $records['group:90']['type'] );
		$this->assertSame( 'pending-cancel', $records['group:90']['status'] );
		$this->assertSame( [ 7 ], array_keys( $records['group:90']['products'] ) );
		$this->assertSame( 'purchase', $records['order:86']['type'] );
		$this->assertSame( 1700000000, $records['order:86']['purchased_at'] );
	}

	/**
	 * A later event for the same seat replaces its record.
	 */
	public function test_later_event_replaces_the_record() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );

		$this->seat( 'https://a.example.test', 'reader@example.test', 'active' )->process_in_node();
		$this->seat( 'https://a.example.test', 'reader@example.test', 'cancelled' )->process_in_node();

		$this->assertSame( 'cancelled', Reader_Product_Changed::get_user_products( $user_id )['https://a.example.test']['group:90']['status'] );
	}

	/**
	 * Every site numbers its own orders and subscriptions, so the same ID on two sites is two records.
	 */
	public function test_records_are_kept_per_site() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );

		$this->purchase( 'https://a.example.test', 'reader@example.test' )->process_in_node();
		$this->purchase( 'https://b.example.test', 'reader@example.test' )->process_in_node();

		$this->assertSame( [ 'https://a.example.test', 'https://b.example.test' ], array_keys( Reader_Product_Changed::get_user_products( $user_id ) ) );
	}

	/**
	 * With no account for the email, the event creates the network reader account and
	 * records the product on it, as membership events do, so an account that never
	 * propagated or that the registration event hasn't reached yet still gets its access.
	 */
	public function test_event_for_unknown_email_creates_the_account() {
		$this->seat( 'https://a.example.test', 'newcomer@example.test' )->process_in_node();

		$user = get_user_by( 'email', 'newcomer@example.test' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertContains( NEWSPACK_NETWORK_READER_ROLE, $user->roles );
		$this->assertSame( 'https://a.example.test', get_user_meta( $user->ID, Users::USER_META_REMOTE_SITE, true ) );
		$this->assertSame( 'active', Reader_Product_Changed::get_user_products( $user->ID )['https://a.example.test']['group:90']['status'] );
	}

	/**
	 * A record that grants nothing creates no account: the cancelled seat a reader's
	 * own deletion sends out must not bring the reader back elsewhere, and a refund
	 * for someone with no account here has nothing to revoke. A paid order does
	 * create the account, as a seat does.
	 */
	public function test_only_a_record_that_grants_creates_the_account() {
		$this->seat( 'https://a.example.test', 'gone@example.test', 'cancelled' )->process_in_node();
		$this->purchase( 'https://a.example.test', 'gone@example.test', 'refunded' )->process_in_node();
		$this->assertFalse( get_user_by( 'email', 'gone@example.test' ) );

		$this->purchase( 'https://a.example.test', 'buyer@example.test', 'completed' )->process_in_node();
		$buyer = get_user_by( 'email', 'buyer@example.test' );
		$this->assertInstanceOf( WP_User::class, $buyer );
		$this->assertSame( 'completed', Reader_Product_Changed::get_user_products( $buyer->ID )['https://a.example.test']['order:86']['status'] );
	}

	/**
	 * An order with no customer names no reader to propagate, so it creates no
	 * account; it still records on an account that exists here, since the origin's
	 * own one-time rule matches such an order by billing email.
	 */
	public function test_guest_purchase_records_only_on_an_existing_account() {
		$this->purchase( 'https://a.example.test', 'guest@example.test', 'completed', 0 )->process_in_node();
		$this->assertFalse( get_user_by( 'email', 'guest@example.test' ) );

		$user_id = self::factory()->user->create( [ 'user_email' => 'guest@example.test' ] );
		$this->purchase( 'https://a.example.test', 'guest@example.test', 'completed', 0 )->process_in_node();
		$this->assertSame( 'completed', Reader_Product_Changed::get_user_products( $user_id )['https://a.example.test']['order:86']['status'] );
	}

	/**
	 * Both actions are accepted and pulled by nodes, and each resolves to the three
	 * classes the hub, the nodes and the backfill load by name from the action map.
	 */
	public function test_actions_are_accepted_and_pulled() {
		$actions = [
			'newspack_node_group_seat_changed'        => 'Group_Seat_Changed',
			'newspack_node_one_time_purchase_changed' => 'One_Time_Purchase_Changed',
		];
		foreach ( $actions as $action => $class ) {
			$this->assertSame( $class, Accepted_Actions::ACTIONS[ $action ] );
			$this->assertContains( $action, Accepted_Actions::ACTIONS_THAT_NODES_PULL );
			$this->assertTrue( class_exists( 'Newspack_Network\\Incoming_Events\\' . $class ), $class . ' incoming event' );
			$this->assertTrue( class_exists( 'Newspack_Network\\Hub\\Stores\\Event_Log_Items\\' . $class ), $class . ' event log item' );
			$this->assertTrue( class_exists( 'Newspack_Network\\Backfillers\\' . $class ), $class . ' backfiller' );
		}
	}
}
