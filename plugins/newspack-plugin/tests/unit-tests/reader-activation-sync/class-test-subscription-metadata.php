<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests the Subscription contact metadata class.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscriptions_Meta;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Subscription;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Test Subscription metadata.
 *
 * @group Subscription_Metadata
 */
class Test_Subscription_Metadata extends WP_UnitTestCase {

	private static $user_id = null;

	/**
	 * Set up test user data.
	 *
	 * @var array Default user data for test user creation.
	 */
	const USER_DATA = [
		'user_login' => 'sub_test_user',
		'user_email' => 'subtest@example.com',
		'user_pass'  => 'password',
	];

	public static function set_up_before_class() {
		self::$user_id = wp_insert_user( self::USER_DATA );
	}

	public function set_up() {
		global $orders_database, $subscriptions_database;
		$orders_database        = [];
		$subscriptions_database = [];
		wp_delete_user( self::$user_id );
		self::$user_id = wp_insert_user( self::USER_DATA );
	}

	/**
	 * Helper to create a subscription with sensible defaults.
	 *
	 * @param array $overrides Optional overrides for subscription properties.
	 */
	private function create_subscription( $overrides = [] ) {
		$defaults = [
			'customer_id'      => self::$user_id,
			'status'           => 'active',
			'total'            => '10.00',
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'date_paid'        => '2025-01-15 12:00:00',
			'dates'            => [
				'start'        => '2025-01-15 12:00:00',
				'end'          => 0,
				'next_payment' => '2025-02-15 12:00:00',
			],
			'items'            => [
				new WC_Order_Item_Product(
					[
						'name'       => 'Premium Plan',
						'product_id' => 100,
					]
				),
			],
		];

		return wcs_create_subscription( array_merge( $defaults, $overrides ) );
	}

	/**
	 * Helper to create a completed order.
	 *
	 * @param array $overrides Optional overrides for order properties.
	 */
	private function create_order( $overrides = [] ) {
		$defaults = [
			'customer_id' => self::$user_id,
			'status'      => 'completed',
			'total'       => '10.00',
			'date_paid'   => '2025-01-15 12:00:00',
		];
		return wc_create_order( array_merge( $defaults, $overrides ) );
	}

	public function test_get_fields_returns_expected_keys() {
		$fields = Subscription::get_fields();
		$this->assertArrayHasKey( 'Subscriber_Status', $fields );
		$this->assertArrayHasKey( 'Active_Subscription_Count', $fields );
		$this->assertArrayHasKey( 'Current_Subscription_Start_Date', $fields );
		$this->assertArrayHasKey( 'Current_Subscription_End_Date', $fields );
		$this->assertArrayHasKey( 'Subscription_Cancellation_Reason', $fields );
		$this->assertArrayHasKey( 'Current_Subscription_Billing_Cycle', $fields );
		$this->assertArrayHasKey( 'Current_Subscription_Recurring_Payment', $fields );
		$this->assertArrayHasKey( 'Current_Subscription_Next_Payment_Date', $fields );
		$this->assertArrayHasKey( 'Current_Subscription_Product_Name', $fields );
		$this->assertArrayHasKey( 'Previous_Subscription_Product', $fields );
		$this->assertArrayHasKey( 'Current_Subscription_Coupon_Code', $fields );
		$this->assertArrayHasKey( 'Last_Payment_Amount', $fields );
		$this->assertArrayHasKey( 'Last_Payment_Date', $fields );
		$this->assertCount( 13, $fields );
	}

	public function test_metadata_empty_for_nonexistent_user() {
		$metadata_obj = new Subscription( 0 );
		$metadata      = $metadata_obj->get_metadata();
		$this->assertEmpty( $metadata );
	}

	public function test_metadata_empty_when_no_subscriptions() {
		$metadata_obj = new Subscription( self::$user_id );
		$metadata      = $metadata_obj->get_metadata();

		$this->assertNotEmpty( $metadata );
		$this->assertSame( '', $metadata['Subscriber_Status'] );
		$this->assertSame( 0, $metadata['Active_Subscription_Count'] );
		$this->assertSame( '', $metadata['Current_Subscription_Start_Date'] );
	}

	public function test_active_subscription_status() {
		$this->create_subscription( [ 'status' => 'active' ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'active', $metadata['Subscriber_Status'] );
	}

	public function test_active_subscription_count() {
		$this->create_subscription( [ 'status' => 'active' ] );
		$this->create_subscription(
			[
				'status' => 'active',
				'total'  => '20.00',
			]
		);
		$this->create_subscription(
			[
				'status' => 'cancelled',
				'total'  => '5.00',
			]
		);

		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 2, $metadata['Active_Subscription_Count'] );
	}

	public function test_pending_cancel_counted_as_active() {
		$this->create_subscription( [ 'status' => 'pending-cancel' ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 1, $metadata['Active_Subscription_Count'] );
	}

	public function test_start_date_formatted() {
		$this->create_subscription(
			[
				'dates' => [
					'start'        => '2025-03-10 08:00:00',
					'end'          => 0,
					'next_payment' => '2025-04-10 08:00:00',
				],
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '2025-03-10 08:00:00', $metadata['Current_Subscription_Start_Date'] );
	}

	public function test_end_date_empty_when_zero() {
		$this->create_subscription(
			[
				'dates' => [
					'start'        => '2025-01-01 00:00:00',
					'end'          => 0,
					'next_payment' => '2025-02-01 00:00:00',
				],
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Current_Subscription_End_Date'] );
	}

	public function test_end_date_formatted_when_set() {
		$this->create_subscription(
			[
				'status' => 'cancelled',
				'dates'  => [
					'start'        => '2025-01-01 00:00:00',
					'end'          => '2025-06-01 00:00:00',
					'next_payment' => 0,
				],
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '2025-06-01 00:00:00', $metadata['Current_Subscription_End_Date'] );
	}

	public function test_billing_cycle() {
		$this->create_subscription( [ 'billing_period' => 'year' ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'year', $metadata['Current_Subscription_Billing_Cycle'] );
	}

	public function test_recurring_payment() {
		$this->create_subscription( [ 'total' => '25.50' ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '25.50', $metadata['Current_Subscription_Recurring_Payment'] );
	}

	public function test_next_payment_date() {
		$this->create_subscription(
			[
				'dates' => [
					'start'        => '2025-01-01 00:00:00',
					'end'          => 0,
					'next_payment' => '2025-07-01 00:00:00',
				],
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '2025-07-01 00:00:00', $metadata['Current_Subscription_Next_Payment_Date'] );
	}

	public function test_next_payment_date_empty_when_zero() {
		$this->create_subscription(
			[
				'status' => 'cancelled',
				'dates'  => [
					'start'        => '2025-01-01 00:00:00',
					'end'          => '2025-03-01 00:00:00',
					'next_payment' => 0,
				],
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Current_Subscription_Next_Payment_Date'] );
	}

	public function test_product_name() {
		$this->create_subscription(
			[
				'items' => [
					new WC_Order_Item_Product(
						[
							'name'       => 'Gold Membership',
							'product_id' => 200,
						]
					),
				],
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Gold Membership', $metadata['Current_Subscription_Product_Name'] );
	}

	public function test_product_name_empty_without_items() {
		$this->create_subscription( [ 'items' => [] ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Current_Subscription_Product_Name'] );
	}

	public function test_cancellation_reason_user_cancelled() {
		$this->create_subscription(
			[
				'status' => 'cancelled',
				'meta'   => [
					Subscriptions_Meta::CANCELLATION_REASON_META_KEY => Subscriptions_Meta::CANCELLATION_REASON_USER_CANCELLED,
				],
				'dates'  => [
					'start'        => '2025-01-01 00:00:00',
					'end'          => '2025-03-01 00:00:00',
					'next_payment' => 0,
				],
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( Subscriptions_Meta::CANCELLATION_REASON_USER_CANCELLED, $metadata['Subscription_Cancellation_Reason'] );
	}

	public function test_cancellation_reason_excludes_pending_cancel() {
		$this->create_subscription(
			[
				'status' => 'pending-cancel',
				'meta'   => [
					Subscriptions_Meta::CANCELLATION_REASON_META_KEY => Subscriptions_Meta::CANCELLATION_REASON_USER_PENDING_CANCEL,
				],
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Subscription_Cancellation_Reason'] );
	}

	public function test_cancellation_reason_excludes_admin_pending_cancel() {
		$this->create_subscription(
			[
				'status' => 'pending-cancel',
				'meta'   => [
					Subscriptions_Meta::CANCELLATION_REASON_META_KEY => Subscriptions_Meta::CANCELLATION_REASON_ADMIN_PENDING_CANCEL,
				],
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Subscription_Cancellation_Reason'] );
	}

	public function test_cancellation_reason_empty_when_not_set() {
		$this->create_subscription( [ 'status' => 'cancelled' ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Subscription_Cancellation_Reason'] );
	}

	public function test_coupon_code_from_subscription() {
		$this->create_subscription( [ 'coupon_codes' => [ 'SAVE10' ] ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'SAVE10', $metadata['Current_Subscription_Coupon_Code'] );
	}

	public function test_coupon_code_from_parent_order() {
		$parent_order = $this->create_order( [ 'coupon_codes' => [ 'WELCOME' ] ] );
		$this->create_subscription( [ 'parent_order' => $parent_order ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'WELCOME', $metadata['Current_Subscription_Coupon_Code'] );
	}

	public function test_coupon_code_subscription_takes_precedence() {
		$parent_order = $this->create_order( [ 'coupon_codes' => [ 'PARENT_COUPON' ] ] );
		$this->create_subscription(
			[
				'coupon_codes' => [ 'SUB_COUPON' ],
				'parent_order' => $parent_order,
			]
		);
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'SUB_COUPON', $metadata['Current_Subscription_Coupon_Code'] );
	}

	public function test_last_payment_amount_and_date() {
		$order = $this->create_order(
			[
				'total'     => '15.00',
				'date_paid' => '2025-05-20 10:00:00',
			]
		);
		$this->create_subscription( [ 'orders' => [ $order ] ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '15.00', $metadata['Last_Payment_Amount'] );
		$this->assertSame( '2025-05-20 10:00:00', $metadata['Last_Payment_Date'] );
	}

	public function test_last_payment_excludes_failed_orders() {
		$good_order = $this->create_order(
			[
				'total'     => '10.00',
				'date_paid' => '2025-04-01 10:00:00',
			]
		);
		$failed_order = $this->create_order(
			[
				'total'     => '99.00',
				'status'    => 'failed',
				'date_paid' => '2025-05-01 10:00:00',
			]
		);
		$this->create_subscription( [ 'orders' => [ $good_order, $failed_order ] ] );
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '10.00', $metadata['Last_Payment_Amount'] );
	}

	public function test_previous_product_from_switch_order() {
		global $products_database;
		$products_database[50] = new WC_Product(
			[
				'id'   => 50,
				'name' => 'Basic Plan',
			]
		);

		$sub = $this->create_subscription();

		$switch_order = $this->create_order(
			[
				'meta' => [
					'_subscription_switch_data' => [
						$sub->get_id() => [ 'old_product_id' => 50 ],
					],
				],
			]
		);

		// Update the subscription's related orders to include the switch order.
		$sub->data['related_orders'] = [ 'switch' => [ $switch_order ] ];

		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Basic Plan', $metadata['Previous_Subscription_Product'] );

		unset( $products_database[50] );
	}

	public function test_previous_product_empty_without_switch() {
		$this->create_subscription();
		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Previous_Subscription_Product'] );
	}

	public function test_current_subscription_prefers_active_over_cancelled() {
		// Create a cancelled subscription first.
		$this->create_subscription(
			[
				'status'         => 'cancelled',
				'total'          => '5.00',
				'billing_period' => 'month',
				'dates'          => [
					'start'        => '2024-01-01 00:00:00',
					'end'          => '2024-06-01 00:00:00',
					'next_payment' => 0,
				],
			]
		);
		// Then an active subscription.
		$this->create_subscription(
			[
				'status'         => 'active',
				'total'          => '20.00',
				'billing_period' => 'year',
				'dates'          => [
					'start'        => '2025-01-01 00:00:00',
					'end'          => 0,
					'next_payment' => '2026-01-01 00:00:00',
				],
			]
		);

		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'active', $metadata['Subscriber_Status'] );
		$this->assertSame( '20.00', $metadata['Current_Subscription_Recurring_Payment'] );
		$this->assertSame( 'year', $metadata['Current_Subscription_Billing_Cycle'] );
	}

	public function test_falls_back_to_cancelled_subscription_when_no_active() {
		$this->create_subscription(
			[
				'status'         => 'cancelled',
				'total'          => '15.00',
				'billing_period' => 'month',
				'dates'          => [
					'start'        => '2025-01-01 00:00:00',
					'end'          => '2025-06-01 00:00:00',
					'next_payment' => 0,
				],
			]
		);

		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'cancelled', $metadata['Subscriber_Status'] );
		$this->assertSame( '15.00', $metadata['Current_Subscription_Recurring_Payment'] );
	}

	public function test_is_relevant_subscription_excludes_donations() {
		// Create a subscription with a donation product item.
		// Since Donations::is_donation_order checks product IDs against donation product IDs,
		// and there are no donation products set up in the test env, all subscriptions
		// should be considered non-donation (relevant for Subscription class).
		$this->create_subscription();

		$metadata = ( new Subscription( self::$user_id ) )->get_metadata();
		$this->assertSame( 'active', $metadata['Subscriber_Status'] );
		$this->assertSame( 1, $metadata['Active_Subscription_Count'] );
	}

	public function test_section_name() {
		$this->assertSame( 'Subscription', Subscription::get_section_name() );
	}
}
