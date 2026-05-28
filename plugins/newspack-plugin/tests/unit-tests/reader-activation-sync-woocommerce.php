<?php
/**
 * Tests Reader Activation Sync WooCommerce.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Sync;
use Newspack\Reader_Activation\Sync\Metadata;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * Tests Reader Activation Sync WooCommerce.
 */
class Newspack_Test_RAS_Sync_WooCommerce extends WP_UnitTestCase {

	/**
	 * The current order that will be returned in the filter
	 *
	 * @var ?WC_Order
	 */
	public static $current_order = false;

	const USER_DATA = [
		'user_login' => 'test_user',
		'user_email' => 'test@example.com',
		'user_pass'  => 'password',
		'meta_input' => [
			'first_name'     => 'John',
			'last_name'      => 'Doe',
			'wc_total_spent' => 100,
		],
	];

	private static $user_id = null; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	public static function set_up_before_class() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		self::$user_id = wp_insert_user( self::USER_DATA );
	}

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		// Clear the mock orders DB.
		global $orders_database;
		$orders_database = [];
		// Reset the user.
		wp_delete_user( self::$user_id );
		self::$user_id = wp_insert_user( self::USER_DATA );

		add_filter(
			'newspack_reader_activation_get_current_product_order_for_sync',
			[ __CLASS__, 'get_current_order' ]
		);
	}

	/**
	 * Tear down the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter(
			'newspack_reader_activation_get_current_product_order_for_sync',
			[ __CLASS__, 'get_current_order' ]
		);
	}

	/**
	 * Get the current order for the test.
	 *
	 * @return ?WC_Order
	 */
	public static function get_current_order() {
		return self::$current_order;
	}

	/**
	 * Test payment metadata extraction - basic shape of the data.
	 */
	public function test_payment_metadata_basic() {
		$order_data = [
			'customer_id' => self::$user_id,
			'status'      => 'completed',
			'total'       => 50,
			'meta'        => [
				'utm' => [
					'source'   => 'test_source',
					'campaign' => 'test_campaign',
					'term'     => 'test_term',
					'content'  => 'test_content',
				],
			],
		];
		$order = \wc_create_order( $order_data );
		self::$current_order = $order;

		$payment_page_url = 'https://example.com/donate';
		$contact_data = Sync\WooCommerce::get_contact_from_order( $order, $payment_page_url );
		$today = gmdate( Metadata::DATE_FORMAT );
		$this->assertEquals(
			[
				'email'    => self::USER_DATA['user_email'],
				'name'     => self::USER_DATA['meta_input']['first_name'] . ' ' . self::USER_DATA['meta_input']['last_name'],
				'metadata' => [
					'payment_page'              => $payment_page_url,
					'membership_status'         => 'customer',
					'product_name'              => '',
					'last_payment_amount'       => $order_data['total'],
					'last_payment_date'         => $today,
					'payment_page_utm_source'   => 'test_source',
					'payment_page_utm_medium'   => '',
					'payment_page_utm_campaign' => 'test_campaign',
					'payment_page_utm_term'     => 'test_term',
					'payment_page_utm_content'  => 'test_content',
					'sub_start_date'            => '',
					'sub_end_date'              => '',
					'billing_cycle'             => '',
					'recurring_payment'         => '',
					'next_payment_date'         => '',
					'total_paid'                => self::USER_DATA['meta_input']['wc_total_spent'] + $order_data['total'],
					'account'                   => self::$user_id,
					'registration_date'         => $today,
					'cancellation_reason'       => '',
				],
			],
			$contact_data
		);
	}

	/**
	 * Test payment metadata extraction - with different location of UTM meta.
	 * Newspack will set the 'utm' order meta, but when importing data, a one-to-one relation
	 * might be needed. In such a case, a field in the imported data would correspond to
	 * a string meta field, instead of the UTM params being stored as serialized values.
	 */
	public function test_payment_metadata_utm() {
		$order_data = [
			'customer_id' => self::$user_id,
			'status'      => 'completed',
			'total'       => 50,
			'meta'        => [
				'utm_source'   => 'test_source',
				'utm_campaign' => 'test_campaign',
			],
		];
		$order = \wc_create_order( $order_data );
		self::$current_order = $order;

		$contact_data = Sync\WooCommerce::get_contact_from_order( $order );
		$this->assertEquals( 'test_source', $contact_data['metadata']['payment_page_utm_source'] );
		$this->assertEquals( 'test_campaign', $contact_data['metadata']['payment_page_utm_campaign'] );
	}

	/**
	 * Test payment metadata extraction using a failed order.
	 */
	public function test_payment_metadata_with_failed_order() {
		$order = \wc_create_order(
			[
				'customer_id' => self::$user_id,
				'status'      => 'failed',
				'total'       => 60,
			]
		);

		$previous_order      = self::$current_order;
		self::$current_order = $order;

		$contact_data = Sync\WooCommerce::get_contact_from_order( $order );

		$this->assertEquals(
			// Disregard the seconds in comparison to avoid flaky tests.
			substr( $contact_data['metadata']['last_payment_date'], 0, -3 ),
			substr( $previous_order->get_date_paid()->date( Metadata::DATE_FORMAT ), 0, -3 )
		);
		$this->assertEquals( $contact_data['metadata']['last_payment_amount'], self::$current_order->get_total() );
	}

	/**
	 * Test payment metadata extraction - using customer as source.
	 */
	public function test_payment_metadata_from_customer() {
		$order_data = [
			'customer_id' => self::$user_id,
			'status'      => 'completed',
			'total'       => 70,
		];
		$order = \wc_create_order( $order_data );

		self::$current_order = $order;

		$contact_data = Sync\WooCommerce::get_contact_from_customer( self::$user_id );
		$this->assertEquals( $order_data['total'], $contact_data['metadata']['last_payment_amount'] );
		$this->assertEquals( gmdate( Metadata::DATE_FORMAT ), $contact_data['metadata']['last_payment_date'] );
	}

	/**
	 * Test payment metadata extraction - using customer who's last order was failed as source.
	 */
	public function test_payment_metadata_from_customer_with_last_order_failed() {
		$completed_order_data = [
			'customer_id' => self::$user_id,
			'status'      => 'completed',
			'total'       => 70,
			'date_paid'   => gmdate( Metadata::DATE_FORMAT, strtotime( '-1 week' ) ),
		];
		$order = \wc_create_order( $completed_order_data );

		self::$current_order = $order;

		// A more recent, but failed, order.
		$failed_order_data = [
			'customer_id' => self::$user_id,
			'status'      => 'failed',
			'total'       => 89,
		];
		$order = \wc_create_order( $failed_order_data );
		$contact_data = Sync\WooCommerce::get_contact_from_customer( self::$user_id );
		$this->assertEquals( $completed_order_data['total'], $contact_data['metadata']['last_payment_amount'] );
		$this->assertEquals( $completed_order_data['date_paid'], $contact_data['metadata']['last_payment_date'] );
	}
}
