<?php
/**
 * Class TestGroupSeats
 *
 * @package Newspack_Network
 */

use Newspack_Network\Woocommerce_Subscriptions\Group_Seats;

require_once dirname( __DIR__ ) . '/mocks/class-group-subscription.php';
require_once dirname( __DIR__ ) . '/mocks/class-group-subscription-settings.php';
require_once dirname( __DIR__ ) . '/mocks/wcs-functions.php';

/**
 * How a site reports changes to the seats on its group subscriptions.
 */
class TestGroupSeats extends WP_UnitTestCase {

	/**
	 * (user, subscription) pairs reported.
	 *
	 * @var array
	 */
	private $reported = [];

	/**
	 * Start from an empty queue and record reports.
	 */
	public function set_up() {
		parent::set_up();
		Group_Seats::dispatch_queued();
		$this->reported                          = [];
		$GLOBALS['newspack_network_test_group'] = [];
		add_action(
			Group_Seats::HOOK,
			function ( $user_id, $subscription_id ) {
				$this->reported[] = [ $user_id, $subscription_id ];
			},
			10,
			2
		);
	}

	/**
	 * A stand-in group subscription.
	 *
	 * @param string $status Status.
	 * @return object
	 */
	private function subscription( $status ) {
		return new class( $status ) {
			/**
			 * Constructor.
			 *
			 * @param string $status Status.
			 */
			public function __construct( public $status ) {}

			/**
			 * ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return 90;
			}

			/**
			 * Status.
			 *
			 * @return string
			 */
			public function get_status() {
				return $this->status;
			}

			/**
			 * No line items; products come from Events::get_subscription_products().
			 *
			 * @return array
			 */
			public function get_items() {
				return [];
			}
		};
	}

	/**
	 * Joining, leaving, and being removed along with every membership at once each
	 * report the seat once, at the end of the request.
	 */
	public function test_queue_sends_one_event_per_seat() {
		$member = self::factory()->user->create();
		add_user_meta( $member, Group_Seats::MEMBER_META_KEY, 123 );
		add_user_meta( $member, Group_Seats::MEMBER_META_KEY, 456 );
		delete_user_meta( $member, Group_Seats::MEMBER_META_KEY, 123 );
		add_user_meta( $member, Group_Seats::MEMBER_META_KEY, 123 );
		add_user_meta( $member, 'unrelated', 789 );

		$this->assertSame( [], $this->reported );
		Group_Seats::dispatch_queued();
		$this->assertEqualsCanonicalizing( [ [ $member, 123 ], [ $member, 456 ] ], $this->reported );

		$this->reported = [];
		delete_user_meta( $member, Group_Seats::MEMBER_META_KEY );
		Group_Seats::dispatch_queued();
		$this->assertEqualsCanonicalizing( [ [ $member, 123 ], [ $member, 456 ] ], $this->reported );
	}

	/**
	 * Deleting a member's account still reports the seat, and keeps the email the cancelled seat event needs.
	 */
	public function test_deleted_member_email_is_kept_for_the_cancelled_seat_event() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$member = self::factory()->user->create( [ 'user_email' => 'deleted-member@example.test' ] );
		add_user_meta( $member, Group_Seats::MEMBER_META_KEY, 123 );
		Group_Seats::dispatch_queued();
		$this->reported = [];

		wp_delete_user( $member );

		$this->assertFalse( get_userdata( $member ) );
		$this->assertSame( 'deleted-member@example.test', Group_Seats::get_queued_email( $member ) );
		$this->assertNull( Group_Seats::get_queued_email( $member + 1000 ) );
		Group_Seats::dispatch_queued();
		$this->assertSame( [ [ $member, 123 ] ], $this->reported );
		$this->assertNull( Group_Seats::get_queued_email( $member ) );
	}

	/**
	 * Turning a group on or off reports every member's seat; other settings don't.
	 */
	public function test_group_toggle_reports_every_seat() {
		$first  = self::factory()->user->create();
		$second = self::factory()->user->create();
		add_user_meta( $first, Group_Seats::MEMBER_META_KEY, 123 );
		add_user_meta( $second, Group_Seats::MEMBER_META_KEY, 123 );
		Group_Seats::dispatch_queued();
		$this->reported = [];

		do_action( 'newspack_group_subscription_settings_updated', 123, [ 'limit' ] );
		Group_Seats::dispatch_queued();
		$this->assertSame( [], $this->reported );

		do_action( 'newspack_group_subscription_settings_updated', 123, [ 'enabled' ] );
		Group_Seats::dispatch_queued();
		$this->assertEqualsCanonicalizing( [ [ $first, 123 ], [ $second, 123 ] ], $this->reported );
	}

	/**
	 * The payload names the member, carries the subscription's status while the seat
	 * is active, and reports the seat as cancelled once it isn't.
	 */
	public function test_payload_reports_seat_state() {
		$active = Group_Seats::build_event_data( 'member@example.test', 5, $this->subscription( 'pending-cancel' ), true );
		$this->assertSame( 'member@example.test', $active['email'] );
		$this->assertSame( 5, $active['user_id'] );
		$this->assertSame( 90, $active['id'] );
		$this->assertSame( 'pending-cancel', $active['status_after'] );
		$this->assertSame( [], $active['products'] );

		$gone = Group_Seats::build_event_data( 'member@example.test', 5, $this->subscription( 'active' ), false );
		$this->assertSame( 'cancelled', $gone['status_after'] );
	}

	/**
	 * A seat is active while the group is on, the member still holds the meta and is
	 * eligible, and the subscription can be loaded; otherwise it is reported cancelled.
	 */
	public function test_event_data_reports_seat_state() {
		$member = self::factory()->user->create( [ 'user_email' => 'member@example.test' ] );
		add_user_meta( $member, Group_Seats::MEMBER_META_KEY, 90 );
		$GLOBALS['newspack_network_test_group']['subscriptions'][90] = $this->subscription( 'active' );

		$this->assertSame( 'active', Group_Seats::get_event_data( $member, 90 )['status_after'] );
		$this->assertSame( 'member@example.test', Group_Seats::get_event_data( $member, 90 )['email'] );

		$GLOBALS['newspack_network_test_group']['settings'][90] = [ 'enabled' => false ];
		$this->assertSame( 'cancelled', Group_Seats::get_event_data( $member, 90 )['status_after'] );

		$GLOBALS['newspack_network_test_group']['settings'][90] = [ 'enabled' => true ];
		$GLOBALS['newspack_network_test_group']['eligible'][ $member ] = false;
		$this->assertSame( 'cancelled', Group_Seats::get_event_data( $member, 90 )['status_after'] );

		unset( $GLOBALS['newspack_network_test_group']['eligible'] );
		delete_user_meta( $member, Group_Seats::MEMBER_META_KEY, 90 );
		$this->assertSame( 'cancelled', Group_Seats::get_event_data( $member, 90 )['status_after'] );
	}

	/**
	 * Deleting an owner cancels and force-deletes their subscription in one request, so
	 * by the time the queued seats are reported there is no subscription to load. The
	 * seat is still reported, as cancelled, so other sites revoke it.
	 */
	public function test_event_data_reports_a_cancelled_seat_when_the_subscription_is_gone() {
		$member = self::factory()->user->create( [ 'user_email' => 'member@example.test' ] );
		add_user_meta( $member, Group_Seats::MEMBER_META_KEY, 90 );

		$data = Group_Seats::get_event_data( $member, 90 );

		$this->assertSame( 'cancelled', $data['status_after'] );
		$this->assertSame( 90, $data['id'] );
		$this->assertSame( 'member@example.test', $data['email'] );
		$this->assertSame( [], $data['products'] );
	}
}
