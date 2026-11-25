<?php
/**
 * Class TestGiftBackfiller
 *
 * @package Newspack_Network
 */

use Newspack_Network\Backfillers\Gift_Changed as Gift_Backfiller;

require_once dirname( __DIR__ ) . '/mocks/class-wcs-gifting.php';
require_once dirname( __DIR__ ) . '/mocks/wcs-functions.php';

/**
 * What the gift backfill sends, for gifts given before gift recipients were reported.
 */
class TestGiftBackfiller extends WP_UnitTestCase {

	/**
	 * Start with no stand-in subscriptions and no gifts.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['newspack_network_test_group'] = [];
		WCS_Gifting::reset();
	}

	/**
	 * Forget the gifts.
	 */
	public function tear_down() {
		$GLOBALS['newspack_network_test_group'] = [];
		WCS_Gifting::reset();
		parent::tear_down();
	}

	/**
	 * A stand-in subscription, registered so wcs_get_subscription() loads it.
	 *
	 * @param int    $id      Subscription ID.
	 * @param string $status  Status.
	 * @param int    $created Creation time.
	 */
	private function subscription( $id, $status, $created ) {
		$GLOBALS['newspack_network_test_group']['subscriptions'][ $id ] = new class( $id, $status, $created ) {
			/**
			 * Constructor.
			 *
			 * @param int    $id      ID.
			 * @param string $status  Status.
			 * @param int    $created Creation time.
			 */
			public function __construct( public $id, public $status, public $created ) {}

			/**
			 * ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return $this->id;
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

			/**
			 * Creation date.
			 *
			 * @return DateTime
			 */
			public function get_date_created() {
				return new DateTime( '@' . $this->created );
			}
		};
	}

	/**
	 * The events a verbose, live backfill builds.
	 *
	 * @return \Newspack_Network\Incoming_Events\Gift_Changed[]
	 */
	private function events() {
		return iterator_to_array( ( new Gift_Backfiller( null, null, true, true ) )->get_events(), false );
	}

	/**
	 * The backfill asks for subscriptions carrying a recipient, and sends one event per
	 * gift to its recipient, stamped with the subscription's creation date. A listed
	 * subscription that isn't a gift, no longer loads, or whose recipient has no account
	 * left is skipped.
	 */
	public function test_backfill_sends_each_gift_to_its_recipient() {
		$recipient = self::factory()->user->create( [ 'user_email' => 'recipient@example.test' ] );
		$this->subscription( 90, 'pending-cancel', 1700000000 );
		$this->subscription( 91, 'active', 1700000000 );
		$this->subscription( 93, 'active', 1700000000 );
		WCS_Gifting::$recipients[90] = $recipient;
		WCS_Gifting::$recipients[93] = $recipient + 1000;
		$GLOBALS['newspack_network_test_group']['order_query_ids'] = [ 90, 91, 92, 93 ];

		$events = $this->events();

		$this->assertContains( '_recipient_user', wp_list_pluck( array_filter( $GLOBALS['newspack_network_test_group']['order_queries'][0]['meta_query'], 'is_array' ), 'key' ) );
		$this->assertCount( 1, $events );
		$this->assertSame( 'newspack_node_gift_changed', $events[0]->get_action_name() );
		$this->assertSame( 90, $events[0]->get_id() );
		$this->assertSame( 'recipient@example.test', $events[0]->get_email() );
		$this->assertSame( $recipient, $events[0]->get_data()->user_id );
		$this->assertSame( 'pending-cancel', $events[0]->get_status_after() );
		$this->assertSame( 1700000000, $events[0]->get_timestamp() );
	}
}
