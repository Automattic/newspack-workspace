<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests the Donation contact metadata class.
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Donation;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Test Donation metadata.
 *
 * @group Donation_Metadata
 */
class Test_Donation_Metadata extends WP_UnitTestCase {

	private static $user_id = null;

	/**
	 * Donation product IDs set via option for Donations::is_donation_product.
	 *
	 * @var int
	 */
	private static $donation_product_id       = 500;
	private static $donation_once_product_id  = 501;
	private static $donation_month_product_id = 502;
	private static $donation_year_product_id  = 503;

	const USER_DATA = [
		'user_login' => 'donor_test_user',
		'user_email' => 'donor@example.com',
		'user_pass'  => 'password',
	];

	public static function set_up_before_class() {
		self::$user_id = wp_insert_user( self::USER_DATA );
		self::set_up_donation_products();
	}

	private static function set_up_donation_products() {
		global $products_database;

		// Register the parent grouped product.
		$products_database[ self::$donation_product_id ] = new WC_Product(
			[
				'id'       => self::$donation_product_id,
				'name'     => 'Newspack Donation',
				'type'     => 'grouped',
				'children' => [
					self::$donation_once_product_id,
					self::$donation_month_product_id,
					self::$donation_year_product_id,
				],
			]
		);

		// Register child products with the types/meta the Donations class expects.
		$products_database[ self::$donation_once_product_id ] = new WC_Product(
			[
				'id'   => self::$donation_once_product_id,
				'name' => 'Donation once',
				'type' => 'simple',
			]
		);
		$products_database[ self::$donation_month_product_id ] = new WC_Product(
			[
				'id'   => self::$donation_month_product_id,
				'name' => 'Donation month',
				'type' => 'subscription',
				'meta' => [ '_subscription_period' => 'month' ],
			]
		);
		$products_database[ self::$donation_year_product_id ] = new WC_Product(
			[
				'id'   => self::$donation_year_product_id,
				'name' => 'Donation year',
				'type' => 'subscription',
				'meta' => [ '_subscription_period' => 'year' ],
			]
		);

		update_option( Donations::DONATION_PRODUCT_ID_OPTION, self::$donation_product_id );
	}

	public function set_up() {
		global $orders_database, $subscriptions_database;
		$orders_database        = [];
		$subscriptions_database = [];
		wp_delete_user( self::$user_id );
		self::$user_id = wp_insert_user( self::USER_DATA );
	}

	/**
	 * Helper to create a donation subscription (items contain a donation product).
	 *
	 * @param array $overrides Arguments to override the defaults when creating the subscription.
	 */
	private function create_donation_subscription( $overrides = [] ) {
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
						'name'       => 'Donation month',
						'product_id' => self::$donation_month_product_id,
					]
				),
			],
		];

		return wcs_create_subscription( array_merge( $defaults, $overrides ) );
	}

	/**
	 * Helper to create a non-donation subscription.
	 *
	 * @param array $overrides Arguments to override the defaults when creating the subscription.
	 */
	private function create_non_donation_subscription( $overrides = [] ) {
		$defaults = [
			'customer_id'      => self::$user_id,
			'status'           => 'active',
			'total'            => '30.00',
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
						'product_id' => 999,
					]
				),
			],
		];

		return wcs_create_subscription( array_merge( $defaults, $overrides ) );
	}

	public function test_get_fields_returns_expected_keys() {
		$fields = Donation::get_fields();
		$this->assertArrayHasKey( 'Donor_Status', $fields );
		$this->assertArrayHasKey( 'Active_Donation_Count', $fields );
		$this->assertArrayHasKey( 'Current_Donation_Start_Date', $fields );
		$this->assertArrayHasKey( 'Current_Donation_End_Date', $fields );
		$this->assertArrayHasKey( 'Current_Donation_Cycle', $fields );
		$this->assertArrayHasKey( 'Current_Recurring_Donation', $fields );
		$this->assertArrayHasKey( 'Next_Donation_Date', $fields );
		$this->assertArrayHasKey( 'Current_Donation_Product_Name', $fields );
		$this->assertArrayHasKey( 'Previous_Donation_Product', $fields );
		$this->assertArrayHasKey( 'Previous_Donation_Amount', $fields );
		$this->assertArrayHasKey( 'Last_Donation_Amount', $fields );
		$this->assertArrayHasKey( 'Last_Donation_Date', $fields );
		$this->assertCount( 12, $fields );
	}

	public function test_section_name() {
		$this->assertSame( 'Donation', Donation::get_section_name() );
	}

	public function test_metadata_empty_for_nonexistent_user() {
		$metadata = ( new Donation( 0 ) )->get_metadata();
		$this->assertEmpty( $metadata );
	}

	public function test_ignores_non_donation_subscriptions() {
		$this->create_non_donation_subscription();

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Donor_Status'] );
		$this->assertSame( 0, $metadata['Active_Donation_Count'] );
	}

	public function test_monthly_donor_status() {
		$this->create_donation_subscription( [ 'billing_period' => 'month' ] );

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Monthly Donor', $metadata['Donor_Status'] );
	}

	public function test_yearly_donor_status() {
		$this->create_donation_subscription(
			[
				'billing_period' => 'year',
				'items'          => [
					new WC_Order_Item_Product(
						[
							'name'       => 'Donation year',
							'product_id' => self::$donation_year_product_id,
						]
					),
				],
			]
		);

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Yearly Donor', $metadata['Donor_Status'] );
	}

	public function test_ex_monthly_donor_status() {
		$this->create_donation_subscription(
			[
				'status'         => 'cancelled',
				'billing_period' => 'month',
				'dates'          => [
					'start'        => '2025-01-01 00:00:00',
					'end'          => '2025-06-01 00:00:00',
					'next_payment' => 0,
				],
			]
		);

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Ex-Monthly Donor', $metadata['Donor_Status'] );
	}

	public function test_ex_yearly_donor_status() {
		$this->create_donation_subscription(
			[
				'status'         => 'expired',
				'billing_period' => 'year',
				'dates'          => [
					'start'        => '2024-01-01 00:00:00',
					'end'          => '2025-01-01 00:00:00',
					'next_payment' => 0,
				],
				'items'          => [
					new WC_Order_Item_Product(
						[
							'name'       => 'Donation year',
							'product_id' => self::$donation_year_product_id,
						]
					),
				],
			]
		);

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Ex-Yearly Donor', $metadata['Donor_Status'] );
	}

	public function test_donor_status_with_non_standard_billing_period() {
		$this->create_donation_subscription( [ 'billing_period' => 'week' ] );
		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Donor', $metadata['Donor_Status'] );
	}

	public function test_active_donation_count() {
		$this->create_donation_subscription();
		$this->create_donation_subscription( [ 'total' => '20.00' ] );
		$this->create_non_donation_subscription(); // Should not count.

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( 2, $metadata['Active_Donation_Count'] );
	}

	public function test_donation_dates() {
		$this->create_donation_subscription(
			[
				'dates' => [
					'start'        => '2025-02-14 12:00:00',
					'end'          => 0,
					'next_payment' => '2025-03-14 12:00:00',
				],
			]
		);

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( '2025-02-14 12:00:00', $metadata['Current_Donation_Start_Date'] );
		$this->assertSame( '', $metadata['Current_Donation_End_Date'] );
		$this->assertSame( '2025-03-14 12:00:00', $metadata['Next_Donation_Date'] );
	}

	public function test_donation_billing_cycle_and_amount() {
		$this->create_donation_subscription(
			[
				'billing_period' => 'year',
				'total'          => '120.00',
				'items'          => [
					new WC_Order_Item_Product(
						[
							'name'       => 'Donation year',
							'product_id' => self::$donation_year_product_id,
						]
					),
				],
			]
		);

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( 'year', $metadata['Current_Donation_Cycle'] );
		$this->assertSame( '120.00', $metadata['Current_Recurring_Donation'] );
	}

	public function test_donation_product_name_from_subscription() {
		$this->create_donation_subscription(
			[
				'items' => [
					new WC_Order_Item_Product(
						[
							'name'       => 'Monthly Donation',
							'product_id' => self::$donation_month_product_id,
						]
					),
				],
			]
		);

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Monthly Donation', $metadata['Current_Donation_Product_Name'] );
	}

	public function test_last_donation_amount_from_subscription() {
		$order = wc_create_order(
			[
				'customer_id' => self::$user_id,
				'status'      => 'completed',
				'total'       => '10.00',
				'date_paid'   => '2025-04-01 10:00:00',
			]
		);
		$this->create_donation_subscription( [ 'orders' => [ $order ] ] );

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( '10.00', $metadata['Last_Donation_Amount'] );
		$this->assertSame( '2025-04-01 10:00:00', $metadata['Last_Donation_Date'] );
	}

	public function test_previous_donation_amount_from_switch() {
		global $subscriptions_database;

		// Create an "old" subscription to represent the pre-switch state.
		$old_sub = wcs_create_subscription(
			[
				'customer_id'      => self::$user_id,
				'status'           => 'cancelled',
				'total'            => '5.00',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'date_paid'        => '2025-01-01 12:00:00',
				'dates'            => [
					'start'        => '2025-01-01 12:00:00',
					'end'          => '2025-03-01 12:00:00',
					'next_payment' => 0,
				],
				'items'            => [
					new WC_Order_Item_Product(
						[
							'name'       => 'Donation month',
							'product_id' => self::$donation_month_product_id,
						]
					),
				],
			]
		);

		$current_sub = $this->create_donation_subscription( [ 'total' => '15.00' ] );

		$switch_order = wc_create_order(
			[
				'customer_id' => self::$user_id,
				'status'      => 'completed',
				'total'       => '15.00',
				'date_paid'   => '2025-03-01 12:00:00',
				'meta'        => [
					'_subscription_switch_data' => [
						$current_sub->get_id() => [ 'old_subscription_id' => $old_sub->get_id() ],
					],
				],
			]
		);

		$current_sub->data['related_orders'] = [ 'switch' => [ $switch_order ] ];

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( '5.00', $metadata['Previous_Donation_Amount'] );
	}

	public function test_previous_donation_amount_empty_without_switch() {
		$this->create_donation_subscription();
		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Previous_Donation_Amount'] );
	}

	public function test_prefers_active_donation_over_cancelled() {
		$this->create_donation_subscription(
			[
				'status' => 'cancelled',
				'total'  => '5.00',
				'dates'  => [
					'start'        => '2024-01-01 00:00:00',
					'end'          => '2024-06-01 00:00:00',
					'next_payment' => 0,
				],
			]
		);
		$this->create_donation_subscription(
			[
				'status'         => 'active',
				'total'          => '20.00',
				'billing_period' => 'year',
				'items'          => [
					new WC_Order_Item_Product(
						[
							'name'       => 'Donation year',
							'product_id' => self::$donation_year_product_id,
						]
					),
				],
			]
		);

		$metadata = ( new Donation( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Yearly Donor', $metadata['Donor_Status'] );
		$this->assertSame( '20.00', $metadata['Current_Recurring_Donation'] );
	}
}
