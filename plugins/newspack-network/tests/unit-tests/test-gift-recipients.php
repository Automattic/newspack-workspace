<?php
/**
 * Class TestGiftRecipients
 *
 * @package Newspack_Network
 */

use Newspack_Network\Woocommerce_Subscriptions\Gift_Recipients;

require_once dirname( __DIR__ ) . '/mocks/class-wcs-gifting.php';
require_once dirname( __DIR__ ) . '/mocks/wcs-functions.php';
require_once dirname( __DIR__ ) . '/mocks/wc-order-functions.php';

/**
 * How a site reports the recipient of a gift subscription, who reads with it but isn't its customer.
 */
class TestGiftRecipients extends WP_UnitTestCase {

	/**
	 * Payloads built for each report, as the Data Events listener builds them.
	 *
	 * @var array[]
	 */
	private $reported = [];

	/**
	 * Arguments of each request to re-send a buyer's subscription event.
	 *
	 * @var array[]
	 */
	private $resent = [];

	/**
	 * Start from an empty queue, no gifts, and record each report's payload.
	 */
	public function set_up() {
		parent::set_up();
		Gift_Recipients::dispatch_queued();
		WCS_Gifting::reset();
		$this->reported                         = [];
		$this->resent                           = [];
		$GLOBALS['newspack_network_test_group'] = [];
		// Data Events hands every hook argument to the listener's callable.
		add_action(
			Gift_Recipients::HOOK,
			function ( ...$args ) {
				$this->reported[] = Gift_Recipients::get_event_data( ...$args );
			},
			10,
			3
		);
		add_action(
			'newspack_network_gift_subscription_changed',
			function ( ...$args ) {
				$this->resent[] = $args;
			}
		);
	}

	/**
	 * Forget the gifts.
	 */
	public function tear_down() {
		WCS_Gifting::reset();
		$GLOBALS['newspack_network_test_group'] = [];
		parent::tear_down();
	}

	/**
	 * A stand-in subscription, registered so wcs_get_subscription() loads it.
	 *
	 * @param int    $id          Subscription ID.
	 * @param string $status      Status.
	 * @param int    $customer_id The buyer's user ID.
	 * @return object
	 */
	private function subscription( $id, $status = 'active', $customer_id = 0 ) {
		$subscription = new class( $id, $status, $customer_id ) {
			/**
			 * Constructor.
			 *
			 * @param int    $id          ID.
			 * @param string $status      Status.
			 * @param int    $customer_id Buyer user ID.
			 */
			public function __construct( public $id, public $status, public $customer_id ) {}

			/**
			 * Buyer user ID.
			 *
			 * @return int
			 */
			public function get_customer_id() {
				return $this->customer_id;
			}

			/**
			 * The buyer's account.
			 *
			 * @return WP_User|false
			 */
			public function get_user() {
				return $this->customer_id ? get_userdata( $this->customer_id ) : false;
			}

			/**
			 * Billing email.
			 *
			 * @return string
			 */
			public function get_billing_email() {
				return 'billing@example.test';
			}

			/**
			 * Formatted total.
			 *
			 * @return string
			 */
			public function get_formatted_order_total() {
				return '$10.00';
			}

			/**
			 * Currency.
			 *
			 * @return string
			 */
			public function get_currency() {
				return 'USD';
			}

			/**
			 * Total.
			 *
			 * @return string
			 */
			public function get_total() {
				return '10';
			}

			/**
			 * Payment method title.
			 *
			 * @return string
			 */
			public function get_payment_method_title() {
				return 'Card';
			}

			/**
			 * Creation date.
			 *
			 * @return DateTime
			 */
			public function get_date_created() {
				return new DateTime( '@1700000000' );
			}

			/**
			 * A subscription date; the stand-in has none.
			 *
			 * @param string $type Date type.
			 * @return int
			 */
			public function get_date( $type ) {
				return 0;
			}

			/**
			 * Billing period.
			 *
			 * @return string
			 */
			public function get_billing_period() {
				return 'month';
			}

			/**
			 * Billing interval.
			 *
			 * @return int
			 */
			public function get_billing_interval() {
				return 1;
			}

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
		};
		$GLOBALS['newspack_network_test_group']['subscriptions'][ $id ] = $subscription;
		return $subscription;
	}

	/**
	 * The reported status for each recipient, keyed by user ID.
	 *
	 * @return string[]
	 */
	private function reported_statuses() {
		return wp_list_pluck( $this->reported, 'status_after', 'user_id' );
	}

	/**
	 * A status change on a gift reports its recipient once, at the end of the request,
	 * named by their own email and carrying the subscription's status.
	 */
	public function test_status_change_on_a_gift_reports_the_recipient() {
		$recipient    = self::factory()->user->create( [ 'user_email' => 'recipient@example.test' ] );
		$subscription = $this->subscription( 90, 'pending-cancel' );
		WCS_Gifting::$recipients[90] = $recipient;

		do_action( 'woocommerce_subscription_status_changed', 90, 'active', 'pending-cancel', $subscription );
		$this->assertSame( [], $this->reported );
		Gift_Recipients::dispatch_queued();

		$this->assertSame( 'newspack_node_gift_changed', Gift_Recipients::ACTION );
		$this->assertSame(
			[
				[
					'email'        => 'recipient@example.test',
					'user_id'      => $recipient,
					'id'           => 90,
					'status_after' => 'pending-cancel',
					'products'     => [],
				],
			],
			$this->reported
		);
	}

	/**
	 * A subscription that isn't a gift has no recipient to report.
	 */
	public function test_status_change_on_a_subscription_that_is_not_a_gift_reports_nothing() {
		$subscription = $this->subscription( 91 );

		do_action( 'woocommerce_subscription_status_changed', 91, 'pending', 'active', $subscription );
		Gift_Recipients::dispatch_queued();

		$this->assertSame( [], $this->reported );
	}

	/**
	 * Moving a gift to someone else reports the new recipient with the subscription's
	 * status and the old one as cancelled, so the old one loses access elsewhere.
	 */
	public function test_recipient_change_reports_the_new_recipient_and_cancels_the_old() {
		$old_recipient = self::factory()->user->create();
		$new_recipient = self::factory()->user->create();
		$subscription  = $this->subscription( 90 );
		WCS_Gifting::$recipients[90] = $new_recipient;

		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $new_recipient, $old_recipient );
		Gift_Recipients::dispatch_queued();

		// Still a gift, so the buyer's subscription event has nothing new to say.
		$this->assertSame( [], $this->resent );
		// assertEquals compares keyed arrays key by key, whatever their order.
		$this->assertEquals(
			[
				$new_recipient => 'active',
				$old_recipient => 'cancelled',
			],
			$this->reported_statuses()
		);
	}

	/**
	 * WooCommerce Subscriptions fires the change hook even when the recipient stays the
	 * same, which must not cancel the gift for the reader still holding it.
	 */
	public function test_unchanged_recipient_is_not_cancelled() {
		$recipient    = self::factory()->user->create();
		$subscription = $this->subscription( 90 );
		WCS_Gifting::$recipients[90] = $recipient;

		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $recipient, $recipient );
		Gift_Recipients::dispatch_queued();

		$this->assertSame( [ $recipient => 'active' ], $this->reported_statuses() );
	}

	/**
	 * A recipient removed without a new one (an admin clearing it, or an account
	 * deletion) is reported as cancelled. The action is taken at its word even if the
	 * recipient still reads as set when the request ends.
	 *
	 * @dataProvider cleared_recipient_still_set_data
	 *
	 * @param bool $still_set Whether the recipient still reads as set at dispatch.
	 */
	public function test_cleared_recipient_is_reported_cancelled( $still_set ) {
		$recipient    = self::factory()->user->create();
		$subscription = $this->subscription( 90 );
		if ( $still_set ) {
			WCS_Gifting::$recipients[90] = $recipient;
		}

		do_action( 'newspack_subscription_gift_recipient_cleared', $subscription, $recipient );
		Gift_Recipients::dispatch_queued();

		$this->assertSame( [ $recipient => 'cancelled' ], $this->reported_statuses() );
	}

	/**
	 * Data provider for test_cleared_recipient_is_reported_cancelled.
	 *
	 * @return array
	 */
	public function cleared_recipient_still_set_data() {
		return [
			'recipient gone'      => [ false ],
			'recipient still set' => [ true ],
		];
	}

	/**
	 * A gift whose recipient is removed is the buyer's again, with no status change to
	 * report it, so the buyer's subscription event is sent again saying it isn't a
	 * gift, and other sites give the buyer access again.
	 */
	public function test_cleared_recipient_resends_the_buyers_subscription_as_not_a_gift() {
		$buyer        = self::factory()->user->create( [ 'user_email' => 'buyer@example.test' ] );
		$recipient    = self::factory()->user->create();
		$subscription = $this->subscription( 90, 'active', $buyer );

		do_action( 'newspack_subscription_gift_recipient_cleared', $subscription, $recipient );
		Gift_Recipients::dispatch_queued();

		$this->assertCount( 1, $this->resent );
		$event = Gift_Recipients::get_subscription_event_data( ...$this->resent[0] );
		$this->assertSame( 90, $event['id'] );
		$this->assertSame( 'buyer@example.test', $event['email'] );
		$this->assertSame( 'active', $event['status_before'] );
		$this->assertSame( 'active', $event['status_after'] );
		$this->assertFalse( $event['is_gift'] );
	}

	/**
	 * A subscription given a recipient after it was bought becomes a gift with no status
	 * change, so the buyer's subscription event is sent again, once per request, saying
	 * it is a gift, and other sites stop giving the buyer access through it.
	 */
	public function test_recipient_set_on_a_subscription_resends_it_once_as_a_gift() {
		$buyer        = self::factory()->user->create( [ 'user_email' => 'buyer@example.test' ] );
		$recipient    = self::factory()->user->create();
		$subscription = $this->subscription( 91, 'active', $buyer );
		WCS_Gifting::$recipients[91] = $recipient;

		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $recipient, 0 );
		do_action( 'woocommerce_subscriptions_gifting_recipient_changed', $subscription, $recipient, 0 );
		Gift_Recipients::dispatch_queued();

		$this->assertCount( 1, $this->resent );
		$event = Gift_Recipients::get_subscription_event_data( ...$this->resent[0] );
		$this->assertSame( 91, $event['id'] );
		$this->assertSame( 'buyer@example.test', $event['email'] );
		$this->assertTrue( $event['is_gift'] );
		$this->assertSame( [ $recipient => 'active' ], $this->reported_statuses() );
	}

	/**
	 * A recipient whose account is deleted before the end of the request is still
	 * reported, as cancelled, under the email kept when the gift was queued.
	 */
	public function test_deleted_recipient_is_reported_with_the_kept_email() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$recipient    = self::factory()->user->create( [ 'user_email' => 'deleted-recipient@example.test' ] );
		$subscription = $this->subscription( 90 );

		do_action( 'newspack_subscription_gift_recipient_cleared', $subscription, $recipient );
		wp_delete_user( $recipient );
		Gift_Recipients::dispatch_queued();

		$this->assertCount( 1, $this->reported );
		$this->assertSame( 'deleted-recipient@example.test', $this->reported[0]['email'] );
		$this->assertSame( $recipient, $this->reported[0]['user_id'] );
		$this->assertSame( 'cancelled', $this->reported[0]['status_after'] );
	}

	/**
	 * A gift whose subscription no longer loads by the end of the request (deleted
	 * along with its buyer) is reported as cancelled.
	 */
	public function test_gift_whose_subscription_is_gone_is_reported_cancelled() {
		$recipient    = self::factory()->user->create();
		$subscription = $this->subscription( 90 );
		WCS_Gifting::$recipients[90] = $recipient;

		do_action( 'woocommerce_subscription_status_changed', 90, 'active', 'cancelled', $subscription );
		unset( $GLOBALS['newspack_network_test_group']['subscriptions'][90] );
		Gift_Recipients::dispatch_queued();

		$this->assertSame( [ $recipient => 'cancelled' ], $this->reported_statuses() );
	}
}
